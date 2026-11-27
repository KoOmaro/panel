<?php

namespace Tests\Support;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * A small stand-in for the Tailscale API v2, so tests never call the real
 * one. It keeps devices and auth keys, takes an access token or an OAuth
 * client, records every call and can fail the next calls to a path.
 */
final class FakeTailscale
{
    public const string TOKEN = 'fake-tailscale-token-for-tests';

    public const string CLIENT_ID = 'fake-client-id';

    public const string CLIENT_SECRET = 'fake-tailscale-client-secret-for-tests';

    private const string OAUTH_ACCESS = 'fake-tailscale-oauth-access-for-tests';

    public string $tailnet = 'acme.example';

    public string $dns = 'tail1234.ts.net';

    /** @var list<string> tags the tailnet's access controls let keys have */
    public array $allowedTags = ['tag:serveros', 'tag:web'];

    /** @var array<string, array<string, mixed>> by nodeId */
    public array $devices = [];

    /** @var array<string, array{id: string, key: string, tags: list<string>, reusable: bool, preauthorized: bool, ephemeral: bool, expirySeconds: int, description: string, deleted: bool}> */
    public array $keys = [];

    /** @var list<array{method: string, path: string, body: array<string, mixed>, query: array<string, string>, auth: string}> */
    public array $calls = [];

    /** @var array<string, list<array{status: int, body: array<string, mixed>}>> */
    private array $failures = [];

    private int $next = 1;

    public static function install(bool $strict = true): self
    {
        $fake = new self;
        if ($strict) {
            Http::preventStrayRequests();
        }
        Http::fake(['api.tailscale.com/*' => fn (Request $request) => $fake->handle($request)]);

        return $fake;
    }

    /**
     * A device already on the tailnet.
     *
     * @param  array<string, mixed>  $extra
     */
    public function device(string $hostname, string $address, array $extra = []): string
    {
        $id = 'n'.str_pad((string) $this->next++, 6, '0', STR_PAD_LEFT).'CNTRL';
        $this->devices[$id] = [
            'id' => (string) (1000 + $this->next),
            'nodeId' => $id,
            'name' => $hostname.'.'.$this->dns,
            'hostname' => $hostname,
            'addresses' => [$address, 'fd7a:115c:a1e0::'.dechex($this->next)],
            'os' => 'linux',
            'clientVersion' => '1.90.0',
            'authorized' => true,
            'isExternal' => false,
            'tags' => [],
            'created' => now()->subDays(3)->toIso8601ZuluString(),
            'lastSeen' => now()->toIso8601ZuluString(),
            'connectedToControl' => true,
            'clientConnectivity' => ['endpoints' => []],
            ...$extra,
        ];

        return $id;
    }

    /** What the machine does with the key it was given: it turns up as a new device. */
    public function joinWith(string $key, string $hostname, string $address = '100.101.102.103'): string
    {
        $found = collect($this->keys)->first(fn (array $k) => $k['key'] === $key && ! $k['deleted']);
        if ($found === null) {
            throw new \RuntimeException('No such key, or it was revoked.');
        }

        return $this->device($hostname, $address, ['tags' => $found['tags'], 'created' => now()->toIso8601ZuluString()]);
    }

    /** @return array<string, mixed>|null */
    public function lastKey(): ?array
    {
        return $this->keys === [] ? null : end($this->keys);
    }

    /** @param  array<string, mixed>  $body */
    public function failNext(string $method, string $path, int $status, array $body = []): self
    {
        $this->failures[strtoupper($method).' '.$path][] = ['status' => $status, 'body' => $body];

        return $this;
    }

    /** @return list<string> */
    public function called(?string $method = null): array
    {
        return array_values(array_map(
            fn (array $call) => $call['method'].' '.$call['path'],
            array_filter($this->calls, fn (array $call) => $method === null || $call['method'] === $method),
        ));
    }

    /** @return list<string> */
    public function writes(): array
    {
        return array_values(array_filter($this->called(), fn (string $call) => ! str_starts_with($call, 'GET ')));
    }

    /** @return array<string, mixed>|null */
    public function bodyOf(string $method, string $path): ?array
    {
        foreach (array_reverse($this->calls) as $call) {
            if ($call['method'] === $method && $call['path'] === $path) {
                return $call['body'];
            }
        }

        return null;
    }

    public function handle(Request $request): PromiseInterface
    {
        $url = parse_url($request->url());
        $path = trim(substr((string) ($url['path'] ?? ''), strlen('/api/v2')), '/');
        $query = [];
        foreach (array_filter(explode('&', (string) ($url['query'] ?? ''))) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $query[urldecode($key)] = urldecode($value);
        }
        $method = strtoupper($request->method());
        $body = $request->data();
        $auth = (string) ($request->header('Authorization')[0] ?? '');
        $this->calls[] = ['method' => $method, 'path' => $path, 'body' => is_array($body) ? $body : [], 'query' => $query, 'auth' => $auth];

        $key = $method.' '.$path;
        if (($this->failures[$key] ?? []) !== [] && ($failure = array_shift($this->failures[$key])) !== null) {
            return Http::response($failure['body'] === [] ? ['message' => 'internal error'] : $failure['body'], $failure['status']);
        }

        if ($method === 'POST' && $path === 'oauth/token') {
            return ($body['client_secret'] ?? null) === self::CLIENT_SECRET && ($body['client_id'] ?? null) === self::CLIENT_ID
                ? Http::response(['access_token' => self::OAUTH_ACCESS, 'token_type' => 'Bearer', 'expires_in' => 3600, 'scope' => 'devices:core auth_keys'])
                : Http::response(['error' => 'invalid_client'], 401);
        }
        if (! in_array($auth, ['Bearer '.self::TOKEN, 'Bearer '.self::OAUTH_ACCESS], true)) {
            return Http::response(['message' => 'API token invalid'], 401);
        }

        return $this->route($method, explode('/', $path), is_array($body) ? $body : []);
    }

    /**
     * @param  list<string>  $parts
     * @param  array<string, mixed>  $body
     */
    private function route(string $method, array $parts, array $body): PromiseInterface
    {
        if ($parts[0] === 'tailnet' && ! in_array(urldecode($parts[1] ?? ''), ['-', $this->tailnet], true)) {
            return Http::response(['message' => 'tailnet not found'], 404);
        }

        return match (true) {
            $method === 'GET' && $parts[0] === 'tailnet' && ($parts[2] ?? null) === 'devices' => Http::response(['devices' => array_values($this->devices)]),
            $method === 'POST' && $parts[0] === 'tailnet' && ($parts[2] ?? null) === 'keys' && count($parts) === 3 => $this->createKey($body),
            $method === 'DELETE' && $parts[0] === 'tailnet' && ($parts[2] ?? null) === 'keys' && isset($parts[3]) => $this->deleteKey(urldecode($parts[3])),
            $method === 'DELETE' && $parts[0] === 'device' && isset($parts[1]) => $this->deleteDevice(urldecode($parts[1])),
            default => Http::response(['message' => 'not found'], 404),
        };
    }

    /** @param  array<string, mixed>  $body */
    private function createKey(array $body): PromiseInterface
    {
        $create = (array) ($body['capabilities']['devices']['create'] ?? []);
        $tags = array_values((array) ($create['tags'] ?? []));
        $bad = array_diff($tags, $this->allowedTags);
        if ($bad !== []) {
            return Http::response(['message' => 'requested tags ['.implode(', ', $bad).'] are invalid or not permitted'], 400);
        }
        $n = $this->next++;
        $id = 'k'.str_pad((string) $n, 6, '0', STR_PAD_LEFT).'CNTRL';
        $this->keys[$id] = [
            'id' => $id,
            'key' => 'fake-tailscale-auth-key-'.$n,
            'tags' => $tags,
            'reusable' => (bool) ($create['reusable'] ?? false),
            'preauthorized' => (bool) ($create['preauthorized'] ?? false),
            'ephemeral' => (bool) ($create['ephemeral'] ?? false),
            'expirySeconds' => (int) ($body['expirySeconds'] ?? 0),
            'description' => (string) ($body['description'] ?? ''),
            'deleted' => false,
        ];

        return Http::response([
            'id' => $id,
            'key' => $this->keys[$id]['key'],
            'created' => now()->toIso8601ZuluString(),
            'expires' => now()->addSeconds((int) ($body['expirySeconds'] ?? 0))->toIso8601ZuluString(),
            'capabilities' => $body['capabilities'] ?? [],
        ]);
    }

    private function deleteKey(string $id): PromiseInterface
    {
        if (! isset($this->keys[$id]) || $this->keys[$id]['deleted']) {
            return Http::response(['message' => 'not found'], 404);
        }
        $this->keys[$id]['deleted'] = true;

        return Http::response('', 200);
    }

    private function deleteDevice(string $id): PromiseInterface
    {
        $found = collect($this->devices)->first(fn (array $d) => $d['nodeId'] === $id || $d['id'] === $id);
        if ($found === null) {
            return Http::response(['message' => 'not found'], 404);
        }
        unset($this->devices[$found['nodeId']]);

        return Http::response('', 200);
    }
}
