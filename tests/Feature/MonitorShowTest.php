<?php

use App\Enums\Plan;
use App\Models\Monitor;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

function showMonitor(array $overrides = []): Monitor
{
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();

    return $workspace->monitors()->create([
        'name' => 'Analytics', 'type' => 'http', 'url' => 'https://stats.example.com/',
        'interval_seconds' => 60, 'timeout_seconds' => 10, 'status' => 'up', 'alerts' => true,
        ...$overrides,
    ]);
}

/** @param  array<string, mixed>  $values */
function addCheck(Monitor $monitor, string $at, array $values = []): void
{
    $monitor->checks()->create([
        'checked_at' => CarbonImmutable::parse($at),
        'ok' => true,
        'response_ms' => 200,
        'status_code' => 200,
        ...$values,
    ]);
}

function showPage(Monitor $monitor, string $query = ''): TestResponse
{
    $workspace = $monitor->workspace;

    return test()->actingAs($workspace->owner)->get($workspace->url("/monitors/{$monitor->id}{$query}"));
}

beforeEach(fn () => $this->travelTo(CarbonImmutable::parse('2026-11-26 14:40:00')));

test('the figures come from the checks and incidents', function () {
    $monitor = showMonitor();
    addCheck($monitor, '2026-11-26 14:00:00', ['response_ms' => 100]);
    addCheck($monitor, '2026-11-26 14:01:00', ['response_ms' => 300]);
    addCheck($monitor, '2026-11-26 14:02:00', ['ok' => false, 'response_ms' => 5000, 'status_code' => 503, 'error' => 'Returned 503.']);
    addCheck($monitor, '2026-11-25 10:00:00', ['response_ms' => 900]);
    $monitor->incidents()->create(['started_at' => now()->subDays(6)->subMinutes(9), 'resolved_at' => now()->subDays(6), 'cause' => 'Timed out after 10 s.']);
    $monitor->daily = [now()->toDateString() => [100, 99, 19800]];
    $monitor->save();

    showPage($monitor)->assertInertia(fn (Assert $page) => $page
        ->component('workspace/MonitorShow')
        ->where('figures.uptime30', 99)
        ->where('figures.uptime90', 99)
        ->where('figures.averageMs', 200)
        ->where('figures.lastIncident.resolvedAt', now()->subDays(6)->toIso8601String()));
});

test('a monitor with no history shows empty figures', function () {
    $monitor = showMonitor(['status' => 'pending']);

    showPage($monitor)->assertInertia(fn (Assert $page) => $page
        ->where('figures.uptime30', null)
        ->where('figures.averageMs', null)
        ->where('figures.lastIncident', null)
        ->where('chart.averageMs', null)
        ->where('incidents.count', 0)
        ->has('checks', 0)
        ->where('days.89.tone', 'none'));
});

test('the response chart has a bar every 15 minutes, or every 2 hours over 7 days', function () {
    $monitor = showMonitor();
    addCheck($monitor, '2026-11-26 14:31:00', ['response_ms' => 600]);
    addCheck($monitor, '2026-11-26 14:32:00', ['response_ms' => 700]);
    addCheck($monitor, '2026-11-26 14:33:00', ['ok' => false, 'response_ms' => null, 'status_code' => null, 'error' => 'Timed out after 10 s.']);
    addCheck($monitor, '2026-11-26 09:05:00', ['response_ms' => 200]);
    addCheck($monitor, '2026-11-21 09:05:00', ['response_ms' => 400]);

    showPage($monitor)->assertInertia(fn (Assert $page) => $page
        ->where('range', '24h')
        ->where('chart.step', 900)
        ->has('chart.bars', 96)
        ->where('chart.bars.95.at', '2026-11-26T14:30:00+00:00')
        ->where('chart.bars.95.ms', 650)
        ->where('chart.bars.95.failed', 1)
        ->where('chart.bars.73.at', '2026-11-26T09:00:00+00:00')
        ->where('chart.bars.73.ms', 200)
        ->where('chart.averageMs', 500));

    showPage($monitor, '?range=7d')->assertInertia(fn (Assert $page) => $page
        ->where('range', '7d')
        ->where('chart.step', 7200)
        ->has('chart.bars', 84)
        ->where('chart.bars.83.at', '2026-11-26T14:00:00+00:00')
        ->where('chart.bars.83.ms', 650)
        ->where('chart.averageMs', 475));

    showPage($monitor, '?range=1y')->assertSessionHasErrors('range');
});

test('each of the last 90 days is coloured by the longest outage that touched it', function () {
    $monitor = showMonitor(['status' => 'down']);
    $monitor->daily = [
        '2026-11-20' => [1440, 1431, 0],
        '2026-11-10' => [1440, 1368, 0],
        '2026-11-01' => [1440, 1440, 0],
    ];
    $monitor->save();
    $monitor->incidents()->create(['started_at' => '2026-11-20 03:12:00', 'resolved_at' => '2026-11-20 03:21:00', 'cause' => 'Timed out after 10 s.']);
    $monitor->incidents()->create(['started_at' => '2026-11-10 23:30:00', 'resolved_at' => '2026-11-11 00:20:00', 'cause' => 'Connection refused.']);
    $monitor->incidents()->create(['started_at' => '2026-11-26 13:40:00', 'resolved_at' => null, 'cause' => 'Returned 503.']);
    $monitor->incidents()->create(['started_at' => '2026-07-01 10:00:00', 'resolved_at' => '2026-07-01 12:00:00', 'cause' => 'Connection refused.']);

    showPage($monitor)->assertInertia(fn (Assert $page) => $page
        ->has('days', 90)
        ->where('days.89.day', '2026-11-26')
        ->where('days.89.down', 3600)
        ->where('days.89.tone', 'long')
        ->where('days.83.day', '2026-11-20')
        ->where('days.83.down', 540)
        ->where('days.83.tone', 'short')
        ->where('days.73.day', '2026-11-10')
        ->where('days.73.down', 1800)
        ->where('days.73.tone', 'long')
        ->where('days.74.down', 1200)
        ->where('days.74.tone', 'long')
        ->where('days.64.day', '2026-11-01')
        ->where('days.64.tone', 'up')
        ->where('days.0.tone', 'none'));
});

test('incidents from the last 90 days are listed with what went wrong', function () {
    $monitor = showMonitor(['status' => 'down']);
    $monitor->incidents()->create(['started_at' => '2026-11-26 14:30:00', 'resolved_at' => null, 'cause' => 'Returned 503.']);
    $monitor->incidents()->create(['started_at' => '2026-11-20 03:12:00', 'resolved_at' => '2026-11-20 03:21:00', 'cause' => 'Timed out after 10 s.']);
    $monitor->incidents()->create(['started_at' => '2026-09-15 22:05:00', 'resolved_at' => '2026-09-15 23:17:00', 'cause' => 'Returned 502.']);
    $monitor->incidents()->create(['started_at' => '2026-07-01 10:00:00', 'resolved_at' => '2026-07-01 12:00:00', 'cause' => 'Connection refused.']);

    showPage($monitor)->assertInertia(fn (Assert $page) => $page
        ->where('incidents.count', 3)
        ->has('incidents.list', 3)
        ->where('incidents.list.0.tone', 'ongoing')
        ->where('incidents.list.0.cause', 'Returned 503 Service Unavailable.')
        ->where('incidents.list.1.tone', 'short')
        ->where('incidents.list.1.seconds', 540)
        ->where('incidents.list.1.cause', 'Timed out after 10 seconds.')
        ->where('incidents.list.2.tone', 'long')
        ->where('incidents.list.2.cause', 'Returned 502 Bad Gateway.')
        ->where('figures.lastIncident.resolvedAt', null));
});

test('the last five checks say what came back', function () {
    $monitor = showMonitor();
    foreach (range(1, 6) as $minute) {
        addCheck($monitor, "2026-11-26 14:0{$minute}:00", ['response_ms' => 200 + $minute]);
    }
    addCheck($monitor, '2026-11-26 14:10:00', ['ok' => false, 'response_ms' => 40, 'status_code' => 503, 'error' => 'Returned 503.']);
    addCheck($monitor, '2026-11-26 14:11:00', ['ok' => false, 'response_ms' => null, 'status_code' => null, 'error' => 'Timed out after 10 s.']);

    showPage($monitor)->assertInertia(fn (Assert $page) => $page
        ->has('checks', 5)
        ->where('checks.0.label', 'Timed out after 10 s.')
        ->where('checks.0.ok', false)
        ->where('checks.1.label', '503 Service Unavailable')
        ->where('checks.2.label', '200 OK')
        ->where('checks.2.ms', 206));

    $port = showMonitor(['type' => 'tcp', 'url' => null, 'host' => 'db.example.com', 'port' => 5432]);
    addCheck($port, '2026-11-26 14:00:00', ['status_code' => null, 'response_ms' => 3]);

    showPage($port)->assertInertia(fn (Assert $page) => $page->where('checks.0.label', 'Connected'));
});

test('another workspace cannot see the monitor', function () {
    $monitor = showMonitor();
    $other = Workspace::factory()->onPlan(Plan::Pro)->create();

    $this->actingAs($other->owner)->get($other->url("/monitors/{$monitor->id}"))->assertNotFound();
});
