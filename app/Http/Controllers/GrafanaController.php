<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Integration;
use App\Models\McpToken;
use App\Models\Workspace;
use App\Support\Audit\AuditContext;
use App\Support\Audit\Audited;
use App\Support\Integrations\Grafana\GrafanaDashboards;
use App\Support\Integrations\Grafana\GrafanaPush;
use App\Support\Integrations\IntegrationFailed;
use App\Support\Metrics\MetricsTokens;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class GrafanaController extends Controller
{
    #[Audited]
    public function push(Request $request, Workspace $workspace, Integration $integration, GrafanaPush $push): RedirectResponse
    {
        $this->own($workspace, $integration);
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:300'],
            'instance' => ['required', 'string', 'max:100', 'regex:/^\S+$/'],
            'token' => [GrafanaPush::of($integration) === null ? 'required' : 'nullable', 'string', 'max:2000', 'regex:/^\S+$/'],
        ], [], ['endpoint' => __('OTLP address'), 'instance' => __('instance ID'), 'token' => __('token')]);

        try {
            $push->configure($integration, $data['endpoint'], $data['instance'], $data['token'] ?? null);
        } catch (IntegrationFailed $e) {
            throw ValidationException::withMessages([$e->field ?? 'endpoint' => $e->getMessage()]);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Sent the metrics. ServerOS sends them every minute from now on.')]);

        return back();
    }

    #[Audited]
    public function stopPush(Workspace $workspace, Integration $integration, GrafanaPush $push): RedirectResponse
    {
        $this->own($workspace, $integration);
        if (GrafanaPush::of($integration) === null) {
            AuditContext::nothingChanged();

            return back();
        }
        $push->stop($integration);
        Activity::record($workspace, 'integration.grafana_push_stop', $integration->label);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('ServerOS stopped sending metrics to Grafana.')]);

        return back();
    }

    #[Audited]
    public function dashboard(Request $request, Workspace $workspace, Integration $integration, GrafanaDashboards $dashboards): RedirectResponse
    {
        $this->own($workspace, $integration);
        $data = $request->validate([
            'datasource' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9._-]+$/'],
            'name' => ['nullable', 'string', 'max:190'],
        ], [], ['datasource' => __('data source')]);

        try {
            $dashboards->save($integration, ['uid' => $data['datasource'], 'name' => trim((string) ($data['name'] ?? '')) ?: $data['datasource']]);
        } catch (IntegrationFailed $e) {
            throw ValidationException::withMessages(['datasource' => $e->getMessage()]);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => __('The dashboard is ready in Grafana.')]);

        return back();
    }

    #[Audited]
    public function storeToken(Request $request, Workspace $workspace, Integration $integration): JsonResponse
    {
        $this->own($workspace, $integration);
        $data = $request->validate(['name' => ['required', 'string', 'max:80']], [], ['name' => __('name')]);
        $ids = array_values(array_map('intval', (array) $integration->setting('tokens', [])));
        $active = MetricsTokens::active($workspace, $ids);
        if ($active->count() >= MetricsTokens::MAX) {
            return response()->json(['errors' => ['name' => [__('An account can have :count metrics tokens. Revoke one first.', ['count' => MetricsTokens::MAX])]]], 422);
        }

        [$token, $secret] = MetricsTokens::issue($workspace, $request->user(), trim($data['name']));
        $integration->forceFill(['settings' => [...($integration->settings ?? []), 'tokens' => [...array_values(array_intersect($ids, $active->map(fn (McpToken $t) => $t->id)->all())), $token->id]]])->save();
        Activity::record($workspace, 'integration.metrics_token', $token->name, meta: ['account' => $integration->label]);

        return response()->json(['id' => $token->id, 'secret' => $secret, 'prefix' => $token->token_prefix], 201);
    }

    #[Audited]
    public function destroyToken(Workspace $workspace, Integration $integration, McpToken $token): RedirectResponse
    {
        $this->own($workspace, $integration);
        $ids = array_values(array_map('intval', (array) $integration->setting('tokens', [])));
        abort_unless($token->workspace_id === $workspace->id && $token->audience === MetricsTokens::AUDIENCE && in_array($token->id, $ids, true), 404);

        $token->revoke();
        $integration->forceFill(['settings' => [...($integration->settings ?? []), 'tokens' => array_values(array_diff($ids, [$token->id]))]])->save();
        Activity::record($workspace, 'integration.metrics_token_revoke', $token->name, meta: ['account' => $integration->label]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Revoked :name.', ['name' => $token->name])]);

        return back();
    }

    private function own(Workspace $workspace, Integration $integration): void
    {
        abort_unless($integration->workspace_id === $workspace->id && $integration->provider === 'grafana', 404);
        Gate::authorize('manageMachines', $workspace);
    }
}
