<?php

namespace App\Http\Controllers;

use App\Models\ChatIdentity;
use App\Models\Integration;
use App\Models\Workspace;
use App\Support\Audit\AuditContext;
use App\Support\Audit\Audited;
use App\Support\Integrations\Slack\SlackChat;
use App\Support\Integrations\Slack\SlackPeople;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The page a Slack user's one-time link opens: whoever is signed in links
 * that Slack account to themselves, so its presses and commands run as them.
 */
class SlackLinkController extends Controller
{
    public function show(Request $request, Workspace $workspace, Integration $integration, string $token): Response
    {
        $this->own($workspace, $integration);
        $pending = SlackPeople::pending($token);
        $done = $request->session()->get('slack-linked') === hash('sha256', $token);
        $mine = ChatIdentity::query()->where('workspace_id', $workspace->id)->where('chat', SlackChat::CHAT)->where('user_id', $request->user()->id)->first();

        return Inertia::render('workspace/SlackLink', [
            'workspace' => ['name' => $workspace->name, 'slug' => $workspace->slug],
            'integration' => ['id' => $integration->id, 'label' => $integration->label, 'team' => $integration->meta['team'] ?? $integration->label],
            'token' => $token,
            'state' => match (true) {
                $done => 'done',
                $pending === null || $pending['integration'] !== $integration->id => 'expired',
                default => 'ready',
            },
            'slack' => $pending === null ? ($done && $mine !== null ? ['name' => $mine->external_name] : null) : ['name' => $pending['name']],
            'viewer' => ['name' => $request->user()->name, 'email' => $request->user()->email],
            'replaces' => $pending !== null && $mine !== null && $mine->external_id !== $pending['user'] ? $mine->external_name : null,
        ]);
    }

    #[Audited]
    public function store(Request $request, Workspace $workspace, Integration $integration, string $token): RedirectResponse
    {
        $this->own($workspace, $integration);
        $pending = SlackPeople::pending($token);
        if ($pending === null || $pending['integration'] !== $integration->id || SlackPeople::redeem($token) === null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('That link has run out or was used already. Press the button in Slack again for a new one.')]);
            AuditContext::nothingChanged();

            return redirect()->away($workspace->url('/integrations/'.$integration->id.'/slack/link/'.$token));
        }

        SlackPeople::link($integration, $request->user(), $pending['user'], $pending['name'], 'link');
        $request->session()->put('slack-linked', hash('sha256', $token));

        return redirect()->away($workspace->url('/integrations/'.$integration->id.'/slack/link/'.$token));
    }

    private function own(Workspace $workspace, Integration $integration): void
    {
        abort_unless($integration->workspace_id === $workspace->id && $integration->provider === 'slack', 404);
    }
}
