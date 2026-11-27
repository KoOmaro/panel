<?php

namespace Tests\Support;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * A small stand-in for the Backblaze B2 Native API v4 and its S3 endpoint,
 * so tests never call the real ones. It keeps application keys with their
 * capabilities, buckets, file versions and the keys ServerOS makes, checks
 * each call's capability, records every call and can fail the next calls
 * to an operation.
 */
final class FakeB2
{
    public const string KEY_ID = 'fakeb2accountkey';

    public const string KEY = 'fake-b2-application-key-for-tests';

    public const string ACCOUNT = 'fakeb2account';

    public const string REGION = 'us-west-004';

    /** @var array<string, array{key: string, capabilities: list<string>, bucketIds: list<string>, keyName: string, namePrefix: string|null}> by key id */
    public array $keys = [];

    /** @var array<string, array<string, mixed>> by bucket id */
    public array $buckets = [];

    /** @var list<string> names other Backblaze accounts already use */
    public array $takenElsewhere = [];

    /** @var array<string, list<array<string, mixed>>> file versions by bucket id */
    public array $files = [];

    /** @var list<array{key: string, bucket: string, path: string}> test uploads through the S3 API */
    public array $uploads = [];

    /** @var list<array{method: string, path: string, body: array<string, mixed>, query: array<string, string>, host: string}> */
    public array $calls = [];

    /** @var array<string, list<array{status: int, body: array<string, mixed>}>> */
    private array $failures = [];

    private int $next = 1;

    public static function install(bool $strict = true): self
    {
        $fake = new self;
        $fake->keys[self::KEY_ID] = ['key' => self::KEY, 'capabilities' => [
            'listKeys', 'writeKeys', 'deleteKeys', 'listAllBucketNames', 'listBuckets', 'readBuckets', 'writeBuckets', 'deleteBuckets',
            'listFiles', 'readFiles', 'shareFiles', 'writeFiles', 'deleteFiles',
        ], 'bucketIds' => [], 'keyName' => 'Master Application Key', 'namePrefix' => null];
        if ($strict) {
            Http::preventStrayRequests();
        }
        Http::fake(['*backblazeb2.com*' => fn (Request $request) => $fake->handle($request)]);

        return $fake;
    }

    /** @param  list<string>  $capabilities */
    public function accountKey(string $id, array $capabilities, array $bucketIds = []): string
    {
        $this->keys[$id] = ['key' => 'fake-b2-key-'.$id, 'capabilities' => $capabilities, 'bucketIds' => $bucketIds, 'keyName' => $id, 'namePrefix' => null];

        return 'fake-b2-key-'.$id;
    }

    public function bucket(string $name, bool $ours = true): string
    {
        if (! $ours) {
            $this->takenElsewhere[] = $name;

            return '';
        }
        $id = 'b2bucket'.str_pad((string) $this->next++, 4, '0', STR_PAD_LEFT);
        $this->buckets[$id] = ['bucketId' => $id, 'bucketName' => $name, 'bucketType' => 'allPrivate', 'lifecycleRules' => [], 'bucketInfo' => [], 'accountId' => self::ACCOUNT];
        $this->files[$id] = [];

        return $id;
    }

    public function file(string $bucketId, string $name, int $bytes, string $action = 'upload'): void
    {
        $this->files[$bucketId][] = ['fileName' => $name, 'fileId' => 'f'.$this->next++, 'action' => $action, 'contentLength' => $bytes, 'uploadTimestamp' => now()->getTimestampMs()];
    }

    /** @param  array<string, mixed>  $body */
    public function failNext(string $operation, int $status, array $body = []): self
    {
        $this->failures[$operation][] = ['status' => $status, 'body' => $body];

        return $this;
    }

    /** @return list<string> */
    public function called(): array
    {
        return array_values(array_map(fn (array $call) => $call['method'].' '.$call['path'], $this->calls));
    }

    /** @return array<string, mixed>|null */
    public function bodyOf(string $operation): ?array
    {
        foreach (array_reverse($this->calls) as $call) {
            if ($call['path'] === $operation) {
                return $call['method'] === 'GET' ? $call['query'] : $call['body'];
            }
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    public function bucketNamed(string $name): ?array
    {
        foreach ($this->buckets as $bucket) {
            if ($bucket['bucketName'] === $name) {
                return $bucket;
            }
        }

        return null;
    }

    /** @return array{key: string, capabilities: list<string>, bucketIds: list<string>, keyName: string, namePrefix: string|null}|null the key ServerOS made last */
    public function madeKey(): ?array
    {
        $made = array_filter($this->keys, fn (array $key) => str_starts_with($key['keyName'], 'serveros-'));

        return $made === [] ? null : end($made);
    }

    public function handle(Request $request): PromiseInterface
    {
        $url = parse_url($request->url());
        $host = (string) ($url['host'] ?? '');
        $method = strtoupper($request->method());

        if (str_starts_with($host, 's3.') || str_contains($host, '.s3.')) {
            return $this->s3($request, $host, (string) ($url['path'] ?? ''));
        }

        $path = trim(str_replace('/b2api/v4', '', (string) ($url['path'] ?? '')), '/');
        parse_str((string) ($url['query'] ?? ''), $query);
        $body = $method === 'GET' ? [] : (array) $request->data();
        $this->calls[] = ['method' => $method, 'path' => $path, 'body' => $body, 'query' => array_map('strval', $query), 'host' => $host];

        if (($this->failures[$path] ?? []) !== [] && ($failure = array_shift($this->failures[$path])) !== null) {
            return Http::response($failure['body'] === [] ? self::error(503, 'service_unavailable', 'Service unavailable') : $failure['body'], $failure['status']);
        }

        if ($path === 'b2_authorize_account') {
            return $this->authorize($request, $host);
        }
        if ($host !== 'api004.backblazeb2.com') {
            return Http::response(self::error(400, 'bad_request', 'Use the apiUrl from b2_authorize_account'), 400);
        }
        $auth = (string) ($request->header('Authorization')[0] ?? '');
        $keyId = str_starts_with($auth, 'fake-b2-auth-') ? substr($auth, strlen('fake-b2-auth-')) : null;
        if ($keyId === null || ! isset($this->keys[$keyId])) {
            return Http::response(self::error(401, 'bad_auth_token', 'Invalid authorization token'), 401);
        }
        $data = [...$query, ...$body];

        return $this->route($path, $data, $this->keys[$keyId]);
    }

    private function authorize(Request $request, string $host): PromiseInterface
    {
        if ($host !== 'api.backblazeb2.com') {
            return Http::response(self::error(400, 'bad_request', 'Wrong host'), 400);
        }
        $auth = (string) ($request->header('Authorization')[0] ?? '');
        [$id, $key] = array_pad(explode(':', (string) base64_decode(substr($auth, 6)), 2), 2, '');
        if (! str_starts_with($auth, 'Basic ') || ! isset($this->keys[$id]) || $this->keys[$id]['key'] !== $key) {
            return Http::response(self::error(401, 'unauthorized', 'Invalid application key'), 401);
        }
        $k = $this->keys[$id];

        return Http::response([
            'accountId' => self::ACCOUNT,
            'apiInfo' => ['storageApi' => [
                'apiUrl' => 'https://api004.backblazeb2.com',
                'downloadUrl' => 'https://f004.backblazeb2.com',
                's3ApiUrl' => 'https://s3.'.self::REGION.'.backblazeb2.com',
                'absoluteMinimumPartSize' => 5000000,
                'recommendedPartSize' => 100000000,
                'allowed' => [
                    'buckets' => array_map(fn (string $b) => ['id' => $b, 'name' => $this->buckets[$b]['bucketName'] ?? null], $k['bucketIds']),
                    'capabilities' => $k['capabilities'],
                    'namePrefix' => $k['namePrefix'],
                ],
            ]],
            'applicationKeyExpirationTimestamp' => null,
            'authorizationToken' => 'fake-b2-auth-'.$id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array{key: string, capabilities: list<string>, bucketIds: list<string>, keyName: string, namePrefix: string|null}  $key
     */
    private function route(string $operation, array $data, array $key): PromiseInterface
    {
        $needs = [
            'b2_list_buckets' => 'listBuckets', 'b2_create_bucket' => 'writeBuckets', 'b2_update_bucket' => 'writeBuckets',
            'b2_create_key' => 'writeKeys', 'b2_delete_key' => 'deleteKeys', 'b2_list_file_versions' => 'listFiles',
        ][$operation] ?? null;
        if ($needs === null) {
            return Http::response(self::error(404, 'not_found', 'No such operation'), 404);
        }
        if (! in_array($needs, $key['capabilities'], true)) {
            return Http::response(self::error(401, 'unauthorized', "Key is missing the {$needs} capability"), 401);
        }

        return match ($operation) {
            'b2_list_buckets' => Http::response(['buckets' => array_values(array_filter(
                $this->buckets,
                fn (array $b) => ! isset($data['bucketName']) || $b['bucketName'] === $data['bucketName'],
            ))]),
            'b2_create_bucket' => $this->createBucket($data),
            'b2_update_bucket' => $this->updateBucket($data),
            'b2_create_key' => $this->createKey($data),
            'b2_delete_key' => $this->deleteKey($data),
            default => $this->listVersions($data),
        };
    }

    /** @param  array<string, mixed>  $data */
    private function createBucket(array $data): PromiseInterface
    {
        $name = (string) ($data['bucketName'] ?? '');
        if (strlen($name) < 6 || strlen($name) > 63 || preg_match('/^[A-Za-z0-9-]+$/', $name) !== 1 || str_starts_with($name, 'b2-')) {
            return Http::response(self::error(400, 'bad_request', 'Invalid bucket name'), 400);
        }
        if (in_array($name, $this->takenElsewhere, true) || $this->bucketNamed($name) !== null) {
            return Http::response(self::error(400, 'duplicate_bucket_name', 'Bucket name is already in use.'), 400);
        }
        $id = $this->bucket($name);
        $this->buckets[$id] = [...$this->buckets[$id], 'bucketType' => $data['bucketType'] ?? 'allPrivate', 'lifecycleRules' => $data['lifecycleRules'] ?? [], 'bucketInfo' => $data['bucketInfo'] ?? [], 'revision' => 1, 'options' => ['s3']];

        return Http::response($this->buckets[$id]);
    }

    /** @param  array<string, mixed>  $data */
    private function updateBucket(array $data): PromiseInterface
    {
        $id = (string) ($data['bucketId'] ?? '');
        if (! isset($this->buckets[$id])) {
            return Http::response(self::error(400, 'bad_bucket_id', 'Bucket does not exist'), 400);
        }
        if (array_key_exists('lifecycleRules', $data)) {
            $this->buckets[$id]['lifecycleRules'] = $data['lifecycleRules'];
        }

        return Http::response($this->buckets[$id]);
    }

    /** @param  array<string, mixed>  $data */
    private function createKey(array $data): PromiseInterface
    {
        foreach ((array) ($data['bucketIds'] ?? []) as $bucket) {
            if (! isset($this->buckets[$bucket])) {
                return Http::response(self::error(400, 'bad_bucket_id', 'The requested bucket ID does not match an existing bucket.'), 400);
            }
        }
        $id = '004fakeb2key'.$this->next++;
        $this->keys[$id] = [
            'key' => 'fake-b2-secret-'.$id,
            'capabilities' => array_values((array) ($data['capabilities'] ?? [])),
            'bucketIds' => array_values((array) ($data['bucketIds'] ?? [])),
            'keyName' => (string) ($data['keyName'] ?? ''),
            'namePrefix' => $data['namePrefix'] ?? null,
        ];

        return Http::response([
            'accountId' => self::ACCOUNT,
            'applicationKeyId' => $id,
            'applicationKey' => $this->keys[$id]['key'],
            'capabilities' => $this->keys[$id]['capabilities'],
            'keyName' => $this->keys[$id]['keyName'],
            'bucketIds' => $this->keys[$id]['bucketIds'],
            'options' => ['s3'],
        ]);
    }

    /** @param  array<string, mixed>  $data */
    private function deleteKey(array $data): PromiseInterface
    {
        $id = (string) ($data['applicationKeyId'] ?? '');
        if (! isset($this->keys[$id])) {
            return Http::response(self::error(400, 'bad_request', 'applicationKeyId not valid'), 400);
        }
        $deleted = $this->keys[$id];
        unset($this->keys[$id]);

        return Http::response(['applicationKeyId' => $id, 'keyName' => $deleted['keyName'], 'capabilities' => $deleted['capabilities'], 'accountId' => self::ACCOUNT]);
    }

    /** @param  array<string, mixed>  $data */
    private function listVersions(array $data): PromiseInterface
    {
        $id = (string) ($data['bucketId'] ?? '');
        if (! isset($this->buckets[$id])) {
            return Http::response(self::error(400, 'bad_bucket_id', 'Bucket does not exist'), 400);
        }
        $files = $this->files[$id];
        $start = 0;
        if (isset($data['startFileId'])) {
            foreach ($files as $i => $file) {
                if ($file['fileId'] === $data['startFileId']) {
                    $start = $i;
                }
            }
        }
        $count = max(1, min(10000, (int) ($data['maxFileCount'] ?? 100)));
        $page = array_slice($files, $start, $count);
        $next = $files[$start + $count] ?? null;

        return Http::response([
            'files' => array_map(fn (array $f) => [...$f, 'accountId' => self::ACCOUNT, 'bucketId' => $id], $page),
            'nextFileName' => $next['fileName'] ?? null,
            'nextFileId' => $next['fileId'] ?? null,
        ]);
    }

    private function s3(Request $request, string $host, string $path): PromiseInterface
    {
        $this->calls[] = ['method' => strtoupper($request->method()), 'path' => 's3:'.$host.$path, 'body' => [], 'query' => [], 'host' => $host];
        $bucket = str_contains($host, '.s3.') ? explode('.s3.', $host)[0] : explode('/', ltrim($path, '/'))[0];
        $auth = (string) ($request->header('Authorization')[0] ?? '');
        $keyId = preg_match('#Credential=([^/]+)/\d{8}/'.self::REGION.'/s3/aws4_request#', $auth, $m) === 1 ? $m[1] : null;
        $key = $keyId === null ? null : ($this->keys[$keyId] ?? null);
        $target = $this->bucketNamed($bucket);

        $code = match (true) {
            $target === null => 'NoSuchBucket',
            $key === null => 'InvalidAccessKeyId',
            ! in_array('writeFiles', $key['capabilities'], true) || ($key['bucketIds'] !== [] && ! in_array($target['bucketId'], $key['bucketIds'], true)) => 'AccessDenied',
            default => null,
        };
        if ($code !== null) {
            return Http::response("<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<Error><Code>{$code}</Code><Message>Nope</Message></Error>", $code === 'NoSuchBucket' ? 404 : 403);
        }
        $this->uploads[] = ['key' => (string) $keyId, 'bucket' => $bucket, 'path' => $path];

        return Http::response('', 200);
    }

    /** @return array{status: int, code: string, message: string} */
    public static function error(int $status, string $code, string $message): array
    {
        return ['status' => $status, 'code' => $code, 'message' => $message];
    }
}
