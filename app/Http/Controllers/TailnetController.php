<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Integration;
use App\Models\Machine;
use App\Models\TailscaleDevice;
use App\Models\Workspace;
use App\Support\Audit\AuditContext;
use App\Support\Audit\Audited;
use App\Support\Integrations\Tailscale\TailnetChanges;
use App\Support\Integrations\Tailscale\TailnetRefused;
use App\Support\Integrations\Tailscale\TailscaleDevices;
use App\Support\Integrations\Tailscale\TailscaleProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Putting machines on a connected tailnet and taking them off, from the
 * machine's settings and from the Tailscale account's page. A one-off key
 * only ever leaves the server in the commands someone asked to see.
 */
class TailnetController extends Controller
{
    private const int BULK = 25;

    public function show(Workspace $workspace, Machine $machine): JsonResponse
    {
        $this->authorizeFor($workspace, $machine);

        return response()->json(['tailnet' => TailscaleDevices::machineProps($machine)]);
    }

    #[Audited]
    public function store(Request $request, Workspace $workspace, Machine $machine, TailnetChanges $changes): JsonResponse
    {
        $this->authorizeFor($workspace, $machine);
        $integration = $this->account($request, $workspace);

        try {
            $change = $changes->join($integration, $machine, $request->user());
        } catch (TailnetRefused $e) {
            return response()->json(['error' => $e->getMessage(), 'manual' => $e->manual], 409);
        }
        Activity::record($workspace, 'machine.tailnet_join', $machine->name, $machine, $request->user(), ['tailnet' => $integration->label]);

        return response()->json(['change' => TailnetChanges::props($change), 'tailnet' => TailscaleDevices::machineProps($machine)], 202);
    }

    #[Audited]
    public function commands(Request $request, Workspace $workspace, Machine $machine, TailnetChanges $changes): JsonResponse
    {
        $this->authorizeFor($workspace, $machine);
        $integration = $this->account($request, $workspace);

        try {
            ['change' => $change, 'commands' => $commands] = $changes->commands($integration, $machine, $request->user());
        } catch (TailnetRefused $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        }
        Activity::record($workspace, 'machine.tailnet_key', $machine->name, $machine, $request->user(), ['tailnet' => $integration->label]);

        return response()->json([
            'commands' => $commands,
            'expiresAt' => $change->key_expires_at?->toIso8601String(),
            'change' => TailnetChanges::props($change),
        ], 201)->header('Cache-Control', 'no-store');
    }

    #[Audited]
    public function destroy(Request $request, Workspace $workspace, Machine $machine, TailnetChanges $changes): JsonResponse
    {
        $this->authorizeFor($workspace, $machine);
        $device = TailscaleDevice::query()->where('machine_id', $machine->id)
            ->whereHas('integration', fn ($query) => $query->where('workspace_id', $workspace->id)->where('provider', 'tailscale'))
            ->when($request->filled('integration'), fn ($query) => $query->where('integration_id', $request->integer('integration')))
            ->with(['integration', 'machine'])->latest('id')->first();
        if ($device === null) {
            return response()->json(['error' => __(':name isn’t on a tailnet ServerOS knows about.', ['name' => $machine->name])], 404);
        }

        try {
            ['change' => $change, 'commands' => $commands] = $changes->leave($device, $request->user());
        } catch (TailnetRefused $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        }
        Activity::record($workspace, 'machine.tailnet_leave', $machine->name, $machine, $request->user(), ['tailnet' => $device->integration->label, ...($commands === null ? [] : ['manual' => true])]);

        return response()->json(['change' => TailnetChanges::props($change), 'commands' => $commands, 'tailnet' => TailscaleDevices::machineProps($machine)], 202);
    }

    #[Audited]
    public function join(Request $request, Workspace $workspace, Integration $integration, TailnetChanges $changes): JsonResponse
    {
        $this->authorizeAccount($workspace, $integration);
        $data = $request->validate([
            'machines' => ['required', 'array', 'min:1', 'max:'.self::BULK],
            'machines.*' => ['integer', 'distinct', Rule::exists('machines', 'id')->where('workspace_id', $workspace->id)],
        ], [], ['machines' => __('machines')]);

        $results = [];
        $started = 0;
        foreach (Machine::query()->whereIn('id', $data['machines'])->where('workspace_id', $workspace->id)->orderBy('name')->get() as $machine) {
            try {
                $change = $changes->join($integration, $machine, $request->user());
                Activity::record($workspace, 'machine.tailnet_join', $machine->name, $machine, $request->user(), ['tailnet' => $integration->label]);
                $started++;
                $results[] = ['machine' => $machine->id, 'name' => $machine->name, 'ok' => true, 'change' => TailnetChanges::props($change)];
            } catch (TailnetRefused $e) {
                $results[] = ['machine' => $machine->id, 'name' => $machine->name, 'ok' => false, 'error' => $e->getMessage(), 'manual' => $e->manual];
            }
        }
        if ($started === 0) {
            AuditContext::nothingChanged();
        }

        return response()->json(['results' => $results, 'started' => $started], $started > 0 ? 202 : 409);
    }

    #[Audited]
    public function update(Request $request, Workspace $workspace, Integration $integration): JsonResponse
    {
        $this->authorizeAccount($workspace, $integration);
        $data = $request->validate([
            'tags' => ['present', 'array', 'max:10'],
            'tags.*' => ['string', 'distinct', 'regex:'.TailscaleProvider::TAG_PATTERN],
        ], ['tags.*.regex' => __('Each tag starts with tag: and has only letters, numbers and dashes, like tag:serveros.')]);
        $tags = array_values(array_unique(array_map('trim', $data['tags'])));
        if (($integration->meta['oauth'] ?? false) && $tags === []) {
            throw ValidationException::withMessages(['tags' => __('Keys made by an OAuth client need at least one tag.')]);
        }

        if ($tags === TailscaleProvider::tags($integration)) {
            AuditContext::nothingChanged();

            return response()->json(['tags' => $tags]);
        }
        $integration->forceFill(['settings' => [...($integration->settings ?? []), 'tags' => $tags]])->save();
        Activity::record($workspace, 'integration.tailscale_tags', $integration->label, null, $request->user(), ['tags' => $tags === [] ? '' : implode(', ', $tags)]);

        return response()->json(['tags' => $tags]);
    }

    private function account(Request $request, Workspace $workspace): Integration
    {
        $accounts = $workspace->integrations()->where('provider', 'tailscale')->orderBy('created_at')->orderBy('id');
        $integration = $request->filled('integration')
            ? (clone $accounts)->whereKey($request->integer('integration'))->first()
            : $accounts->first();
        if ($integration === null) {
            throw ValidationException::withMessages(['integration' => __('Connect a Tailscale account first.')]);
        }

        return $integration;
    }

    private function authorizeFor(Workspace $workspace, Machine $machine): void
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);
    }

    private function authorizeAccount(Workspace $workspace, Integration $integration): void
    {
        abort_unless($integration->workspace_id === $workspace->id && $integration->provider === 'tailscale', 404);
        Gate::authorize('manageMachines', $workspace);
    }
}
