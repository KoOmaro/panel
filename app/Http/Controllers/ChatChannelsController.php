<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Integration;
use App\Models\Workspace;
use App\Support\Audit\AuditContext;
use App\Support\Audit\Audited;
use App\Support\Integrations\IntegrationFailed;
use App\Support\Integrations\PostsToChannels;
use App\Support\Integrations\Provider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/** Where a Slack or Teams account posts alerts and deploys, and a test to check it. */
class ChatChannelsController extends Controller
{
    #[Audited]
    public function update(Request $request, Workspace $workspace, Integration $integration): RedirectResponse
    {
        $provider = $this->provider($workspace, $integration);
        $rules = $provider->settingsRules();
        $data = $request->validate($rules);
        try {
            $settings = $provider->settings($integration, $data);
        } catch (IntegrationFailed $e) {
            throw ValidationException::withMessages([$e->field !== null && isset($rules[$e->field]) ? $e->field : (string) array_key_first($rules) => $e->getMessage()]);
        }

        $before = $integration->settings ?? [];
        if ($settings == $before) {
            AuditContext::nothingChanged();

            return back();
        }
        $integration->forceFill(['settings' => $settings])->save();
        Activity::record($workspace, 'integration.routing', $integration->label, null, $request->user(), [
            'provider' => $provider->name(),
            'changed' => array_keys(array_filter($settings, fn ($value, $key) => ($before[$key] ?? null) != $value, ARRAY_FILTER_USE_BOTH)),
        ]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Saved. :provider gets what you picked from now on.', ['provider' => $provider->name()])]);

        return back();
    }

    #[Audited]
    public function test(Request $request, Workspace $workspace, Integration $integration): RedirectResponse
    {
        $provider = $this->provider($workspace, $integration);
        try {
            $said = $provider->sendTest($integration);
            $ok = true;
        } catch (IntegrationFailed $e) {
            $said = $e->getMessage();
            $ok = false;
        }

        Activity::record($workspace, 'integration.test', $integration->label, null, $request->user(), ['provider' => $provider->name(), 'ok' => $ok]);
        Inertia::flash('toast', ['type' => $ok ? 'success' : 'error', 'message' => $said]);

        return back();
    }

    private function provider(Workspace $workspace, Integration $integration): PostsToChannels&Provider
    {
        abort_unless($integration->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);
        $provider = $integration->provider();
        abort_unless($provider instanceof PostsToChannels, 404);

        return $provider;
    }
}
