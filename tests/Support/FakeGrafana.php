<?php

namespace Tests\Support;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * A small stand-in for Grafana's HTTP API and Grafana Cloud's OTLP gateway,
 * so tests never call the real ones. It keeps data sources and dashboards,
 * takes OTLP pushes with Basic auth, records every call and can fail the
 * next call to a path.
 */
final class FakeGrafana
{
    public const string TOKEN = 'fake-grafana-service-account-token';

    public const string OTLP_HOST = 'otlp-gateway-prod-eu-west-2.grafana.net';

    public const string INSTANCE = '1234567';

    public const string OTLP_TOKEN = 'fake-grafana-cloud-otlp-token-for-tests';

    public string $org = 'Acme';

    public string $version = '12.1.0';

    public bool $canListDataSources = true;

    /** @var list<array<string, mixed>> */
    public array $dataSources = [
        ['id' => 1, 'uid' => 'grafanacloud-prom', 'name' => 'grafanacloud-acme-prom', 'type' => 'prometheus', 'isDefault' => true],
        ['id' => 2, 'uid' => 'grafanacloud-logs', 'name' => 'grafanacloud-acme-logs', 'type' => 'loki', 'isDefault' => false],
        ['id' => 3, 'uid' => 'own-prom', 'name' => 'Office Prometheus', 'type' => 'prometheus', 'isDefault' => false],
    ];

    /** @var array<string, array{dashboard: array<string, mixed>, version: int}> by uid */
    public array $dashboards = [];

    /** @var list<array{method: string, host: string, path: string, body: array<string, mixed>, auth: string|null}> */
    public array $calls = [];

    /** @var list<array<string, mixed>> */
    public array $pushes = [];

    /** @var array<string, list<array{status: int, body: array<string, mixed>}>> */
    private array $failures = [];

    /** @param  list<string>  $hosts */
    public static function install(array $hosts = ['acme.grafana.net', 'grafana.example.com'], bool $strict = true): self
    {
        $fake = new self;
        if ($strict) {
            Http::preventStrayRequests();
        }
        $routes = [self::OTLP_HOST.'/otlp/*' => fn (Request $request) => $fake->otlp($request)];
        foreach ($hosts as $host) {
            $routes["{$host}/api/*"] = fn (Request $request) => $fake->api($request);
        }
        Http::fake($routes);

        return $fake;
    }

    /** @param  array<string, mixed>  $body */
    public function failNext(string $method, string $path, int $status, array $body = []): self
    {
        $this->failures[strtoupper($method).' '.$path][] = ['status' => $status, 'body' => $body];

        return $this;
    }

    /** @return list<string> */
    public function called(): array
    {
        return array_map(fn (array $call) => $call['method'].' '.$call['path'], $this->calls);
    }

    public function api(Request $request): PromiseInterface
    {
        $url = parse_url($request->url());
        $path = substr((string) ($url['path'] ?? ''), strlen('/api/'));
        $method = strtoupper($request->method());
        $body = $request->data();
        $auth = $request->header('Authorization')[0] ?? null;
        $this->calls[] = ['method' => $method, 'host' => (string) ($url['host'] ?? ''), 'path' => $path, 'body' => is_array($body) ? $body : [], 'auth' => $auth];

        if (($this->failures["{$method} {$path}"] ?? []) !== [] && ($failure = array_shift($this->failures["{$method} {$path}"])) !== null) {
            return Http::response($failure['body'], $failure['status']);
        }
        if ($path === 'health') {
            return Http::response(['commit' => 'abc1234', 'database' => 'ok', 'version' => $this->version]);
        }
        if ($auth !== 'Bearer '.self::TOKEN) {
            return Http::response(['message' => 'Invalid API key'], 401);
        }

        return match (true) {
            $method === 'GET' && $path === 'org' => Http::response(['id' => 1, 'name' => $this->org]),
            $method === 'GET' && $path === 'datasources' => $this->canListDataSources
                ? Http::response($this->dataSources)
                : Http::response(['message' => 'You\'ll need additional permissions to perform this action. Permissions needed: datasources:read'], 403),
            $method === 'POST' && $path === 'dashboards/db' => $this->saveDashboard($body),
            default => Http::response(['message' => 'Not found'], 404),
        };
    }

    public function otlp(Request $request): PromiseInterface
    {
        $url = parse_url($request->url());
        $path = (string) ($url['path'] ?? '');
        $auth = $request->header('Authorization')[0] ?? null;
        $this->calls[] = ['method' => strtoupper($request->method()), 'host' => self::OTLP_HOST, 'path' => $path, 'body' => [], 'auth' => $auth];

        if (($this->failures['POST '.$path] ?? []) !== [] && ($failure = array_shift($this->failures['POST '.$path])) !== null) {
            return Http::response($failure['body'], $failure['status']);
        }
        if ($path !== '/otlp/v1/metrics') {
            return Http::response('404 page not found', 404);
        }
        if ($auth !== 'Basic '.base64_encode(self::INSTANCE.':'.self::OTLP_TOKEN)) {
            return Http::response(['code' => 16, 'message' => 'authentication error: invalid authentication credentials'], 401);
        }
        if (! str_starts_with((string) ($request->header('Content-Type')[0] ?? ''), 'application/json')) {
            return Http::response(['message' => 'unsupported content type'], 415);
        }
        $this->pushes[] = (array) json_decode($request->body(), true);

        return Http::response([]);
    }

    /** @param  array<string, mixed>  $body */
    private function saveDashboard(array $body): PromiseInterface
    {
        $dashboard = (array) ($body['dashboard'] ?? []);
        $uid = (string) ($dashboard['uid'] ?? 'generated');
        if (isset($this->dashboards[$uid]) && ($body['overwrite'] ?? false) !== true) {
            return Http::response(['message' => 'The dashboard has been changed by someone else', 'status' => 'version-mismatch'], 412);
        }
        $version = ($this->dashboards[$uid]['version'] ?? 0) + 1;
        $this->dashboards[$uid] = ['dashboard' => $dashboard, 'version' => $version];
        $slug = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', (string) ($dashboard['title'] ?? 'dashboard')));

        return Http::response(['id' => 10, 'uid' => $uid, 'url' => "/d/{$uid}/{$slug}", 'status' => 'success', 'version' => $version, 'slug' => $slug]);
    }
}
