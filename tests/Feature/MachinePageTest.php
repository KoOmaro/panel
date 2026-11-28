<?php

use App\Enums\WorkspaceRole;
use App\Models\Backup;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\MachinePackageUpdate;
use App\Models\MachineSample;
use App\Models\ProjectNode;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

test('the machine page gives the overview its day of usage, updates, backups and projects', function () {
    $workspace = Workspace::factory()->create();
    $machine = Machine::factory()->for($workspace)->create(['cpu_percent' => 34, 'memory_used_mb' => 5017]);
    $machine->forceFill([
        'updates_count' => 3,
        'security_updates_count' => 1,
        'updates_checked_at' => now()->subHour(),
        'backup_schedule' => 'daily',
    ])->save();
    MachineSample::query()->create(['machine_id' => $machine->id, 'ts' => intdiv(now()->getTimestamp(), 60) * 60, 'samples' => 6, 'cpu' => 78, 'cpu_max' => 80, 'mem_used' => 100, 'mem_max' => 200, 'disk_percent' => 40]);
    MachinePackageUpdate::query()->create(['machine_id' => $machine->id, 'name' => 'curl', 'installed' => '8.5.0-2', 'candidate' => '8.5.0-3', 'security' => false]);
    MachinePackageUpdate::query()->create(['machine_id' => $machine->id, 'name' => 'openssl', 'installed' => '3.0.13-1', 'candidate' => '3.0.13-2', 'security' => true]);
    Backup::query()->forceCreate(['machine_id' => $machine->id, 'service' => 'docker:aaa', 'service_name' => 'shop', 'trigger' => 'scheduled', 'state' => 'succeeded', 'bytes' => 2048]);

    foreach (['Shop', 'Docs'] as $name) {
        $project = $workspace->projects()->create(['name' => $name]);
        foreach (['web', 'db'] as $node) {
            (new ProjectNode)->forceFill(['project_id' => $project->id, 'environment_id' => $project->production()->id, 'machine_id' => $machine->id, 'name' => $node, 'kind' => 'app'])->save();
        }
    }

    $this->actingAs($workspace->owner)
        ->get($workspace->url().'/machines/'.$machine->id)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('workspace/MachineShow')
            ->where('day', fn ($points) => collect($points)->contains('cpu', 78))
            ->where('live.cpu', 34)
            ->where('live.memoryMb', 5017)
            ->where('overview.projects', 2)
            ->where('overview.updates.count', 3)
            ->where('overview.updates.security', 1)
            ->where('overview.updates.packages.0.name', 'openssl')
            ->where('overview.updates.packages.0.security', true)
            ->where('overview.updates.packages.1.name', 'curl')
            ->where('overview.backup.schedule', 'daily')
            ->where('overview.backups.0.serviceName', 'shop')
            ->where('overview.backups.0.bytes', 2048)
            ->missing('preview')
            ->where('live.load', null)
            ->where('live.networkIn', null)
            ->where('traffic', null)
            ->etc());
});

test('the machine page keeps another workspace’s backups and updates out', function () {
    $workspace = Workspace::factory()->create();
    $machine = Machine::factory()->for($workspace)->create();
    $other = Machine::factory()->create();
    MachinePackageUpdate::query()->create(['machine_id' => $other->id, 'name' => 'curl', 'installed' => '1', 'candidate' => '2', 'security' => false]);
    Backup::query()->forceCreate(['machine_id' => $other->id, 'service' => 'docker:aaa', 'service_name' => 'theirs', 'trigger' => 'manual', 'state' => 'succeeded']);

    $this->actingAs($workspace->owner)
        ->get($workspace->url().'/machines/'.$machine->id)
        ->assertInertia(fn (Assert $page) => $page
            ->where('overview.updates.packages', [])
            ->where('overview.backups', [])
            ->where('overview.projects', 0)
            ->etc());
});

test('the machine page opens on its updates, backups, firewall and terminal tabs', function () {
    $workspace = Workspace::factory()->create();
    $machine = Machine::factory()->for($workspace)->create();
    $url = $workspace->url().'/machines/'.$machine->id;
    $this->actingAs($workspace->owner);

    foreach (['/system-updates', '/backups', '/firewall', '/terminal'] as $tab) {
        $this->get($url.$tab)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('workspace/MachineShow')->where('machine.id', $machine->id)->etc());
    }

    $this->get($url.'/elsewhere')->assertNotFound();
});

test('opening a machine leaves deploy logs for the dialog', function () {
    $workspace = Workspace::factory()->create();
    $machine = Machine::factory()->for($workspace)->create();
    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => WorkspaceRole::Viewer->value]);
    $lines = array_fill(0, 500, 'release step');
    $release = DaemonJob::query()->create([
        'id' => (string) Str::uuid(),
        'machine_id' => $machine->id,
        'kind' => 'release',
        'service' => 'shop',
        'state' => 'succeeded',
        'log' => $lines,
    ]);
    $failed = DaemonJob::query()->create([
        'id' => (string) Str::uuid(),
        'machine_id' => $machine->id,
        'kind' => 'deploy',
        'service' => 'shop',
        'state' => 'failed',
        'command' => ['job' => []],
        'error' => ['message' => 'docker: not found'],
        'log' => ['docker: not found'],
    ]);
    $console = DaemonJob::query()->create([
        'id' => (string) Str::uuid(),
        'machine_id' => $machine->id,
        'kind' => 'service_exec',
        'service' => 'docker:abc',
        'state' => 'succeeded',
        'command' => ['job' => ['command' => 'cat .env']],
        'log' => ['$ cat .env', 'SECRET=hunter2'],
    ]);
    $url = $workspace->url().'/machines/'.$machine->id;

    $this->actingAs($workspace->owner)->get($url)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('jobs', function ($jobs) use ($console, $failed, $release) {
                $logs = collect($jobs)->pluck('log', 'id');

                return $logs[$console->id] === ['$ cat .env', 'SECRET=hunter2']
                    && $logs[$failed->id] === null
                    && $logs[$release->id] === null
                    && str_contains((string) collect($jobs)->firstWhere('id', $failed->id)['explanation']['cause'], 'Docker');
            })
            ->missing('templates')
            ->etc());

    $this->actingAs($workspace->owner)->getJson($url.'/jobs/'.$release->id)
        ->assertOk()
        ->assertJsonPath('log', $lines);

    $this->actingAs($viewer)->getJson($url.'/jobs/'.$console->id)->assertNotFound();
    $this->actingAs($viewer)->get($url)->assertDontSee('hunter2');

    $other = DaemonJob::query()->create([
        'id' => (string) Str::uuid(),
        'machine_id' => Machine::factory()->create()->id,
        'kind' => 'release',
        'state' => 'succeeded',
        'log' => ['elsewhere'],
    ]);
    $this->actingAs($workspace->owner)->getJson($url.'/jobs/'.$other->id)->assertNotFound();
});

test('the machines list says when each machine was last backed up', function () {
    $workspace = Workspace::factory()->create();
    $at = now()->subDays(3)->startOfSecond();
    Machine::factory()->for($workspace)->create(['last_backup_at' => $at]);
    Machine::factory()->for($workspace)->create(['last_backup_at' => null]);

    $this->actingAs($workspace->owner)
        ->get($workspace->url().'/machines')
        ->assertInertia(fn (Assert $page) => $page
            ->component('workspace/Machines')
            ->where('machines', fn ($machines) => collect($machines)->pluck('lastBackupAt')->filter()->values()->all() === [$at->toIso8601String()])
            ->etc());
});
