<?php

namespace Tests\Support;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * A stand-in for the Telegram Bot API: one bot, its webhook and commands,
 * the messages it sent and edited, every call recorded, and failures on
 * demand.
 */
final class FakeTelegram
{
    public const string TOKEN = '123456789:fake-telegram-token-for-tests-only';

    public const string USERNAME = 'acme_alerts_bot';

    /** @var list<array{method: string, body: array<string, mixed>}> */
    public array $calls = [];

    /** @var array<string, mixed>|null */
    public ?array $webhook = null;

    /** @var list<array<string, mixed>> */
    public array $commands = [];

    /** @var array<int, array<string, mixed>> */
    public array $messages = [];

    /** @var array<string, list<array{status: int, description: string}>> */
    private array $failures = [];

    private int $next = 100;

    public static function install(): self
    {
        $fake = new self;
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/*' => fn (Request $request) => $fake->handle($request)]);

        return $fake;
    }

    public function failNext(string $method, int $status, string $description): self
    {
        $this->failures[$method][] = ['status' => $status, 'description' => $description];

        return $this;
    }

    /** @return list<array<string, mixed>> */
    public function sent(string $method): array
    {
        return array_values(array_map(fn (array $c) => $c['body'], array_filter($this->calls, fn (array $c) => $c['method'] === $method)));
    }

    /** @return list<string> */
    public function methods(): array
    {
        return array_map(fn (array $c) => $c['method'], $this->calls);
    }

    public function handle(Request $request): PromiseInterface
    {
        if (preg_match('#^https://api\.telegram\.org/bot([^/]+)/([A-Za-z]+)$#', $request->url(), $m) !== 1) {
            return Http::response(['ok' => false, 'error_code' => 404, 'description' => 'Not Found'], 404);
        }
        [, $token, $method] = $m;
        $body = (array) $request->data();
        $this->calls[] = ['method' => $method, 'body' => $body];

        if ($token !== self::TOKEN) {
            return Http::response(['ok' => false, 'error_code' => 401, 'description' => 'Unauthorized'], 401);
        }
        if (($this->failures[$method] ?? []) !== []) {
            $failure = array_shift($this->failures[$method]);

            return Http::response(['ok' => false, 'error_code' => $failure['status'], 'description' => $failure['description']], $failure['status']);
        }

        return match ($method) {
            'getMe' => self::ok(['id' => 123456789, 'is_bot' => true, 'first_name' => 'Acme alerts', 'username' => self::USERNAME]),
            'setWebhook' => self::ok(true, fn () => $this->webhook = $body),
            'deleteWebhook' => self::ok(true, fn () => $this->webhook = null),
            'setMyCommands' => self::ok(true, fn () => $this->commands = (array) ($body['commands'] ?? [])),
            'sendMessage' => $this->send($body),
            'editMessageText' => $this->edit($body),
            'answerCallbackQuery' => self::ok(true),
            default => Http::response(['ok' => false, 'error_code' => 404, 'description' => 'Not Found: method not found'], 404),
        };
    }

    /** @param  array<string, mixed>  $body */
    private function send(array $body): PromiseInterface
    {
        $id = $this->next++;
        $this->messages[$id] = ['chat_id' => $body['chat_id'] ?? null, 'text' => $body['text'] ?? '', 'reply_markup' => $body['reply_markup'] ?? null];

        return self::ok(['message_id' => $id, 'chat' => ['id' => $body['chat_id'] ?? null], 'text' => $body['text'] ?? '']);
    }

    /** @param  array<string, mixed>  $body */
    private function edit(array $body): PromiseInterface
    {
        $id = (int) ($body['message_id'] ?? 0);
        if (! isset($this->messages[$id])) {
            return Http::response(['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: message to edit not found'], 400);
        }
        $this->messages[$id] = ['chat_id' => $body['chat_id'] ?? null, 'text' => $body['text'] ?? '', 'reply_markup' => $body['reply_markup'] ?? null];

        return self::ok(['message_id' => $id, 'text' => $body['text'] ?? '']);
    }

    private static function ok(mixed $result, ?callable $then = null): PromiseInterface
    {
        if ($then !== null) {
            $then();
        }

        return Http::response(['ok' => true, 'result' => $result]);
    }
}
