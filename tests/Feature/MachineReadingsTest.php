<?php

use App\Models\Machine;
use App\Models\Workspace;
use App\Support\Machines\MachineDisks;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

function readingsNow(): Carbon
{
    return Carbon::parse('2026-11-27 12:00:00', 'UTC');
}

/** @param  array<string, mixed>  $attributes */
function reportingMachine(Workspace $workspace, string $name, Carbon $seen, array $attributes = []): Machine
{
    $machine = Machine::factory()->for($workspace)->create(['name' => $name, 'cpu_percent' => 66, 'memory_used_mb' => 3431]);
    $machine->forceFill([
        'uid' => (string) Str::uuid(),
        'last_seen_at' => $seen,
        'load_1' => 0.5,
        'load_5' => 0.4,
        'load_15' => 0.3,
        'processes' => 120,
        'net_rx_rate' => 2048,
        'net_tx_rate' => 1024,
        'facts' => ['memory_bytes' => 8 << 30],
        'inventory' => ['scanned_at' => $seen->getTimestamp(), 'complete' => true, 'listeners' => [], 'services' => [
            ['key' => 'docker:nginx0000001', 'name' => 'nginx', 'kind' => 'container', 'manager' => 'docker', 'status' => 'running', 'ports' => [80], 'confidence' => 90, 'details' => []],
        ]],
        'service_stats' => ['docker:nginx0000001' => ['cpu' => 12.5, 'memUsed' => 400_000_000, 'memLimit' => null, 'pids' => 9, 'netRx' => 10, 'netTx' => 20, 'startedAt' => $seen->copy()->subDay()->getTimestamp(), 'restarts' => 1, 'at' => $seen->getTimestamp()]],
        ...$attributes,
    ])->save();

    return $machine;
}

/** @return array{mount: string, used_bytes: int, free_bytes: int, inodes_used: int, inodes_free: int, read_bytes: int, write_bytes: int} */
function diskSample(string $mount, int $used, int $free): array
{
    return ['mount' => $mount, 'used_bytes' => $used, 'free_bytes' => $free, 'inodes_used' => 0, 'inodes_free' => 0, 'read_bytes' => 0, 'write_bytes' => 0];
}

test('a machine that stopped reporting shows no current readings, only when and what it last read', function () {
    $this->travelTo(readingsNow());
    $workspace = Workspace::factory()->create();
    $machine = reportingMachine($workspace, 'old-box', Carbon::parse('2026-10-19 13:28:01', 'UTC'));

    $this->actingAs($workspace->owner)
        ->get($workspace->url().'/machines/'.$machine->id)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('machine.connection', 'offline')
            ->where('machine.cpu', null)
            ->where('machine.memoryMb', null)
            ->where('machine.memory', '–')
            ->where('live', [
                'cpu' => null,
                'memoryMb' => null,
                'load' => null,
                'processes' => null,
                'networkIn' => null,
                'networkOut' => null,
                'at' => null,
                'last' => ['at' => '2026-10-19T13:28:01+00:00', 'cpu' => 66, 'memoryMb' => 3431],
            ])
            ->where('machine.serviceStats.docker:nginx0000001.cpu', null)
            ->where('machine.serviceStats.docker:nginx0000001.memUsed', null)
            ->where('machine.serviceStats.docker:nginx0000001.pids', null)
            ->where('machine.serviceStats.docker:nginx0000001.restarts', 1)
            ->etc());

    $this->actingAs($workspace->owner)
        ->get($workspace->url().'/machines')
        ->assertInertia(fn (Assert $page) => $page
            ->where('machines.0.cpu', null)
            ->where('machines.0.memoryMb', null)
            ->where('machines.0.memory', '–'));

    $this->actingAs($workspace->owner)
        ->get($workspace->url('/machines/'.$machine->id.'/services/docker:nginx0000001'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('stats.cpu', null)
            ->where('stats.memUsed', null)
            ->where('stats.netRx', null)
            ->where('stats.startedAt', Carbon::parse('2026-10-18 13:28:01', 'UTC')->getTimestamp())
            ->etc());
});

test('a reporting machine shows its readings as they are, with no last reading line', function () {
    $this->travelTo(readingsNow());
    $workspace = Workspace::factory()->create();
    $machine = reportingMachine($workspace, 'web-1', readingsNow()->copy()->subSeconds(20), ['telemetry_ts' => readingsNow()->getTimestamp() - 20]);

    $this->actingAs($workspace->owner)
        ->get($workspace->url().'/machines/'.$machine->id)
        ->assertInertia(fn (Assert $page) => $page
            ->where('machine.cpu', 66)
            ->where('machine.memoryMb', 3431)
            ->where('live.cpu', 66)
            ->where('live.memoryMb', 3431)
            ->where('live.load', [0.5, 0.4, 0.3])
            ->where('live.processes', 120)
            ->where('live.networkIn', 2048)
            ->where('live.last', null)
            ->where('machine.serviceStats.docker:nginx0000001.cpu', 12.5)
            ->etc());
});

test('home leaves machines that stopped reporting out of average CPU and the busiest', function () {
    $this->travelTo(readingsNow());
    $workspace = Workspace::factory()->create(['onboarded_at' => now()]);
    reportingMachine($workspace, 'old-box', Carbon::parse('2026-10-19 13:28:01', 'UTC'));
    reportingMachine($workspace, 'web-1', readingsNow()->copy()->subSeconds(20), ['cpu_percent' => 20]);

    $this->actingAs($workspace->owner)
        ->get($workspace->url())
        ->assertInertia(fn (Assert $page) => $page
            ->where('overview.averageCpu', 20)
            ->where('overview.reporting', 1)
            ->where('busiest', fn ($busiest) => collect($busiest)->pluck('name')->all() === ['web-1'])
            ->etc());
});

test('disks count each filesystem once, by device, without the files Docker mounts one by one', function () {
    $this->travelTo(readingsNow());
    $workspace = Workspace::factory()->create();
    $used = 42_619_461_632;
    $total = 460_738_027_520;
    $facts = ['memory_bytes' => 8 << 30, 'disks' => array_map(
        fn (string $mount) => ['device' => '/dev/vda1', 'fs_type' => 'ext4', 'mount' => $mount, 'total_bytes' => 485_473_984_512],
        ['/etc/resolv.conf', '/etc/hostname', '/etc/hosts', '/var/lib/docker'],
    )];
    $machine = reportingMachine($workspace, 'in-docker', Carbon::parse('2026-10-19 13:28:01', 'UTC'), [
        'facts' => $facts,
        'disk_percent' => 9,
        'disks' => array_map(fn (string $mount) => ['mount' => $mount, 'used' => $used, 'total' => $total], ['/etc/resolv.conf', '/etc/hostname', '/etc/hosts', '/var/lib/docker']),
    ]);

    $this->actingAs($workspace->owner)
        ->get($workspace->url().'/machines/'.$machine->id)
        ->assertInertia(fn (Assert $page) => $page
            ->where('machine.disks', [['mount' => '/var/lib/docker', 'used' => $used, 'total' => $total]])
            ->where('machine.diskPercent', 9)
            ->where('machine.facts.disks', [['mount' => '/var/lib/docker', 'device' => '/dev/vda1', 'fs_type' => 'ext4', 'total_bytes' => 485_473_984_512]])
            ->etc());

    $this->actingAs($workspace->owner)
        ->get($workspace->url().'/machines')
        ->assertInertia(fn (Assert $page) => $page->where('machines.0.disks', [['mount' => '/var/lib/docker', 'used' => $used, 'total' => $total]]));

    expect(MachineDisks::clean([
        ['mount' => '/var/lib/docker', 'used' => 10 << 30, 'total' => 50 << 30],
        ['mount' => '/', 'used' => 10 << 30, 'total' => 50 << 30],
        ['mount' => '/srv', 'used' => 1 << 30, 'total' => 20 << 30],
        ['mount' => '/mnt/b', 'used' => 1 << 30, 'total' => 20 << 30],
        ['mount' => '/dev/shm', 'used' => 0, 'total' => 4 << 30],
        ['mount' => '/scratch', 'used' => 2 << 30, 'total' => 8 << 30],
    ], ['disks' => [
        ['mount' => '/', 'device' => '/dev/sda1', 'fs_type' => 'ext4', 'total_bytes' => 50 << 30],
        ['mount' => '/var/lib/docker', 'device' => '/dev/sda1', 'fs_type' => 'ext4', 'total_bytes' => 50 << 30],
        ['mount' => '/srv', 'device' => '/dev/sdb', 'fs_type' => 'xfs', 'total_bytes' => 20 << 30],
        ['mount' => '/mnt/b', 'device' => '/dev/sdc', 'fs_type' => 'xfs', 'total_bytes' => 20 << 30],
        ['mount' => '/scratch', 'device' => 'tmpfs', 'fs_type' => 'tmpfs', 'total_bytes' => 8 << 30],
    ]]))->toBe([
        ['mount' => '/', 'used' => 10 << 30, 'total' => 50 << 30],
        ['mount' => '/mnt/b', 'used' => 1 << 30, 'total' => 20 << 30],
        ['mount' => '/srv', 'used' => 1 << 30, 'total' => 20 << 30],
    ]);
});

test('without facts, disks with the same used and total bytes count once under the clearest mount', function () {
    expect(MachineDisks::clean([
        ['mount' => '/etc/hosts', 'used' => 40 << 30, 'total' => 400 << 30],
        ['mount' => '/var/lib/docker/volumes', 'used' => 40 << 30, 'total' => 400 << 30],
        ['mount' => '/var/lib/docker', 'used' => 40 << 30, 'total' => 400 << 30],
        ['mount' => '/data', 'used' => 300 << 30, 'total' => 1000 << 30],
        ['mount' => '/app/config.json', 'used' => 300 << 30, 'total' => 1000 << 30],
    ], null))->toBe([
        ['mount' => '/data', 'used' => 300 << 30, 'total' => 1000 << 30],
        ['mount' => '/var/lib/docker', 'used' => 40 << 30, 'total' => 400 << 30],
    ]);
});

test('telemetry stores each filesystem once, using the facts sent with it', function () {
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    $machine = Machine::factory()->create();
    $machine->forceFill(['uid' => (string) Str::uuid()])->save();
    $facts = ['hostname' => 'box', 'disks' => [
        ['device' => '/dev/vda1', 'fs_type' => 'ext4', 'mount' => '/etc/hosts', 'total_bytes' => 100 << 30],
        ['device' => '/dev/vda1', 'fs_type' => 'ext4', 'mount' => '/var/lib/docker', 'total_bytes' => 100 << 30],
        ['device' => '/dev/vdb', 'fs_type' => 'ext4', 'mount' => '/mnt/data', 'total_bytes' => 200 << 30],
    ]];

    $this->postJson('/api/gateway/machines/'.$machine->uid.'/ingest', ['envelopes' => [
        ['v' => 1, 'kind' => 'facts', 'payload' => $facts],
        ['v' => 1, 'kind' => 'telemetry', 'payload' => ['samples' => [[
            'ts' => time(), 'cpu_percent' => 5, 'mem_used' => 1 << 30,
            'disks' => [diskSample('/etc/hosts', 30 << 30, 70 << 30), diskSample('/var/lib/docker', 30 << 30, 70 << 30), diskSample('/mnt/data', 20 << 30, 180 << 30)],
        ]]]],
    ]], ['X-Gateway-Secret' => 'test-gateway-secret'])->assertOk();

    $machine->refresh();
    expect($machine->disks)->toBe([
        ['mount' => '/var/lib/docker', 'used' => 30 << 30, 'total' => 100 << 30],
        ['mount' => '/mnt/data', 'used' => 20 << 30, 'total' => 200 << 30],
    ])->and($machine->disk_percent)->toBe(30);
});
