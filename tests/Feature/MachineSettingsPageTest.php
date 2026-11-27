<?php

use App\Models\Machine;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Logs\LogStore;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

function settingsPageMachine(Workspace $workspace, array $attributes = []): Machine
{
    $machine = Machine::factory()->for($workspace)->create(['name' => 'web-1', 'provider' => 'Hetzner']);
    $machine->forceFill([
        'uid' => (string) Str::uuid(),
        'daemon_version' => 'v0.2.0',
        'last_seen_at' => now(),
        'facts' => ['os' => 'Ubuntu', 'hosting' => ['provider' => 'Hetzner', 'region' => 'fsn1', 'instance_type' => 'CX32']],
        ...$attributes,
    ])->save();

    return $machine->fresh();
}

function settingsPageUrl(Workspace $workspace, Machine $machine): string
{
    return $workspace->url().'/machines/'.$machine->id.'/settings';
}

function settingsPageLogs(Machine $machine, string $service, int $lines): void
{
    LogStore::counts($machine->workspace_id)->insert([
        'machine_id' => $machine->id, 'minute' => now()->getTimestampMs() - now()->getTimestampMs() % 60_000, 'service' => $service, 'level' => 'info', 'n' => $lines,
    ]);
}

test('the settings page passes what the daemon detected and the providers in use', function () {
    $workspace = Workspace::factory()->create();
    $machine = settingsPageMachine($workspace, ['updates_count' => 4]);
    Machine::factory()->for($workspace)->create(['provider' => 'Vultr']);

    $this->actingAs($workspace->owner)->get(settingsPageUrl($workspace, $machine))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('workspace/MachineSettings')
            ->where('detected.provider', 'Hetzner')
            ->where('detected.region', 'fsn1')
            ->where('detected.tier', 'CX32')
            ->where('providers', ['Hetzner', 'Vultr'])
            ->where('updatesWaiting', 4)
            ->where('deployEnabled', false)
            ->missing('skippedLogs'));
});

test('a machine the daemon knows nothing about detects nothing', function () {
    $workspace = Workspace::factory()->create();
    $machine = settingsPageMachine($workspace, ['facts' => null]);

    $this->actingAs($workspace->owner)->get(settingsPageUrl($workspace, $machine))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('detected.provider', null)
            ->where('detected.region', null)
            ->where('detected.tier', null)
            ->where('updatesWaiting', 0));
});

test('stored lines are counted for each skipped service once asked', function () {
    $workspace = Workspace::factory()->create();
    $machine = settingsPageMachine($workspace, ['log_skip' => [
        'services' => ['shop-*', 'worker'],
        'patterns' => [['service' => null, 'pattern' => 'GET /healthz']],
    ]]);
    settingsPageLogs($machine, 'shop-api', 10);
    settingsPageLogs($machine, 'shop-web', 4);
    settingsPageLogs($machine, 'postgres', 7);

    $url = settingsPageUrl($workspace, $machine);
    $page = $this->actingAs($workspace->owner)->get($url)->viewData('page');

    $this->actingAs($workspace->owner)
        ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => (string) $page['version'], 'X-Inertia-Partial-Component' => 'workspace/MachineSettings', 'X-Inertia-Partial-Data' => 'skippedLogs'])
        ->get($url)
        ->assertOk()
        ->assertJsonPath('props.skippedLogs', [
            ['rule' => 'shop-*', 'services' => [['service' => 'shop-api', 'lines' => 10], ['service' => 'shop-web', 'lines' => 4]]],
            ['rule' => 'worker', 'services' => []],
        ]);
});

test('saving the whole form with an empty region goes back to the detected one', function () {
    $workspace = Workspace::factory()->create();
    $machine = settingsPageMachine($workspace, ['region' => 'nbg1', 'region_manual' => true]);

    $this->actingAs($workspace->owner)
        ->patch($workspace->url().'/machines/'.$machine->id, [
            'name' => 'web-1', 'environment' => 'production', 'provider' => '', 'region' => '', 'tier' => 'CX32',
            'labels' => [['key' => 'customer', 'value' => 'acme']],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $machine->refresh();
    expect($machine->region)->toBe('fsn1')
        ->and($machine->region_manual)->toBeFalse()
        ->and($machine->tier)->toBe('CX32')
        ->and($machine->labels)->toBe(['customer' => 'acme']);
});

test('people who can only view still get the page without the collected data', function () {
    $workspace = Workspace::factory()->create();
    $machine = settingsPageMachine($workspace);
    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);

    $this->actingAs($viewer)->get(settingsPageUrl($workspace, $machine))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('canManage', false)
            ->has('policy')
            ->has('logSkip'));
});
