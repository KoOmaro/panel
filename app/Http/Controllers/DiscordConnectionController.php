<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\ChatPost;
use App\Models\Integration;
use App\Models\Workspace;
use App\Support\Audit\AuditContext;
use App\Support\Audit\Audited;
use App\Support\Audit\NotAudited;
use App\Support\Integrations\Chat\ChatAlert;
use App\Support\Integrations\Discord\DiscordApp;
use App\Support\Integrations\Discord\DiscordChat;
use App\Support\Integrations\Discord\DiscordClient;
use App\Support\Integrations\Discord\DiscordProvider;
use App\Support\Integrations\IntegrationFailed;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Connecting a Discord server: the bot invite (OAuth2 with the bot and
 * applications.commands scopes), then the channels alerts and deploys go to.
 */
class DiscordConnectionController extends Controller
{
    private const int STATE_TTL_SECONDS = 1800;

    #[NotAudited('Sends the person to Discord; connecting is recorded when they come back')]
    public function install(Request $request, Workspace $workspace): Response
    {
        Gate::authorize('manageMachines', $workspace);
        abort_unless(DiscordApp::configured(), 404);

        $existing = $workspace->integrations()->where('provider', 'discord')->first();
        if ($existing !== null) {
            return Inertia::location($workspace->url('/integrations/'.$existing->id));
        }

        $state = Crypt::encryptString((string) json_encode([
            'workspace' => $workspace->id,
            'user' => $request->user()->id,
            'at' => now()->getTimestamp(),
            'nonce' => Str::random(16),
        ]));
        $request->session()->put('discord_connect_state', $state);

        return Inertia::location(DiscordApp::installUrl($state));
    }

    public function callback(Request $request, DiscordProvider $provider): RedirectResponse
    {
        abort_unless(DiscordApp::configured(), 404);

        $workspace = $this->workspaceFromState($request);
        if ($workspace === null) {
            return redirect()->route('workspaces.index')->with('status', __('Connect Discord from a workspace’s Integrations page, so ServerOS knows which workspace it’s for.'));
        }
        $back = $workspace->url('/integrations');
        $fail = function (string $message) use ($back): RedirectResponse {
            Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

            return redirect()->away($back);
        };

        $code = (string) $request->query('code');
        if ($request->query('error') !== null || $code === '') {
            return $fail(__('Discord didn’t add the bot, so nothing changed.'));
        }
        if ($workspace->integrations()->where('provider', 'discord')->exists()) {
            return $fail(__('Discord is connected already. Disconnect it first to connect another server.'));
        }

        try {
            $guild = (new DiscordClient)->exchange($code);
        } catch (IntegrationFailed $e) {
            return $fail($e->getMessage());
        }
        if ($guild === null) {
            return $fail(__('Discord didn’t confirm which server the bot was added to. Try again.'));
        }
        $credentials = ['guild_id' => (string) $guild['id']];
        $fingerprint = Integration::fingerprintOf('discord', $credentials);
        if (Integration::query()->where('provider', 'discord')->where('fingerprint', $fingerprint)->exists()) {
            return $fail(__('That Discord server is connected to another workspace already. A server can send to one workspace.'));
        }

        try {
            $account = $provider->verify($credentials);
        } catch (IntegrationFailed $e) {
            return $fail($e->getMessage());
        }

        $integration = $workspace->integrations()->create([
            'provider' => 'discord',
            'label' => mb_substr($account->label, 0, 80),
            'credentials' => $credentials,
            'fingerprint' => $fingerprint,
            'meta' => $account->meta,
            'status' => Integration::CONNECTED,
            'last_checked_at' => now(),
            'created_by' => $request->user()->id,
        ]);
        Activity::record($workspace, 'integration.connect', $integration->label, null, $request->user(), ['provider' => 'Discord']);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Connected :name. Pick the channel alerts go to.', ['name' => $integration->label])]);

        return redirect()->away($workspace->url('/integrations/'.$integration->id));
    }

    #[Audited]
    public function channels(Request $request, Workspace $workspace, Integration $integration): RedirectResponse
    {
        $this->own($workspace, $integration);
        $data = $request->validate([
            'alerts' => ['nullable', 'string', 'regex:/^\d{5,25}$/'],
            'deploys' => ['nullable', 'string', 'regex:/^\d{5,25}$/'],
        ]);

        try {
            $channels = collect((new DiscordClient)->channels($integration->credential('guild_id')))->keyBy('id');
        } catch (IntegrationFailed $e) {
            throw ValidationException::withMessages(['alerts' => $e->getMessage()]);
        }
        $settings = $integration->settings ?? [];
        foreach (['alerts', 'deploys'] as $which) {
            $id = $data[$which] ?? null;
            if ($id !== null && ! $channels->has($id)) {
                throw ValidationException::withMessages([$which => __('That channel isn’t in the server, or the bot can’t see it.')]);
            }
            $settings[$which] = $id;
            $settings[$which.'_name'] = $id === null ? null : (string) $channels->get($id)['name'];
        }

        if ($settings === ($integration->settings ?? [])) {
            AuditContext::nothingChanged();

            return back();
        }
        $integration->forceFill(['settings' => $settings])->save();
        Activity::record($workspace, 'integration.routing', $integration->label, null, $request->user(), ['provider' => 'Discord']);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Saved.')]);

        return back();
    }

    #[NotAudited('Sends a test message; nothing changes')]
    public function test(Workspace $workspace, Integration $integration): JsonResponse
    {
        $this->own($workspace, $integration);
        $channel = $integration->setting('alerts');
        if (! is_string($channel) || $channel === '') {
            return response()->json(['ok' => false, 'error' => __('Pick a channel for alerts first.')]);
        }

        $alert = new ChatAlert($workspace, 'test', "Test from ServerOS: alerts for {$workspace->name} will arrive here.", [], $workspace->url('/integrations/'.$integration->id), false, 'Open ServerOS');
        $post = new ChatPost(['id' => 0]);
        try {
            (new DiscordClient)->postMessage($channel, DiscordChat::message($alert, $post, []));
        } catch (IntegrationFailed $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()]);
        }

        return response()->json(['ok' => true, 'error' => null]);
    }

    private function own(Workspace $workspace, Integration $integration): void
    {
        abort_unless($integration->workspace_id === $workspace->id && $integration->provider === 'discord', 404);
        Gate::authorize('manageMachines', $workspace);
    }

    private function workspaceFromState(Request $request): ?Workspace
    {
        $given = (string) $request->query('state');
        $kept = (string) $request->session()->pull('discord_connect_state');
        if ($given === '' || ! hash_equals($kept, $given)) {
            return null;
        }
        try {
            $state = json_decode(Crypt::decryptString($given), true);
        } catch (DecryptException) {
            return null;
        }
        if (! is_array($state) || ! is_int($state['workspace'] ?? null)
            || ($state['user'] ?? null) !== $request->user()?->id
            || now()->getTimestamp() - (int) ($state['at'] ?? 0) > self::STATE_TTL_SECONDS) {
            return null;
        }
        $workspace = Workspace::query()->find($state['workspace']);

        return $workspace !== null && $request->user()->can('manageMachines', $workspace) ? $workspace : null;
    }
}
