<?php

namespace App\Support\Integrations\R2;

use App\Support\Integrations\IntegrationFailed;
use App\Support\Integrations\ProviderClient;
use Illuminate\Http\Client\Response;

/**
 * The Cloudflare API v4's R2, token and GraphQL Analytics endpoints, with an
 * API token. A bucket in a jurisdiction (eu) is only found with the
 * cf-r2-jurisdiction header, so calls about one name it.
 */
final class R2Client extends ProviderClient
{
    public const string URL = 'https://api.cloudflare.com/client/v4';

    private const int PAGE = 50;

    private const int MAX_PAGES = 20;

    public function __construct(private readonly string $token) {}

    protected function baseUrl(): string
    {
        return self::URL;
    }

    protected function providerName(): string
    {
        return 'Cloudflare';
    }

    protected function headers(): array
    {
        return ['Authorization' => 'Bearer '.$this->token];
    }

    protected function providerMessage(Response $response): ?string
    {
        $messages = array_filter(array_map(
            fn ($error) => is_array($error) && is_string($error['message'] ?? null) ? $error['message'] : null,
            (array) $response->json('errors', []),
        ));

        return $messages === [] ? null : implode(' ', $messages);
    }

    protected function failure(Response $response): ?IntegrationFailed
    {
        $codes = self::codes((array) $response->json('errors', []));
        $status = $response->status();

        return match (true) {
            array_intersect($codes, [1000, 6003, 6111, 9106]) !== [] => new IntegrationFailed($this->t(':provider turned the token down. Check it’s copied in full and hasn’t been deleted.'), IntegrationFailed::CREDENTIALS, $status),
            array_intersect($codes, [10042]) !== [] => new IntegrationFailed($this->t('R2 isn’t turned on for this Cloudflare account yet. Open R2 in the Cloudflare dashboard and turn it on first.'), IntegrationFailed::PERMISSION, $status),
            array_intersect($codes, [10004]) !== [] || $status === 409 => new IntegrationFailed($this->t('That bucket name is taken in this account.'), IntegrationFailed::CONFLICT, $status),
            array_intersect($codes, [9109, 10000]) !== [] || $status === 403 => new IntegrationFailed($this->t('Cloudflare says this token isn’t allowed to do that. Give it Account › Workers R2 Storage › Edit.'), IntegrationFailed::PERMISSION, $status),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    protected function send(string $method, string $path, array $options): array
    {
        $body = parent::send($method, $path, $options);
        if (($body['success'] ?? true) === false) {
            $codes = self::codes((array) ($body['errors'] ?? []));
            $said = implode(' ', array_filter(array_map(fn ($e) => is_array($e) ? (string) ($e['message'] ?? '') : '', (array) ($body['errors'] ?? []))));

            throw in_array(10004, $codes, true)
                ? new IntegrationFailed($this->t('That bucket name is taken in this account.'), IntegrationFailed::CONFLICT)
                : new IntegrationFailed($said !== '' ? $said : $this->t(':provider turned the request down.'));
        }

        return $body;
    }

    /**
     * A call about one bucket, in its jurisdiction.
     *
     * @param  array<string, mixed>|null  $body
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     *
     * @throws IntegrationFailed
     */
    public function inJurisdiction(string $method, string $path, ?string $jurisdiction, ?array $body = null, array $query = []): array
    {
        $options = [];
        if ($jurisdiction !== null && $jurisdiction !== 'default') {
            $options['headers'] = ['cf-r2-jurisdiction' => $jurisdiction];
        }
        if ($body !== null) {
            $options['json'] = $body;
        }
        if ($query !== []) {
            $options['query'] = $query;
        }

        return $this->send($method, $path, $options);
    }

    /**
     * A GraphQL Analytics query. Its errors come back in a 200, so they're turned into IntegrationFailed here.
     *
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     *
     * @throws IntegrationFailed
     */
    public function graphql(string $query, array $variables): array
    {
        $body = $this->post('graphql', ['query' => $query, 'variables' => $variables]);
        $errors = array_values(array_filter((array) ($body['errors'] ?? [])));
        if ($errors !== []) {
            $said = strtolower(implode(' ', array_map(fn ($e) => is_array($e) ? (string) ($e['message'] ?? '') : '', $errors)));

            throw new IntegrationFailed(str_contains($said, 'authoriz') || str_contains($said, 'permission') || str_contains($said, 'access')
                ? $this->t('Give the token Account › Account Analytics › Read to see how much is stored here.')
                : $this->t('Cloudflare couldn’t say how much is stored right now.'), IntegrationFailed::PERMISSION);
        }

        return (array) ($body['data'] ?? []);
    }

    /**
     * Every page of a list, up to a thousand items.
     *
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     */
    public function all(string $path, array $query = []): array
    {
        $items = [];

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $body = $this->get($path, [...$query, 'page' => $page, 'per_page' => self::PAGE]);
            foreach ((array) ($body['result'] ?? []) as $item) {
                if (is_array($item)) {
                    $items[] = $item;
                }
            }
            $pages = $body['result_info']['total_pages'] ?? null;
            if (! is_int($pages) || $page >= $pages) {
                break;
            }
        }

        return $items;
    }

    /**
     * @param  array<int|string, mixed>  $errors
     * @return list<int>
     */
    private static function codes(array $errors): array
    {
        return array_values(array_map(fn ($error) => is_array($error) ? (int) ($error['code'] ?? 0) : 0, $errors));
    }
}
