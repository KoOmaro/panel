<?php

use App\Enums\Plan;
use App\Models\Activity;
use App\Models\Machine;
use App\Models\User;
use App\Models\Workspace;
use Inertia\Testing\AssertableInertia as Assert;

function overviewWorkspace(): Workspace
{
    return Workspace::factory()->onPlan(Plan::Pro)->create();
}

test('the monitors list shows each monitor’s machine and the service it watches', function () {
    $workspace = overviewWorkspace();
    $machine = Machine::factory()->for($workspace)->create([
        'name' => 'db-1',
        'provider' => 'Hetzner',
        'inventory' => ['services' => [
            ['key' => 'systemd:postgresql.service', 'name' => 'postgres', 'ports' => [5432]],
            ['key' => 'docker:abc', 'name' => 'serveros-shop-0123456789ab', 'ports' => [], 'details' => ['image' => 'ghcr.io/acme/shop:1']],
        ]],
    ]);
    $workspace->monitors()->create([
        'name' => 'Postgres', 'type' => 'tcp', 'host' => 'db-1', 'port' => 5432, 'machine_id' => $machine->id,
        'interval_seconds' => 30, 'timeout_seconds' => 10, 'status' => 'up', 'alerts' => true,
    ]);
    $workspace->monitors()->create([
        'name' => 'Shop', 'type' => 'http', 'url' => 'https://shop.example.com', 'machine_id' => $machine->id,
        'interval_seconds' => 60, 'timeout_seconds' => 10, 'status' => 'up', 'alerts' => true,
    ]);

    $this->actingAs($workspace->owner)->get($workspace->url('/monitors'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('workspace/Monitors')
            ->where('monitors.0.name', 'Postgres')
            ->where('monitors.0.machine', ['id' => $machine->id, 'name' => 'db-1', 'provider' => 'Hetzner'])
            ->where('monitors.0.service.name', 'postgres')
            ->where('monitors.1.name', 'Shop')
            ->where('monitors.1.service.name', 'shop')
            ->where('monitors.1.service.image', 'ghcr.io/acme/shop:1'));
});

test('the monitors list says who paused a monitor and the overall uptime', function () {
    $workspace = overviewWorkspace();
    $maya = User::factory()->create(['name' => 'Maya Patel']);
    $today = now()->toDateString();
    $workspace->monitors()->create([
        'name' => 'Staging', 'type' => 'http', 'url' => 'https://staging.example.com',
        'interval_seconds' => 60, 'timeout_seconds' => 10, 'status' => 'up', 'alerts' => true, 'paused' => true,
    ]);
    $up = $workspace->monitors()->create([
        'name' => 'Shop', 'type' => 'http', 'url' => 'https://shop.example.com',
        'interval_seconds' => 60, 'timeout_seconds' => 10, 'status' => 'up', 'alerts' => true,
    ]);
    $up->forceFill(['daily' => [$today => [100, 99, 9900]]])->save();
    Activity::record($workspace, 'monitor.pause', 'Staging', user: $maya);

    $this->actingAs($workspace->owner)->get($workspace->url('/monitors'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('uptime', 99)
            ->where('monitors.1.name', 'Staging')
            ->where('monitors.1.status', 'paused')
            ->where('monitors.1.pausedBy.name', 'Maya Patel')
            ->where('monitors.0.pausedBy', null)
            ->where('statusPage.shown', 0));
});

test('the status page card counts the monitors it shows', function () {
    $workspace = overviewWorkspace();
    $shown = $workspace->monitors()->create([
        'name' => 'Shop', 'type' => 'http', 'url' => 'https://shop.example.com',
        'interval_seconds' => 60, 'timeout_seconds' => 10, 'status' => 'up', 'alerts' => true,
    ]);
    $workspace->monitors()->create([
        'name' => 'Docs', 'type' => 'http', 'url' => 'https://docs.example.com',
        'interval_seconds' => 60, 'timeout_seconds' => 10, 'status' => 'up', 'alerts' => true,
    ]);
    $workspace->fleetStatusPage()->create(['slug' => 'acme-status', 'enabled' => true, 'monitors' => [$shown->id]]);

    $this->actingAs($workspace->owner)->get($workspace->url('/monitors'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('statusPage.set', true)
            ->where('statusPage.shown', 1));
});
