<?php

namespace App\Support\Integrations\Teams;

use App\Support\Integrations\IntegrationFailed;
use App\Support\Integrations\ProviderClient;
use Illuminate\Http\Client\Response;

/**
 * A Teams channel's Workflows webhook ("When a Teams webhook request is
 * received", learn.microsoft.com/connectors/teams). It takes a message with
 * Adaptive Card attachments and answers 202 with nothing to follow: there's
 * no message id, so a card can't be changed or replied to afterwards.
 */
final class TeamsClient extends ProviderClient
{
    /** Where Power Automate's HTTP triggers live: the older logic.azure.com, and api.powerplatform.com since late 2025. */
    public const array HOSTS = ['logic.azure.com', 'api.powerplatform.com'];

    /** Office 365 connector webhooks, retired in May 2026. */
    private const array RETIRED = ['webhook.office.com', 'outlook.office.com', 'outlook.office365.com'];

    private string $base;

    private string $path;

    public function __construct(string $url)
    {
        $parts = self::check($url);
        $this->base = 'https://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        $this->path = $parts['path'].'?'.$parts['query'];
    }

    protected function baseUrl(): string
    {
        return $this->base;
    }

    protected function providerName(): string
    {
        return 'Teams';
    }

    protected function headers(): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $message
     *
     * @throws IntegrationFailed
     */
    public function postCard(array $message): void
    {
        $this->post($this->path, $message);
    }

    /**
     * The webhook address taken apart, once it's one a Teams workflow made.
     *
     * @return array{host: string, port?: int, path: string, query: string}
     *
     * @throws IntegrationFailed
     */
    public static function check(string $url): array
    {
        $t = fn (string $text): string => is_string($line = __($text)) ? $line : $text;
        $parts = parse_url(trim($url));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $ends = fn (array $domains): bool => collect($domains)->contains(fn (string $d) => $host === $d || str_ends_with($host, '.'.$d));

        if ($host !== '' && $ends(self::RETIRED)) {
            throw new IntegrationFailed($t('That’s an Office 365 connector webhook, and Teams retired those in May 2026. In the channel, open Workflows, pick “Send webhook alerts to a channel” and paste the link it makes.'), IntegrationFailed::CREDENTIALS, null, 'webhook_url');
        }
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || ! $ends(self::HOSTS) || isset($parts['user']) || ! is_string($parts['path'] ?? null)) {
            throw new IntegrationFailed($t('That isn’t a Teams workflow’s webhook link. It starts with https:// and ends in logic.azure.com or api.powerplatform.com, followed by the workflow’s path.'), IntegrationFailed::CREDENTIALS, null, 'webhook_url');
        }
        parse_str((string) ($parts['query'] ?? ''), $query);
        if (! is_string($query['sig'] ?? null) || $query['sig'] === '') {
            throw new IntegrationFailed($t('The link has no signature, so only people in your Microsoft tenant can call it. In the workflow’s trigger, set who can trigger it to Anyone, save, and copy the link again.'), IntegrationFailed::CREDENTIALS, null, 'webhook_url');
        }

        return array_filter([
            'host' => $host,
            'port' => isset($parts['port']) ? (int) $parts['port'] : null,
            'path' => $parts['path'],
            'query' => (string) ($parts['query'] ?? ''),
        ], fn ($v) => $v !== null);
    }

    protected function failure(Response $response): ?IntegrationFailed
    {
        return match ($response->status()) {
            401, 403 => new IntegrationFailed($this->t('The workflow turned the card down. Check who can trigger it is set to Anyone, and that the workflow is on.'), IntegrationFailed::CREDENTIALS, $response->status()),
            404 => new IntegrationFailed($this->t('The workflow isn’t there any more. Make it again in the channel’s Workflows, then connect the new link.'), IntegrationFailed::CREDENTIALS, 404),
            400 => new IntegrationFailed($this->t('The workflow didn’t take the card. If you changed the workflow, make it again from “Send webhook alerts to a channel”.'), IntegrationFailed::INVALID, 400),
            413 => new IntegrationFailed($this->t('The card was too big for Teams.'), IntegrationFailed::INVALID, 413),
            default => null,
        };
    }

    protected function providerMessage(Response $response): ?string
    {
        $message = $response->json('error.message');

        return is_string($message) ? $message : null;
    }
}
