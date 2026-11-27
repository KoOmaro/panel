<?php

namespace Tests\Support;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * A stand-in for Slack: the Web API methods ServerOS calls (oauth.v2.access,
 * auth.test, conversations.list, chat.postMessage, chat.update, users.info)
 * and the response_url webhooks. It keeps messages by channel and ts,
 * records every call and can fail the next call to a method.
 */
final class FakeSlack
{
    public const string TOKEN = 'fake-slack-bot-token-for-tests';

    public const string CODE = 'fake-slack-oauth-code';

    public const string TEAM = 'T0FAKE001';

    public const string SECRET = 'fake-slack-signing-secret';

    /** @var list<array{id: string, name: string, is_private: bool, is_member: bool}> */
    public array $channels = [
        ['id' => 'C0ALERTS1', 'name' => 'ops', 'is_private' => false, 'is_member' => true],
        ['id' => 'C0DEPLOY1', 'name' => 'deploys', 'is_private' => false, 'is_member' => true],
        ['id' => 'C0RANDOM1', 'name' => 'random', 'is_private' => false, 'is_member' => false],
        ['id' => 'G0PRIVATE', 'name' => 'oncall', 'is_private' => true, 'is_member' => true],
    ];

    /** @var array<string, array<string, mixed>> Slack users by id */
    public array $users = [];

    /** @var array<string, array<string, array<string, mixed>>> the messages in each channel, by ts */
    public array $messages = [];

    /** @var list<array{method: string, args: array<string, mixed>}> */
    public array $calls = [];

    /** @var list<array{url: string, body: array<string, mixed>}> what went to response_urls */
    public array $responses = [];

    /** @var array<string, list<string>> */
    private array $failures = [];

    private int $ts = 1764200000;

    public static function install(bool $strict = true): self
    {
        $fake = new self;
        if ($strict) {
            Http::preventStrayRequests();
        }
        config()->set('services.slack_app', ['client_id' => 'fake-client-id', 'client_secret' => 'fake-client-secret', 'signing_secret' => self::SECRET]);
        Http::fake([
            'slack.com/api/*' => fn (Request $request) => $fake->handle($request),
            'hooks.slack.com/*' => function (Request $request) use ($fake) {
                $fake->responses[] = ['url' => $request->url(), 'body' => (array) $request->data()];

                return Http::response('ok');
            },
        ]);

        return $fake;
    }

    public function failNext(string $method, string $error): self
    {
        $this->failures[$method][] = $error;

        return $this;
    }

    /** @return list<string> the methods called, in order */
    public function methods(): array
    {
        return array_map(fn (array $c) => $c['method'], $this->calls);
    }

    /** @return list<array<string, mixed>> */
    public function posted(string $method = 'chat.postMessage'): array
    {
        return array_values(array_map(fn (array $c) => $c['args'], array_filter($this->calls, fn (array $c) => $c['method'] === $method)));
    }

    /** @return list<string> every action_id on a message's buttons */
    public static function actionIds(array $message): array
    {
        $ids = [];
        foreach ((array) ($message['blocks'] ?? []) as $block) {
            foreach ((array) ($block['elements'] ?? []) as $element) {
                if (is_array($element) && isset($element['action_id'])) {
                    $ids[] = (string) $element['action_id'];
                }
            }
        }

        return $ids;
    }

    /** @return array<string, mixed>|null a button on a message, by its action_id */
    public static function button(array $message, string $actionId): ?array
    {
        foreach ((array) ($message['blocks'] ?? []) as $block) {
            foreach ((array) ($block['elements'] ?? []) as $element) {
                if (is_array($element) && ($element['action_id'] ?? null) === $actionId) {
                    return $element;
                }
            }
        }

        return null;
    }

    /** All the text in a message's blocks. */
    public static function text(array $message): string
    {
        $out = [];
        array_walk_recursive($message, function ($value, $key) use (&$out) {
            if ($key === 'text' && is_string($value)) {
                $out[] = $value;
            }
        });

        return implode("\n", $out);
    }

    public function handle(Request $request): PromiseInterface
    {
        $method = (string) str($request->url())->after('/api/')->before('?');
        $args = $request->method() === 'GET' ? (array) $request->data() : (array) $request->data();
        if ($request->method() === 'GET') {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $args);
        }
        $this->calls[] = ['method' => $method, 'args' => $args];

        if (($this->failures[$method] ?? []) !== []) {
            return Http::response(['ok' => false, 'error' => array_shift($this->failures[$method])]);
        }
        if ($method === 'oauth.v2.access') {
            $basic = 'Basic '.base64_encode('fake-client-id:fake-client-secret');
            if (($request->header('Authorization')[0] ?? null) !== $basic) {
                return Http::response(['ok' => false, 'error' => 'invalid_client_id']);
            }
            if (($args['code'] ?? null) !== self::CODE) {
                return Http::response(['ok' => false, 'error' => 'invalid_code']);
            }

            return Http::response([
                'ok' => true,
                'access_token' => self::TOKEN,
                'token_type' => 'bot',
                'scope' => 'chat:write,commands,channels:read,groups:read,users:read,users:read.email',
                'bot_user_id' => 'U0BOT0001',
                'app_id' => 'A0FAKEAPP',
                'team' => ['name' => 'Acme Slack', 'id' => self::TEAM],
                'enterprise' => null,
                'authed_user' => ['id' => 'U0SAM0001'],
            ]);
        }
        if (($request->header('Authorization')[0] ?? null) !== 'Bearer '.self::TOKEN) {
            return Http::response(['ok' => false, 'error' => 'invalid_auth']);
        }

        return Http::response(match ($method) {
            'auth.test' => ['ok' => true, 'url' => 'https://acme-fake.slack.com/', 'team' => 'Acme Slack', 'user' => 'serveros', 'team_id' => self::TEAM, 'user_id' => 'U0BOT0001', 'bot_id' => 'B0BOT0001'],
            'conversations.list' => ['ok' => true, 'channels' => $this->channels, 'response_metadata' => ['next_cursor' => '']],
            'users.info' => isset($this->users[$args['user'] ?? '']) ? ['ok' => true, 'user' => $this->users[$args['user']]] : ['ok' => false, 'error' => 'user_not_found'],
            'chat.postMessage' => $this->post($args),
            'chat.update' => $this->update($args),
            default => ['ok' => false, 'error' => 'unknown_method'],
        });
    }

    /** @param  array<string, mixed>  $args */
    private function post(array $args): array
    {
        $channel = (string) ($args['channel'] ?? '');
        $known = collect($this->channels)->firstWhere('id', $channel);
        if ($known === null) {
            return ['ok' => false, 'error' => 'channel_not_found'];
        }
        if (! $known['is_member']) {
            return ['ok' => false, 'error' => 'not_in_channel'];
        }
        $ts = ($this->ts++).'.000100';
        $this->messages[$channel][$ts] = $args;

        return ['ok' => true, 'channel' => $channel, 'ts' => $ts, 'message' => ['text' => $args['text'] ?? '']];
    }

    /** @param  array<string, mixed>  $args */
    private function update(array $args): array
    {
        $channel = (string) ($args['channel'] ?? '');
        $ts = (string) ($args['ts'] ?? '');
        if (! isset($this->messages[$channel][$ts])) {
            return ['ok' => false, 'error' => 'message_not_found'];
        }
        $this->messages[$channel][$ts] = [...$this->messages[$channel][$ts], ...$args];

        return ['ok' => true, 'channel' => $channel, 'ts' => $ts];
    }
}
