<?php

namespace App\Support\Integrations\Grafana;

use App\Http\Controllers\MetricsController;
use App\Models\Integration;
use App\Models\McpToken;
use App\Support\Integrations\Capability;
use App\Support\Integrations\ConnectedAccount;
use App\Support\Integrations\ConnectField;
use App\Support\Integrations\HasAccountPage;
use App\Support\Integrations\IntegrationFailed;
use App\Support\Integrations\PinnedAddress;
use App\Support\Integrations\Provider;
use App\Support\Metrics\MetricsTokens;
use App\Support\Metrics\WorkspaceMetrics;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Grafana, on Grafana Cloud or self-hosted. ServerOS gets the workspace's
 * metrics into a Prometheus that Grafana reads, either by sending them to
 * Grafana Cloud's OTLP gateway every minute (GrafanaPush) or by letting your
 * own Prometheus scrape /api/metrics with a metrics token, and makes a
 * dashboard of them through the Grafana API (GrafanaDashboards).
 */
final class GrafanaProvider extends Provider implements HasAccountPage
{
    public function key(): string
    {
        return 'grafana';
    }

    public function name(): string
    {
        return 'Grafana';
    }

    public function fields(): array
    {
        return [
            new ConnectField(
                key: 'url',
                label: self::t('Grafana address'),
                secret: false,
                help: self::t('The address you open Grafana at, like https://acme.grafana.net or your own.'),
                placeholder: 'https://acme.grafana.net',
                rules: ['required', 'string', 'max:200'],
            ),
            new ConnectField(
                key: 'token',
                label: self::t('Service account token'),
                help: self::t('In Grafana, open Administration › Users and access › Service accounts, add one with the Editor role, and add a token. Give it Admin if you want ServerOS to list your data sources.'),
                linkLabel: self::t('How service accounts work'),
                link: 'https://grafana.com/docs/grafana/latest/administration/service-accounts/',
                placeholder: self::t('Paste the token'),
                rules: ['required', 'string', 'min:20', 'max:500', 'regex:/^\S+$/'],
            ),
        ];
    }

    public function capabilities(): array
    {
        return [Capability::Metrics, Capability::Dashboards];
    }

    public function verify(array $credentials): ConnectedAccount
    {
        $address = GrafanaClient::normalise($credentials['url'] ?? null)
            ?? throw new IntegrationFailed(self::t('That isn’t a Grafana address. Use the https:// address you open Grafana at.'), field: 'url');
        $client = new GrafanaClient($address, (string) ($credentials['token'] ?? ''));

        $health = $client->get('health');
        $version = $health['version'] ?? null;
        if (! is_string($version) || $version === '') {
            throw new IntegrationFailed(self::t('That address answered, but not the way Grafana does. Check it’s your Grafana’s address.'), field: 'url');
        }
        $org = $client->get('org');
        $name = is_string($org['name'] ?? null) && $org['name'] !== '' ? $org['name'] : self::t('Main Org.');

        return new ConnectedAccount(
            GrafanaClient::cloud($address) ? PinnedAddress::host($address) : self::t(':org on :host', ['org' => $name, 'host' => PinnedAddress::host($address)]),
            ['org' => $name, 'version' => $version, 'cloud' => GrafanaClient::cloud($address), 'address' => $address],
            ['url' => $address],
        );
    }

    public function summary(Integration $integration): string
    {
        return self::t(':org on :host', ['org' => (string) ($integration->meta['org'] ?? ''), 'host' => PinnedAddress::host(GrafanaDashboards::address($integration))]);
    }

    public function facts(Integration $integration): array
    {
        $meta = $integration->meta ?? [];

        return [
            ['label' => self::t('Address'), 'value' => PinnedAddress::host(GrafanaDashboards::address($integration))],
            ['label' => self::t('Organization'), 'value' => (string) ($meta['org'] ?? '')],
            ['label' => self::t('Version'), 'value' => (string) ($meta['version'] ?? '')],
        ];
    }

    public function usage(Integration $integration): array
    {
        $out = [];
        $push = GrafanaPush::of($integration);
        $out[] = match (true) {
            $push === null => self::t('ServerOS doesn’t send it metrics. Send them to Grafana Cloud, or let your Prometheus scrape them, from this page.'),
            $push['failing'] => self::t('Sending metrics to :host stopped: :error', ['host' => PinnedAddress::host($push['endpoint']), 'error' => (string) $push['error']]),
            $push['lastAt'] !== null => self::t('ServerOS sends :count metrics to :host every minute, last :when.', ['count' => (int) $push['lastCount'], 'host' => PinnedAddress::host($push['endpoint']), 'when' => CarbonImmutable::parse($push['lastAt'])->diffForHumans()]),
            default => self::t('ServerOS sends metrics to :host every minute.', ['host' => PinnedAddress::host($push['endpoint'])]),
        };
        $tokens = $this->tokens($integration)->count();
        if ($tokens > 0) {
            $out[] = $tokens === 1
                ? self::t('1 metrics token lets a Prometheus scrape ServerOS.')
                : self::t(':count metrics tokens let a Prometheus scrape ServerOS.', ['count' => $tokens]);
        }
        $dashboard = $integration->setting('dashboard');
        if (is_array($dashboard)) {
            $out[] = self::t('The dashboard of this workspace reads :source, updated :when.', [
                'source' => (string) ($dashboard['datasource']['name'] ?? ''),
                'when' => CarbonImmutable::parse((string) ($dashboard['at'] ?? now()))->diffForHumans(),
            ]);
        }

        return $out;
    }

    public function dependents(Integration $integration): array
    {
        $out = [];
        if (GrafanaPush::of($integration) !== null) {
            $out[] = self::t('ServerOS stops sending metrics, so the dashboard stops getting new data from it.');
        }
        $tokens = $this->tokens($integration)->count();
        if ($tokens > 0) {
            $out[] = $tokens === 1
                ? self::t('The metrics token made here is revoked, so Prometheus can’t scrape ServerOS with it.')
                : self::t('The :count metrics tokens made here are revoked, so Prometheus can’t scrape ServerOS with them.', ['count' => $tokens]);
        }

        return $out;
    }

    public function disconnect(Integration $integration): ?string
    {
        $tokens = $this->tokens($integration);
        $tokens->each(fn (McpToken $token) => $token->revoke());
        $dashboard = is_array($integration->setting('dashboard'));

        return match (true) {
            $tokens->isNotEmpty() => self::t('Disconnected :name and revoked its metrics tokens.', ['name' => $integration->label]).($dashboard ? ' '.self::t('The dashboard stays in Grafana.') : ''),
            $dashboard => self::t('Disconnected :name. The dashboard stays in Grafana.', ['name' => $integration->label]),
            default => null,
        };
    }

    /** @return Collection<int, McpToken> */
    public function tokens(Integration $integration): Collection
    {
        return MetricsTokens::active($integration->workspace, array_values(array_map('intval', (array) $integration->setting('tokens', []))));
    }

    public function accountPage(Integration $integration): array
    {
        $address = GrafanaDashboards::address($integration);
        $error = null;
        $sources = [];
        try {
            $sources = app(GrafanaDashboards::class)->dataSources($integration);
        } catch (IntegrationFailed $e) {
            $error = $e->getMessage();
        }

        $dashboard = $integration->setting('dashboard');
        $push = GrafanaPush::of($integration);
        $app = (string) config('app.url');
        $scheme = (string) (parse_url($app, PHP_URL_SCHEME) ?: 'https');
        $host = (string) parse_url($app, PHP_URL_HOST).(parse_url($app, PHP_URL_PORT) ? ':'.parse_url($app, PHP_URL_PORT) : '');
        $path = rtrim((string) parse_url($app, PHP_URL_PATH), '/').'/api/metrics';

        return [
            'address' => $address,
            'cloud' => GrafanaClient::cloud($address),
            'dataSources' => $sources,
            'dataSourcesError' => $error,
            'suggestedUid' => GrafanaClient::cloud($address) ? 'grafanacloud-prom' : null,
            'dashboard' => is_array($dashboard) ? [
                'href' => $address.(string) ($dashboard['url'] ?? ''),
                'version' => (int) ($dashboard['version'] ?? 1),
                'at' => (string) ($dashboard['at'] ?? ''),
                'datasource' => $dashboard['datasource'] ?? null,
            ] : null,
            'push' => $push === null ? null : [
                'endpoint' => $push['endpoint'],
                'instance' => $push['instance'],
                'lastAt' => $push['lastAt'],
                'lastCount' => $push['lastCount'],
                'error' => $push['error'],
                'failing' => $push['failing'],
            ],
            'scrape' => [
                'url' => route('metrics'),
                'perMinute' => MetricsController::PER_MINUTE,
                'config' => implode("\n", [
                    'scrape_configs:',
                    '  - job_name: serveros',
                    "    scheme: {$scheme}",
                    "    metrics_path: {$path}",
                    '    scrape_interval: 60s',
                    '    authorization:',
                    '      type: Bearer',
                    '      credentials: '.MetricsTokens::PREFIX.'…',
                    '    static_configs:',
                    "      - targets: ['{$host}']",
                ]),
                'tokens' => array_values($this->tokens($integration)->map(fn (McpToken $token) => [
                    'id' => $token->id,
                    'name' => $token->name,
                    'prefix' => $token->token_prefix,
                    'lastUsedAt' => $token->last_used_at?->toIso8601String(),
                    'createdAt' => $token->created_at?->toIso8601String(),
                ])->all()),
            ],
            'series' => WorkspaceMetrics::names(),
        ];
    }
}
