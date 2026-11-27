<?php

namespace App\Support\Integrations\Backblaze;

use App\Support\Integrations\IntegrationFailed;
use App\Support\Integrations\ProviderClient;
use Illuminate\Http\Client\Response;

/**
 * The B2 Native API v4 (backblaze.com/apidocs). b2_authorize_account takes
 * the key ID and application key as Basic auth and answers with the API
 * address and a token for the calls after it; the token lasts a day, and a
 * client is made per request, so it never runs out while in use.
 *
 * @phpstan-type Authorized array{accountId: string, apiUrl: string, token: string, s3ApiUrl: string, capabilities: list<string>, buckets: list<string>, namePrefix: string|null}
 */
final class B2Client extends ProviderClient
{
    public const string URL = 'https://api.backblazeb2.com';

    /** @var Authorized|null */
    private ?array $auth = null;

    public function __construct(private readonly string $keyId, private readonly string $key) {}

    protected function baseUrl(): string
    {
        return ($this->auth['apiUrl'] ?? self::URL).'/b2api/v4';
    }

    protected function providerName(): string
    {
        return 'Backblaze';
    }

    protected function headers(): array
    {
        return ['Authorization' => $this->auth === null
            ? 'Basic '.base64_encode($this->keyId.':'.$this->key)
            : $this->auth['token']];
    }

    protected function providerMessage(Response $response): ?string
    {
        $message = $response->json('message');

        return is_string($message) ? $message : null;
    }

    protected function failure(Response $response): ?IntegrationFailed
    {
        $code = (string) $response->json('code', '');
        $status = $response->status();

        return match (true) {
            $this->auth === null && $status === 401 => new IntegrationFailed($this->t('Backblaze turned the key down. Check the key ID and the application key are copied in full and the key hasn’t been deleted.'), IntegrationFailed::CREDENTIALS, $status),
            in_array($code, ['bad_auth_token', 'expired_auth_token'], true) => new IntegrationFailed($this->t('Backblaze turned the key down. Check it hasn’t been deleted.'), IntegrationFailed::CREDENTIALS, $status),
            $code === 'unauthorized' => new IntegrationFailed($this->t('Backblaze says this key isn’t allowed to do that. Use the master key, or a key for all buckets with listBuckets, writeBuckets, writeKeys, deleteKeys and listFiles.'), IntegrationFailed::PERMISSION, $status),
            $code === 'duplicate_bucket_name' => new IntegrationFailed($this->t('That bucket name is taken at Backblaze.'), IntegrationFailed::CONFLICT, $status),
            $code === 'too_many_buckets' => new IntegrationFailed($this->t('This Backblaze account already has the most buckets it can (100). Delete one there first.'), IntegrationFailed::INVALID, $status),
            $code === 'transaction_cap_exceeded' => new IntegrationFailed($this->t('Backblaze stopped this account’s calls for today because it reached its daily cap. Raise the cap in Caps & Alerts at Backblaze.'), IntegrationFailed::RATE_LIMITED, $status),
            default => null,
        };
    }

    /**
     * Signs in and keeps what Backblaze said for the calls after it.
     *
     * @return Authorized
     *
     * @throws IntegrationFailed
     */
    public function authorize(): array
    {
        if ($this->auth !== null) {
            return $this->auth;
        }

        $body = $this->get('b2_authorize_account');
        $storage = (array) ($body['apiInfo']['storageApi'] ?? []);
        $allowed = (array) ($storage['allowed'] ?? []);
        if (! is_string($body['authorizationToken'] ?? null) || ! is_string($storage['apiUrl'] ?? null)) {
            throw new IntegrationFailed($this->t('Backblaze answered in a way ServerOS didn’t expect. Try again in a few minutes.'), IntegrationFailed::UNAVAILABLE);
        }

        return $this->auth = [
            'accountId' => (string) ($body['accountId'] ?? ''),
            'apiUrl' => rtrim($storage['apiUrl'], '/'),
            'token' => $body['authorizationToken'],
            's3ApiUrl' => (string) ($storage['s3ApiUrl'] ?? ''),
            'capabilities' => array_values(array_filter((array) ($allowed['capabilities'] ?? []), 'is_string')),
            'buckets' => array_values(array_filter(array_map(
                fn ($bucket) => is_array($bucket) ? (string) ($bucket['name'] ?? $bucket['id'] ?? '') : '',
                (array) ($allowed['buckets'] ?? []),
            ))),
            'namePrefix' => is_string($allowed['namePrefix'] ?? null) && $allowed['namePrefix'] !== '' ? $allowed['namePrefix'] : null,
        ];
    }

    public function accountId(): string
    {
        return $this->authorize()['accountId'];
    }

    /** The S3 endpoint host, like s3.us-west-004.backblazeb2.com. */
    public function s3Host(): string
    {
        return strtolower((string) parse_url($this->authorize()['s3ApiUrl'], PHP_URL_HOST));
    }

    public function region(): string
    {
        return preg_match('/^s3\.([a-z0-9-]+)\.backblazeb2\.com$/', $this->s3Host(), $m) === 1 ? $m[1] : '';
    }

    /**
     * A read, sent as a GET with its fields in the query (so it's tried again when Backblaze is busy).
     *
     * @param  array<string, string|int>  $query
     * @return array<string, mixed>
     *
     * @throws IntegrationFailed
     */
    public function read(string $operation, array $query = []): array
    {
        $this->authorize();

        return $this->get($operation, $query);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     *
     * @throws IntegrationFailed
     */
    public function call(string $operation, array $body): array
    {
        $this->authorize();

        return $this->post($operation, $body);
    }
}
