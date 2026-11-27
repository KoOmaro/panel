<?php

namespace App\Support\Integrations\Telegram;

use App\Models\TelegramBot;
use App\Models\Workspace;
use App\Support\Integrations\Chat\ChatFailed;
use App\Support\Monitors\TelegramAlerts;
use Illuminate\Support\Str;

/**
 * Makes the workspace's bot answer: when its token is saved, ServerOS sets
 * the bot's webhook to this panel (setWebhook with a secret_token Telegram
 * sends back on every update) and lists its commands (setMyCommands). When
 * the token goes or changes, the old webhook is taken off.
 */
final class TelegramWebhooks
{
    public const array COMMANDS = [
        ['command' => 'status', 'description' => 'Machines, services and monitors that are down'],
        ['command' => 'machine', 'description' => 'How one machine is doing: /machine web-1'],
        ['command' => 'checknow', 'description' => 'Check a monitor now: /checknow api'],
        ['command' => 'mute', 'description' => 'Mute a monitor: /mute api 1h'],
        ['command' => 'link', 'description' => 'Link your ServerOS account: /link CODE'],
        ['command' => 'help', 'description' => 'What the bot can do'],
    ];

    private const array PRIVATE_TLDS = ['test', 'example', 'invalid', 'localhost', 'local', 'internal', 'lan', 'home', 'arpa'];

    public function __construct(private readonly TelegramApi $api) {}

    /** Whether Telegram could reach this panel at all: a public https address, and not turned off. */
    public static function reachable(): bool
    {
        $url = (string) config('app.url');
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $tld = str_contains($host, '.') ? substr($host, (int) strrpos($host, '.') + 1) : $host;

        return (bool) config('services.telegram.webhooks', true)
            && str_starts_with(strtolower($url), 'https://')
            && $host !== '' && filter_var($host, FILTER_VALIDATE_IP) === false
            && ! in_array($tld, self::PRIVATE_TLDS, true);
    }

    public static function url(TelegramBot $bot): string
    {
        return rtrim((string) config('app.url'), '/').route('telegram.webhook', ['hook' => $bot->hook], false);
    }

    /**
     * Brings the bot in line with the workspace's saved token. Calls Telegram
     * only when something changed or the last try didn't work.
     */
    public function sync(Workspace $workspace, ?string $previousToken = null): ?TelegramBot
    {
        $bot = TelegramBot::query()->where('workspace_id', $workspace->id)->first();
        $token = TelegramAlerts::configured($workspace) ? (string) $workspace->alert_telegram_token : null;
        $hash = $token === null ? null : hash_hmac('sha256', $token, (string) config('app.key'));

        if ($bot !== null && $bot->answers() && $previousToken !== null && $previousToken !== $token) {
            $this->quietly($previousToken, 'deleteWebhook');
        }
        if ($token === null || $hash === null) {
            $bot?->delete();

            return null;
        }
        if ($bot !== null && $bot->token_hash === $hash && $bot->answers()) {
            return $bot;
        }

        $bot ??= new TelegramBot(['workspace_id' => $workspace->id, 'hook' => (string) Str::uuid()]);
        if (! $bot->exists || $bot->token_hash !== $hash) {
            $bot->forceFill(['secret' => Str::random(48), 'token_hash' => $hash, 'username' => null, 'last_update_id' => null]);
        }

        if (! self::reachable()) {
            $bot->forceFill(['state' => TelegramBot::UNREACHABLE, 'error' => 'Telegram can only send buttons and commands to a panel on a public https address.', 'registered_at' => null])->save();

            return $bot;
        }

        try {
            $me = $this->api->call($token, 'getMe');
            $this->api->call($token, 'setWebhook', [
                'url' => self::url($bot),
                'secret_token' => $bot->secret,
                'allowed_updates' => ['message', 'callback_query'],
                'drop_pending_updates' => true,
            ]);
            $this->api->call($token, 'setMyCommands', ['commands' => self::COMMANDS]);
            $bot->forceFill([
                'state' => TelegramBot::ON,
                'error' => null,
                'username' => is_array($me) && is_string($me['username'] ?? null) ? mb_substr($me['username'], 0, 64) : null,
                'registered_at' => now(),
            ])->save();
        } catch (ChatFailed $e) {
            $bot->forceFill(['state' => TelegramBot::FAILED, 'error' => mb_substr($e->getMessage(), 0, 300), 'registered_at' => null])->save();
        }

        return $bot;
    }

    /**
     * The bot's state for the alert settings, never its token or secret.
     *
     * @return array{state: string, username: string|null, error: string|null}|null
     */
    public static function props(Workspace $workspace): ?array
    {
        if (! TelegramAlerts::configured($workspace)) {
            return null;
        }
        $bot = TelegramBot::query()->where('workspace_id', $workspace->id)->first();

        return $bot === null ? null : ['state' => $bot->state, 'username' => $bot->username, 'error' => $bot->error];
    }

    public static function answering(Workspace $workspace): ?TelegramBot
    {
        $bot = TelegramBot::query()->where('workspace_id', $workspace->id)->first();

        return $bot !== null && $bot->answers() && TelegramAlerts::configured($workspace) ? $bot : null;
    }

    private function quietly(string $token, string $method): void
    {
        try {
            $this->api->call($token, $method);
        } catch (ChatFailed) {
        }
    }
}
