<?php

namespace App\Support\Integrations\Telegram;

use App\Models\ChatPost;
use App\Support\Integrations\Chat\ChatAction;
use App\Support\Integrations\Chat\ChatAlert;
use App\Support\Integrations\Chat\ChatFailed;
use App\Support\Integrations\Chat\ThreadedChat;
use App\Support\Monitors\TelegramAlerts;

/**
 * Alerts in the workspace's Telegram chat, as the one line they've always
 * been, with buttons under them once the bot answers (Check now, Restart,
 * Mute 1 hour, then Open). The alert that says it's fixed edits the message.
 */
final class TelegramChat extends ThreadedChat
{
    public function __construct(private readonly TelegramApi $api) {}

    public function chat(): string
    {
        return 'telegram';
    }

    protected function destinations(ChatAlert $alert): array
    {
        if ($alert->isDeployNotice() || ! TelegramAlerts::configured($alert->workspace)) {
            return [];
        }

        return [['destination' => (string) $alert->workspace->alert_telegram_chat, 'integration' => null]];
    }

    protected function post(ChatAlert $alert, ChatPost $post): string
    {
        $token = (string) $alert->workspace->alert_telegram_token;
        $actions = TelegramWebhooks::answering($alert->workspace) !== null ? $alert->actions() : [];
        $keyboard = self::keyboard($alert, $post, $actions);
        $body = [
            'chat_id' => $post->destination,
            'text' => mb_substr($alert->text(), 0, TelegramAlerts::MAX_TEXT),
            'disable_web_page_preview' => true,
        ];

        try {
            $sent = $this->api->call($token, 'sendMessage', [...$body, 'reply_markup' => $keyboard]);
        } catch (ChatFailed $e) {
            // A link Telegram won't take on a button (a panel on a private address) shouldn't stop the alert.
            if ($e->getMessage() !== 'Telegram didn’t accept the link on a button.') {
                throw $e;
            }
            $sent = $this->api->call($token, 'sendMessage', [...$body, 'reply_markup' => self::keyboard($alert, $post, $actions, link: false)]);
        }

        return is_array($sent) ? (string) ($sent['message_id'] ?? '') : '';
    }

    protected function resolve(ChatAlert $recovery, ChatPost $open): bool
    {
        try {
            $this->api->call((string) $recovery->workspace->alert_telegram_token, 'editMessageText', [
                'chat_id' => $open->destination,
                'message_id' => (int) $open->message_id,
                'text' => mb_substr($recovery->text()."\n\nEarlier: {$open->subject}.", 0, TelegramAlerts::MAX_TEXT),
                'disable_web_page_preview' => true,
                'reply_markup' => self::keyboard($recovery, $open, []),
            ]);
        } catch (ChatFailed $e) {
            return $e->getMessage() === 'not modified';
        }

        return true;
    }

    /**
     * @param  list<ChatAction>  $actions
     * @return array{inline_keyboard: list<list<array<string, string>>>}
     */
    private static function keyboard(ChatAlert $alert, ChatPost $post, array $actions, bool $link = true): array
    {
        $rows = [];
        $buttons = array_map(fn (ChatAction $action) => ['text' => $alert->label($action), 'callback_data' => "{$action->value}:{$post->id}"], $actions);
        if ($buttons !== []) {
            $rows[] = $buttons;
        }
        if ($link) {
            $rows[] = [['text' => $alert->actionLabel, 'url' => $alert->url]];
        }

        return ['inline_keyboard' => $rows];
    }
}
