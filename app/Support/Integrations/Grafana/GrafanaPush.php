<?php

namespace App\Support\Integrations\Grafana;

use App\Models\Activity;
use App\Models\Integration;
use App\Support\Integrations\IntegrationFailed;
use App\Support\Integrations\PinnedAddress;
use App\Support\Metrics\WorkspaceMetrics;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Sends the workspace's metrics to an OTLP/HTTP endpoint every minute:
 * Grafana Cloud's OTLP gateway (its instance ID and an access policy token
 * with metrics:write, as Basic auth), or any OTLP endpoint that takes Basic
 * auth. The token is kept with the account's credentials; the endpoint,
 * instance ID and how the last push went are in its settings.
 *
 * @phpstan-type Push array{endpoint: string, instance: string, at: string, lastAt: string|null, lastCount: int|null, error: string|null, failing: bool}
 */
final class GrafanaPush
{
    public const int TIMEOUT = 15;

    public function __construct(private readonly WorkspaceMetrics $metrics) {}

    /** The endpoint as it's kept: the OTLP base, without /v1/metrics. */
    public static function normalise(?string $url): ?string
    {
        return PinnedAddress::normalise($url, '#/v1/metrics$#');
    }

    /** @return Push|null */
    public static function of(Integration $integration): ?array
    {
        $push = $integration->setting('push');
        if (! is_array($push) || ! is_string($push['endpoint'] ?? null) || $integration->credential('otlp_token') === '') {
            return null;
        }

        return [
            'endpoint' => $push['endpoint'],
            'instance' => (string) ($push['instance'] ?? ''),
            'at' => (string) ($push['at'] ?? ''),
            'lastAt' => is_string($push['lastAt'] ?? null) ? $push['lastAt'] : null,
            'lastCount' => isset($push['lastCount']) ? (int) $push['lastCount'] : null,
            'error' => is_string($push['error'] ?? null) ? $push['error'] : null,
            'failing' => (bool) ($push['failing'] ?? false),
        ];
    }

    /**
     * Saves where to send metrics and sends them once now, so a wrong
     * address or token is said straight away. An empty token keeps the one
     * kept already.
     *
     * @throws IntegrationFailed
     */
    public function configure(Integration $integration, string $endpoint, string $instance, ?string $token): void
    {
        $base = self::normalise($endpoint) ?? throw new IntegrationFailed(self::t('That isn’t an OTLP address. Use the https:// one from your stack’s OpenTelemetry page, like https://otlp-gateway-prod-eu-west-2.grafana.net/otlp.'), field: 'endpoint');
        $token = $token === null || trim($token) === '' ? $integration->credential('otlp_token') : trim($token);
        if ($token === '') {
            throw new IntegrationFailed(self::t('Paste an access policy token with the metrics:write scope.'), field: 'token');
        }
        $was = self::of($integration);

        $sent = $this->send($integration, $base, trim($instance), $token);

        $integration->forceFill([
            'credentials' => [...$integration->credentials, 'otlp_token' => $token],
            'settings' => [...($integration->settings ?? []), 'push' => [
                'endpoint' => $base,
                'instance' => trim($instance),
                'at' => now()->toIso8601String(),
                'lastAt' => now()->toIso8601String(),
                'lastCount' => $sent,
                'error' => null,
                'failing' => false,
            ]],
        ])->save();
        Activity::record($integration->workspace, $was === null ? 'integration.grafana_push' : 'integration.grafana_push_update', $integration->label, meta: ['host' => PinnedAddress::host($base)]);
    }

    public function stop(Integration $integration): void
    {
        $settings = $integration->settings ?? [];
        unset($settings['push']);
        $credentials = $integration->credentials;
        unset($credentials['otlp_token']);
        $integration->forceFill(['settings' => $settings, 'credentials' => $credentials])->save();
    }

    /**
     * One push, from the scheduler. A refused token stops the pushes until
     * someone saves the settings again; anything else is tried next minute.
     */
    public function push(Integration $integration): void
    {
        $push = self::of($integration);
        if ($push === null || $push['failing'] || ! $integration->workspace->isActive()) {
            return;
        }

        try {
            $sent = $this->send($integration, $push['endpoint'], $push['instance'], $integration->credential('otlp_token'));
            $state = ['lastAt' => now()->toIso8601String(), 'lastCount' => $sent, 'error' => null, 'failing' => false];
        } catch (IntegrationFailed $e) {
            $state = ['error' => $e->getMessage(), 'failing' => $e->refusedCredentials()];
        }

        $integration->refresh();
        $current = $integration->setting('push');
        if (is_array($current) && ($current['endpoint'] ?? null) === $push['endpoint']) {
            $integration->forceFill(['settings' => [...($integration->settings ?? []), 'push' => [...$current, ...$state]]])->save();
        }
    }

    /**
     * Sends the metrics as they are now, and says how many points went.
     *
     * @throws IntegrationFailed
     */
    private function send(Integration $integration, string $base, string $instance, string $token): int
    {
        $workspace = $integration->workspace;
        $series = $this->metrics->collect($workspace);
        $url = $base.'/v1/metrics';
        $options = PinnedAddress::options($url, 'endpoint');

        try {
            $response = Http::timeout(self::TIMEOUT)->connectTimeout(5)
                ->withOptions($options)
                ->withBasicAuth($instance, $token)
                ->withHeaders(['User-Agent' => 'ServerOS'])
                ->acceptJson()
                ->withBody((string) json_encode(WorkspaceMetrics::otlp($workspace, $series, now()->getTimestamp()), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'application/json')
                ->post($url);
        } catch (ConnectionException) {
            throw new IntegrationFailed(self::t(':host couldn’t be reached. Check the OTLP address.', ['host' => PinnedAddress::host($base)]), IntegrationFailed::UNAVAILABLE, null, 'endpoint');
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw new IntegrationFailed(self::t('The OTLP endpoint turned the instance ID and token down. Check the instance ID, and that the token has the metrics:write scope.'), IntegrationFailed::CREDENTIALS, $response->status(), 'token');
        }
        if ($response->status() === 404) {
            throw new IntegrationFailed(self::t('Nothing takes metrics at :url. Use the OTLP address from your stack’s OpenTelemetry page, ending in /otlp.', ['url' => $url]), IntegrationFailed::NOT_FOUND, 404, 'endpoint');
        }
        if (! $response->successful()) {
            $said = self::said($response);

            throw new IntegrationFailed($said === null
                ? self::t('The OTLP endpoint answered :status.', ['status' => $response->status()])
                : self::t('The OTLP endpoint answered :status: :message', ['status' => $response->status(), 'message' => $said]), $response->status() >= 500 ? IntegrationFailed::UNAVAILABLE : IntegrationFailed::INVALID, $response->status());
        }

        $rejected = (int) $response->json('partialSuccess.rejectedDataPoints', 0);
        if ($rejected > 0) {
            throw new IntegrationFailed(self::t('The OTLP endpoint turned :count points away: :message', ['count' => $rejected, 'message' => (string) $response->json('partialSuccess.errorMessage', '')]), IntegrationFailed::INVALID, 200);
        }

        return WorkspaceMetrics::count($series);
    }

    private static function said(Response $response): ?string
    {
        $message = $response->json('message');
        if (! is_string($message) || trim($message) === '') {
            $message = mb_substr(trim($response->body()), 0, 200);
        }

        return $message === '' ? null : rtrim($message, '.').'.';
    }

    /** @param  array<string, string|int>  $replace */
    private static function t(string $text, array $replace = []): string
    {
        return is_string($line = __($text, $replace)) ? $line : $text;
    }
}
