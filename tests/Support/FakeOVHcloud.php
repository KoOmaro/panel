<?php

namespace Tests\Support;

use App\Support\Integrations\OVHcloud\OVHcloudClient;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * A small stand-in for the OVHcloud API v1, so tests never call the real
 * one. Like OVHcloud, it checks every signed call: the application and
 * consumer keys, a timestamp on its own clock (which runs ahead of ours, so
 * /auth/time has to be used) and the "$1$" SHA1 signature of the request as
 * it arrived. It keeps one account's Public Cloud projects, instances,
 * snapshots and dedicated servers, records every call, can refuse calls the
 * keys weren't granted and can fail the next calls to a path. Instances have
 * no actions: changes show on the instance after a few reads.
 */
final class FakeOVHcloud
{
    public const string APPLICATION_KEY = 'fakeovhcloudappkey';

    public const string APPLICATION_SECRET = 'fakeovhcloudappsecretfortests';

    public const string CONSUMER_KEY = 'fakeovhcloudconsumerkeyfortests';

    public const string PROJECT = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';

    public const int CLOCK_AHEAD = 1234;

    public const array HOSTS = ['ovh-eu' => 'eu.api.ovh.com', 'ovh-ca' => 'ca.api.ovh.com', 'ovh-us' => 'api.us.ovhcloud.com'];

    public const array REGIONS = [
        'GRA11' => ['GRA', 'fr', 'EU'],
        'SBG5' => ['SBG', 'fr', 'EU'],
        'BHS5' => ['BHS', 'ca', 'NA'],
    ];

    /** name => [vcpus, ram GB, disk GB, type, regions, hourly price, osType, regions where it's out of stock] */
    public const array FLAVORS = [
        'd2-2' => [1, 2, 25, 'ovh.d2', ['GRA11', 'SBG5', 'BHS5'], 0.0099, 'linux', []],
        'd2-4' => [2, 4, 50, 'ovh.d2', ['GRA11', 'SBG5'], 0.0198, 'linux', []],
        'b2-7' => [2, 7, 50, 'ovh.ssd.eg', ['GRA11', 'SBG5', 'BHS5'], 0.0681, 'linux', []],
        'b2-15' => [4, 15, 100, 'ovh.ssd.eg', ['GRA11', 'SBG5', 'BHS5'], 0.1364, 'linux', ['BHS5']],
        'c2-7' => [2, 7, 50, 'ovh.ssd.cpu', ['GRA11'], 0.0827, 'linux', []],
        'b2-7-flex' => [2, 7, 50, 'ovh.ssd.eg', ['GRA11'], 0.0750, 'linux', []],
        'b2-15-flex' => [4, 15, 50, 'ovh.ssd.eg', ['GRA11'], 0.1500, 'linux', []],
        'win-b2-7' => [2, 7, 50, 'ovh.ssd.eg', ['GRA11'], 0.1200, 'windows', []],
        't1-45' => [8, 45, 400, 'ovh.ssd.gpu', ['GRA11'], 1.9000, 'linux', []],
    ];

    public const array IMAGES = ['Ubuntu 22.04', 'Ubuntu 24.04', 'Ubuntu 25.04', 'Debian 12', 'Debian 11', 'Ubuntu 24.04 - Docker', 'AlmaLinux 9'];

    /** @var array<string, array{description: string, status: string}> */
    public array $projects = [self::PROJECT => ['description' => 'Acme production', 'status' => 'ok']];

    /** @var array<string, array<string, mixed>> */
    public array $instances = [];

    /** @var array<string, array<string, mixed>> */
    public array $snapshots = [];

    /** @var array<string, array{detail: array<string, mixed>, hardware: array<string, mixed>, ips: list<string>}> */
    public array $dedicated = [];

    /** @var list<string> calls the keys weren't granted, like "GET dedicated/server" (a trailing * matches the rest) */
    public array $denied = [];

    /** @var list<array{method: string, host: string, path: string, query: array<string, mixed>, body: array<string, mixed>, raw: string, headers: array<string, string>}> */
    public array $calls = [];

    public int $settlePolls = 1;

    public bool $catalogueDown = false;

    public int $clockReads = 0;

    /** @var array<string, array{polls: int, apply: callable}> */
    private array $pending = [];

    /** @var array<string, list<array{status: int, body: array<string, mixed>}>> */
    private array $failures = [];

    private int $next = 1;

    public static function install(bool $strict = true): self
    {
        $fake = new self;
        if ($strict) {
            Http::preventStrayRequests();
        }
        Http::fake(array_fill_keys(array_map(fn (string $host) => $host.'/*', self::HOSTS), fn (Request $request) => $fake->handle($request)));

        return $fake;
    }

    /** @return array<string, string> */
    public static function credentials(string $endpoint = 'ovh-eu', string $project = ''): array
    {
        return ['endpoint' => $endpoint, 'application_key' => self::APPLICATION_KEY, 'application_secret' => self::APPLICATION_SECRET, 'consumer_key' => self::CONSUMER_KEY, 'project' => $project];
    }

    public static function id(int $n): string
    {
        return sprintf('0f6a1c2e-4b3d-4e5f-9a8b-%012d', $n);
    }

    public static function flavorId(string $region, string $name): string
    {
        $hash = md5($region.'/'.$name);

        return substr($hash, 0, 8).'-'.substr($hash, 8, 4).'-'.substr($hash, 12, 4).'-'.substr($hash, 16, 4).'-'.substr($hash, 20, 12);
    }

    public static function imageId(string $region, string $name): string
    {
        return self::flavorId($region, 'image:'.$name);
    }

    /** @param  array<string, mixed>  $overrides */
    public function instance(string $id, string $name, ?string $ipv4, ?string $ipv6 = null, array $overrides = []): self
    {
        $region = (string) ($overrides['region'] ?? 'GRA11');
        $flavor = (string) ($overrides['flavor'] ?? 'b2-7');
        unset($overrides['flavor']);
        $this->instances[$id] = array_replace([
            'id' => $id,
            'name' => $name,
            'ipAddresses' => array_values(array_filter([
                $ipv4 === null ? null : ['ip' => $ipv4, 'type' => 'public', 'version' => 4, 'networkId' => 'ext-net', 'gatewayIp' => '203.0.113.1'],
                $ipv6 === null ? null : ['ip' => $ipv6, 'type' => 'public', 'version' => 6, 'networkId' => 'ext-net', 'gatewayIp' => null],
                ['ip' => '10.1.0.'.$this->next, 'type' => 'private', 'version' => 4, 'networkId' => 'vrack', 'gatewayIp' => null],
            ])),
            'flavorId' => self::flavorId($region, $flavor),
            'imageId' => self::imageId($region, 'Ubuntu 24.04'),
            'sshKeyId' => null,
            'created' => '2026-11-01T10:00:00Z',
            'region' => $region,
            'monthlyBilling' => null,
            'status' => 'ACTIVE',
            'planCode' => $flavor.'.consumption',
            'operationIds' => [],
            'currentMonthOutgoingTraffic' => null,
            'availabilityZone' => null,
            'rescuePassword' => null,
        ], $overrides);

        return $this;
    }

    public function dedicatedServer(string $name, string $ip, string $datacenter = 'rbx8', string $power = 'poweron'): self
    {
        $this->dedicated[$name] = [
            'detail' => [
                'name' => $name, 'ip' => $ip, 'datacenter' => $datacenter, 'commercialRange' => 'rise-1', 'powerState' => $power, 'state' => 'ok',
                'reverse' => $name, 'os' => 'ubuntu2404-server_64', 'serverId' => 4242, 'monitoring' => true, 'rack' => 'G101A01', 'linkSpeed' => 1000,
                'professionalUse' => false, 'supportLevel' => 'pro', 'bootId' => 1, 'region' => 'eu-west-rbx', 'availabilityZone' => 'eu-west-rbx-a',
            ],
            'hardware' => [
                'description' => 'RISE-1 - Intel Xeon-E 2386G', 'numberOfProcessors' => 1, 'coresPerProcessor' => 6, 'threadsPerProcessor' => 12,
                'memorySize' => ['unit' => 'MB', 'value' => 32768], 'processorArchitecture' => 'coffeelake', 'processorName' => 'Xeon-E 2386G',
                'diskGroups' => [['numberOfDisks' => 2, 'diskSize' => ['unit' => 'GB', 'value' => 512], 'diskType' => 'NVME', 'raidController' => null]],
            ],
            'ips' => [$ip.'/32', '2001:41d0:303:1a00::/56'],
        ];

        return $this;
    }

    /** @param  array<string, mixed>  $body */
    public function failNext(string $method, string $path, int $status, array $body = []): self
    {
        $this->failures[strtoupper($method).' '.$path][] = ['status' => $status, 'body' => $body];

        return $this;
    }

    /** @return list<string> */
    public function called(?string $method = null): array
    {
        return array_values(array_map(
            fn (array $call) => $call['method'].' '.$call['path'],
            array_filter($this->calls, fn (array $call) => ($method === null || $call['method'] === $method) && $call['path'] !== 'auth/time'),
        ));
    }

    /** @return array<string, mixed>|null */
    public function bodyOf(string $method, string $path): ?array
    {
        foreach (array_reverse($this->calls) as $call) {
            if ($call['method'] === $method && $call['path'] === $path) {
                return $call['body'];
            }
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    public function queryOf(string $method, string $path): ?array
    {
        foreach (array_reverse($this->calls) as $call) {
            if ($call['method'] === $method && $call['path'] === $path) {
                return $call['query'];
            }
        }

        return null;
    }

    public function handle(Request $request): PromiseInterface
    {
        $url = parse_url($request->url());
        $host = (string) ($url['host'] ?? '');
        $path = trim(substr((string) ($url['path'] ?? ''), strlen('/1.0')), '/');
        parse_str((string) ($url['query'] ?? ''), $query);
        $method = strtoupper($request->method());
        $raw = $request->body();
        $body = $raw === '' ? [] : json_decode($raw, true);
        $headers = [];
        foreach (['X-Ovh-Application', 'X-Ovh-Consumer', 'X-Ovh-Timestamp', 'X-Ovh-Signature'] as $name) {
            if ($request->hasHeader($name)) {
                $headers[$name] = $request->header($name)[0];
            }
        }
        $this->calls[] = ['method' => $method, 'host' => $host, 'path' => $path, 'query' => $query, 'body' => is_array($body) ? $body : [], 'raw' => $raw, 'headers' => $headers];

        if ($path === 'auth/time') {
            $this->clockReads++;

            return Http::response((string) $this->now());
        }
        if ($path === 'order/catalog/public/cloud') {
            if ($headers !== []) {
                return $this->error(400, 'BAD_REQUEST', 'The catalogue is public, so it never takes keys');
            }

            return $this->catalogueDown ? $this->error(503, 'SERVICE_UNAVAILABLE', 'Service unavailable') : Http::response($this->catalogue((string) ($query['ovhSubsidiary'] ?? '')));
        }

        $key = $method.' '.$path;
        if (($this->failures[$key] ?? []) !== [] && ($failure = array_shift($this->failures[$key])) !== null) {
            return $failure['body'] === [] ? $this->error($failure['status'], 'INTERNAL', 'Internal server error') : Http::response($failure['body'], $failure['status']);
        }
        if (($headers['X-Ovh-Application'] ?? null) !== self::APPLICATION_KEY) {
            return $this->error(403, 'INVALID_KEY', 'This application key is invalid');
        }
        if (($headers['X-Ovh-Consumer'] ?? null) !== self::CONSUMER_KEY) {
            return $this->error(403, 'INVALID_CREDENTIAL', 'This credential does not exist');
        }
        $timestamp = (int) ($headers['X-Ovh-Timestamp'] ?? 0);
        if (abs($timestamp - $this->now()) > 30) {
            return $this->error(400, 'QUERY_TIME_OUT', 'Query out of time');
        }
        if (($headers['X-Ovh-Signature'] ?? null) !== OVHcloudClient::signature(self::APPLICATION_SECRET, self::CONSUMER_KEY, $method, $request->url(), $raw, $timestamp)) {
            return $this->error(400, 'INVALID_SIGNATURE', 'Invalid signature');
        }
        foreach ($this->denied as $rule) {
            if ($rule === $key || (str_ends_with($rule, '*') && str_starts_with($key, substr($rule, 0, -1)))) {
                return $this->error(403, 'NOT_GRANTED_CALL', 'This call has not been granted');
            }
        }

        return $this->route($method, $path, is_array($body) ? $body : [], $query);
    }

    public function now(): int
    {
        return time() + self::CLOCK_AHEAD;
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $query
     */
    private function route(string $method, string $path, array $body, array $query): PromiseInterface
    {
        $parts = explode('/', $path);
        if ($method === 'GET' && $path === 'me') {
            return Http::response(['nichandle' => 'sr12345-ovh', 'email' => 'sam@example.com', 'firstname' => 'Sam', 'name' => 'Rivera', 'ovhSubsidiary' => 'FR', 'currency' => ['code' => 'EUR', 'symbol' => '€'], 'state' => 'complete']);
        }
        if ($parts[0] === 'dedicated') {
            return $this->dedicatedRoute($method, $parts);
        }
        if ($parts[0] !== 'cloud' || ($parts[1] ?? null) !== 'project') {
            return $this->notFound();
        }
        if ($method === 'GET' && count($parts) === 2) {
            return Http::response(array_keys($this->projects));
        }
        $project = (string) ($parts[2] ?? '');
        if (! isset($this->projects[$project])) {
            return $this->error(404, 'NOT_FOUND', 'This service does not exist');
        }
        if ($method === 'GET' && count($parts) === 3) {
            return Http::response(['project_id' => $project, 'projectName' => null, 'description' => $this->projects[$project]['description'], 'status' => $this->projects[$project]['status'],
                'planCode' => 'project.2018', 'access' => 'full', 'creationDate' => '2025-01-10T09:00:00Z', 'expiration' => null, 'manualQuota' => false, 'unleash' => false, 'orderId' => 1]);
        }
        $what = $parts[3] ?? '';
        $id = (string) ($parts[4] ?? '');
        $verb = $parts[5] ?? null;

        return match (true) {
            $what === 'region' && $method === 'GET' && $id === '' => Http::response(array_keys(self::REGIONS)),
            $what === 'region' && $method === 'GET' => isset(self::REGIONS[$id]) ? Http::response(self::region($id)) : $this->notFound(),
            $what === 'flavor' && $method === 'GET' => Http::response(self::flavors(isset($query['region']) ? (string) $query['region'] : null)),
            $what === 'image' && $method === 'GET' => Http::response(self::images(isset($query['region']) ? (string) $query['region'] : null, (string) ($query['osType'] ?? ''))),
            $what === 'sshkey' && $method === 'GET' => Http::response(self::keys()),
            $what === 'snapshot' && $method === 'GET' && $id === '' => Http::response(array_values(array_map(fn (array $s) => array_diff_key($s, ['instance' => true]),
                array_filter($this->snapshots, fn (array $s) => ! isset($query['instanceId']) || $s['instance'] === $query['instanceId'])))),
            $what === 'snapshot' && $method === 'DELETE' => $this->deleteSnapshot($id),
            $what !== 'instance' => $this->notFound(),
            $method === 'GET' && $id === '' => Http::response(array_values(array_map(fn (string $i) => $this->read($i, detail: false), array_keys($this->instances)))),
            $method === 'POST' && $id === '' => $this->create($body),
            ! isset($this->instances[$id]) => $this->error(404, 'NOT_FOUND', "The requested object (instanceId = {$id}) does not exist"),
            $method === 'GET' && $verb === null => Http::response($this->read($id, detail: true)),
            $method === 'DELETE' && $verb === null => $this->deleteInstance($id),
            $method === 'POST' && $verb === 'reboot' => $this->reboot($id, $body),
            $method === 'POST' && $verb === 'stop' => $this->power($id, 'SHUTOFF'),
            $method === 'POST' && $verb === 'start' => $this->power($id, 'ACTIVE'),
            $method === 'POST' && $verb === 'resize' => $this->resize($id, $body),
            $method === 'POST' && $verb === 'snapshot' => $this->takeSnapshot($id, $body),
            default => $this->notFound(),
        };
    }

    /** @param  list<string>  $parts */
    private function dedicatedRoute(string $method, array $parts): PromiseInterface
    {
        if ($method !== 'GET' || ($parts[1] ?? null) !== 'server') {
            return $this->notFound();
        }
        $name = (string) ($parts[2] ?? '');
        if ($name === '') {
            return Http::response(array_keys($this->dedicated));
        }
        if (! isset($this->dedicated[$name])) {
            return $this->error(404, 'NOT_FOUND', 'This service does not exist');
        }
        $rest = implode('/', array_slice($parts, 3));

        return match ($rest) {
            '' => Http::response($this->dedicated[$name]['detail']),
            'specifications/hardware' => Http::response($this->dedicated[$name]['hardware']),
            'ips' => Http::response($this->dedicated[$name]['ips']),
            default => $this->notFound(),
        };
    }

    private function notFound(): PromiseInterface
    {
        return $this->error(404, 'NOT_FOUND', 'Got an invalid (or empty) URL');
    }

    private function error(int $status, string $code, string $message): PromiseInterface
    {
        return Http::response(['class' => 'Client::'.($status >= 500 ? 'ServerError' : 'Forbidden'), 'message' => $message, 'errorCode' => $code, 'httpCode' => $status.' Error'], $status);
    }

    /**
     * Reads an instance, letting a change that's under way land after a few reads.
     *
     * @return array<string, mixed>
     */
    private function read(string $id, bool $detail): array
    {
        if (isset($this->pending[$id]) && $this->pending[$id]['polls']-- <= 0) {
            $apply = $this->pending[$id]['apply'];
            unset($this->pending[$id]);
            $apply();
        }
        $instance = $this->instances[$id];
        if (! $detail) {
            return $instance;
        }
        $flavor = collect(self::flavors($instance['region']))->firstWhere('id', $instance['flavorId']);

        return [...$instance, 'flavor' => $flavor, 'image' => null, 'sshKey' => null];
    }

    private function later(string $id, callable $apply): void
    {
        $this->pending[$id] = ['polls' => $this->settlePolls, 'apply' => $apply];
    }

    /** @param  array<string, mixed>  $body */
    private function create(array $body): PromiseInterface
    {
        $region = (string) ($body['region'] ?? '');
        $flavor = collect(self::flavors($region))->firstWhere('id', $body['flavorId'] ?? null);
        $image = collect(self::images($region, 'linux'))->firstWhere('id', $body['imageId'] ?? null);
        if ($flavor === null) {
            return $this->error(400, 'BAD_REQUEST', 'Flavor not found in this region');
        }
        if ($image === null) {
            return $this->error(400, 'BAD_REQUEST', 'Image not found in this region');
        }
        if (isset($body['sshKeyId']) && collect(self::keys())->firstWhere('id', $body['sshKeyId']) === null) {
            return $this->error(400, 'BAD_REQUEST', 'SSH key not found');
        }
        if (! is_string($body['userData'] ?? null)) {
            return $this->error(400, 'BAD_REQUEST', 'Invalid userData');
        }
        $id = self::id(900 + $this->next++);
        $this->instance($id, (string) $body['name'], null, null, ['region' => $region, 'flavor' => $flavor['name'], 'status' => 'BUILD', 'imageId' => $image['id'], 'sshKeyId' => $body['sshKeyId'] ?? null]);
        $this->instances[$id]['ipAddresses'] = [];
        $this->later($id, function () use ($id): void {
            $n = 100 + $this->next;
            $this->instances[$id] = [...$this->instances[$id], 'status' => 'ACTIVE', 'ipAddresses' => [
                ['ip' => '203.0.113.'.$n, 'type' => 'public', 'version' => 4, 'networkId' => 'ext-net', 'gatewayIp' => '203.0.113.1'],
                ['ip' => '2001:db8:ab::'.$n, 'type' => 'public', 'version' => 6, 'networkId' => 'ext-net', 'gatewayIp' => null],
            ]];
        });

        return Http::response($this->instances[$id]);
    }

    /** @param  array<string, mixed>  $body */
    private function resize(string $id, array $body): PromiseInterface
    {
        $instance = $this->instances[$id];
        $current = collect(self::flavors($instance['region']))->firstWhere('id', $instance['flavorId']);
        $target = collect(self::flavors($instance['region']))->firstWhere('id', $body['flavorId'] ?? null);
        if ($target === null || $current === null) {
            return $this->error(400, 'BAD_REQUEST', 'Flavor not found in this region');
        }
        $flex = str_ends_with($current['name'], '-flex');
        if ($flex !== str_ends_with($target['name'], '-flex') || (! $flex && $target['disk'] < $current['disk'])) {
            return $this->error(400, 'BAD_REQUEST', 'You can only resize to a flavor with a bigger disk');
        }
        $this->instances[$id]['status'] = 'RESIZE';
        $this->later($id, function () use ($id, $target): void {
            $this->instances[$id] = [...$this->instances[$id], 'flavorId' => $target['id'], 'planCode' => $target['name'].'.consumption', 'status' => 'ACTIVE'];
        });

        return Http::response($this->instances[$id]);
    }

    /** @param  array<string, mixed>  $body */
    private function reboot(string $id, array $body): PromiseInterface
    {
        if (! in_array($body['type'] ?? null, ['soft', 'hard'], true)) {
            return $this->error(400, 'BAD_REQUEST', 'Invalid reboot type');
        }
        $this->instances[$id]['status'] = $body['type'] === 'hard' ? 'HARD_REBOOT' : 'REBOOT';
        $this->later($id, function () use ($id): void {
            $this->instances[$id]['status'] = 'ACTIVE';
        });

        return Http::response(null);
    }

    private function power(string $id, string $status): PromiseInterface
    {
        $this->instances[$id]['status'] = $status;

        return Http::response(null);
    }

    private function deleteInstance(string $id): PromiseInterface
    {
        unset($this->instances[$id]);

        return Http::response(null);
    }

    /** @param  array<string, mixed>  $body */
    private function takeSnapshot(string $id, array $body): PromiseInterface
    {
        if (! is_string($body['snapshotName'] ?? null) || $body['snapshotName'] === '') {
            return $this->error(400, 'BAD_REQUEST', 'Invalid snapshotName');
        }
        $snapshot = self::flavorId('snapshot', (string) $this->next++);
        $this->snapshots[$snapshot] = self::snapshotOf($snapshot, $id, $body['snapshotName'], 'queued', '2026-11-27T10:00:00Z', (string) $this->instances[$id]['region']);

        return Http::response(['snapshotId' => $snapshot]);
    }

    private function deleteSnapshot(string $id): PromiseInterface
    {
        if (! isset($this->snapshots[$id])) {
            return $this->error(404, 'NOT_FOUND', "The requested object (imageId = {$id}) does not exist");
        }
        $image = array_diff_key($this->snapshots[$id], ['instance' => true]);
        unset($this->snapshots[$id]);

        return Http::response($image);
    }

    /** @return array<string, mixed> */
    public static function snapshotOf(string $id, string $instance, string $name, string $status = 'active', string $created = '2026-11-20T09:00:00Z', string $region = 'GRA11'): array
    {
        return ['id' => $id, 'instance' => $instance, 'name' => $name, 'region' => $region, 'visibility' => 'private', 'type' => 'linux', 'minDisk' => 50, 'minRam' => 0,
            'size' => $status === 'active' ? 2.37 : 0, 'creationDate' => $created, 'status' => $status, 'user' => 'ubuntu', 'planCode' => 'snapshot.consumption', 'flavorType' => null, 'tags' => null];
    }

    /** @return array<string, mixed> */
    public static function region(string $name): array
    {
        [$location, $country, $continent] = self::REGIONS[$name];

        return ['name' => $name, 'type' => 'region', 'status' => 'UP', 'continentCode' => $continent, 'countryCode' => $country, 'datacenterLocation' => $location,
            'ipCountries' => [], 'availabilityZones' => [], 'services' => [['name' => 'instance', 'status' => 'UP', 'endpoint' => 'https://compute.'.strtolower($name).'.cloud.ovh.net/'], ['name' => 'storage', 'status' => 'UP', 'endpoint' => '']]];
    }

    /** @return list<array<string, mixed>> */
    public static function flavors(?string $region = null): array
    {
        $flavors = [];
        foreach (self::FLAVORS as $name => [$vcpus, $ram, $disk, $type, $regions, $hourly, $os, $outOfStock]) {
            foreach ($regions as $at) {
                if ($region !== null && $at !== $region) {
                    continue;
                }
                $flavors[] = [
                    'id' => self::flavorId($at, $name), 'name' => $name, 'region' => $at, 'ram' => $ram, 'disk' => $disk, 'vcpus' => $vcpus, 'type' => $type, 'osType' => $os,
                    'inboundBandwidth' => 250, 'outboundBandwidth' => 250, 'available' => ! in_array($at, $outOfStock, true), 'quota' => 20,
                    'planCodes' => ['monthly' => $name.'.monthly.postpaid', 'hourly' => $name.'.consumption', 'license' => $os === 'windows' ? 'windows.license' : null],
                    'capabilities' => [['name' => 'resize', 'enabled' => true], ['name' => 'snapshot', 'enabled' => true], ['name' => 'volume', 'enabled' => true], ['name' => 'failoverip', 'enabled' => true]],
                ];
            }
        }

        return $flavors;
    }

    /** @return list<array<string, mixed>> */
    public static function images(?string $region, string $osType): array
    {
        $images = [];
        foreach ([...self::IMAGES, 'Windows Server 2022', 'Ubuntu 24.04 baremetal'] as $name) {
            $os = str_starts_with($name, 'Windows') ? 'windows' : 'linux';
            if ($osType !== '' && $osType !== $os) {
                continue;
            }
            foreach (array_keys(self::REGIONS) as $at) {
                if ($region !== null && $at !== $region) {
                    continue;
                }
                $images[] = [
                    'id' => self::imageId($at, $name), 'name' => str_ends_with($name, ' baremetal') ? 'Ubuntu 24.04' : $name, 'region' => $at, 'visibility' => 'public',
                    'type' => $os, 'minDisk' => 0, 'minRam' => 0, 'size' => 0.6, 'creationDate' => '2026-10-01T00:00:00Z', 'status' => 'active', 'user' => 'ubuntu',
                    'flavorType' => str_ends_with($name, ' baremetal') ? 'bm' : null, 'tags' => null, 'planCode' => null,
                ];
            }
        }

        return $images;
    }

    /** @return list<array<string, mixed>> */
    public static function keys(): array
    {
        return [
            ['id' => 'c2FtQGxhcHRvcA==', 'name' => 'sam@laptop', 'publicKey' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFakeLaptopKeyForTestsOnly sam@laptop', 'regions' => array_keys(self::REGIONS)],
            ['id' => 'Y2ktZGVwbG95', 'name' => 'ci-deploy', 'publicKey' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFakeCiKeyForTestsOnly ci@deploy', 'regions' => array_keys(self::REGIONS)],
        ];
    }

    /** @return array<string, mixed> */
    private function catalogue(string $subsidiary): array
    {
        $addons = [];
        foreach (self::FLAVORS as $name => [, , , , , $hourly]) {
            $addons[] = ['planCode' => $name.'.consumption', 'invoiceName' => $name, 'product' => 'publiccloud-instance-'.$name, 'pricingType' => 'consumption',
                'pricings' => [['capacities' => ['consumption'], 'mode' => 'consumption', 'phase' => 0, 'commitment' => 0, 'description' => 'Price per hour',
                    'interval' => 1, 'intervalUnit' => 'hour', 'quantity' => ['min' => 1, 'max' => null], 'repeat' => ['min' => 1, 'max' => null],
                    'price' => (int) round($hourly * 100_000_000), 'tax' => 0, 'type' => 'consumption', 'strategy' => 'tiered', 'mustBeCompleted' => false]]];
            $addons[] = ['planCode' => $name.'.monthly.postpaid', 'invoiceName' => $name, 'product' => 'publiccloud-instance-'.$name, 'pricingType' => 'rental',
                'pricings' => [['capacities' => ['renew'], 'mode' => 'default', 'phase' => 1, 'commitment' => 0, 'description' => 'Monthly',
                    'interval' => 1, 'intervalUnit' => 'month', 'quantity' => ['min' => 1, 'max' => null], 'repeat' => ['min' => 1, 'max' => null],
                    'price' => (int) round($hourly * 365 * 100_000_000), 'tax' => 0, 'type' => 'rental', 'strategy' => 'tiered', 'mustBeCompleted' => false]]];
        }

        return ['catalogId' => 1, 'locale' => ['currencyCode' => $subsidiary === 'US' ? 'USD' : 'EUR', 'subsidiary' => $subsidiary, 'taxRate' => 20], 'plans' => [], 'products' => [], 'planFamilies' => [], 'addons' => $addons];
    }
}
