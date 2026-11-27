<?php

namespace Tests\Support;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * A stand-in for Discord's HTTP API and the panel's Discord app: a server
 * with its channels, the app's commands there, the messages the bot posted
 * and edited, the OAuth2 code exchange, every call recorded, and failures on
 * demand. keys() makes the app's Ed25519 key pair, sign() signs a request.
 */
final class FakeDiscord
{
    public const string APP = '1100000000000000001';

    public const string BOT_TOKEN = 'fake-discord-bot-token-for-tests';

    public const string SECRET = 'fake-discord-client-secret-for-tests';

    public const string GUILD = '1200000000000000001';

    public const string CODE = 'fake-oauth-code';

    public const string ALERTS = '1300000000000000001';

    public const string DEPLOYS = '1300000000000000002';

    public string $secretKey;

    public string $publicKey;

    /** @var list<array{method: string, path: string, body: mixed}> */
    public array $calls = [];

    /** @var array<string, list<array<string, mixed>>> by guild */
    public array $commands = [];

    /** @var array<string, array{channel: string, body: array<string, mixed>}> */
    public array $messages = [];

    public bool $inGuild = true;

    /** @var array<string, list<array{status: int, body: array<string, mixed>}>> */
    private array $failures = [];

    private int $next = 1400000000000000001;

    public static function install(): self
    {
        $fake = new self;
        $pair = sodium_crypto_sign_keypair();
        $fake->secretKey = sodium_crypto_sign_secretkey($pair);
        $fake->publicKey = bin2hex(sodium_crypto_sign_publickey($pair));

        config()->set('services.discord', [
            ...(array) config('services.discord'),
            'application_id' => self::APP,
            'public_key' => $fake->publicKey,
            'bot_token' => self::BOT_TOKEN,
            'client_secret' => self::SECRET,
        ]);
        Http::preventStrayRequests();
        Http::fake(['discord.com/api/*' => fn (Request $request) => $fake->handle($request)]);

        return $fake;
    }

    /** @return array<string, string> the headers Discord signs an interaction with */
    public function sign(string $body, ?string $timestamp = null): array
    {
        $timestamp ??= (string) time();

        return [
            'X-Signature-Ed25519' => bin2hex(sodium_crypto_sign_detached($timestamp.$body, $this->secretKey)),
            'X-Signature-Timestamp' => $timestamp,
        ];
    }

    /** @param  array<string, mixed>  $body */
    public function failNext(string $key, int $status, array $body = []): self
    {
        $this->failures[$key][] = ['status' => $status, 'body' => $body];

        return $this;
    }

    /** @return list<array{method: string, path: string, body: mixed}> */
    public function called(string $method, string $pattern): array
    {
        return array_values(array_filter($this->calls, fn (array $c) => $c['method'] === $method && preg_match($pattern, $c['path']) === 1));
    }

    public function handle(Request $request): PromiseInterface
    {
        $path = (string) preg_replace('#^https://discord\.com/api/v10/#', '', $request->url());
        $path = (string) strtok($path, '?');
        $method = $request->method();
        $body = json_decode($request->body(), true) ?? $request->data();
        $this->calls[] = ['method' => $method, 'path' => $path, 'body' => $body];

        if (str_starts_with($path, 'oauth2/')) {
            return $this->oauth($path, $request);
        }
        if (str_starts_with($path, 'webhooks/'.self::APP.'/')) {
            return Http::response(['id' => '1', 'content' => $body['content'] ?? '']);
        }
        if (($request->header('Authorization')[0] ?? null) !== 'Bot '.self::BOT_TOKEN) {
            return Http::response(['message' => '401: Unauthorized', 'code' => 0], 401);
        }
        $key = $method.' '.$path;
        if (($this->failures[$key] ?? []) !== []) {
            $failure = array_shift($this->failures[$key]);

            return Http::response($failure['body'], $failure['status']);
        }

        $guild = preg_quote(self::GUILD, '#');
        if (! $this->inGuild && preg_match("#guilds/{$guild}#", $path) === 1 && $method !== 'PUT') {
            return Http::response(['message' => 'Unknown Guild', 'code' => 10004], 404);
        }

        return match (true) {
            $method === 'GET' && $path === 'guilds/'.self::GUILD => Http::response(['id' => self::GUILD, 'name' => 'Acme ops', 'system_channel_id' => self::ALERTS]),
            $method === 'GET' && $path === 'guilds/'.self::GUILD.'/channels' => Http::response([
                ['id' => '1300000000000000009', 'type' => 4, 'name' => 'Text channels', 'position' => 0],
                ['id' => self::DEPLOYS, 'type' => 0, 'name' => 'deploys', 'position' => 2],
                ['id' => self::ALERTS, 'type' => 0, 'name' => 'alerts', 'position' => 1],
                ['id' => '1300000000000000003', 'type' => 2, 'name' => 'Voice', 'position' => 3],
            ]),
            $method === 'PUT' && $path === 'applications/'.self::APP.'/guilds/'.self::GUILD.'/commands' => $this->registered((array) $body),
            $method === 'DELETE' && $path === 'users/@me/guilds/'.self::GUILD => Http::response(null, 204),
            $method === 'POST' && preg_match('#^channels/(\d+)/messages$#', $path, $m) === 1 => $this->post($m[1], (array) $body),
            $method === 'PATCH' && preg_match('#^channels/(\d+)/messages/(\d+)$#', $path, $m) === 1 => $this->edit($m[1], $m[2], (array) $body),
            default => Http::response(['message' => '404: Not Found', 'code' => 0], 404),
        };
    }

    private function oauth(string $path, Request $request): PromiseInterface
    {
        if ($path === 'oauth2/token/revoke') {
            return Http::response([]);
        }
        $auth = $request->header('Authorization')[0] ?? '';
        $data = $request->data();
        if ($auth !== 'Basic '.base64_encode(self::APP.':'.self::SECRET) || ($data['code'] ?? null) !== self::CODE || ($data['grant_type'] ?? null) !== 'authorization_code') {
            return Http::response(['error' => 'invalid_grant'], 400);
        }

        return Http::response([
            'token_type' => 'Bearer',
            'access_token' => 'fake-oauth-access-token',
            'scope' => 'bot applications.commands',
            'expires_in' => 604800,
            'refresh_token' => 'fake-oauth-refresh-token',
            'guild' => ['id' => self::GUILD, 'name' => 'Acme ops', 'owner_id' => '1'],
        ]);
    }

    /** @param  array<int, mixed>  $commands */
    private function registered(array $commands): PromiseInterface
    {
        $this->commands[self::GUILD] = array_values($commands);

        return Http::response(array_map(fn ($c, $i) => [...(array) $c, 'id' => (string) (1500000000000000000 + $i), 'application_id' => self::APP, 'guild_id' => self::GUILD], $commands, array_keys($commands)));
    }

    /** @param  array<string, mixed>  $body */
    private function post(string $channel, array $body): PromiseInterface
    {
        $id = (string) $this->next++;
        $this->messages[$id] = ['channel' => $channel, 'body' => $body];

        return Http::response(['id' => $id, 'channel_id' => $channel, ...$body]);
    }

    /** @param  array<string, mixed>  $body */
    private function edit(string $channel, string $id, array $body): PromiseInterface
    {
        if (! isset($this->messages[$id])) {
            return Http::response(['message' => 'Unknown Message', 'code' => 10008], 404);
        }
        $this->messages[$id] = ['channel' => $channel, 'body' => $body];

        return Http::response(['id' => $id, 'channel_id' => $channel, ...$body]);
    }
}
