<?php

namespace Tests\Support;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * A small stand-in for Cloudflare's R2, API token and GraphQL Analytics
 * endpoints and R2's S3 endpoint, so tests never call the real ones. Each
 * token says which accounts it reaches and whether it may use R2, make
 * tokens (account or user owned) and read analytics. Anything else on
 * api.cloudflare.com goes to a FakeCloudflare when one is given, so a
 * connected Cloudflare account can be reused.
 */
final class FakeR2
{
    public const string TOKEN = 'fake-r2-token-for-tests-0123456789';

    public const string ACCOUNT = 'ab12ab12ab12ab12ab12ab12ab12ab12';

    public const string WRITE_GROUP = 'fakegroupr2bucketitemwrite000001';

    /** @var array<string, array{accounts: array<string, string>, r2: bool, make: list<string>, analytics: bool}> */
    public array $tokens = [];

    /** @var array<string, array<string, mixed>> by jurisdiction and name, like "default/backups" */
    public array $buckets = [];

    /** @var array<string, list<array<string, mixed>>> lifecycle rules by jurisdiction and name */
    public array $lifecycle = [];

    /** @var array<string, array{kind: string, name: string, policies: list<array<string, mixed>>, value: string}> tokens ServerOS made, by id */
    public array $made = [];

    /** @var array<string, string> S3 keys made by hand: access key id => bucket */
    public array $pasted = [];

    /** @var array<string, array{objectCount: int, payloadSize: int, metadataSize: int}> by GraphQL bucket name */
    public array $storage = [];

    /** @var list<array{key: string, path: string}> */
    public array $uploads = [];

    /** @var list<array{method: string, path: string, body: array<string, mixed>, query: array<string, string>, jurisdiction: string|null}> */
    public array $calls = [];

    /** @var array<string, list<array{status: int, body: array<string, mixed>}>> */
    private array $failures = [];

    private int $next = 1;

    private ?FakeCloudflare $cloudflare = null;

    public static function install(?FakeCloudflare $cloudflare = null, bool $strict = true): self
    {
        $fake = new self;
        $fake->cloudflare = $cloudflare;
        $fake->token(self::TOKEN);
        if ($strict) {
            Http::preventStrayRequests();
        }
        Http::fake([
            'api.cloudflare.com/*' => fn (Request $request) => $fake->handle($request),
            '*.r2.cloudflarestorage.com/*' => fn (Request $request) => $fake->s3($request),
        ]);

        return $fake;
    }

    /**
     * @param  array<string, string>|null  $accounts
     * @param  list<string>  $make
     */
    public function token(string $token, ?array $accounts = null, bool $r2 = true, array $make = ['account', 'user'], bool $analytics = true): self
    {
        $this->tokens[$token] = ['accounts' => $accounts ?? [self::ACCOUNT => 'Acme'], 'r2' => $r2, 'make' => $make, 'analytics' => $analytics];

        return $this;
    }

    public function bucket(string $name, string $jurisdiction = 'default'): void
    {
        $this->buckets["{$jurisdiction}/{$name}"] = ['name' => $name, 'creation_date' => now()->toIso8601ZuluString(), 'location' => 'weur', 'jurisdiction' => $jurisdiction, 'storage_class' => 'Standard'];
    }

    public function pastedKey(string $accessKey, string $bucket): void
    {
        $this->pasted[$accessKey] = $bucket;
    }

    /** @param  array<string, mixed>  $body */
    public function failNext(string $method, string $path, int $status, array $body = []): self
    {
        $this->failures[strtoupper($method).' '.$path][] = ['status' => $status, 'body' => $body];

        return $this;
    }

    /** @return list<string> */
    public function called(): array
    {
        return array_values(array_map(fn (array $call) => $call['method'].' '.$call['path'], $this->calls));
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

    /** @return array{kind: string, name: string, policies: list<array<string, mixed>>, value: string}|null */
    public function madeToken(): ?array
    {
        return $this->made === [] ? null : end($this->made);
    }

    public function handle(Request $request): PromiseInterface
    {
        $url = parse_url($request->url());
        $path = trim(substr((string) ($url['path'] ?? ''), strlen('/client/v4')), '/');
        parse_str((string) ($url['query'] ?? ''), $query);
        $method = strtoupper($request->method());

        if (! $this->ours($path)) {
            return $this->cloudflare?->handle($request) ?? Http::response(FakeCloudflare::error(7000, 'No route for that URI'), 404);
        }

        $body = $method === 'GET' || $method === 'DELETE' ? [] : (array) $request->data();
        $jurisdiction = $request->header('cf-r2-jurisdiction')[0] ?? null;
        $this->calls[] = ['method' => $method, 'path' => $path, 'body' => $body, 'query' => array_map('strval', $query), 'jurisdiction' => $jurisdiction];

        $key = $method.' '.$path;
        if (($this->failures[$key] ?? []) !== [] && ($failure = array_shift($this->failures[$key])) !== null) {
            return Http::response($failure['body'] === [] ? FakeCloudflare::error(10001, 'Internal error') : $failure['body'], $failure['status']);
        }
        $auth = (string) ($request->header('Authorization')[0] ?? '');
        $token = $this->tokens[substr($auth, 7)] ?? null;
        if (! str_starts_with($auth, 'Bearer ') || $token === null) {
            return Http::response(FakeCloudflare::error(10000, 'Authentication error'), 403);
        }

        return $this->route($method, $path, $body, array_map('strval', $query), $jurisdiction ?? 'default', $token);
    }

    private function ours(string $path): bool
    {
        return $path === 'accounts' || $path === 'graphql' || (str_starts_with($path, 'user/tokens') && $path !== 'user/tokens/verify')
            || preg_match('#^accounts/[^/]+/(r2|tokens)(/|$)#', $path) === 1;
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $query
     * @param  array{accounts: array<string, string>, r2: bool, make: list<string>, analytics: bool}  $token
     */
    private function route(string $method, string $path, array $body, array $query, string $jurisdiction, array $token): PromiseInterface
    {
        if ($path === 'accounts') {
            return self::ok(array_map(fn (string $id, string $name) => ['id' => $id, 'name' => $name], array_keys($token['accounts']), $token['accounts']), ['page' => 1, 'per_page' => 50, 'total_pages' => 1]);
        }
        if ($path === 'graphql') {
            return $this->graphql($body, $token);
        }
        if (str_starts_with($path, 'user/tokens')) {
            return $this->tokens($method, $path, 'user', $body, $query, $token);
        }

        $parts = explode('/', $path);
        $account = $parts[1];
        if (! isset($token['accounts'][$account])) {
            return Http::response(FakeCloudflare::error(9109, 'Unauthorized to access requested resource'), 403);
        }
        if ($parts[2] === 'tokens') {
            return $this->tokens($method, implode('/', array_slice($parts, 2)), 'account', $body, $query, $token);
        }
        if (! $token['r2']) {
            return Http::response(FakeCloudflare::error(10000, 'Authentication error'), 403);
        }

        return match (true) {
            $method === 'GET' && count($parts) === 4 => self::ok(['buckets' => array_values(array_filter(
                $this->buckets,
                fn (array $b) => $b['jurisdiction'] === $jurisdiction && (! isset($query['name_contains']) || str_contains($b['name'], $query['name_contains'])),
            ))], ['cursor' => '', 'per_page' => (int) ($query['per_page'] ?? 20)]),
            $method === 'POST' && count($parts) === 4 => $this->createBucket($body, $jurisdiction),
            $method === 'PUT' && count($parts) === 6 && $parts[5] === 'lifecycle' => $this->setLifecycle($parts[4], $jurisdiction, $body),
            default => Http::response(FakeCloudflare::error(7000, 'No route for that URI'), 404),
        };
    }

    /** @param  array<string, mixed>  $body */
    private function createBucket(array $body, string $jurisdiction): PromiseInterface
    {
        $name = (string) ($body['name'] ?? '');
        if (preg_match('/^[a-z0-9][a-z0-9-]{1,61}[a-z0-9]$/', $name) !== 1) {
            return Http::response(FakeCloudflare::error(10005, 'The bucket name is not valid.'), 400);
        }
        if (isset($this->buckets["{$jurisdiction}/{$name}"])) {
            return Http::response(FakeCloudflare::error(10004, 'The bucket you tried to create already exists, and you own it.'), 409);
        }
        $this->buckets["{$jurisdiction}/{$name}"] = ['name' => $name, 'creation_date' => now()->toIso8601ZuluString(), 'location' => $body['locationHint'] ?? 'weur', 'jurisdiction' => $jurisdiction, 'storage_class' => $body['storageClass'] ?? 'Standard'];
        $this->lifecycle["{$jurisdiction}/{$name}"] = [['id' => 'Default Multipart Abort Rule', 'enabled' => true, 'conditions' => ['prefix' => ''], 'abortMultipartUploadsTransition' => ['condition' => ['type' => 'Age', 'maxAge' => 604800]]]];

        return self::ok($this->buckets["{$jurisdiction}/{$name}"]);
    }

    /** @param  array<string, mixed>  $body */
    private function setLifecycle(string $name, string $jurisdiction, array $body): PromiseInterface
    {
        if (! isset($this->buckets["{$jurisdiction}/{$name}"])) {
            return Http::response(FakeCloudflare::error(10006, 'The specified bucket does not exist.'), 404);
        }
        $this->lifecycle["{$jurisdiction}/{$name}"] = array_values((array) ($body['rules'] ?? []));

        return self::ok([]);
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $query
     * @param  array{accounts: array<string, string>, r2: bool, make: list<string>, analytics: bool}  $token
     */
    private function tokens(string $method, string $path, string $kind, array $body, array $query, array $token): PromiseInterface
    {
        if (! in_array($kind, $token['make'], true)) {
            return Http::response(FakeCloudflare::error(9109, 'Unauthorized to access requested resource'), 403);
        }
        $rest = trim(preg_replace('#^(user/)?tokens#', '', $path) ?? '', '/');

        if ($method === 'GET' && $rest === 'permission_groups') {
            $groups = [
                ['id' => self::WRITE_GROUP, 'name' => 'Workers R2 Storage Bucket Item Write', 'scopes' => ['com.cloudflare.edge.r2.bucket']],
                ['id' => 'fakegroupr2bucketitemread0000001', 'name' => 'Workers R2 Storage Bucket Item Read', 'scopes' => ['com.cloudflare.edge.r2.bucket']],
            ];

            return self::ok(array_values(array_filter($groups, fn (array $g) => ! isset($query['name']) || $g['name'] === $query['name'])));
        }
        if ($method === 'POST' && $rest === '') {
            $id = 'faker2tokenid'.str_pad((string) $this->next++, 4, '0', STR_PAD_LEFT);
            $value = 'fake-r2-made-token-value-'.$id;
            $this->made[$id] = ['kind' => $kind, 'name' => (string) ($body['name'] ?? ''), 'policies' => array_values((array) ($body['policies'] ?? [])), 'value' => $value];

            return self::ok(['id' => $id, 'name' => $body['name'] ?? '', 'status' => 'active', 'value' => $value, 'policies' => $body['policies'] ?? []]);
        }
        if ($method === 'DELETE' && $rest !== '') {
            if (! isset($this->made[$rest]) || $this->made[$rest]['kind'] !== $kind) {
                return Http::response(FakeCloudflare::error(1003, 'Token not found'), 404);
            }
            unset($this->made[$rest]);

            return self::ok(['id' => $rest]);
        }

        return Http::response(FakeCloudflare::error(7000, 'No route for that URI'), 404);
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array{accounts: array<string, string>, r2: bool, make: list<string>, analytics: bool}  $token
     */
    private function graphql(array $body, array $token): PromiseInterface
    {
        $vars = (array) ($body['variables'] ?? []);
        if (! $token['analytics'] || ! isset($token['accounts'][(string) ($vars['accountTag'] ?? '')])) {
            return Http::response(['data' => null, 'errors' => [['message' => 'not authorized for that account', 'path' => ['viewer', 'accounts']]]]);
        }
        $stored = $this->storage[(string) ($vars['bucketName'] ?? '')] ?? null;

        return Http::response(['data' => ['viewer' => ['accounts' => [[
            'r2StorageAdaptiveGroups' => $stored === null ? [] : [['max' => $stored]],
        ]]]], 'errors' => null]);
    }

    public function s3(Request $request): PromiseInterface
    {
        $url = parse_url($request->url());
        $path = (string) ($url['path'] ?? '');
        $bucket = explode('/', ltrim($path, '/'))[0];
        $host = (string) ($url['host'] ?? '');
        $jurisdiction = str_contains($host, '.eu.') ? 'eu' : 'default';
        $this->calls[] = ['method' => strtoupper($request->method()), 'path' => 's3:'.$host.$path, 'body' => [], 'query' => [], 'jurisdiction' => $jurisdiction];
        $auth = (string) ($request->header('Authorization')[0] ?? '');
        $access = preg_match('#Credential=([^/]+)/\d{8}/auto/s3/aws4_request#', $auth, $m) === 1 ? $m[1] : null;
        $resource = 'com.cloudflare.edge.r2.bucket.'.explode('.', $host)[0].'_'.$jurisdiction.'_'.$bucket;
        $made = $access === null ? null : ($this->made[$access] ?? null);

        $code = match (true) {
            ! isset($this->buckets["{$jurisdiction}/{$bucket}"]) => 'NoSuchBucket',
            $made === null && ! isset($this->pasted[(string) $access]) => 'InvalidAccessKeyId',
            $made !== null && ! isset($made['policies'][0]['resources'][$resource]) => 'AccessDenied',
            $made === null && $this->pasted[(string) $access] !== $bucket => 'AccessDenied',
            default => null,
        };
        if ($code !== null) {
            return Http::response("<?xml version=\"1.0\" encoding=\"UTF-8\"?><Error><Code>{$code}</Code><Message>Nope</Message></Error>", $code === 'NoSuchBucket' ? 404 : 403);
        }
        $this->uploads[] = ['key' => (string) $access, 'path' => $path];

        return Http::response('', 200);
    }

    /**
     * @param  array<string, mixed>|null  $info
     */
    private static function ok(mixed $result, ?array $info = null): PromiseInterface
    {
        return Http::response(['success' => true, 'errors' => [], 'messages' => [], 'result' => $result, ...($info === null ? [] : ['result_info' => $info])]);
    }
}
