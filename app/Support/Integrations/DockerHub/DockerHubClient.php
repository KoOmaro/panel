<?php

namespace App\Support\Integrations\DockerHub;

use App\Support\Integrations\IntegrationFailed;
use App\Support\Integrations\ProviderClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The Docker Hub API (docs.docker.com/reference/api/hub/latest), signed in
 * with a username and personal access token. The token is swapped for an
 * access token at POST /v2/auth/token, which lasts ten minutes, so it's
 * kept encrypted in the cache for nine. Only the namespace-scoped
 * repository routes are used.
 */
final class DockerHubClient extends ProviderClient
{
    public const string URL = 'https://hub.docker.com/v2';

    private const int PAGE = 100;

    private ?string $accessToken = null;

    public function __construct(
        private readonly string $username,
        private readonly string $token,
    ) {}

    protected function baseUrl(): string
    {
        return self::URL;
    }

    protected function providerName(): string
    {
        return 'Docker Hub';
    }

    protected function headers(): array
    {
        return ['Authorization' => 'Bearer '.$this->accessToken()];
    }

    protected function providerMessage(Response $response): ?string
    {
        foreach (['message', 'detail'] as $key) {
            $said = $response->json($key);
            if (is_string($said) && $said !== '') {
                return $said;
            }
        }

        return null;
    }

    protected function failure(Response $response): ?IntegrationFailed
    {
        if ($response->status() === 401) {
            Cache::forget($this->cacheKey());
        }

        return match ($response->status()) {
            401 => new IntegrationFailed($this->t('Docker Hub turned the token down. Check it’s copied in full, still active, and belongs to this username.'), IntegrationFailed::CREDENTIALS, 401, 'token'),
            403 => new IntegrationFailed($this->t('Docker Hub says this token can’t read that. Give it Read-only access, from an account that can see the repository.'), IntegrationFailed::PERMISSION, 403),
            default => null,
        };
    }

    /**
     * How many repositories a namespace (the account, or an organization)
     * has that this token can see.
     *
     * @throws IntegrationFailed
     */
    public function count(string $namespace): int
    {
        return (int) ($this->get(self::namespace($namespace).'/repositories', ['page_size' => 1])['count'] ?? 0);
    }

    /**
     * A namespace's repositories, most recently pushed first.
     *
     * @return list<array<string, mixed>>
     *
     * @throws IntegrationFailed
     */
    public function repositories(string $namespace, int $pages = 5): array
    {
        return $this->all(self::namespace($namespace).'/repositories', ['ordering' => '-last_updated'], $pages);
    }

    /**
     * A repository's tags, most recently pushed first.
     *
     * @return list<array<string, mixed>>
     *
     * @throws IntegrationFailed
     */
    public function tags(string $namespace, string $repository, int $pages = 2): array
    {
        $tags = $this->all(self::namespace($namespace).'/repositories/'.rawurlencode($repository).'/tags', [], $pages);
        usort($tags, fn (array $a, array $b) => strcmp((string) ($b['tag_last_pushed'] ?? $b['last_updated'] ?? ''), (string) ($a['tag_last_pushed'] ?? $a['last_updated'] ?? '')));

        return $tags;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws IntegrationFailed
     */
    public function tag(string $namespace, string $repository, string $tag): array
    {
        return $this->get(self::namespace($namespace).'/repositories/'.rawurlencode($repository).'/tags/'.rawurlencode($tag));
    }

    /**
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     *
     * @throws IntegrationFailed
     */
    private function all(string $path, array $query, int $pages): array
    {
        $out = [];
        for ($page = 1; $page <= $pages; $page++) {
            $body = $this->get($path, [...$query, 'page' => $page, 'page_size' => self::PAGE]);
            foreach ((array) ($body['results'] ?? []) as $row) {
                if (is_array($row)) {
                    $out[] = $row;
                }
            }
            if (! is_string($body['next'] ?? null) || $body['next'] === '') {
                break;
            }
        }

        return $out;
    }

    private static function namespace(string $namespace): string
    {
        return 'namespaces/'.rawurlencode(strtolower($namespace));
    }

    private function cacheKey(): string
    {
        return 'integrations:docker-hub:token:'.hash_hmac('sha256', strtolower($this->username)."\n".$this->token, (string) config('app.key'));
    }

    /** @throws IntegrationFailed */
    public function accessToken(): string
    {
        if ($this->accessToken !== null) {
            return $this->accessToken;
        }

        $key = $this->cacheKey();
        $cached = Cache::get($key);
        if (is_string($cached)) {
            try {
                return $this->accessToken = Crypt::decryptString($cached);
            } catch (Throwable) {
                Cache::forget($key);
            }
        }

        try {
            $response = Http::acceptJson()->asJson()
                ->timeout(self::TIMEOUT)->connectTimeout(self::CONNECT_TIMEOUT)
                ->post(self::URL.'/auth/token', ['identifier' => $this->username, 'secret' => $this->token]);
        } catch (ConnectionException) {
            throw new IntegrationFailed($this->t(':provider couldn’t be reached. Try again in a few minutes.'), IntegrationFailed::UNAVAILABLE);
        }

        $token = $response->json('access_token');
        if (! $response->successful() || ! is_string($token) || $token === '') {
            throw match (true) {
                $response->status() === 429 => new IntegrationFailed($this->t(':provider is limiting requests from this account right now. Try again in a few minutes.'), IntegrationFailed::RATE_LIMITED, 429),
                $response->status() >= 500 => new IntegrationFailed($this->t(':provider isn’t answering properly right now. Try again in a few minutes.'), IntegrationFailed::UNAVAILABLE, $response->status()),
                default => new IntegrationFailed($this->t('Docker Hub turned the username and token down. Check the token is copied in full, still active, and made by this account.'), IntegrationFailed::CREDENTIALS, $response->status(), 'token'),
            };
        }
        Cache::put($key, Crypt::encryptString($token), now()->addMinutes(9));

        return $this->accessToken = $token;
    }
}
