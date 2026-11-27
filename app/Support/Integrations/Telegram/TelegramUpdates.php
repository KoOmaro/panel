<?php

namespace App\Support\Integrations\Telegram;

use App\Models\ChatPost;
use App\Models\TelegramBot;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Integrations\Chat\ChatAction;
use App\Support\Integrations\Chat\ChatCommands;
use App\Support\Integrations\Chat\ChatFailed;
use App\Support\Integrations\Chat\ChatLinks;
use App\Support\Monitors\TelegramAlerts;
use Illuminate\Support\Facades\Log;

/**
 * What the bot does with an update Telegram sent (already checked against
 * the webhook's secret): commands in a message, and alert buttons pressed
 * (callback_query). Reading works for anyone in the alert chat and for
 * linked members; buttons and commands that change something need a linked
 * member whose role can manage machines.
 */
final class TelegramUpdates
{
    public const string CHAT = 'telegram';

    public function __construct(private readonly TelegramApi $api, private readonly ChatCommands $commands) {}

    /** @param  array<string, mixed>  $update */
    public function handle(TelegramBot $bot, array $update): void
    {
        $workspace = $bot->workspace;
        if (! TelegramAlerts::configured($workspace)) {
            return;
        }

        if (is_array($update['callback_query'] ?? null)) {
            $this->button($bot, $workspace, $update['callback_query']);
        } elseif (is_array($update['message'] ?? null)) {
            $this->message($bot, $workspace, $update['message']);
        }
    }

    /** @param  array<string, mixed>  $message */
    private function message(TelegramBot $bot, Workspace $workspace, array $message): void
    {
        $text = trim((string) ($message['text'] ?? ''));
        if (preg_match('/^\/([a-z_]{1,32})(?:@([A-Za-z0-9_]{1,64}))?(?:\s+(.*))?$/s', $text, $m) !== 1) {
            return;
        }
        $command = strtolower($m[1]);
        $to = $m[2] ?? '';
        $args = trim($m[3] ?? '');
        if ($to !== '' && $bot->username !== null && strcasecmp($to, $bot->username) !== 0) {
            return;
        }

        $chat = (array) ($message['chat'] ?? []);
        $chatId = (string) ($chat['id'] ?? '');
        $from = (array) ($message['from'] ?? []);
        $fromId = (string) ($from['id'] ?? '');
        if ($chatId === '' || $fromId === '') {
            return;
        }
        $private = ($chat['type'] ?? null) === 'private';
        $alertChat = self::isAlertChat($workspace, $chat);
        $user = ChatLinks::member($workspace, self::CHAT, $fromId);
        $sees = $alertChat || $user !== null;
        $replyTo = is_int($message['message_id'] ?? null) ? $message['message_id'] : null;
        $send = fn (string $reply) => $this->send($workspace, $chatId, $reply, $replyTo);

        $reply = match ($command) {
            'start' => $args !== '' ? $this->link($workspace, $args, $from) : ($private ? ChatCommands::help() : null),
            'link' => $this->link($workspace, $args, $from),
            'help' => ChatCommands::help(),
            'status' => $sees ? $this->commands->status($workspace) : self::stranger(),
            'machine' => $sees ? $this->commands->machine($workspace, $args) : self::stranger(),
            'checknow' => $sees ? $this->checkNow($workspace, $user, $args, $send) : self::stranger(),
            'mute' => $sees ? $this->mute($workspace, $user, $args) : self::stranger(),
            default => $private ? ChatCommands::help() : null,
        };

        if ($reply !== null) {
            $send($reply);
        }
    }

    /** @param  array<string, mixed>  $query */
    private function button(TelegramBot $bot, Workspace $workspace, array $query): void
    {
        $id = (string) ($query['id'] ?? '');
        $fromId = (string) ($query['from']['id'] ?? '');
        if ($id === '' || $fromId === '') {
            return;
        }
        $post = null;
        $action = null;
        if (preg_match('/^(check|restart|mute):(\d{1,18})$/', (string) ($query['data'] ?? ''), $m) === 1) {
            $action = ChatAction::from($m[1]);
            $post = ChatPost::query()->whereKey((int) $m[2])->where('workspace_id', $workspace->id)->where('chat', self::CHAT)->first();
        }
        if ($post === null || $action === null) {
            $this->answer($workspace, $id, 'That button doesn’t work any more. Open ServerOS instead.', true);

            return;
        }

        $user = ChatLinks::member($workspace, self::CHAT, $fromId);
        if ($user === null || ! ChatCommands::allowed($workspace, $user)) {
            $this->answer($workspace, $id, ChatCommands::refusal($workspace, $user), true);

            return;
        }

        $replyTo = is_int($query['message']['message_id'] ?? null) ? $query['message']['message_id'] : null;
        $chatId = (string) ($query['message']['chat']['id'] ?? $post->destination);
        $name = $user->name;
        $reply = $this->commands->press($action, $post, $user, self::CHAT,
            fn (string $result) => $this->send($workspace, $chatId, "{$name}: {$result}", $replyTo));
        $this->answer($workspace, $id, $reply, false);
        $this->send($workspace, $chatId, "{$name}: {$reply}", $replyTo);
    }

    /** @param  array<string, mixed>  $from */
    private function link(Workspace $workspace, string $code, array $from): string
    {
        $name = trim(((string) ($from['first_name'] ?? '')).' '.((string) ($from['last_name'] ?? '')));
        $handle = is_string($from['username'] ?? null) ? '@'.$from['username'] : null;
        $user = $code === '' ? null : ChatLinks::redeem(self::CHAT, $workspace, $code, (string) ($from['id'] ?? ''), $handle ?? ($name !== '' ? $name : null));

        return $user === null
            ? 'That code didn’t work. Make a new one in your ServerOS profile, under Chat accounts. Codes work once, for '.ChatLinks::MINUTES.' minutes.'
            : "Linked to {$user->name} in {$workspace->name}. The buttons on alerts now work for you, as far as your role allows.";
    }

    /** @param  callable(string): void  $send */
    private function checkNow(Workspace $workspace, ?User $user, string $args, callable $send): string
    {
        $monitor = $this->commands->findMonitor($workspace, $args);
        if (is_string($monitor)) {
            return $monitor;
        }

        return $this->commands->checkNow($workspace, $user, $monitor, fn (string $result) => $send($result));
    }

    private function mute(Workspace $workspace, ?User $user, string $args): string
    {
        $words = preg_split('/\s+/', $args) ?: [];
        $last = count($words) > 1 ? (string) end($words) : '';
        $minutes = $last !== '' ? ChatCommands::minutes($last) : null;
        $name = $minutes !== null ? trim(implode(' ', array_slice($words, 0, -1))) : $args;
        $monitor = $this->commands->findMonitor($workspace, $name);

        return is_string($monitor) ? $monitor : $this->commands->mute($workspace, $user, $monitor, $minutes ?? 60, self::CHAT);
    }

    /** @param  array<string, mixed>  $chat */
    public static function isAlertChat(Workspace $workspace, array $chat): bool
    {
        $saved = (string) $workspace->alert_telegram_chat;
        if ($saved === '') {
            return false;
        }

        return str_starts_with($saved, '@')
            ? is_string($chat['username'] ?? null) && strcasecmp('@'.$chat['username'], $saved) === 0
            : (string) ($chat['id'] ?? '') === $saved;
    }

    private static function stranger(): string
    {
        return 'This chat doesn’t get alerts from ServerOS. Ask in the alerts chat, or link your account with /link and the code from your ServerOS profile.';
    }

    private function send(Workspace $workspace, string $chatId, string $text, ?int $replyTo = null): void
    {
        try {
            $this->api->call((string) $workspace->alert_telegram_token, 'sendMessage', array_filter([
                'chat_id' => $chatId,
                'text' => mb_substr($text, 0, TelegramAlerts::MAX_TEXT),
                'disable_web_page_preview' => true,
                'reply_parameters' => $replyTo === null ? null : ['message_id' => $replyTo, 'allow_sending_without_reply' => true],
            ], fn ($v) => $v !== null));
        } catch (ChatFailed $e) {
            Log::warning('Telegram reply failed', ['workspace' => $workspace->id, 'error' => $e->getMessage()]);
        }
    }

    private function answer(Workspace $workspace, string $id, string $text, bool $alert): void
    {
        try {
            $this->api->call((string) $workspace->alert_telegram_token, 'answerCallbackQuery', [
                'callback_query_id' => $id,
                'text' => mb_strimwidth($text, 0, 200, '…'),
                'show_alert' => $alert,
            ]);
        } catch (ChatFailed $e) {
            Log::warning('Telegram reply failed', ['workspace' => $workspace->id, 'error' => $e->getMessage()]);
        }
    }
}
