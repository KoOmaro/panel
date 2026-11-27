<?php

namespace Tests\Support;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * A small stand-in for the Proxmox VE API, so tests never call a real one.
 * It keeps nodes, VMs, containers and their snapshots, runs every change as
 * a task that finishes after a few more calls, knows the QEMU
 * guest agent, records every call with the transport options it was sent
 * with, and can be told to fail the next calls to a path.
 *
 * $certificate is the SHA-256 fingerprint of the certificate it answers
 * with, or null for one a trusted authority signed: a request pinned to
 * another fingerprint, or one that isn't pinned to a self-signed one, fails
 * the way PHP's and curl's TLS do, before anything is answered.
 */
final class FakeProxmox
{
    public const string TOKEN = 'serveros@pve!panel=fake-proxmox-secret-for-tests';

    public const string HOST = 'pve.example.com';

    public const string IP = '203.0.113.20';

    public const string FINGERPRINT = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

    public const string GIB = '1073741824';

    public ?string $certificate = null;

    public string $version = '8.2.4';

    public ?string $cluster = 'homelab';

    /** @var array<string, array<string, mixed>> */
    public array $nodes = [
        'pve1' => ['node' => 'pve1', 'status' => 'online', 'maxcpu' => 16, 'maxmem' => 68719476736],
        'pve2' => ['node' => 'pve2', 'status' => 'online', 'maxcpu' => 8, 'maxmem' => 34359738368],
    ];

    /** @var array<string, array<string, int>> */
    public array $permissions = ['/' => [
        'VM.Audit' => 1, 'VM.PowerMgmt' => 1, 'VM.Config.CPU' => 1, 'VM.Config.Memory' => 1, 'VM.Config.Disk' => 1,
        'VM.Config.Cloudinit' => 1, 'VM.Snapshot' => 1, 'VM.Clone' => 1, 'VM.Allocate' => 1, 'Datastore.AllocateSpace' => 1,
        'VM.Monitor' => 1, 'Sys.Audit' => 1,
    ]];

    /** @var array<int, array<string, mixed>> */
    public array $guests = [];

    /** @var array<string, array{node: string, polls: int, exit: string, apply: callable|null}> */
    public array $tasks = [];

    /** @var array<int, array{vmid: int, command: mixed, input: string, polls: int, exitcode: int, out: string}> */
    public array $execs = [];

    /** @var list<array{method: string, path: string, body: array<string, mixed>, query: array<string, mixed>, host: string, hostHeader: string, verify: mixed, pin: string|null, resolve: mixed}> */
    public array $calls = [];

    /** Calls before a task is done. */
    public int $taskPolls = 1;

    /** Reads of an exec's status before it's done. */
    public int $execPolls = 1;

    /** Whether a disk resize answers with a task (Proxmox VE 8) or nothing (7). */
    public bool $resizeTasks = true;

    /** @var array<string, list<array{status: int, body: array<string, mixed>}>> */
    private array $failures = [];

    private int $nextId = 200;

    private int $nextTask = 1;

    private int $nextPid = 4000;

    public static function install(bool $strict = true): self
    {
        $fake = new self;
        if ($strict) {
            Http::preventStrayRequests();
        }
        Http::fake(['*:8006/api2/json/*' => fn (Request $request, array $options) => $fake->handle($request, $options)]);

        return $fake;
    }

    /**
     * A QEMU VM, or a template with template: 1.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $overrides
     */
    public function vm(int $vmid, string $name, string $node = 'pve1', array $config = [], array $overrides = []): self
    {
        $this->guests[$vmid] = array_replace([
            'type' => 'qemu', 'vmid' => $vmid, 'name' => $name, 'node' => $node, 'status' => 'running', 'template' => 0, 'lock' => null,
            'config' => array_replace([
                'name' => $name, 'cores' => 2, 'sockets' => 1, 'memory' => '4096', 'ostype' => 'l26', 'agent' => '1',
                'boot' => 'order=scsi0;net0', 'scsi0' => "local-lvm:vm-{$vmid}-disk-0,size=32G", 'ide2' => "local-lvm:vm-{$vmid}-cloudinit,media=cdrom",
                'net0' => 'virtio=BC:24:11:00:00:01,bridge=vmbr0', 'hotplug' => 'network,disk,usb', 'numa' => 0,
            ], $config),
            'pending' => [],
            'snapshots' => [],
            'agent' => ['up' => true, 'interfaces' => []],
        ], $overrides);

        return $this;
    }

    /** @param  list<string>  $addresses */
    public function withAgentAddresses(int $vmid, array $addresses, string $interface = 'eth0'): self
    {
        $this->guests[$vmid]['agent']['interfaces'] = [
            ['name' => 'lo', 'ip-addresses' => [['ip-address' => '127.0.0.1', 'ip-address-type' => 'ipv4', 'prefix' => 8]]],
            ['name' => 'docker0', 'ip-addresses' => [['ip-address' => '172.17.0.1', 'ip-address-type' => 'ipv4', 'prefix' => 16]]],
            ['name' => $interface, 'hardware-address' => 'bc:24:11:00:00:01', 'ip-addresses' => array_map(fn (string $ip) => [
                'ip-address' => explode('/', $ip)[0], 'ip-address-type' => str_contains($ip, ':') ? 'ipv6' : 'ipv4', 'prefix' => (int) (explode('/', $ip)[1] ?? 24),
            ], $addresses)],
        ];

        return $this;
    }

    /** @param  array<string, mixed>  $overrides */
    public function container(int $vmid, string $name, ?string $inet, string $node = 'pve1', array $overrides = []): self
    {
        $this->guests[$vmid] = array_replace([
            'type' => 'lxc', 'vmid' => $vmid, 'name' => $name, 'node' => $node, 'status' => 'running', 'template' => 0, 'lock' => null,
            'maxcpu' => 1, 'maxmem' => 1073741824, 'maxdisk' => 8589934592,
            'interfaces' => array_values(array_filter([
                ['name' => 'lo', 'inet' => '127.0.0.1/8', 'inet6' => '::1/128', 'hwaddr' => '00:00:00:00:00:00'],
                $inet === null ? null : ['name' => 'eth0', 'inet' => $inet, 'hwaddr' => 'bc:24:11:00:00:09'],
            ])),
            'config' => [], 'pending' => [], 'snapshots' => [], 'agent' => ['up' => false, 'interfaces' => []],
        ], $overrides);

        return $this;
    }

    public function snapshotOf(int $vmid, string $name, string $description, int $time): self
    {
        $this->guests[$vmid]['snapshots'][$name] = ['name' => $name, 'description' => $description, 'snaptime' => $time, 'vmstate' => 0, 'parent' => null];

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
            array_filter($this->calls, fn (array $call) => $method === null || $call['method'] === $method),
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

    /** Lets every task finish now. */
    public function finishTasks(): void
    {
        foreach (array_keys($this->tasks) as $upid) {
            $this->tasks[$upid]['polls'] = 0;
            $this->settle($upid);
        }
    }

    /** @param  array<string, mixed>  $options */
    public function handle(Request $request, array $options): mixed
    {
        $url = parse_url($request->url());
        $path = (string) preg_replace('#^/api2/json/#', '', (string) ($url['path'] ?? ''));
        parse_str((string) ($url['query'] ?? ''), $query);
        $method = strtoupper($request->method());
        $body = $request->data();
        $pin = $options['stream_context']['ssl']['peer_fingerprint']['sha256'] ?? null;
        $this->calls[] = [
            'method' => $method, 'path' => $path, 'body' => is_array($body) ? $body : [], 'query' => $query,
            'host' => (string) ($url['host'] ?? ''), 'hostHeader' => (string) ($request->header('Host')[0] ?? ''),
            'verify' => $options['verify'] ?? null, 'pin' => is_string($pin) ? $pin : null, 'resolve' => $options['curl'][CURLOPT_RESOLVE] ?? null,
        ];

        if ($pin !== null && $pin !== $this->certificate) {
            return Http::failedConnection('Error creating resource: [message] fopen(): peer_fingerprint match failure; [file] StreamHandler.php');
        }
        if ($pin === null && $this->certificate !== null) {
            return Http::failedConnection('cURL error 60: SSL certificate problem: self-signed certificate (see https://curl.haxx.se/libcurl/c/libcurl-errors.html)');
        }

        $key = $method.' '.$path;
        if (($this->failures[$key] ?? []) !== [] && ($failure = array_shift($this->failures[$key])) !== null) {
            return Http::response(['data' => null, ...$failure['body']], $failure['status']);
        }
        if (($request->header('Authorization')[0] ?? '') !== 'PVEAPIToken='.self::TOKEN) {
            return Http::response(['data' => null, 'message' => "authentication failure\n"], 401);
        }
        $this->tick();

        return $this->route($method, $path, is_array($body) ? $body : [], $query);
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $query
     */
    private function route(string $method, string $path, array $body, array $query): PromiseInterface
    {
        $p = explode('/', $path);
        if ($method === 'GET' && $path === 'version') {
            return $this->ok(['version' => $this->version, 'release' => substr($this->version, 0, 3), 'repoid' => 'b8a8a8a8']);
        }
        if ($method === 'GET' && $path === 'nodes') {
            return $this->ok(array_values($this->nodes));
        }
        if ($method === 'GET' && $path === 'cluster/status') {
            return $this->ok([
                ...($this->cluster === null ? [] : [['type' => 'cluster', 'id' => 'cluster', 'name' => $this->cluster, 'nodes' => count($this->nodes), 'quorate' => 1, 'version' => 4]]),
                ...array_map(fn (array $n) => ['type' => 'node', 'id' => 'node/'.$n['node'], 'name' => $n['node'], 'online' => 1, 'local' => 0], array_values($this->nodes)),
            ]);
        }
        if ($method === 'GET' && $path === 'access/permissions') {
            return $this->ok($this->permissions);
        }
        if ($method === 'GET' && $path === 'cluster/resources') {
            return $this->ok(array_values(array_map(fn (int $vmid) => $this->resource($vmid), array_keys($this->guests))));
        }
        if ($method === 'GET' && $path === 'cluster/nextid') {
            return $this->ok((string) $this->nextId);
        }
        if ($p[0] === 'nodes' && ($p[2] ?? null) === 'tasks' && ($p[4] ?? null) === 'status' && $method === 'GET') {
            return isset($this->tasks[rawurldecode($p[3])]) ? $this->ok($this->taskStatus(rawurldecode($p[3]))) : $this->error(500, 'no such task');
        }
        if ($p[0] !== 'nodes' || ! in_array($p[2] ?? null, ['qemu', 'lxc'], true) || ! isset($p[3])) {
            return $this->error(501, "Method '{$method} /{$path}' not implemented");
        }

        $node = $p[1];
        $vmid = (int) $p[3];
        $guest = $this->guests[$vmid] ?? null;
        if ($guest === null || $guest['node'] !== $node || $guest['type'] !== $p[2]) {
            return $this->error(500, "Configuration file 'nodes/{$node}/{$p[2]}-server/{$vmid}.conf' does not exist");
        }
        $rest = implode('/', array_slice($p, 4));

        return match (true) {
            $method === 'GET' && $p[2] === 'lxc' && $rest === 'interfaces' => $guest['status'] === 'running' ? $this->ok($guest['interfaces']) : $this->error(500, 'CT is not running'),
            $method === 'GET' && $rest === 'config' => $this->ok($this->config($vmid)),
            $method === 'PUT' && $rest === 'config' => $this->setConfig($vmid, $body),
            $method === 'GET' && $rest === 'pending' => $this->ok($this->pending($vmid)),
            $method === 'PUT' && $rest === 'resize' => $this->resize($vmid, $body),
            $method === 'GET' && $rest === 'status/current' => $this->ok(['status' => $guest['status'], 'vmid' => $vmid, 'name' => $guest['name'], 'lock' => $guest['lock'], 'agent' => 1]),
            $method === 'POST' && str_starts_with($rest, 'status/') => $this->power($vmid, substr($rest, 7)),
            $method === 'POST' && $rest === 'clone' => $this->cloneOf($vmid, $body),
            $method === 'GET' && $rest === 'snapshot' => $this->ok([...array_values($guest['snapshots']), ['name' => 'current', 'description' => 'You are here!', 'running' => 1]]),
            $method === 'POST' && $rest === 'snapshot' => $this->takeSnapshot($vmid, $body),
            $method === 'DELETE' && str_starts_with($rest, 'snapshot/') => $this->deleteSnapshot($vmid, rawurldecode(substr($rest, 9))),
            $method === 'DELETE' && $rest === '' => $this->destroy($vmid, $query),
            $method === 'POST' && $rest === 'agent/ping' => $this->agentUp($vmid) ? $this->ok(null) : $this->error(500, 'QEMU guest agent is not running'),
            $method === 'GET' && $rest === 'agent/network-get-interfaces' => $this->agentUp($vmid) ? $this->ok(['result' => $guest['agent']['interfaces']]) : $this->error(500, 'QEMU guest agent is not running'),
            $method === 'POST' && $rest === 'agent/exec' => $this->exec($vmid, $body),
            $method === 'GET' && $rest === 'agent/exec-status' => $this->execStatus((int) ($query['pid'] ?? 0)),
            default => $this->error(501, "Method '{$method} /{$path}' not implemented"),
        };
    }

    private function ok(mixed $data): PromiseInterface
    {
        return Http::response(['data' => $data]);
    }

    private function error(int $status, string $message): PromiseInterface
    {
        return Http::response(['data' => null, 'message' => $message."\n"], $status);
    }

    private function agentUp(int $vmid): bool
    {
        $guest = $this->guests[$vmid];

        return $guest['status'] === 'running' && (bool) $guest['agent']['up'] && str_starts_with((string) ($guest['config']['agent'] ?? '0'), '1');
    }

    /** @return array<string, mixed> */
    private function resource(int $vmid): array
    {
        $g = $this->guests[$vmid];
        if ($g['type'] === 'lxc') {
            return ['id' => "lxc/{$vmid}", 'type' => 'lxc', 'vmid' => $vmid, 'name' => $g['name'], 'node' => $g['node'], 'status' => $g['status'], 'template' => $g['template'],
                'maxcpu' => $g['maxcpu'], 'maxmem' => $g['maxmem'], 'maxdisk' => $g['maxdisk'], ...($g['lock'] === null ? [] : ['lock' => $g['lock']])];
        }
        $c = $g['config'];
        preg_match('/size=(\d+)G/', (string) ($c['scsi0'] ?? ''), $m);

        return ['id' => "qemu/{$vmid}", 'type' => 'qemu', 'vmid' => $vmid, 'name' => $g['name'], 'node' => $g['node'], 'status' => $g['status'], 'template' => $g['template'],
            'maxcpu' => (int) $c['cores'] * (int) ($c['sockets'] ?? 1), 'maxmem' => (int) $c['memory'] * 1048576, 'maxdisk' => (int) ($m[1] ?? 0) * (int) self::GIB,
            ...($g['lock'] === null ? [] : ['lock' => $g['lock']])];
    }

    /** @return array<string, mixed> config with pending changes applied, like Proxmox's GET */
    private function config(int $vmid): array
    {
        $g = $this->guests[$vmid];

        return [...$g['config'], ...array_map(fn ($p) => $p, $g['pending']), 'digest' => sha1(json_encode($g['config']) ?: ''), ...($g['template'] ? ['template' => 1] : [])];
    }

    /** @return list<array<string, mixed>> */
    private function pending(int $vmid): array
    {
        $g = $this->guests[$vmid];
        $out = [];
        foreach ([...$g['config'], ...$g['pending']] as $key => $value) {
            $row = ['key' => $key, 'value' => $g['config'][$key] ?? null];
            if (array_key_exists($key, $g['pending'])) {
                $row['pending'] = $g['pending'][$key];
            }
            $out[] = $row;
        }

        return $out;
    }

    /** @param  array<string, mixed>  $body */
    private function setConfig(int $vmid, array $body): PromiseInterface
    {
        $g = &$this->guests[$vmid];
        if ($g['lock'] !== null) {
            return $this->error(500, "VM is locked ({$g['lock']})");
        }
        if (isset($body['sshkeys']) && rawurldecode((string) $body['sshkeys']) === (string) $body['sshkeys'] && str_contains((string) $body['sshkeys'], ' ')) {
            return Http::response(['data' => null, 'errors' => ['sshkeys' => 'invalid urlencoded string'], 'message' => "Parameter verification failed.\n"], 400);
        }
        if (isset($body['memory']) && (int) $body['memory'] < 16) {
            return Http::response(['data' => null, 'errors' => ['memory' => 'value must be >= 16'], 'message' => "Parameter verification failed.\n"], 400);
        }
        foreach ($body as $key => $value) {
            $live = match ($key) {
                'cores', 'sockets' => false,
                'memory' => str_contains((string) $g['config']['hotplug'], 'memory') && (int) $g['config']['numa'] === 1,
                default => true,
            };
            if ($g['status'] === 'running' && ! $live) {
                $g['pending'][$key] = (string) $value;
            } else {
                $g['config'][$key] = (string) $value;
                unset($g['pending'][$key]);
            }
        }

        return $this->ok(null);
    }

    /** @param  array<string, mixed>  $body */
    private function resize(int $vmid, array $body): PromiseInterface
    {
        $disk = (string) ($body['disk'] ?? '');
        $value = (string) ($this->guests[$vmid]['config'][$disk] ?? '');
        if (preg_match('/size=(\d+)G/', $value, $m) !== 1) {
            return $this->error(500, "disk '{$disk}' does not exist");
        }
        $to = (int) rtrim((string) ($body['size'] ?? ''), 'G');
        if ($to < (int) $m[1]) {
            return $this->error(500, 'shrinking disks is not supported');
        }
        $this->guests[$vmid]['config'][$disk] = (string) preg_replace('/size=\d+G/', "size={$to}G", $value);

        return $this->resizeTasks ? $this->ok($this->task($this->guests[$vmid]['node'], 'resize', $vmid)) : $this->ok(null);
    }

    private function power(int $vmid, string $what): PromiseInterface
    {
        $g = $this->guests[$vmid];
        if ($what === 'start' && $g['status'] === 'running') {
            return $this->error(500, "VM {$vmid} already running");
        }
        if (in_array($what, ['stop', 'shutdown', 'reboot'], true) && $g['status'] !== 'running') {
            return $this->error(500, "VM {$vmid} not running");
        }
        if ($g['lock'] !== null) {
            return $this->error(500, "VM is locked ({$g['lock']})");
        }

        return $this->ok($this->task($g['node'], 'qm'.$what, $vmid, function () use ($vmid, $what): void {
            $this->guests[$vmid]['status'] = in_array($what, ['stop', 'shutdown'], true) ? 'stopped' : 'running';
            if ($what !== 'reboot' && $what !== 'start') {
                return;
            }
            foreach ($this->guests[$vmid]['pending'] as $key => $value) {
                $this->guests[$vmid]['config'][$key] = $value;
            }
            $this->guests[$vmid]['pending'] = [];
        }));
    }

    /** @param  array<string, mixed>  $body */
    private function cloneOf(int $template, array $body): PromiseInterface
    {
        $source = $this->guests[$template];
        $newid = (int) ($body['newid'] ?? 0);
        if (isset($this->guests[$newid])) {
            return $this->error(500, "unable to create VM {$newid}: config file already exists");
        }
        $this->nextId = max($this->nextId, $newid + 1);
        $config = $source['config'];
        foreach ($config as $key => $value) {
            $config[$key] = is_string($value) ? str_replace("vm-{$template}-", "vm-{$newid}-", $value) : $value;
        }
        $this->guests[$newid] = [...$source, 'vmid' => $newid, 'name' => (string) $body['name'], 'template' => 0, 'status' => 'stopped', 'lock' => 'clone',
            'config' => [...$config, 'name' => (string) $body['name']], 'snapshots' => [], 'pending' => []];

        return $this->ok($this->task($source['node'], 'qmclone', $template, function () use ($newid): void {
            $this->guests[$newid]['lock'] = null;
        }));
    }

    /** @param  array<string, mixed>  $body */
    private function takeSnapshot(int $vmid, array $body): PromiseInterface
    {
        $name = (string) ($body['snapname'] ?? '');
        if (preg_match('/^[a-z][a-z0-9_-]{1,39}$/i', $name) !== 1) {
            return Http::response(['data' => null, 'errors' => ['snapname' => 'invalid format - invalid configuration ID'], 'message' => "Parameter verification failed.\n"], 400);
        }
        if (isset($this->guests[$vmid]['snapshots'][$name])) {
            return $this->error(500, "snapshot name '{$name}' already used");
        }
        $this->guests[$vmid]['snapshots'][$name] = ['name' => $name, 'description' => (string) ($body['description'] ?? ''), 'snaptime' => time(), 'vmstate' => 0, 'parent' => null];
        $this->guests[$vmid]['lock'] = 'snapshot';

        return $this->ok($this->task($this->guests[$vmid]['node'], 'qmsnapshot', $vmid, function () use ($vmid): void {
            $this->guests[$vmid]['lock'] = null;
        }));
    }

    private function deleteSnapshot(int $vmid, string $name): PromiseInterface
    {
        if (! isset($this->guests[$vmid]['snapshots'][$name])) {
            return $this->error(500, "snapshot '{$name}' does not exist");
        }
        unset($this->guests[$vmid]['snapshots'][$name]);

        return $this->ok($this->task($this->guests[$vmid]['node'], 'qmdelsnapshot', $vmid));
    }

    /** @param  array<string, mixed>  $query */
    private function destroy(int $vmid, array $query): PromiseInterface
    {
        if ($this->guests[$vmid]['status'] === 'running') {
            return $this->error(500, "VM {$vmid} is running - destroy failed");
        }
        $node = $this->guests[$vmid]['node'];
        $this->guests[$vmid]['lock'] = 'destroyed';

        return $this->ok($this->task($node, 'qmdestroy', $vmid, function () use ($vmid): void {
            unset($this->guests[$vmid]);
        }));
    }

    /** @param  array<string, mixed>  $body */
    private function exec(int $vmid, array $body): PromiseInterface
    {
        if (! $this->agentUp($vmid)) {
            return $this->error(500, 'QEMU guest agent is not running');
        }
        $pid = $this->nextPid++;
        $this->execs[$pid] = ['vmid' => $vmid, 'command' => $body['command'] ?? null, 'input' => (string) ($body['input-data'] ?? ''), 'polls' => $this->execPolls, 'exitcode' => 0, 'out' => "Installed serverosd.\n"];

        return $this->ok(['pid' => $pid]);
    }

    private function execStatus(int $pid): PromiseInterface
    {
        if (! isset($this->execs[$pid])) {
            return $this->error(500, "Agent error: PID {$pid} does not exist");
        }
        if ($this->execs[$pid]['polls']-- > 0) {
            return $this->ok(['exited' => 0]);
        }

        return $this->ok(['exited' => 1, 'exitcode' => $this->execs[$pid]['exitcode'], 'out-data' => $this->execs[$pid]['out']]);
    }

    private function task(string $node, string $type, int $vmid, ?callable $apply = null): string
    {
        $upid = sprintf('UPID:%s:%08X:%08X:%08X:%s:%d:%s:', $node, 4000 + $this->nextTask, 100 + $this->nextTask, 1764000000 + $this->nextTask++, $type, $vmid, explode('=', self::TOKEN)[0]);
        $this->tasks[$upid] = ['node' => $node, 'polls' => $this->taskPolls, 'exit' => 'OK', 'apply' => $apply];

        return $upid;
    }

    /** Makes the next task of a kind fail with Proxmox's words. */
    public function failTask(string $upid, string $exit): void
    {
        $this->tasks[$upid]['exit'] = $exit;
        $this->tasks[$upid]['apply'] = null;
    }

    // Time passes with every call: each task that's still going gets one step nearer done.
    private function tick(): void
    {
        foreach (array_keys($this->tasks) as $upid) {
            if ($this->tasks[$upid]['polls'] > 0) {
                $this->tasks[$upid]['polls']--;
            }
            $this->settle($upid);
        }
    }

    private function settle(string $upid): void
    {
        $task = &$this->tasks[$upid];
        if ($task['polls'] <= 0 && $task['apply'] !== null) {
            ($task['apply'])();
            $task['apply'] = null;
        }
    }

    /** @return array<string, mixed> */
    private function taskStatus(string $upid): array
    {
        $task = $this->tasks[$upid];

        return $task['polls'] > 0
            ? ['status' => 'running', 'upid' => $upid, 'node' => $task['node']]
            : ['status' => 'stopped', 'exitstatus' => $task['exit'], 'upid' => $upid, 'node' => $task['node']];
    }
}
