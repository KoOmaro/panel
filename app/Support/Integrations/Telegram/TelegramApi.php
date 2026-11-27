<?php

namespace App\Support\Integrations\Telegram;

use App\Support\Integrations\Chat\ChatFailed;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The Telegram Bot API (https://core.telegram.org/bots/api), with the
 * workspace's own bot token. The token is in the address, so nothing that
 * holds the address (an exception's message) is ever passed on.
 */
final class TelegramApi
{
    public const string BASE = 'https://api.telegram.org/bot';

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>|bool|null Telegram's result
     *
     * @throws ChatFailed
     */
    public function call(string $token, string $method, array $body = []): array|bool|null
    {
        try {
            $response = Http::timeout(8)
                ->withOptions(['allow_redirects' => false])
                ->asJson()
                ->post(self::BASE.$token.'/'.$method, $body === [] ? (object) [] : $body);
        } catch (Throwable) {
            // The exception's message can hold the address, and with it the token.
            throw new ChatFailed('Couldn’t reach Telegram.');
        }

        if ($response->successful()) {
            $result = $response->json('result');

            return is_array($result) || is_bool($result) ? $result : null;
        }

        throw new ChatFailed(self::explain($response));
    }

    /** What went wrong, in words a person can act on. */
    public static function explain(Response $response): string
    {
        $said = strtolower((string) $response->json('description'));

        return match (true) {
            in_array($response->status(), [401, 404], true) => 'Telegram didn’t accept the bot token.',
            str_contains($said, 'chat not found') => 'Telegram couldn’t find that chat. Add the bot to the group or channel first, or send the bot a message.',
            str_contains($said, 'message is not modified') => 'not modified',
            str_contains($said, 'message to edit not found') || str_contains($said, 'message can\'t be edited') => 'That message can’t be edited any more.',
            str_contains($said, 'button_url_invalid') || str_contains($said, 'wrong http url') => 'Telegram didn’t accept the link on a button.',
            str_contains($said, 'https url must be provided') || str_contains($said, 'bad webhook') => 'Telegram can’t send updates to this panel’s address. It needs a public https address.',
            $response->status() === 403 => 'The bot can’t post in that chat. Add it to the group or channel, or start a chat with it.',
            $response->status() === 429 => 'Telegram is asking to slow down. Try again in a minute.',
            default => "Telegram answered {$response->status()}.",
        };
    }
}
