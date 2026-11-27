<?php

namespace App\Support\Integrations\Slack;

use App\Models\ChatIdentity;
use App\Models\ChatPost;
use App\Models\Integration;
use App\Support\Integrations\Capability;
use App\Support\Integrations\ConnectedAccount;
use App\Support\Integrations\ConnectsWithOAuth;
use App\Support\Integrations\HasAccountPage;
use App\Support\Integrations\IntegrationFailed;
use App\Support\Integrations\PostsToChannels;
use App\Support\Integrations\Provider;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * A Slack workspace connected through this install's Slack app (OAuth v2).
 * It keeps the bot token, and which channel gets alerts and which deploys.
 */
final class SlackProvider extends Provider implements ConnectsWithOAuth, HasAccountPage, PostsToChannels
{
    public const array PURPOSES = ['alerts', 'deploys'];

    private const int CHANNELS_MINUTES = 2;

    private const int MAX_PAGES = 5;

    public function key(): string
    {
        return 'slack';
    }

    public function name(): string
    {
        return 'Slack';
    }

    public function fields(): array
    {
        return [];
    }

    public function capabilities(): array
    {
        return [Capability::ChannelAlerts, Capability::AlertButtons, Capability::DeployNotices, Capability::SlashCommand];
    }

    public function available(): bool
    {
        return SlackApp::configured();
    }

    public function unavailableNote(): string
    {
        return self::t('Buttons on alerts and /serveros need the ServerOS Slack app, which whoever runs this install sets up. Until then, alerts can go to a Slack webhook.');
    }

    public function connectPath(): string
    {
        return '/integrations/oauth/slack';
    }

    public function authorizeUrl(string $state): string
    {
        return SlackApp::authorizeUrl($state);
    }

    public function exchange(Request $request): ConnectedAccount
    {
        $code = $request->string('code')->trim()->toString();
        if ($code === '' || strlen($code) > 500) {
            throw new IntegrationFailed(self::t('Slack didn’t send a sign-in code. Start connecting again.'), IntegrationFailed::CREDENTIALS);
        }
        $access = (new SlackClient)->exchange($code, SlackApp::redirectUrl());
        if (($access['is_enterprise_install'] ?? false) === true || ! is_string($access['team']['id'] ?? null)) {
            throw new IntegrationFailed(self::t('Add the app to one Slack workspace, not a whole Enterprise Grid organization.'), IntegrationFailed::INVALID);
        }
        $token = (string) ($access['access_token'] ?? '');
        if (($access['token_type'] ?? 'bot') !== 'bot' || $token === '') {
            throw new IntegrationFailed(self::t('Slack didn’t give ServerOS a bot token. Start connecting again.'), IntegrationFailed::CREDENTIALS);
        }
        $account = $this->verify(['bot_token' => $token]);

        return new ConnectedAccount($account->label, [
            ...$account->meta,
            'app_id' => is_string($access['app_id'] ?? null) ? $access['app_id'] : null,
            'scopes' => is_string($access['scope'] ?? null) ? mb_substr($access['scope'], 0, 500) : null,
        ], ['bot_token' => $token]);
    }

    public function accountId(ConnectedAccount $account): string
    {
        return (string) ($account->meta['team_id'] ?? '');
    }

    public function verify(array $credentials): ConnectedAccount
    {
        $auth = (new SlackClient($credentials['bot_token'] ?? ''))->read('auth.test');
        $team = (string) ($auth['team'] ?? 'Slack');

        return new ConnectedAccount($team, [
            'team_id' => is_string($auth['team_id'] ?? null) ? $auth['team_id'] : null,
            'team' => $team,
            'team_url' => is_string($auth['url'] ?? null) && str_starts_with($auth['url'], 'https://') ? $auth['url'] : null,
            'bot_user_id' => is_string($auth['user_id'] ?? null) ? $auth['user_id'] : null,
            'bot' => is_string($auth['user'] ?? null) ? $auth['user'] : null,
        ]);
    }

    /** @return array{id: string, name: string}|null the channel picked for alerts or deploys */
    public static function channel(Integration $integration, string $purpose): ?array
    {
        $value = $integration->setting($purpose);

        return is_array($value) && is_string($value['id'] ?? null) && $value['id'] !== '' ? ['id' => $value['id'], 'name' => (string) ($value['name'] ?? $value['id'])] : null;
    }

    public static function emailLinking(Integration $integration): bool
    {
        $scopes = explode(',', (string) ($integration->meta['scopes'] ?? ''));

        return in_array('users:read.email', $scopes, true);
    }

    public function summary(Integration $integration): string
    {
        $alerts = self::channel($integration, 'alerts');
        $deploys = self::channel($integration, 'deploys');

        return match (true) {
            $alerts !== null && $deploys !== null => self::t('Alerts to #:alerts, deploys to #:deploys', ['alerts' => $alerts['name'], 'deploys' => $deploys['name']]),
            $alerts !== null => self::t('Alerts to #:channel', ['channel' => $alerts['name']]),
            $deploys !== null => self::t('Deploys to #:channel', ['channel' => $deploys['name']]),
            default => self::t('Pick channels for alerts and deploys'),
        };
    }

    public function facts(Integration $integration): array
    {
        return array_values(array_filter([
            isset($integration->meta['team']) ? ['label' => self::t('Slack workspace'), 'value' => (string) $integration->meta['team']] : null,
            ['label' => self::t('Alerts'), 'value' => ($c = self::channel($integration, 'alerts')) !== null ? '#'.$c['name'] : '–'],
            ['label' => self::t('Deploys'), 'value' => ($c = self::channel($integration, 'deploys')) !== null ? '#'.$c['name'] : '–'],
            ['label' => self::t('People linked'), 'value' => (string) $this->people($integration)->count()],
        ]));
    }

    public function usage(Integration $integration): array
    {
        $alerts = self::channel($integration, 'alerts');
        $deploys = self::channel($integration, 'deploys');

        return array_values(array_filter([
            $alerts !== null
                ? self::t('Alerts go to #:channel, with buttons to check a monitor now, restart the service behind it and mute it for an hour. A fixed monitor or machine changes its alert to fixed.', ['channel' => $alerts['name']])
                : self::t('No channel gets alerts yet.'),
            $deploys !== null ? self::t('Each deploy is posted to #:channel, with a button to roll back to the version before.', ['channel' => $deploys['name']]) : null,
            self::t('/serveros works in any channel of this Slack, for people linked to their ServerOS account.'),
        ]));
    }

    public function dependents(Integration $integration): array
    {
        return array_values(array_filter([
            ($c = self::channel($integration, 'alerts')) !== null ? self::t('Alerts stop going to #:channel.', ['channel' => $c['name']]) : null,
            ($c = self::channel($integration, 'deploys')) !== null ? self::t('Deploys stop going to #:channel.', ['channel' => $c['name']]) : null,
        ]));
    }

    public function disconnect(Integration $integration): string
    {
        return self::t('Disconnected :name. The ServerOS app stays in your Slack until you remove it there.', ['name' => $integration->label]);
    }

    /**
     * The channels the app can see: public ones, and private ones it was added to.
     *
     * @return list<array{id: string, name: string, private: bool, member: bool}>
     *
     * @throws IntegrationFailed
     */
    public function channels(Integration $integration, bool $fresh = false): array
    {
        $key = "integrations:{$integration->id}:slack-channels";
        $cached = Cache::get($key);
        if (! $fresh && is_array($cached)) {
            $kept = [];
            foreach ($cached as $c) {
                if (is_array($c) && is_string($c['id'] ?? null) && is_string($c['name'] ?? null)) {
                    $kept[] = ['id' => $c['id'], 'name' => $c['name'], 'private' => (bool) ($c['private'] ?? false), 'member' => (bool) ($c['member'] ?? false)];
                }
            }

            return $kept;
        }
        $client = SlackChat::client($integration);
        $channels = [];
        $cursor = null;
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $list = $client->read('conversations.list', array_filter([
                'types' => 'public_channel,private_channel',
                'exclude_archived' => 'true',
                'limit' => 200,
                'cursor' => $cursor,
            ]));
            foreach ((array) ($list['channels'] ?? []) as $c) {
                if (is_array($c) && is_string($c['id'] ?? null) && is_string($c['name'] ?? null)) {
                    $channels[] = ['id' => $c['id'], 'name' => $c['name'], 'private' => (bool) ($c['is_private'] ?? false), 'member' => (bool) ($c['is_member'] ?? false)];
                }
            }
            $cursor = $list['response_metadata']['next_cursor'] ?? null;
            if (! is_string($cursor) || $cursor === '') {
                break;
            }
        }
        usort($channels, fn (array $a, array $b) => strcmp($a['name'], $b['name']));
        Cache::put($key, $channels, now()->addMinutes(self::CHANNELS_MINUTES));

        return $channels;
    }

    public function settingsRules(): array
    {
        return [
            'alerts' => ['nullable', 'string', 'max:40', 'regex:/^[CG][A-Z0-9]{2,39}$/'],
            'deploys' => ['nullable', 'string', 'max:40', 'regex:/^[CG][A-Z0-9]{2,39}$/'],
        ];
    }

    public function settings(Integration $integration, array $data): array
    {
        $wanted = array_filter([$data['alerts'] ?? null, $data['deploys'] ?? null], fn ($v) => is_string($v) && $v !== '');
        $channels = collect($wanted === [] ? [] : $this->channels($integration, fresh: true))->keyBy('id');
        $out = [];
        foreach (self::PURPOSES as $purpose) {
            $id = $data[$purpose] ?? null;
            if (! is_string($id) || $id === '') {
                $out[$purpose] = null;

                continue;
            }
            $channel = $channels->get($id);
            if ($channel === null) {
                throw new IntegrationFailed(self::t('The app can’t see that channel. Private channels show once the app is in them: /invite @ServerOS there.'), IntegrationFailed::NOT_FOUND, null, $purpose);
            }
            $out[$purpose] = ['id' => $channel['id'], 'name' => $channel['name']];
        }

        return [...($integration->settings ?? []), ...$out];
    }

    public function sendTest(Integration $integration): string
    {
        $channel = self::channel($integration, 'alerts') ?? self::channel($integration, 'deploys')
            ?? throw new IntegrationFailed(self::t('Pick a channel for alerts or deploys first.'), IntegrationFailed::INVALID, null, 'alerts');
        SlackChat::client($integration)->call('chat.postMessage', [
            'channel' => $channel['id'],
            'text' => 'Test from ServerOS: alerts and deploys for '.$integration->workspace->name.' will arrive here.',
            'blocks' => [SlackBlocks::section(':wave: '.SlackBlocks::escape('Test from ServerOS: alerts and deploys for '.$integration->workspace->name.' will arrive here, and '.SlackApp::COMMAND.' works in any channel.'))],
        ]);

        return self::t('Posted a test message in #:channel.', ['channel' => $channel['name']]);
    }

    public function accountPage(Integration $integration): array
    {
        $error = null;
        try {
            $channels = $this->channels($integration);
        } catch (IntegrationFailed $e) {
            $channels = [];
            $error = $e->getMessage();
        }
        $teamUrl = is_string($integration->meta['team_url'] ?? null) ? $integration->meta['team_url'] : null;

        return [
            'kind' => 'slack',
            'team' => $integration->meta['team'] ?? $integration->label,
            'teamUrl' => $teamUrl,
            'channels' => $channels,
            'channelsError' => $error,
            'alerts' => self::channel($integration, 'alerts'),
            'deploys' => self::channel($integration, 'deploys'),
            'command' => SlackApp::COMMAND,
            'emailLinking' => self::emailLinking($integration),
            'people' => array_values($this->people($integration)->map(fn (ChatIdentity $identity) => [
                'id' => $identity->id,
                'slack' => $identity->external_name,
                'member' => $identity->user->name,
                'avatar' => $identity->user->avatar,
                'since' => $identity->created_at?->toIso8601String(),
            ])->all()),
            'posts' => self::posts($integration, fn (ChatPost $post) => SlackBlocks::permalink($teamUrl, $post->destination, (string) $post->message_id)),
        ];
    }

    /** @return Collection<int, ChatIdentity> */
    private function people(Integration $integration): Collection
    {
        return ChatIdentity::query()->where('workspace_id', $integration->workspace_id)->where('chat', SlackChat::CHAT)
            ->with('user')->orderBy('external_name')->get()
            ->filter(fn (ChatIdentity $identity) => $integration->workspace->hasMember($identity->user))
            ->values();
    }

    /**
     * The last things posted through the account, newest first.
     *
     * @param  callable(ChatPost): ?string  $link
     * @return list<array<string, mixed>>
     */
    public static function posts(Integration $integration, callable $link): array
    {
        return array_values(ChatPost::query()->where('integration_id', $integration->id)->latest('id')->limit(15)->get()
            ->map(fn (ChatPost $post) => [
                'id' => $post->id,
                'subject' => $post->subject,
                'event' => $post->event,
                'state' => $post->state,
                'error' => $post->error,
                'channel' => $post->destination,
                'at' => $post->created_at?->toIso8601String(),
                'url' => $post->message_id === null || $post->message_id === '' ? null : $link($post),
            ])->all());
    }
}
