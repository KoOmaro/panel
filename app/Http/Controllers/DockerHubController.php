<?php

namespace App\Http\Controllers;

use App\Models\DockerHubApp;
use App\Models\Integration;
use App\Models\Machine;
use App\Models\Workspace;
use App\Support\Audit\AuditContext;
use App\Support\Audit\Audited;
use App\Support\DaemonJobQueue;
use App\Support\Deploys\DeployRequest;
use App\Support\Deploys\ImageDeploy;
use App\Support\Images\ImageReference;
use App\Support\Images\RegistryClient;
use App\Support\Integrations\DockerHub\DockerHubApps;
use App\Support\Integrations\DockerHub\DockerHubRepositories;
use App\Support\Integrations\IntegrationFailed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use RuntimeException;

/**
 * Deploying from the workspace's Docker Hub account: its repositories and
 * tags for the deploy dialog, deploying a tag's image as an app, and that
 * app's updates and redeploys on push.
 */
class DockerHubController extends Controller
{
    /** The account's repositories, to pick one to deploy. */
    public function repositories(Workspace $workspace, DockerHubRepositories $hub): JsonResponse
    {
        Gate::authorize('manageMachines', $workspace);
        $account = DockerHubRepositories::account($workspace);
        if ($account === null) {
            return response()->json(['connected' => false, 'account' => null, 'repositories' => [], 'error' => null]);
        }
        $list = $account->status === Integration::FAILING
            ? ['repositories' => [], 'error' => $account->last_error ?? __('Docker Hub turned the token down.')]
            : $hub->list($account);

        return response()->json([
            'connected' => true,
            'account' => ['id' => $account->id, 'label' => $account->label, 'username' => $account->credential('username')],
            'repositories' => $list['repositories'],
            'error' => $list['error'],
        ]);
    }

    public function tags(Request $request, Workspace $workspace, DockerHubRepositories $hub): JsonResponse
    {
        Gate::authorize('manageMachines', $workspace);
        $repository = strtolower((string) $request->validate(['repository' => ['required', 'string', 'max:255', 'regex:'.DockerHubRepositories::REPOSITORY_PATTERN]])['repository']);
        $account = DockerHubRepositories::account($workspace);
        abort_if($account === null || ! DockerHubRepositories::owns($account, $repository), 404);

        try {
            return response()->json(['tags' => $hub->tags($account, $repository)]);
        } catch (IntegrationFailed $e) {
            return response()->json(['tags' => [], 'error' => $e->getMessage()]);
        }
    }

    /** Deploys a tag of one of the account's repositories as an app. */
    #[Audited]
    public function deploy(Request $request, Workspace $workspace, Machine $machine, DaemonJobQueue $queue, RegistryClient $registry): JsonResponse
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);
        $account = DockerHubRepositories::account($workspace) ?? abort(404);

        if (($reason = $queue->unavailable($machine, 'deploy')) !== null) {
            return response()->json(['error' => $reason], config('daemon.commands_enabled') ? 409 : 503);
        }
        if (is_string($domain = $request->input('domain'))) {
            $request->merge(['domain' => DeployRequest::bareDomain($domain)]);
        }
        $data = $request->validate([
            'repository' => ['required', 'string', 'max:255', 'regex:'.DockerHubRepositories::REPOSITORY_PATTERN],
            'tag' => ['required', 'string', 'regex:'.DockerHubRepositories::TAG_PATTERN],
            'service' => ['required', 'string', 'max:63', 'regex:'.DeployRequest::SERVICE_PATTERN],
            'port' => ['required', 'integer', 'between:1,65535'],
            'health_path' => ['nullable', 'string', 'max:200', 'regex:/^\/[\x21-\x7e]*$/'],
            'domain' => ['nullable', 'string', 'max:253', 'regex:'.DeployRequest::DOMAIN_PATTERN],
            'wildcard' => ['sometimes', 'boolean'],
            'proxy' => ['nullable', 'string', Rule::in(array_keys(Machine::PROXIES))],
            'https' => ['sometimes', 'nullable', 'boolean'],
            'extra_env' => ['nullable', 'string', 'max:10000'],
        ], ['repository.regex' => __('Pick a repository from the list.')]);
        $repository = strtolower($data['repository']);
        if (! DockerHubRepositories::owns($account, $repository)) {
            throw ValidationException::withMessages(['repository' => __('That repository isn’t in this Docker Hub account or its organizations.')]);
        }

        DeployRequest::assertWildcard($machine, $data['domain'] ?? null, (bool) ($data['wildcard'] ?? false));
        DeployRequest::assertDocker($machine, $request->user());
        $proxy = DeployRequest::proxy($machine, $data['domain'] ?? null, $data['proxy'] ?? null);
        $https = DeployRequest::https($machine, $data['domain'] ?? null, $data['https'] ?? null);
        $env = ImageDeploy::env($data['extra_env'] ?? null);

        $image = ImageReference::parse($repository.':'.$data['tag']) ?? throw ValidationException::withMessages(['tag' => __('That isn’t a tag name.')]);
        $login = DockerHubApps::login($machine, $image);
        if ($login !== null && ! DaemonJobQueue::canUseRegistryLogins($machine)) {
            throw ValidationException::withMessages(['form' => __(':machine’s daemon is too old to pull private images. It updates itself, or run “sudo serverosd update” on the server, then deploy again.', ['machine' => $machine->name])]);
        }
        try {
            $digest = $registry->digest($image, $login, fresh: true);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['tag' => $e->getMessage()]);
        }

        $job = DeployRequest::withHttps(DeployRequest::withProxy(ImageDeploy::job($data, $image, $digest, $env), $proxy), $https);
        try {
            $record = $queue->deploy($machine, $request->user(), $job);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }
        DockerHubApps::remember($machine, $data['service'], $image, $digest, $account);

        return response()->json([
            'jobId' => $record->id,
            'state' => 'queued',
            'template' => $image->display(),
            'generated' => [],
            'kept' => [],
            'url' => filled($data['domain'] ?? null) ? (($https ?? true) ? 'https://' : 'http://').$data['domain'] : null,
            'localUrl' => null,
            'tunnel' => null,
            'port' => (int) $data['port'],
            'notes' => null,
            'commit' => ImageDeploy::commitOf($digest),
        ], 202);
    }

    /** Turns redeploying when a new image is pushed on or off, or makes a new webhook address. */
    #[Audited]
    public function settings(Request $request, Workspace $workspace, Machine $machine, string $service, DockerHubApps $apps): JsonResponse
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);
        $data = $request->validate([
            'redeploy_on_push' => ['required', 'boolean'],
            'new_address' => ['sometimes', 'boolean'],
        ]);

        abort_if(ImageDeploy::live($machine, $service) === null, 404, 'This app wasn’t deployed from an image.');
        $was = (bool) DockerHubApp::query()->where('machine_id', $machine->id)->where('service', $service)->value('redeploy_on_push');
        $row = $apps->setPush($machine, $service, $request->user(), (bool) $data['redeploy_on_push'], (bool) ($data['new_address'] ?? false));
        if ($was === $row->redeploy_on_push && ! ($data['new_address'] ?? false)) {
            AuditContext::nothingChanged();
        }

        return response()->json(['dockerHub' => $apps->props($machine, $service, true)]);
    }

    /** Redeploys the app with the newest image of its tag. */
    #[Audited]
    public function update(Request $request, Workspace $workspace, Machine $machine, string $service, DockerHubApps $apps): JsonResponse
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);

        try {
            $job = $apps->update($machine, $service, $request->user());
        } catch (RuntimeException $e) {
            AuditContext::nothingChanged();

            return response()->json(['error' => $e->getMessage()], 409);
        }

        return response()->json(['jobId' => $job->id, 'state' => 'queued'], 202);
    }

    /** Uses the connected account's login for Docker Hub pulls, in place of the one saved in Settings. */
    #[Audited]
    public function useForPulls(Workspace $workspace, Integration $integration, DockerHubRepositories $hub): RedirectResponse
    {
        abort_unless($integration->workspace_id === $workspace->id && $integration->provider()->key() === DockerHubRepositories::PROVIDER, 404);
        Gate::authorize('manageMachines', $workspace);
        if ($hub->loginState($integration)['state'] === 'linked') {
            AuditContext::nothingChanged();

            return back();
        }

        $hub->syncLogin($integration, takeOver: true);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Deploys now pull from Docker Hub as :name.', ['name' => $integration->credential('username')])]);

        return back();
    }
}
