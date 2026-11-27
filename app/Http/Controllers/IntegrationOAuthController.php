<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Integration;
use App\Models\Workspace;
use App\Support\Integrations\ConnectsWithOAuth;
use App\Support\Integrations\IntegrationFailed;
use App\Support\Integrations\IntegrationProviders;
use App\Support\Integrations\Provider;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Connecting a provider by signing in there: off to the provider with a
 * state that says which workspace and person it's for, and back with a code
 * that becomes the account (or brings an account connected before back).
 */
class IntegrationOAuthController extends Controller
{
    private const int STATE_SECONDS = 1800;

    public function redirect(Request $request, Workspace $workspace, string $provider, IntegrationProviders $providers): Response
    {
        Gate::authorize('manageMachines', $workspace);
        $built = $providers->find($provider);
        abort_unless($built instanceof ConnectsWithOAuth, 404);
        if (! $built->available()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => (string) $built->unavailableNote()]);

            return redirect($workspace->url('/integrations'));
        }

        $state = Crypt::encryptString((string) json_encode([
            'w' => $workspace->id,
            'u' => $request->user()->id,
            'p' => $provider,
            'at' => now()->getTimestamp(),
            'n' => Str::random(16),
        ]));

        return Inertia::location($built->authorizeUrl($state));
    }

    public function callback(Request $request, string $provider, IntegrationProviders $providers): RedirectResponse
    {
        $built = $providers->find($provider);
        abort_unless($built instanceof ConnectsWithOAuth && $built->available(), 404);
        $workspace = $this->workspaceFromState($request, $provider);
        if ($workspace === null) {
            return redirect()->route('workspaces.index')->with('status', __('Connect :provider from a workspace’s Integrations page, so ServerOS knows which workspace it’s for.', ['provider' => $built->name()]));
        }
        $back = $workspace->url('/integrations');

        if ($request->filled('error')) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $request->query('error') === 'access_denied'
                ? __(':provider wasn’t connected: the app wasn’t allowed in.', ['provider' => $built->name()])
                : __(':provider didn’t finish connecting. Try again.', ['provider' => $built->name()])]);

            return redirect()->away($back);
        }

        try {
            $account = $built->exchange($request);
        } catch (IntegrationFailed $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $e->getMessage()]);

            return redirect()->away($back);
        }
        $id = $built->accountId($account);
        abort_if($id === '', 502);
        $fingerprint = Integration::fingerprintOf($provider, ['account' => $id]);
        $user = $request->user();

        $integration = $workspace->integrations()->where('provider', $provider)->where('fingerprint', $fingerprint)->first();
        if ($integration instanceof Integration) {
            $integration->forceFill([
                'credentials' => $account->credentials,
                'meta' => [...($integration->meta ?? []), ...$account->meta],
                'status' => Integration::CONNECTED,
                'last_checked_at' => now(),
                'last_error' => null,
            ])->save();
            Activity::record($workspace, 'integration.reconnect', $integration->label, null, $user, ['provider' => $built->name()]);
            Inertia::flash('toast', ['type' => 'success', 'message' => __('Connected :name again.', ['name' => $integration->label])]);
        } else {
            $integration = $workspace->integrations()->create([
                'provider' => $provider,
                'label' => $this->freeLabel($workspace, $provider, $account->label),
                'credentials' => $account->credentials,
                'fingerprint' => $fingerprint,
                'meta' => $account->meta,
                'status' => Integration::CONNECTED,
                'last_checked_at' => now(),
                'created_by' => $user->id,
            ]);
            Activity::record($workspace, 'integration.connect', $integration->label, null, $user, ['provider' => $built->name()]);
            Inertia::flash('toast', ['type' => 'success', 'message' => __('Connected :name.', ['name' => $integration->label])]);
        }

        return redirect()->away($workspace->url('/integrations/'.$integration->id));
    }

    private function workspaceFromState(Request $request, string $provider): ?Workspace
    {
        try {
            $state = json_decode(Crypt::decryptString((string) $request->query('state')), true);
        } catch (DecryptException) {
            return null;
        }
        if (! is_array($state) || ! is_int($state['w'] ?? null) || ($state['p'] ?? null) !== $provider
            || ($state['u'] ?? null) !== $request->user()?->id
            || now()->getTimestamp() - (int) ($state['at'] ?? 0) > self::STATE_SECONDS) {
            return null;
        }
        $workspace = Workspace::query()->find($state['w']);

        return $workspace instanceof Workspace && $request->user()->can('manageMachines', $workspace) ? $workspace : null;
    }

    private function freeLabel(Workspace $workspace, string $provider, string $label): string
    {
        $label = mb_substr(trim($label) === '' ? $provider : trim($label), 0, 76);
        $taken = $workspace->integrations()->where('provider', $provider)->pluck('label')->all();
        $candidate = $label;
        for ($n = 2; in_array($candidate, $taken, true); $n++) {
            $candidate = "{$label} {$n}";
        }

        return $candidate;
    }
}
