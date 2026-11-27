<?php

namespace App\Support\Integrations\DockerHub;

use App\Http\Controllers\AppVersionController;
use App\Jobs\RedeployImageApp;
use App\Models\Activity;
use App\Models\ContainerRegistry;
use App\Models\DaemonJob;
use App\Models\DockerHubApp;
use App\Models\Integration;
use App\Models\Machine;
use App\Models\User;
use App\Support\DaemonJobQueue;
use App\Support\Deploys\DeployOnPush;
use App\Support\Deploys\ImageDeploy;
use App\Support\Images\ImageReference;
use App\Support\Images\RegistryClient;
use App\Support\Integrations\IntegrationFailed;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Apps deployed from a Docker Hub image: whether the tag has a newer image
 * than the one running (asked of the registry the way the image update
 * checks ask, from the same kept answers), updating onto it, and
 * redeploying when Docker Hub says a new image was pushed.
 */
final class DockerHubApps
{
    public const int PUSHES_PER_MINUTE = 10;

    public function __construct(
        private readonly RegistryClient $registry,
        private readonly DockerHubRepositories $repositories,
        private readonly DaemonJobQueue $queue,
        private readonly DeployOnPush $pushes,
    ) {}

    public static function login(Machine $machine, ImageReference $image): ?ContainerRegistry
    {
        return $machine->workspace->containerRegistries()->where('host', $image->registry)->first();
    }

    public static function hubUrl(ImageReference $image): ?string
    {
        if ($image->registry !== ContainerRegistry::DOCKER_HUB) {
            return null;
        }

        return str_starts_with($image->repository, 'library/')
            ? 'https://hub.docker.com/_/'.substr($image->repository, 8)
            : 'https://hub.docker.com/r/'.$image->repository;
    }

    /** Keeps which repository and tag an app was deployed from, and with which account. */
    public static function remember(Machine $machine, string $app, ImageReference $image, string $digest, ?Integration $account): DockerHubApp
    {
        $row = DockerHubApp::query()->firstOrNew(['machine_id' => $machine->id, 'service' => $app]);
        $row->forceFill([
            'integration_id' => $account !== null ? $account->id : $row->integration_id,
            'repository' => $image->repository,
            'tag' => $image->tag,
            'digest' => $digest,
        ])->save();

        return $row;
    }

    /**
     * The image card on an app's page: what it runs, whether its tag has
     * moved on since, and redeploying on push. Null for apps not deployed
     * from an image.
     *
     * @return array<string, mixed>|null
     */
    public function props(Machine $machine, string $app, bool $canManage): ?array
    {
        $live = ImageDeploy::live($machine, $app);
        if ($live === null) {
            return null;
        }
        $image = $live['reference'];
        $hub = $image->registry === ContainerRegistry::DOCKER_HUB;
        $row = DockerHubApp::query()->where('machine_id', $machine->id)->where('service', $app)->first();
        $account = $hub ? DockerHubRepositories::account($machine->workspace) : null;

        $latest = null;
        $error = null;
        try {
            $latest = $this->registry->digest($image, self::login($machine, $image));
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        }
        $tag = null;
        if ($account !== null && $account->status === Integration::CONNECTED && DockerHubRepositories::owns($account, $image->repository)) {
            try {
                $tag = $this->repositories->tag($account, $image->repository, $image->tag);
            } catch (IntegrationFailed) {
            }
        }
        $updating = DaemonJob::query()->where('machine_id', $machine->id)->where('kind', 'deploy')->where('service', $app)
            ->whereIn('state', ['queued', 'running'])->latest()->first();

        return [
            'image' => $image->display(),
            'repository' => $image->repository,
            'tag' => $image->tag,
            'url' => self::hubUrl($image),
            'running' => $live['digest'],
            'latest' => $latest,
            'behind' => $latest !== null && $latest !== $live['digest'],
            'error' => $error,
            'pushedAt' => $tag['pushedAt'] ?? null,
            'size' => $tag['size'] ?? null,
            'updating' => $updating?->id,
            'account' => $account === null ? null : ['id' => $account->id, 'label' => $account->label],
            'push' => ! $hub ? null : [
                'on' => (bool) $row?->redeploy_on_push,
                'available' => $account !== null,
                'url' => $canManage && $row?->redeploy_on_push ? $row->hookUrl() : null,
                'firstDeliveryAt' => $row?->first_delivery_at?->toIso8601String(),
                'lastDeliveryAt' => $row?->last_delivery_at?->toIso8601String(),
                'lastPushAt' => $row?->last_push_at?->toIso8601String(),
            ],
        ];
    }

    /**
     * The account page: the login pulls use, its repositories, and the
     * apps deployed from them.
     *
     * @return array<string, mixed>
     */
    public function accountProps(Integration $integration): array
    {
        $list = $this->repositories->list($integration);
        $apps = DockerHubApp::query()->where('integration_id', $integration->id)->with('machine:id,name,workspace_id')->orderBy('repository')->get();

        return [
            'login' => $this->repositories->loginState($integration),
            'namespaces' => $list['namespaces'],
            'repositories' => array_slice($list['repositories'], 0, 50),
            'total' => count($list['repositories']),
            'error' => $list['error'],
            'apps' => array_values($apps->map(fn (DockerHubApp $row) => [
                'machine' => $row->machine->name,
                'app' => $row->service,
                'href' => "/machines/{$row->machine_id}/services/app:{$row->service}",
                'image' => $row->repository.':'.$row->tag,
                'redeploy' => $row->redeploy_on_push,
                'firstDeliveryAt' => $row->first_delivery_at?->toIso8601String(),
                'lastPushAt' => $row->last_push_at?->toIso8601String(),
            ])->all()),
        ];
    }

    /**
     * Turns redeploying on push on or off. Turning it on makes the app's
     * webhook address the first time; a new address replaces the old one.
     *
     * @throws ValidationException
     */
    public function setPush(Machine $machine, string $app, User $user, bool $on, bool $newAddress = false): DockerHubApp
    {
        $live = ImageDeploy::live($machine, $app);
        if ($live === null || $live['reference']->registry !== ContainerRegistry::DOCKER_HUB) {
            throw ValidationException::withMessages(['redeploy_on_push' => self::t('Only an app deployed from a Docker Hub image can redeploy when one is pushed.')]);
        }
        $account = DockerHubRepositories::account($machine->workspace);
        if ($on && $account === null) {
            throw ValidationException::withMessages(['redeploy_on_push' => self::t('Connect Docker Hub in Integrations first.')]);
        }

        $row = DockerHubApp::query()->where('machine_id', $machine->id)->where('service', $app)->first()
            ?? self::remember($machine, $app, $live['reference'], $live['digest'], $account);
        $was = $row->redeploy_on_push;
        $row->forceFill([
            'integration_id' => $account !== null ? $account->id : $row->integration_id,
            'repository' => $live['reference']->repository,
            'tag' => $live['reference']->tag,
            'redeploy_on_push' => $on,
            'push_user_id' => $on ? $user->id : $row->push_user_id,
        ]);
        $renewed = $on && ($row->hook_token === null || $newAddress);
        if ($renewed) {
            $row->newHook();
        }
        $row->save();

        if ($was !== $on || $renewed) {
            Activity::record($machine->workspace, 'app.image_push_setting', $app, $machine, $user, array_filter([
                'on' => $on,
                'image' => $live['reference']->display(),
                'new_address' => $was && $renewed ? true : null,
            ], fn ($value) => $value !== null));
        }

        return $row;
    }

    /**
     * Moves the app onto the newest image of its tag, with everything else
     * as its last deploy had it.
     *
     * @throws RuntimeException
     */
    public function update(Machine $machine, string $app, User $user): DaemonJob
    {
        $last = AppVersionController::lastDeployOf($machine, $app);
        $found = $last === null ? null : ImageDeploy::of((array) $last->command['job']);
        $live = ImageDeploy::live($machine, $app);
        if ($last === null || $found === null || $live === null) {
            throw new RuntimeException(self::t('This app wasn’t deployed from an image.'));
        }
        if (($reason = $this->queue->unavailable($machine, 'deploy')) !== null) {
            throw new RuntimeException($reason);
        }
        $digest = $this->registry->digest($found['reference'], self::login($machine, $found['reference']), fresh: true);
        if ($digest === $live['digest']) {
            throw new RuntimeException(self::t(':app already runs the newest image of :image.', ['app' => $app, 'image' => $found['reference']->display()]));
        }

        $job = $this->queue->deploy($machine, $user, (array) ImageDeploy::withDigest((array) $last->command['job'], $digest));
        DockerHubApp::query()->where('machine_id', $machine->id)->where('service', $app)->update(['digest' => $digest]);

        return $job;
    }

    /**
     * A delivery to an app's webhook address. Docker Hub signs nothing, so
     * the secret in the address is what proves it, and the payload has to
     * name the app's repository and tag.
     *
     * @param  array<string, mixed>  $payload
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function receive(DockerHubApp $row, array $payload): array
    {
        $now = now();
        $row->forceFill(['first_delivery_at' => $row->first_delivery_at ?? $now, 'last_delivery_at' => $now])->save();

        $limit = 'docker-hub-push:'.$row->id;
        if (RateLimiter::tooManyAttempts($limit, self::PUSHES_PER_MINUTE)) {
            return [429, ['error' => 'Too many deliveries for this app. Try again in a minute.']];
        }
        RateLimiter::hit($limit, 60);

        if (! $row->redeploy_on_push || $row->integration_id === null) {
            return [200, ['ignored' => 'Redeploying on push is off for this app.']];
        }
        $repository = data_get($payload, 'repository.repo_name');
        $tag = data_get($payload, 'push_data.tag');
        if (! is_string($repository) || ! is_string($tag) || preg_match(DockerHubRepositories::TAG_PATTERN, $tag) !== 1) {
            return [422, ['error' => 'That isn’t a push from Docker Hub.']];
        }
        if (strcasecmp(trim($repository), $row->repository) !== 0) {
            return [422, ['error' => "This address is for {$row->repository}, not {$repository}. Add it to that repository’s webhooks instead."]];
        }
        if ($tag !== $row->tag) {
            return [200, ['ignored' => "Only pushes of {$row->tag} redeploy this app."]];
        }

        RedeployImageApp::dispatch($row->id, $tag);

        return [202, ['redeploying' => $row->service]];
    }

    /** After a push of the app's tag: redeploys it when the tag points at a new image. */
    public function pushed(DockerHubApp $row): void
    {
        $machine = $row->machine;
        $app = $row->service;
        $last = AppVersionController::lastDeployOf($machine, $app);
        $found = $last === null ? null : ImageDeploy::of((array) $last->command['job']);
        if ($last === null || $found === null || $found['reference']->registry !== ContainerRegistry::DOCKER_HUB || $found['reference']->repository !== $row->repository) {
            $this->skipped($row, null, self::t('it isn’t deployed from that image any more'));

            return;
        }

        try {
            $digest = $this->registry->digest($found['reference'], self::login($machine, $found['reference']), fresh: true);
        } catch (RuntimeException $e) {
            $this->skipped($row, null, $e->getMessage());

            return;
        }
        $row->forceFill(['last_push_at' => now(), 'last_push_digest' => $digest])->save();

        $commit = ImageDeploy::commitOf($digest);
        if ($digest === (ImageDeploy::live($machine, $app)['digest'] ?? null) || $this->pushes->alreadyDeploying($machine, $app, $commit)) {
            return;
        }

        $user = DeployOnPush::actorFor($machine, $row->push_user_id);
        $reason = $user === null ? self::t('no one in the workspace can deploy it') : $this->queue->unavailable($machine, 'deploy');
        if ($user !== null && $reason === null) {
            $reason = $this->pushes->queuePush($machine, $app, $user, (array) ImageDeploy::withDigest((array) $last->command['job'], $digest), $row->tag);
        }
        if ($reason !== null) {
            $this->skipped($row, $digest, $reason);

            return;
        }

        $row->forceFill(['digest' => $digest])->save();
        Activity::system($machine->workspace, 'app.image_pushed', $app, $machine, [
            'image' => $found['reference']->display(),
            'digest' => substr($commit, 0, 7),
        ]);
    }

    /** @param  array<string, string|int>  $replace */
    private static function t(string $text, array $replace = []): string
    {
        return is_string($line = __($text, $replace)) ? $line : $text;
    }

    private function skipped(DockerHubApp $row, ?string $digest, string $reason): void
    {
        Activity::system($row->machine->workspace, 'app.push_skipped', $row->service, $row->machine, [
            'reason' => $reason,
            'branch' => $row->tag,
            'commit' => $digest === null ? '' : substr(ImageDeploy::commitOf($digest), 0, 7),
        ]);
    }
}
