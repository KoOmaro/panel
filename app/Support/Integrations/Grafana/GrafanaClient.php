<?php

namespace App\Support\Integrations\Grafana;

use App\Support\Integrations\IntegrationFailed;
use App\Support\Integrations\PinnedAddress;
use App\Support\Integrations\ProviderClient;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * Grafana's HTTP API (grafana.com/docs/grafana/latest/developers/http_api),
 * on Grafana Cloud or self-hosted, signed in with a service account token.
 * The address is pinned for every request (PinnedAddress).
 */
final class GrafanaClient extends ProviderClient
{
    public function __construct(
        private readonly string $address,
        #[\SensitiveParameter] private readonly string $token,
    ) {}

    /** The address as it's kept, from what was typed (an /api on the end is dropped). */
    public static function normalise(?string $url): ?string
    {
        return PinnedAddress::normalise($url, '#/api$#');
    }

    public static function cloud(string $address): bool
    {
        return str_ends_with(PinnedAddress::host($address), '.grafana.net');
    }

    protected function baseUrl(): string
    {
        return $this->address.'/api';
    }

    protected function providerName(): string
    {
        return 'Grafana';
    }

    protected function headers(): array
    {
        return $this->token === '' ? ['User-Agent' => 'ServerOS'] : ['Authorization' => 'Bearer '.$this->token, 'User-Agent' => 'ServerOS'];
    }

    protected function providerMessage(Response $response): ?string
    {
        $message = $response->json('message');

        return is_string($message) && trim($message) !== '' ? trim($message) : null;
    }

    protected function failure(Response $response): ?IntegrationFailed
    {
        if ($response->status() === 403) {
            return new IntegrationFailed($this->t('Grafana says this token isn’t allowed to do that. Give its service account the Editor role (Admin to list data sources).'), IntegrationFailed::PERMISSION, 403);
        }
        if ($response->status() === 412) {
            return new IntegrationFailed($this->t('Grafana has another dashboard by that name in the folder. Rename it there, then try again.'), IntegrationFailed::CONFLICT, 412);
        }

        return null;
    }

    protected function request(): PendingRequest
    {
        return parent::request()->withOptions(PinnedAddress::options($this->address));
    }
}
