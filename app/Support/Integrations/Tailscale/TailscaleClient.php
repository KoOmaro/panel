<?php

namespace App\Support\Integrations\Tailscale;

use App\Support\Integrations\IntegrationFailed;
use App\Support\Integrations\ProviderClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

/**
 * The Tailscale API v2 (tailscale.com/api), with an API access token or an
 * OAuth client (devices:core and auth_keys scopes). An OAuth client's
 * secret is swapped for an hour-long access token at /oauth/token, kept
 * encrypted in the cache until just before it runs out.
 */
final class TailscaleClient extends ProviderClient
{
    public const string URL = 'https://api.tailscale.com/api/v2';

    public const string DEFAULT_TAILNET = '-';

    private ?string $accessToken = null;

    public function __construct(
        private readonly string $token,
        private readonly string $tailnet = self::DEFAULT_TAILNET,
        private readonly ?string $clientId = null,
    ) {}

    public function oauth(): bool
    {
        return $this->clientId !== null && $this->clientId !== '';
    }

    protected function baseUrl(): string
    {
        return self::URL;
    }

    protected function providerName(): string
    {
        return 'Tailscale';
    }

    protected function headers(): array
    {
        return ['Authorization' => 'Bearer '.$this->accessToken()];
    }

    protected function providerMessage(Response $response): ?string
    {
        $message = $response->json('message');

        return is_string($message) ? $message : null;
    }

    protected function failure(Response $response): ?IntegrationFailed
    {
        $said = strtolower((string) $this->providerMessage($response));

        return match (true) {
            $response->status() === 400 && str_contains($said, 'tag') => new IntegrationFailed($this->t('Tailscale won’t make a key with those tags. Add each tag to tagOwners in your tailnet’s access controls, or change the tags ServerOS uses.'), IntegrationFailed::PERMISSION, 400),
            $response->status() === 403 => new IntegrationFailed($this->oauth()
                ? $this->t('Tailscale says this OAuth client isn’t allowed to do that. Give it the devices:core and auth_keys scopes, with the tags ServerOS uses.')
                : $this->t('Tailscale says this token isn’t allowed to do that. Make a new access token as an admin of the tailnet.'), IntegrationFailed::PERMISSION, 403),
            $response->status() === 404 && str_contains($said, 'tailnet') => new IntegrationFailed($this->t('Tailscale can’t find that tailnet. Use - for the token’s own tailnet, or the name in Settings › General.'), IntegrationFailed::NOT_FOUND, 404),
            default => null,
        };
    }

    /**
     * Every device on the tailnet, with all fields.
     *
     * @return list<array<string, mixed>>
     *
     * @throws IntegrationFailed
     */
    public function devices(): array
    {
        $devices = (array) ($this->get($this->tailnetPath('devices'), ['fields' => 'all'])['devices'] ?? []);

        return array_values(array_filter($devices, 'is_array'));
    }

    /**
     * A one-off, pre-authorized key for one device, that runs out in minutes.
     *
     * @param  list<string>  $tags
     * @return array{id: string, key: string, expires: string|null}
     *
     * @throws IntegrationFailed
     */
    public function createKey(array $tags, int $seconds, string $description): array
    {
        $create = ['reusable' => false, 'ephemeral' => false, 'preauthorized' => true];
        if ($tags !== []) {
            $create['tags'] = $tags;
        }
        $body = $this->post($this->tailnetPath('keys'), [
            'capabilities' => ['devices' => ['create' => $create]],
            'expirySeconds' => $seconds,
            'description' => self::description($description),
        ]);
        $id = $body['id'] ?? null;
        $key = $body['key'] ?? null;
        if (! is_string($id) || $id === '' || ! is_string($key) || $key === '') {
            throw new IntegrationFailed($this->t('Tailscale didn’t send a key back. Try again in a minute.'), IntegrationFailed::UNAVAILABLE);
        }

        return ['id' => $id, 'key' => $key, 'expires' => is_string($body['expires'] ?? null) ? $body['expires'] : null];
    }

    /** @throws IntegrationFailed */
    public function deleteKey(string $id): void
    {
        try {
            $this->delete($this->tailnetPath('keys/'.rawurlencode($id)));
        } catch (IntegrationFailed $e) {
            if ($e->reason !== IntegrationFailed::NOT_FOUND) {
                throw $e;
            }
        }
    }

    /** @throws IntegrationFailed */
    public function deleteDevice(string $id): void
    {
        try {
            $this->delete('device/'.rawurlencode($id));
        } catch (IntegrationFailed $e) {
            if ($e->reason !== IntegrationFailed::NOT_FOUND) {
                throw $e;
            }
        }
    }

    private function tailnetPath(string $path): string
    {
        $tailnet = trim($this->tailnet) === '' ? self::DEFAULT_TAILNET : trim($this->tailnet);

        return 'tailnet/'.rawurlencode($tailnet).'/'.$path;
    }

    /** At most 50 letters, digits, spaces and hyphens, as the API asks. */
    public static function description(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^A-Za-z0-9 -]+/', '-', $text)));

        return mb_substr($text === '' ? 'ServerOS' : $text, 0, 50);
    }

    /** @throws IntegrationFailed */
    private function accessToken(): string
    {
        if (! $this->oauth()) {
            return $this->token;
        }
        if ($this->accessToken !== null) {
            return $this->accessToken;
        }

        $key = 'integrations:tailscale:oauth:'.hash_hmac('sha256', $this->clientId."\n".$this->token, (string) config('app.key'));
        $cached = Cache::get($key);
        if (is_string($cached)) {
            try {
                return $this->accessToken = Crypt::decryptString($cached);
            } catch (\Throwable) {
                Cache::forget($key);
            }
        }

        try {
            $response = Http::asForm()->acceptJson()
                ->timeout(self::TIMEOUT)->connectTimeout(self::CONNECT_TIMEOUT)
                ->post(self::URL.'/oauth/token', [
                    'client_id' => $this->clientId,
                    'client_secret' => $this->token,
                    'grant_type' => 'client_credentials',
                ]);
        } catch (ConnectionException) {
            throw new IntegrationFailed($this->t(':provider couldn’t be reached. Try again in a few minutes.'), IntegrationFailed::UNAVAILABLE);
        }

        $token = $response->json('access_token');
        if (! $response->successful() || ! is_string($token) || $token === '') {
            throw match (true) {
                $response->status() >= 500 => new IntegrationFailed($this->t(':provider isn’t answering properly right now. Try again in a few minutes.'), IntegrationFailed::UNAVAILABLE, $response->status()),
                default => new IntegrationFailed($this->t('Tailscale turned the OAuth client down. Check the client ID and secret are copied in full and the client hasn’t been deleted.'), IntegrationFailed::CREDENTIALS, $response->status()),
            };
        }

        $seconds = (int) ($response->json('expires_in') ?? 3600);
        Cache::put($key, Crypt::encryptString($token), now()->addSeconds(max(60, $seconds - 120)));

        return $this->accessToken = $token;
    }
}
