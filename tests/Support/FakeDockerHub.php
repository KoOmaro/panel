<?php

namespace Tests\Support;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * A small stand-in for the Docker Hub API (hub.docker.com/v2) and Docker
 * Hub's registry (registry-1.docker.io, with tokens from auth.docker.io),
 * so tests never call the real ones. It keeps users and the namespaces
 * they can see, repositories with their tags and the digest each tag
 * points at, records every call, and can fail the next call to a path.
 */
final class FakeDockerHub
{
    public const string TOKEN = 'fake-docker-hub-token-for-tests';

    public const string OTHER_TOKEN = 'fake-docker-hub-other-token-for-tests';

    /** @var array<string, array{token: string, namespaces: list<string>}> by username */
    public array $users = [];

    /** @var array<string, array<string, mixed>> by namespace/name */
    public array $repositories = [];

    /** @var list<array{method: string, host: string, path: string, query: array<string, mixed>, body: array<string, mixed>, auth: string|null}> */
    public array $calls = [];

    /** @var array<string, list<array{status: int, body: array<string, mixed>}>> */
    private array $failures = [];

    private int $issued = 0;

    public static function install(bool $strict = true): self
    {
        $fake = new self;
        if ($strict) {
            Http::preventStrayRequests();
        }
        Http::fake([
            'hub.docker.com/v2/*' => fn (Request $request) => $fake->hub($request),
            'auth.docker.io/token*' => fn (Request $request) => $fake->registryToken($request),
            'registry-1.docker.io/v2/*' => fn (Request $request) => $fake->registry($request),
        ]);

        return $fake;
    }

    /** @param  list<string>  $organizations */
    public function user(string $username, string $token = self::TOKEN, array $organizations = []): self
    {
        $this->users[$username] = ['token' => $token, 'namespaces' => [$username, ...$organizations]];

        return $this;
    }

    /**
     * @param  array<string, string>  $tags  name => digest
     * @param  array<string, mixed>  $overrides
     */
    public function repository(string $path, array $tags = ['latest' => 'sha256:'.'a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1'], bool $private = true, array $overrides = [], ?int $port = null): self
    {
        [$namespace, $name] = explode('/', $path, 2);
        $this->repositories[$path] = [
            'name' => $name,
            'namespace' => $namespace,
            'repository_type' => 'image',
            'is_private' => $private,
            'description' => '',
            'pull_count' => 12,
            'last_updated' => '2026-11-2'.count($this->repositories).'T10:00:00.000000Z',
            ...$overrides,
            '_tags' => $tags,
            '_port' => $port,
        ];

        return $this;
    }

    /** Points a tag at a new image, as a push does. */
    public function push(string $path, string $tag, string $digest): self
    {
        $this->repositories[$path]['_tags'][$tag] = $digest;

        return $this;
    }

    public function failNext(string $path, int $status, array $body = []): self
    {
        $this->failures[$path][] = ['status' => $status, 'body' => $body];

        return $this;
    }

    /** @return list<string> */
    public function called(?string $host = null): array
    {
        return array_values(array_map(
            fn (array $call) => $call['method'].' '.$call['path'],
            array_filter($this->calls, fn (array $call) => $host === null || $call['host'] === $host),
        ));
    }

    private function hub(Request $request): PromiseInterface
    {
        $path = (string) preg_replace('#^/v2/#', '', (string) parse_url($request->url(), PHP_URL_PATH));
        $this->record($request, 'hub.docker.com', $path);

        if (isset($this->failures[$path]) && $this->failures[$path] !== []) {
            $failure = array_shift($this->failures[$path]);

            return Http::response($failure['body'], $failure['status']);
        }

        if ($request->method() === 'POST' && $path === 'auth/token') {
            $user = $this->users[(string) ($request['identifier'] ?? '')] ?? null;
            if ($user === null || ! hash_equals($user['token'], (string) ($request['secret'] ?? ''))) {
                return Http::response(['message' => 'Incorrect authentication credentials', 'errinfo' => null], 401);
            }
            $this->issued++;

            return Http::response(['access_token' => 'fake-hub-access-'.$request['identifier'].'-'.$this->issued]);
        }

        $username = $this->bearer($request);
        if ($username === null) {
            return Http::response(['message' => 'unauthorized', 'errinfo' => null], 401);
        }
        $sees = $this->users[$username]['namespaces'];

        if (preg_match('#^namespaces/([^/]+)/repositories$#', $path, $m)) {
            if (! in_array($m[1], $sees, true)) {
                return Http::response(['message' => 'not found', 'errinfo' => null], 404);
            }
            $rows = array_values(array_filter($this->repositories, fn (array $repo) => $repo['namespace'] === $m[1]));

            return $this->page($request, array_map(fn (array $repo) => array_diff_key($repo, ['_tags' => 1, '_port' => 1]), $rows));
        }
        if (preg_match('#^namespaces/([^/]+)/repositories/([^/]+)/tags(?:/([^/]+))?$#', $path, $m)) {
            $repo = $this->repositories[$m[1].'/'.$m[2]] ?? null;
            if ($repo === null || ! in_array($m[1], $sees, true)) {
                return Http::response(['message' => 'not found', 'errinfo' => null], 404);
            }
            $tags = [];
            $n = 0;
            foreach ($repo['_tags'] as $name => $digest) {
                $n++;
                $tags[$name] = [
                    'id' => $n,
                    'name' => (string) $name,
                    'full_size' => 52_000_000 + $n,
                    'last_updated' => '2026-11-2'.$n.'T09:00:00.000000Z',
                    'tag_last_pushed' => '2026-11-2'.$n.'T09:00:00.000000Z',
                    'digest' => $digest,
                    'images' => [[
                        'architecture' => 'amd64', 'os' => 'linux', 'variant' => null, 'size' => 52_000_000 + $n,
                        'digest' => 'sha256:'.str_repeat('e', 64), 'status' => 'active',
                        'layers' => $repo['_port'] === null ? [] : [['digest' => null, 'size' => 0, 'instruction' => 'EXPOSE '.$repo['_port'].'/tcp']],
                    ]],
                ];
            }
            if (isset($m[3])) {
                return isset($tags[urldecode($m[3])]) ? Http::response($tags[urldecode($m[3])]) : Http::response(['message' => 'not found', 'errinfo' => null], 404);
            }

            return $this->page($request, array_values($tags));
        }

        return Http::response(['message' => 'not found', 'errinfo' => null], 404);
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function page(Request $request, array $rows): PromiseInterface
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $size = max(1, (int) ($query['page_size'] ?? 10));
        $page = max(1, (int) ($query['page'] ?? 1));
        $slice = array_slice($rows, ($page - 1) * $size, $size);

        return Http::response([
            'count' => count($rows),
            'next' => $page * $size < count($rows) ? 'https://hub.docker.com/v2/next?page='.($page + 1) : null,
            'previous' => null,
            'results' => $slice,
        ]);
    }

    private function registryToken(Request $request): PromiseInterface
    {
        $this->record($request, 'auth.docker.io', 'token');
        $auth = $request->header('Authorization')[0] ?? '';
        if ($auth !== '') {
            [$user, $secret] = array_pad(explode(':', (string) base64_decode(substr($auth, 6)), 2), 2, '');
            if (! isset($this->users[$user]) || ! hash_equals($this->users[$user]['token'], $secret)) {
                return Http::response(['details' => 'incorrect username or password'], 401);
            }

            return Http::response(['token' => 'pull-token-'.$user]);
        }

        return Http::response(['token' => 'pull-token-anonymous']);
    }

    private function registry(Request $request): PromiseInterface
    {
        $path = (string) preg_replace('#^/v2/#', '', (string) parse_url($request->url(), PHP_URL_PATH));
        $this->record($request, 'registry-1.docker.io', $path);
        if (! preg_match('#^(.+)/manifests/([^/]+)$#', $path, $m)) {
            return Http::response('', 404);
        }
        $repo = $this->repositories[$m[1]] ?? null;
        $auth = $request->header('Authorization')[0] ?? '';
        if (! str_starts_with($auth, 'Bearer pull-token-')) {
            return Http::response('', 401, ['WWW-Authenticate' => 'Bearer realm="https://auth.docker.io/token",service="registry.docker.io",scope="repository:'.$m[1].':pull"']);
        }
        $user = substr($auth, strlen('Bearer pull-token-'));
        if ($repo === null || (($repo['is_private'] ?? false) && ! in_array($repo['namespace'], $this->users[$user]['namespaces'] ?? [], true))) {
            return Http::response('', 401, ['WWW-Authenticate' => 'Bearer realm="https://auth.docker.io/token",service="registry.docker.io",scope="repository:'.$m[1].':pull",error="insufficient_scope"']);
        }
        $digest = $repo['_tags'][urldecode($m[2])] ?? null;

        return $digest === null ? Http::response('', 404) : Http::response('', 200, ['Docker-Content-Digest' => $digest]);
    }

    private function bearer(Request $request): ?string
    {
        $auth = $request->header('Authorization')[0] ?? '';
        if (! preg_match('/^Bearer fake-hub-access-(.+)-\d+$/', $auth, $m)) {
            return null;
        }

        return isset($this->users[$m[1]]) ? $m[1] : null;
    }

    private function record(Request $request, string $host, string $path): void
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $this->calls[] = [
            'method' => $request->method(),
            'host' => $host,
            'path' => $path,
            'query' => $query,
            'body' => $request->isJson() ? (array) $request->data() : [],
            'auth' => $request->header('Authorization')[0] ?? null,
        ];
    }
}
