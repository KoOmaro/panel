<?php

use App\Models\Machine;
use App\Models\MachineSample;
use App\Models\Workspace;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

function vitalsNow(): Carbon
{
    return Carbon::parse('2026-10-15 12:00:30', 'UTC');
}

function connectedMachine(?Workspace $workspace = null): Machine
{
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    $machine = Machine::factory()->for($workspace ?? Workspace::factory()->create())->create(['cpu_percent' => null, 'memory_used_mb' => null]);
    $machine->forceFill(['uid' => (string) Str::uuid(), 'certificate_serial' => 'abc123'])->save();

    return $machine;
}

/** @param  list<array<string, mixed>>  $samples */
function reportVitals(Machine $machine, array $samples): void
{
    test()->postJson('/api/gateway/machines/'.$machine->uid.'/ingest', ['envelopes' => [['v' => 1, 'kind' => 'telemetry', 'payload' => ['samples' => $samples]]]], ['X-Gateway-Secret' => 'test-gateway-secret'])
        ->assertOk();
}

/** @return array<string, mixed> */
function vitalsSample(int $ts, int $rx, int $tx, array $load = [0.5, 0.4, 0.3], int $processes = 120): array
{
    return ['ts' => $ts, 'cpu_percent' => 10, 'mem_used' => 1 << 30, 'load' => $load, 'process_count' => $processes, 'net_rx_bytes' => $rx, 'net_tx_bytes' => $tx, 'disks' => []];
}

test('telemetry keeps load, processes and the bytes each minute moved, across requests and a reboot', function () {
    $this->travelTo(vitalsNow());
    $machine = connectedMachine();
    $minute = vitalsNow()->startOfMinute()->getTimestamp() - 600;

    reportVitals($machine, [vitalsSample($minute, 1_000_000, 500_000)]);
    $machine->refresh();
    expect($machine->load_1)->toBe(0.5)
        ->and($machine->load_15)->toBe(0.3)
        ->and($machine->processes)->toBe(120)
        ->and($machine->net_rx_bytes)->toBe(1_000_000)
        ->and($machine->net_rx_rate)->toBeNull()
        ->and($machine->telemetry_ts)->toBe($minute)
        ->and(MachineSample::query()->where('ts', $minute)->sole()->net_rx)->toBeNull();

    reportVitals($machine, [vitalsSample($minute + 60, 7_000_000, 1_100_000, [1.0, 0.6, 0.35], 130)]);
    $machine->refresh();
    $row = MachineSample::query()->where('ts', $minute + 60)->sole();
    expect($row->net_rx)->toBe(6_000_000)
        ->and($row->net_tx)->toBe(600_000)
        ->and($row->load_1)->toEqual(1.0)
        ->and($row->load_5)->toEqual(0.6)
        ->and($row->processes)->toBe(130)
        ->and($machine->net_rx_rate)->toBe(100_000)
        ->and($machine->net_tx_rate)->toBe(10_000)
        ->and($machine->processes)->toBe(130)
        ->and($machine->load_1)->toBe(1.0);

    reportVitals($machine, [vitalsSample($minute + 120, 2_000, 1_000)]);
    $machine->refresh();
    $row = MachineSample::query()->where('ts', $minute + 120)->sole();
    expect($row->net_rx)->toBeNull()
        ->and($row->net_tx)->toBeNull()
        ->and($machine->net_rx_rate)->toBeNull()
        ->and($machine->net_rx_bytes)->toBe(2_000);

    reportVitals($machine, [vitalsSample($minute + 180, 62_000, 7_000)]);
    $machine->refresh();
    expect(MachineSample::query()->where('ts', $minute + 180)->sole()->net_rx)->toBe(60_000)
        ->and($machine->net_rx_rate)->toBe(1_000)
        ->and($machine->net_tx_rate)->toBe(100);
});

test('a minute sent in two parts counts every byte once and keeps the rate right', function () {
    $this->travelTo(vitalsNow());
    $machine = connectedMachine();
    $minute = vitalsNow()->startOfMinute()->getTimestamp() - 600;

    reportVitals($machine, [vitalsSample($minute, 100, 0)]);
    reportVitals($machine, [vitalsSample($minute, 300, 0), vitalsSample($minute + 60, 700, 0)]);

    expect(MachineSample::query()->where('ts', $minute)->sole()->net_rx)->toBe(200)
        ->and(MachineSample::query()->where('ts', $minute + 60)->sole()->net_rx)->toBe(400)
        ->and($machine->fresh()->net_rx_rate)->toBe(10);
});

test('an older reading sent late is kept in its minute but moves no bytes and leaves the newest values', function () {
    $this->travelTo(vitalsNow());
    $machine = connectedMachine();
    $minute = vitalsNow()->startOfMinute()->getTimestamp() - 600;

    reportVitals($machine, [vitalsSample($minute, 1_000, 0), vitalsSample($minute + 60, 2_000, 0, processes: 99)]);
    reportVitals($machine, [vitalsSample($minute - 300, 500, 0, [9, 9, 9], 7)]);

    $late = MachineSample::query()->where('ts', $minute - 300)->sole();
    $machine->refresh();
    expect($late->net_rx)->toBeNull()
        ->and($late->load_1)->toEqual(9)
        ->and($late->processes)->toBe(7)
        ->and($machine->processes)->toBe(99)
        ->and($machine->net_rx_bytes)->toBe(2_000)
        ->and($machine->telemetry_ts)->toBe($minute + 60);
});

test('a rate over a long gap is left out rather than shown as current', function () {
    $this->travelTo(vitalsNow());
    $machine = connectedMachine();
    $minute = vitalsNow()->startOfMinute()->getTimestamp() - 3600;

    reportVitals($machine, [vitalsSample($minute, 1_000, 0)]);
    reportVitals($machine, [vitalsSample($minute + 1800, 901_000, 0)]);

    expect(MachineSample::query()->where('ts', $minute + 1800)->sole()->net_rx)->toBe(900_000)
        ->and($machine->fresh()->net_rx_rate)->toBeNull();
});

test('a daemon saying hello after a reboot starts the counters again', function () {
    $this->travelTo(vitalsNow());
    $machine = connectedMachine();
    $machine->forceFill(['facts' => ['hostname' => 'box', 'boot_ts' => 1_700_000_000]])->save();
    $minute = vitalsNow()->startOfMinute()->getTimestamp() - 600;
    reportVitals($machine, [vitalsSample($minute, 1_000, 1_000)]);
    $hello = fn (int $boot) => $this->postJson('/api/gateway/hello', [
        'serial' => 'abc123',
        'hello' => ['machine_id' => $machine->uid, 'daemon_version' => 'v0.1.0', 'supported_majors' => [1], 'facts' => ['hostname' => 'box', 'boot_ts' => $boot]],
    ], ['X-Gateway-Secret' => 'test-gateway-secret'])->assertOk();

    $hello(1_700_000_001);
    expect($machine->fresh()->net_rx_bytes)->toBe(1_000);

    $hello(1_760_000_000);
    expect($machine->fresh()->net_rx_bytes)->toBeNull();

    reportVitals($machine, [vitalsSample($minute + 60, 5_000, 5_000)]);
    expect(MachineSample::query()->where('ts', $minute + 60)->sole()->net_rx)->toBeNull()
        ->and($machine->fresh()->net_rx_bytes)->toBe(5_000);
});

test('the machine page shows the newest load, processes and network, and the month’s traffic', function () {
    $this->travelTo(vitalsNow());
    $machine = connectedMachine();
    $minute = vitalsNow()->startOfMinute()->getTimestamp() - 120;
    reportVitals($machine, [vitalsSample($minute, 1_000_000, 100_000)]);
    reportVitals($machine, [vitalsSample($minute + 60, 4_000_000, 700_000, [0.75, 0.5, 0.25], 210)]);

    $this->actingAs($machine->workspace->owner)
        ->get($machine->workspace->url().'/machines/'.$machine->id)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('workspace/MachineShow')
            ->missing('preview')
            ->where('live.load', [0.75, 0.5, 0.25])
            ->where('live.processes', 210)
            ->where('live.networkIn', 50_000)
            ->where('live.networkOut', 10_000)
            ->where('live.at', Carbon::createFromTimestamp($minute + 60)->toIso8601String())
            ->where('traffic.received', 3_000_000)
            ->where('traffic.sent', 600_000)
            ->where('traffic.since', Carbon::createFromTimestamp($minute + 60)->toIso8601String())
            ->etc());
});

test('the month’s traffic says since when only part of the month was counted', function () {
    $this->travelTo(vitalsNow());
    $workspace = Workspace::factory()->create();
    $machine = Machine::factory()->for($workspace)->create();
    $start = vitalsNow()->startOfMonth()->getTimestamp();
    $row = fn (int $ts, ?int $rx, ?int $tx) => MachineSample::query()->create(['machine_id' => $machine->id, 'ts' => $ts, 'samples' => 1, 'net_rx' => $rx, 'net_tx' => $tx]);
    $row($start + 86_400, 1_000, 10);
    $row($start + 2 * 86_400, 2_000, 20);
    $row($start + 3 * 86_400, null, null);

    expect(MachineSample::monthTraffic($machine))->toBe([
        'received' => 3_000,
        'sent' => 30,
        'since' => Carbon::createFromTimestamp($start + 86_400)->toIso8601String(),
    ]);

    $row($start - 600, 5_000, 5_000);
    expect(MachineSample::monthTraffic($machine))->toBe(['received' => 3_000, 'sent' => 30, 'since' => null]);
});

test('a machine with nothing reported shows no load, network, processes or traffic, and no made-up CPU', function () {
    $this->travelTo(vitalsNow());
    $machine = connectedMachine();
    MachineSample::query()->create(['machine_id' => $machine->id, 'ts' => vitalsNow()->startOfMinute()->getTimestamp(), 'samples' => 1, 'cpu' => 12]);

    $this->actingAs($machine->workspace->owner)
        ->get($machine->workspace->url().'/machines/'.$machine->id)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->missing('preview')
            ->where('machine.cpu', null)
            ->where('live', [
                'cpu' => null,
                'memoryMb' => null,
                'load' => null,
                'processes' => null,
                'networkIn' => null,
                'networkOut' => null,
                'at' => null,
            ])
            ->where('traffic', null)
            ->etc());
});
