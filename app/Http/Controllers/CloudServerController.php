<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Integration;
use App\Models\IntegrationOperation;
use App\Models\Workspace;
use App\Support\Audit\AuditContext;
use App\Support\Audit\Audited;
use App\Support\Integrations\Capability;
use App\Support\Integrations\IntegrationFailed;
use App\Support\Integrations\Servers\ChoosesRegions;
use App\Support\Integrations\Servers\CloudRegions;
use App\Support\Integrations\Servers\CloudServers;
use App\Support\Integrations\Servers\ManagesServers;
use App\Support\Integrations\Servers\NewServer;
use App\Support\Integrations\Servers\ServerLocation;
use App\Support\Integrations\Servers\ServerOperations;
use App\Support\Integrations\Servers\ServerRules;
use App\Support\Integrations\Servers\ServerSize;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class CloudServerController extends Controller
{
    public function options(Workspace $workspace, Integration $integration): JsonResponse
    {
        abort_unless($integration->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);
        $manager = CloudServers::manager($integration) ?? abort(404);

        try {
            return response()->json([...$manager->plans($integration)->toArray(), 'rules' => $manager->rules()->toArray()])->header('Cache-Control', 'no-store');
        } catch (IntegrationFailed $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    #[Audited]
    public function store(Request $request, Workspace $workspace, Integration $integration, ServerOperations $operations): JsonResponse
    {
        abort_unless($integration->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);
        $manager = CloudServers::manager($integration);
        abort_unless($manager !== null && $integration->provider()->can(Capability::CreateServers), 404);
        if ($manager->rules()->sizing === ServerRules::SIZING_CUSTOM) {
            return $this->storeSized($request, $workspace, $integration, $manager, $operations);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:63', 'regex:/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/i'],
            'location' => ['required', 'string', 'max:40'],
            'type' => ['required', 'string', 'max:40'],
            'image' => ['required', 'string', 'max:80'],
            'keys' => ['sometimes', 'array', 'max:20'],
            'keys.*' => ['string', 'max:200'],
        ], ['name.regex' => __('Use letters, numbers and dashes, like web-3.')]);

        try {
            $plans = $manager->plans($integration);
        } catch (IntegrationFailed $e) {
            throw ValidationException::withMessages(['form' => $e->getMessage()]);
        }
        $type = $plans->type($data['type']);
        $image = $plans->image($data['image'], $type?->architecture);
        $location = $plans->location($data['location']);
        $keys = array_column(array_map(fn ($k) => $k->toArray(), $plans->keys), 'id');
        $errors = array_filter([
            'location' => $location === null ? __('Pick a location from the list.') : null,
            'type' => $type === null || ! in_array($data['location'], $type->locations, true) ? __('That server type can’t be made in this location. Pick another.') : null,
            'image' => $image === null || ($type !== null && $image->architecture !== $type->architecture) ? __('That system doesn’t run on this server type. Pick another.') : null,
            'keys' => array_diff($data['keys'] ?? [], $keys) !== [] ? __('One of the SSH keys isn’t in this project any more.') : null,
        ]);
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        try {
            $operation = $operations->create($integration, $request->user(), new NewServer(
                name: strtolower($data['name']),
                location: $data['location'],
                type: $data['type'],
                image: $data['image'],
                keys: array_values($data['keys'] ?? []),
            ));
        } catch (IntegrationFailed $e) {
            throw ValidationException::withMessages(['form' => $e->getMessage()]);
        }

        Activity::record($workspace, 'machine.server_create', strtolower($data['name']), null, $request->user(), [
            'provider' => $integration->provider()->name(),
            'account' => $integration->label,
            'type' => $type->label,
            'location' => $location->city,
        ]);

        return response()->json(['operation' => ServerOperations::props($operation)], 201);
    }

    // A server sized by hand from a template (Proxmox): the template says where it's made and its smallest disk.
    private function storeSized(Request $request, Workspace $workspace, Integration $integration, ManagesServers $manager, ServerOperations $operations): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:63', 'regex:/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/i'],
            'image' => ['required', 'string', 'max:80'],
            'cores' => ['required', 'integer', 'min:1', 'max:512'],
            'memory_gb' => ['required', 'numeric', 'min:0.5', 'max:4096'],
            'disk_gb' => ['required', 'integer', 'min:1', 'max:65536'],
            'user' => ['nullable', 'string', 'max:32', 'regex:/^[a-z_][a-z0-9_-]*$/'],
            'ssh_keys' => ['nullable', 'string', 'max:16000'],
            'address' => ['nullable', 'string', 'max:18', 'regex:/^(\d{1,3}\.){3}\d{1,3}\/\d{1,2}$/'],
            'gateway' => ['nullable', 'required_with:address', 'ip'],
        ], [
            'name.regex' => __('Use letters, numbers and dashes, like web-3.'),
            'user.regex' => __('Use lowercase letters, numbers, dashes and underscores, like deploy.'),
            'address.regex' => __('Use an IPv4 address with its prefix, like 192.168.1.50/24.'),
        ]);

        $keys = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) ($data['ssh_keys'] ?? '')) ?: [])));
        $badKey = collect($keys)->first(fn (string $key) => preg_match('/^(?:ssh-(?:rsa|ed25519|dss)|ecdsa-sha2-nistp\d+|sk-[a-z0-9-]+@openssh\.com) [A-Za-z0-9+\/=]+(?: .*)?$/', $key) !== 1);
        $address = $data['address'] ?? null;
        $prefix = $address === null ? null : (int) explode('/', $address)[1];

        try {
            $image = $manager->plans($integration)->image($data['image']);
        } catch (IntegrationFailed $e) {
            throw ValidationException::withMessages(['form' => $e->getMessage()]);
        }
        if ($image === null || $image->location === null) {
            throw ValidationException::withMessages(['image' => __('Pick a template from the list.')]);
        }
        $errors = array_filter([
            'disk_gb' => $image->size !== null && (int) $data['disk_gb'] < $image->size->diskGb ? __('The template’s disk is :size GB, so it can’t be smaller.', ['size' => $image->size->diskGb]) : null,
            'ssh_keys' => $badKey !== null ? __('Each line needs one public key, like ssh-ed25519 AAAA… you@laptop.') : null,
            'address' => $address !== null && (filter_var(explode('/', $address)[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false || $prefix < 1 || $prefix > 32) ? __('Use an IPv4 address with its prefix, like 192.168.1.50/24.') : null,
            'gateway' => isset($data['gateway']) && filter_var($data['gateway'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false ? __('Use an IPv4 address, like 192.168.1.1.') : null,
        ]);
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $size = new ServerSize((int) $data['cores'], round((float) $data['memory_gb'], 2), (int) $data['disk_gb']);
        $name = strtolower($data['name']);
        try {
            $operation = $operations->create($integration, $request->user(), new NewServer(
                name: $name,
                location: $image->location,
                type: '',
                image: $image->id,
                size: $size,
                user: ($data['user'] ?? null) ?: null,
                publicKeys: $keys,
                address: $address,
                gateway: $address === null ? null : $data['gateway'],
            ));
        } catch (IntegrationFailed $e) {
            throw ValidationException::withMessages(['form' => $e->getMessage()]);
        }

        Activity::record($workspace, 'machine.server_create', $name, null, $request->user(), [
            'provider' => $integration->provider()->name(),
            'account' => $integration->label,
            'type' => $size->label(),
            'location' => $image->location,
            'template' => $image->label,
        ]);

        return response()->json(['operation' => ServerOperations::props($operation)], 201);
    }

    public function operation(Workspace $workspace, Integration $integration, IntegrationOperation $operation): JsonResponse
    {
        abort_unless($integration->workspace_id === $workspace->id && $operation->integration_id === $integration->id, 404);
        Gate::authorize('manageMachines', $workspace);
        ServerOperations::expireLost();

        return response()->json(['operation' => ServerOperations::props($operation->refresh())])->header('Cache-Control', 'no-store');
    }

    /** The regions ServerOS lists servers in and makes them in, for providers that choose regions. */
    #[Audited]
    public function regions(Request $request, Workspace $workspace, Integration $integration, CloudServers $clouds): RedirectResponse
    {
        abort_unless($integration->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);
        $provider = $integration->provider();
        abort_unless($provider instanceof ChoosesRegions, 404);
        $data = $request->validate(['regions' => ['required', 'array', 'min:1', 'max:50'], 'regions.*' => ['string', 'max:40']]);

        try {
            $available = array_map(fn (ServerLocation $region) => $region->name, $provider->regions($integration));
        } catch (IntegrationFailed $e) {
            throw ValidationException::withMessages(['regions' => $e->getMessage()]);
        }
        if (array_diff($data['regions'], $available) !== []) {
            throw ValidationException::withMessages(['regions' => __('Pick regions from the list.')]);
        }
        $before = $provider->chosenRegions($integration);
        $default = $provider->defaultRegion($integration);
        $picked = array_values(array_unique([$default, ...array_map('strval', $data['regions'])]));
        $stuck = CloudRegions::linkedIn($integration, array_values(array_diff($before, $picked)));
        if ($stuck !== []) {
            $region = (string) array_key_first($stuck);
            throw ValidationException::withMessages(['regions' => trans_choice('Keep :region: :machines is linked to a server there.|Keep :region: :machines are linked to servers there.', count($stuck[$region]), ['region' => $region, 'machines' => implode(', ', $stuck[$region])])]);
        }

        $integration->forceFill(['settings' => [...($integration->settings ?? []), 'regions' => array_values(array_diff($picked, [$default]))]])->save();
        $after = $provider->chosenRegions($integration);
        if ($after === $before) {
            AuditContext::nothingChanged();

            return back();
        }
        Activity::record($workspace, 'integration.regions', $integration->label, null, $request->user(), [
            'provider' => $provider->name(),
            'regions' => implode(', ', $after),
        ]);
        try {
            $clouds->sync($integration);
        } catch (IntegrationFailed) {
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice('Saved. ServerOS looks for servers in :count region.|Saved. ServerOS looks for servers in :count regions.', count($after), ['count' => count($after)])]);

        return back();
    }
}
