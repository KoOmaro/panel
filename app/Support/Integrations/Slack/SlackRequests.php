<?php

namespace App\Support\Integrations\Slack;

use App\Http\Controllers\AppVersionController;
use App\Mcp\ToolError;
use App\Mcp\Tools\Deploy\Deploys;
use App\Mcp\Tools\Deploy\RefResolver;
use App\Models\ChatPost;
use App\Models\DaemonJob;
use App\Models\Integration;
use App\Models\Machine;
use App\Models\SlackMessage;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Audit\AuditContext;
use App\Support\DaemonJobQueue;
use App\Support\Deploys\AppVersions;
use App\Support\Integrations\Chat\ChatAction;
use App\Support\Integrations\Chat\ChatCommands;
use Illuminate\Support\Collection;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * What a button press or a /serveros command does, after Slack has had its
 * answer (it wants one within three seconds): it works out which workspace
 * and member it's for, checks they may, does it as them, and says how it
 * went by redrawing the message or answering only them.
 */
final class SlackRequests
{
    private const int PER_MINUTE = 30;

    public function __construct(
        private readonly ChatCommands $commands,
        private readonly AppVersions $versions,
        private readonly DaemonJobQueue $queue,
        private readonly RefResolver $refs,
        private readonly SlackChat $chat,
    ) {}

    /** @param  array<string, mixed>  $payload  a block_actions payload */
    public function interaction(array $payload): void
    {
        $url = (string) ($payload['response_url'] ?? '');
        $team = (string) ($payload['team']['id'] ?? $payload['user']['team_id'] ?? '');
        $slackUser = (string) ($payload['user']['id'] ?? '');
        $name = self::name($payload['user'] ?? null);
        $action = $payload['actions'][0] ?? null;
        if (! is_array($action) || $slackUser === '' || ! str_starts_with((string) ($action['action_id'] ?? ''), SlackBlocks::PREFIX)) {
            return;
        }
        $id = Str::after((string) $action['action_id'], SlackBlocks::PREFIX);
        $value = SlackBlocks::value($action['value'] ?? null);
        if (in_array($id, [SlackBlocks::OPEN, SlackBlocks::LINK], true)) {
            return;
        }
        if (! $this->slowDown($slackUser, $url)) {
            return;
        }

        if ($id === SlackBlocks::CANCEL) {
            SlackClient::respond($url, SlackBlocks::ephemeral('Okay, nothing was deployed.', replace: true));

            return;
        }
        if ($id === SlackBlocks::DEPLOY) {
            $this->deploy($team, $slackUser, $name, $value, $url);

            return;
        }

        $post = $value['post'] === null ? null : ChatPost::query()->find($value['post']);
        $integration = $post !== null && SlackBlocks::isPost($post) ? Integration::query()->find($post->integration_id) : null;
        if ($post === null || ! $integration instanceof Integration || ! self::sameTeam($integration, $team)) {
            SlackClient::respond($url, SlackBlocks::ephemeral('This message isn’t from a ServerOS workspace connected to this Slack any more.'));

            return;
        }
        $user = $this->who($integration, $slackUser, $name, $url);
        if ($user === null) {
            return;
        }
        $workspace = $integration->workspace;
        if (! ChatCommands::allowed($workspace, $user)) {
            SlackClient::respond($url, SlackBlocks::ephemeral(SlackBlocks::escape(ChatCommands::refusal($workspace, $user))));

            return;
        }
        $message = SlackMessage::query()->where('chat_post_id', $post->id)->with('integration')->first();

        if ($id === SlackBlocks::ROLLBACK) {
            $this->rollback($workspace, $user, $message, $value['commit'], $url);

            return;
        }
        $pressed = ChatAction::tryFrom($id);
        if ($pressed === null) {
            return;
        }

        $since = now()->subSecond();
        $reply = $this->commands->press($pressed, $post, $user, SlackChat::CHAT, function (string $result) use ($message, $url): void {
            if ($message === null) {
                SlackClient::respond($url, SlackBlocks::ephemeral(SlackBlocks::escape($result)));

                return;
            }
            $message->refresh();
            $message->note($result);
            $message->save();
            $this->chat->redraw($message);
        });
        if ($message === null) {
            SlackClient::respond($url, SlackBlocks::ephemeral(SlackBlocks::escape($reply)));
        } else {
            $message->note($user->name.': '.$reply);
            if ($pressed === ChatAction::Restart) {
                $message->job_id = DaemonJob::query()->where('machine_id', $post->contextInt('machine'))->where('kind', 'service_action')
                    ->where('user_id', $user->id)->where('created_at', '>=', $since)->latest('created_at')->value('id') ?? $message->job_id;
            }
            $message->save();
            $this->chat->redraw($message);
        }
        // Slack has had its answer already, so what press() left for later (a check's result) runs now.
        app(DeferredCallbackCollection::class)->invoke();
    }

    /**
     * A /serveros command (form fields as Slack sends them).
     *
     * @param  array<string, mixed>  $fields
     */
    public function command(array $fields): void
    {
        $url = (string) ($fields['response_url'] ?? '');
        $slackUser = (string) ($fields['user_id'] ?? '');
        if ($slackUser === '' || ! $this->slowDown($slackUser, $url)) {
            return;
        }
        [$integration, $text, $problem] = $this->account((string) ($fields['team_id'] ?? ''), (string) ($fields['channel_id'] ?? ''), trim((string) ($fields['text'] ?? '')));
        if ($integration === null) {
            SlackClient::respond($url, SlackBlocks::ephemeral(SlackBlocks::escape((string) $problem)));

            return;
        }
        $verb = mb_strtolower((string) Str::before($text.' ', ' '));
        $rest = trim(Str::after($text, ' '));
        $rest = $rest === $text ? '' : $rest;
        if (in_array($verb, ['', 'help'], true)) {
            SlackClient::respond($url, self::help());

            return;
        }
        $user = $this->who($integration, $slackUser, is_string($fields['user_name'] ?? null) ? $fields['user_name'] : null, $url);
        if ($user === null) {
            return;
        }
        $workspace = $integration->workspace;

        match ($verb) {
            'status' => SlackClient::respond($url, SlackBlocks::ephemeral(SlackBlocks::escape($this->commands->status($workspace)))),
            'machine' => SlackClient::respond($url, SlackBlocks::ephemeral(SlackBlocks::escape($this->commands->machine($workspace, $rest)))),
            'deploy' => $this->offerDeploy($integration, $user, $rest, $url),
            default => SlackClient::respond($url, SlackBlocks::ephemeral(SlackBlocks::escape("There’s no “{$verb}” command. ").self::helpText())),
        };
    }

    /** @return array<string, mixed> */
    public static function help(): array
    {
        return SlackBlocks::ephemeral(self::helpText());
    }

    public static function helpText(): string
    {
        return implode("\n", [
            '*'.SlackApp::COMMAND.'* answers only you:',
            '• `'.SlackApp::COMMAND.' status`: machines, services and monitors that are down',
            '• `'.SlackApp::COMMAND.' machine <name>`: how one machine is doing',
            '• `'.SlackApp::COMMAND.' deploy <app>`: deploy an app’s latest code once you confirm (add `on <machine>` when it runs on several)',
            'Buttons and commands run as your ServerOS account, and changes need a role that can manage machines. The first time, ServerOS links your Slack account by its email, or sends you a link to sign in and link it.',
        ]);
    }

    private function offerDeploy(Integration $integration, User $user, string $rest, string $url): void
    {
        $workspace = $integration->workspace;
        if (! ChatCommands::allowed($workspace, $user)) {
            SlackClient::respond($url, SlackBlocks::ephemeral(SlackBlocks::escape(ChatCommands::refusal($workspace, $user))));

            return;
        }
        $say = fn (string $text) => SlackClient::respond($url, SlackBlocks::ephemeral(SlackBlocks::escape($text)));
        if (preg_match('/^(\S+)(?:\s+on\s+(\S+))?$/i', $rest, $m) !== 1) {
            $say('Say which app: '.SlackApp::COMMAND.' deploy <app>, or '.SlackApp::COMMAND.' deploy <app> on <machine>.');

            return;
        }
        $app = $m[1];
        $on = isset($m[2]) ? mb_strtolower($m[2]) : null;
        $found = $this->appsCalled($workspace, $app)->filter(fn (array $f) => $on === null || mb_strtolower($f['machine']->name) === $on)->values();
        if ($found->isEmpty()) {
            $say($on === null ? "No app called {$app} was deployed from code in {$workspace->name}." : "No app called {$app} was deployed from code on {$m[2]}.");

            return;
        }
        if ($found->count() > 1) {
            $say("{$app} runs on ".$found->map(fn (array $f) => $f['machine']->name)->implode(', ').'. Say which: '.SlackApp::COMMAND." deploy {$app} on ".$found->first()['machine']->name.'.');

            return;
        }
        ['machine' => $machine, 'last' => $last] = $found->first();
        $repo = (string) ($last->command['job']['repo'] ?? '');
        if (Deploys::fromUpload($repo)) {
            $say("{$app}’s code was uploaded as a .zip, so there’s nothing to fetch a new version from. Upload the new version on its page in ServerOS.");

            return;
        }
        $branch = $machine->app_settings[$app]['push_branch'] ?? null;
        try {
            $resolved = $this->refs->resolve($repo, is_string($branch) && $branch !== '' ? $branch : null, $workspace);
        } catch (ToolError $e) {
            $say($e->getMessage());

            return;
        }
        $live = strtolower((string) ($last->command['job']['commit'] ?? ''));
        $where = $resolved['ref'] !== null ? " (the latest on {$resolved['ref']})" : '';
        if ($resolved['commit'] === $live) {
            $say("{$app} on {$machine->name} is already running ".substr($live, 0, 7)."{$where}.");

            return;
        }

        SlackClient::respond($url, SlackBlocks::ephemeral(
            '*'.SlackBlocks::escape("Deploy {$app} on {$machine->name}?").'*'."\n".'`'.substr($live, 0, 7).'` → `'.substr($resolved['commit'], 0, 7).'`'.SlackBlocks::escape($where).'. The new version starts beside the live one and takes over once it’s healthy.',
            [
                SlackBlocks::button('Deploy '.substr($resolved['commit'], 0, 7), SlackBlocks::DEPLOY, ['i' => $integration->id, 'm' => $machine->id, 'app' => $app, 'commit' => $resolved['commit']], 'primary'),
                SlackBlocks::button('Cancel', SlackBlocks::CANCEL, ['i' => $integration->id]),
            ],
        ));
    }

    /** @param  array{post: int|null, commit: string|null, i: int|null, m: int|null, app: string|null}  $value */
    private function deploy(string $team, string $slackUser, ?string $name, array $value, string $url): void
    {
        $integration = $value['i'] === null ? null : Integration::query()->where('provider', 'slack')->find($value['i']);
        if (! $integration instanceof Integration || ! self::sameTeam($integration, $team) || $value['m'] === null || $value['app'] === null || $value['commit'] === null) {
            SlackClient::respond($url, SlackBlocks::ephemeral('This isn’t from a ServerOS workspace connected to this Slack any more.', replace: true));

            return;
        }
        $user = $this->who($integration, $slackUser, $name, $url);
        if ($user === null) {
            return;
        }
        $workspace = $integration->workspace;
        $say = fn (string $text) => SlackClient::respond($url, SlackBlocks::ephemeral(SlackBlocks::escape($text), replace: true));
        if (! ChatCommands::allowed($workspace, $user)) {
            $say(ChatCommands::refusal($workspace, $user));

            return;
        }
        $machine = $workspace->machines()->find($value['m']);
        $last = $machine instanceof Machine ? AppVersionController::lastDeployOf($machine, $value['app']) : null;
        if (! $machine instanceof Machine || $last === null) {
            $say("{$value['app']} isn’t on that machine any more.");

            return;
        }
        if (($reason = $this->queue->unavailable($machine, 'deploy')) !== null) {
            $say($reason);

            return;
        }
        try {
            $job = AuditContext::within(['via' => SlackChat::CHAT, 'client' => 'Slack'], fn () => $this->queue->deploy($machine, $user, [...$last->command['job'], 'commit' => $value['commit']]));
        } catch (RuntimeException $e) {
            $say($e->getMessage());

            return;
        }

        SlackClient::respond($url, SlackBlocks::ephemeral(
            SlackBlocks::escape("{$user->name} is deploying {$value['app']} on {$machine->name}: ").'`'.substr($value['commit'], 0, 7).'`.'.(SlackProvider::channel($integration, 'deploys') !== null ? ' The deploys channel hears when it’s done.' : ''),
            [SlackBlocks::link('Open the deploy', $workspace->url("/machines/{$machine->id}?job={$job->id}"))],
            replace: true,
        ));
    }

    private function rollback(Workspace $workspace, User $user, ?SlackMessage $message, ?string $commit, string $url): void
    {
        $machine = $message === null ? null : $workspace->machines()->find((int) $message->input('machine'));
        $app = $message?->input('app');
        $before = $message?->payload['previous']['commit'] ?? null;
        if ($message === null || ! $machine instanceof Machine || $app === null || $commit === null || $before !== $commit) {
            SlackClient::respond($url, SlackBlocks::ephemeral('That version isn’t one this message can roll back to.'));

            return;
        }
        try {
            $job = AuditContext::within(['via' => SlackChat::CHAT, 'client' => 'Slack'], fn () => $this->versions->rollback($machine, $user, $app, $commit, false));
        } catch (RuntimeException $e) {
            SlackClient::respond($url, SlackBlocks::ephemeral(SlackBlocks::escape('Can’t roll back: '.$e->getMessage())));

            return;
        }
        $message->note("{$user->name} is rolling back to ".substr($commit, 0, 7).'.');
        $message->job_id = $job->id;
        $message->save();
        $this->chat->redraw($message);
    }

    /** The member the Slack user acts as, or null after telling them how to link. */
    private function who(Integration $integration, string $slackUser, ?string $name, string $url): ?User
    {
        $user = SlackPeople::member($integration, $slackUser, $name);
        if ($user !== null) {
            return $user;
        }
        SlackClient::respond($url, SlackBlocks::ephemeral(
            SlackBlocks::escape("Link your Slack account to your ServerOS account in {$integration->workspace->name} first, so what you do here runs as you. The link works once, for ".SlackPeople::LINK_MINUTES.' minutes.'),
            [SlackBlocks::link('Link my account', SlackPeople::linkUrl($integration, $slackUser, $name), SlackBlocks::LINK)],
        ));

        return null;
    }

    /**
     * The connected account a command is for. Several ServerOS workspaces can
     * share a Slack: the command can start with the workspace, or it's the
     * one whose channel it was typed in.
     *
     * @return array{0: Integration|null, 1: string, 2: string|null}
     */
    private function account(string $team, string $channel, string $text): array
    {
        $accounts = Integration::query()->where('provider', 'slack')->where('status', Integration::CONNECTED)
            ->where('meta->team_id', $team)->with('workspace')->orderBy('id')->get();
        if ($team === '' || $accounts->isEmpty()) {
            return [null, $text, 'This Slack isn’t connected to a ServerOS workspace.'];
        }
        $first = mb_strtolower((string) Str::before($text.' ', ' '));
        $named = $accounts->first(fn (Integration $i) => $i->workspace->slug === $first);
        if ($named instanceof Integration) {
            return [$named, trim(Str::after($text, ' ')) === $text ? '' : trim(Str::after($text, ' ')), null];
        }
        if ($accounts->count() === 1) {
            return [$accounts->first(), $text, null];
        }
        $here = $accounts->filter(fn (Integration $i) => in_array($channel, array_filter([SlackProvider::channel($i, 'alerts')['id'] ?? null, SlackProvider::channel($i, 'deploys')['id'] ?? null]), true));
        if ($here->count() === 1) {
            return [$here->first(), $text, null];
        }
        $slugs = $accounts->map(fn (Integration $i) => $i->workspace->slug)->unique()->values();

        return [null, $text, 'Several ServerOS workspaces use this Slack: '.$slugs->implode(', ').'. Start with the one you mean, like '.SlackApp::COMMAND.' '.$slugs->first().' status.'];
    }

    /** @return Collection<int, array{machine: Machine, last: DaemonJob}> */
    private function appsCalled(Workspace $workspace, string $app): Collection
    {
        return $workspace->machines()->whereNotNull('uid')->orderBy('name')->get()
            ->map(fn (Machine $machine) => ['machine' => $machine, 'last' => AppVersionController::lastDeployOf($machine, $app)])
            ->filter(fn (array $f) => $f['last'] !== null)
            ->values();
    }

    private static function sameTeam(Integration $integration, string $team): bool
    {
        return $integration->provider === 'slack' && $team !== '' && ($integration->meta['team_id'] ?? null) === $team;
    }

    private static function name(mixed $user): ?string
    {
        if (! is_array($user)) {
            return null;
        }
        $name = $user['name'] ?? $user['username'] ?? null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    private function slowDown(string $slackUser, string $url): bool
    {
        if (RateLimiter::attempt('slack-user:'.$slackUser, self::PER_MINUTE, fn () => true)) {
            return true;
        }
        SlackClient::respond($url, SlackBlocks::ephemeral('That’s a lot in a minute. Try again shortly.'));

        return false;
    }
}
