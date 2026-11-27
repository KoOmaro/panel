<?php

namespace App\Support\Integrations\N8n;

use App\Support\Integrations\IntegrationFailed;
use App\Support\Integrations\PinnedAddress;
use App\Support\Integrations\ProviderClient;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * n8n's public REST API (docs.n8n.io/api), on n8n Cloud or a self-hosted
 * instance, signed in with an API key in X-N8N-API-KEY. The instance's
 * address is pinned for every request (PinnedAddress).
 */
final class N8nClient extends ProviderClient
{
    private const int PAGE = 250;

    public function __construct(
        private readonly string $instance,
        #[\SensitiveParameter] private readonly string $key,
    ) {}

    /** The instance's address as it's kept, from what was typed (an /api/v1 on the end is dropped). */
    public static function normalise(?string $url): ?string
    {
        return PinnedAddress::normalise($url, '#/api/v1$#');
    }

    protected function baseUrl(): string
    {
        return $this->instance.'/api/v1';
    }

    protected function providerName(): string
    {
        return 'n8n';
    }

    protected function headers(): array
    {
        return ['X-N8N-API-KEY' => $this->key, 'User-Agent' => 'ServerOS'];
    }

    protected function providerMessage(Response $response): ?string
    {
        $message = $response->json('message');

        return is_string($message) && trim($message) !== '' ? trim($message) : null;
    }

    protected function failure(Response $response): ?IntegrationFailed
    {
        if ($response->status() === 401) {
            return new IntegrationFailed($this->t('n8n turned the API key down. Check it’s copied in full and hasn’t expired.'), IntegrationFailed::CREDENTIALS, 401);
        }
        if ($response->status() === 403) {
            return new IntegrationFailed($this->t('This n8n API key isn’t allowed to do that. Give it the workflow scopes ServerOS needs, or make one without scopes.'), IntegrationFailed::PERMISSION, 403);
        }

        return null;
    }

    /**
     * Every workflow, a page of 250 at a time, up to $pages pages.
     *
     * @return list<array<string, mixed>>
     */
    public function workflows(int $pages = 8): array
    {
        $items = [];
        $cursor = null;
        for ($page = 0; $page < $pages; $page++) {
            $body = $this->get('workflows', array_filter(['limit' => self::PAGE, 'excludePinnedData' => 'true', 'cursor' => $cursor]));
            foreach ((array) ($body['data'] ?? []) as $item) {
                if (is_array($item) && isset($item['id'])) {
                    $items[] = $item;
                }
            }
            $cursor = is_string($body['nextCursor'] ?? null) && $body['nextCursor'] !== '' ? $body['nextCursor'] : null;
            if ($cursor === null) {
                break;
            }
        }

        return $items;
    }

    protected function request(): PendingRequest
    {
        return parent::request()->withOptions(PinnedAddress::options($this->instance));
    }
}
