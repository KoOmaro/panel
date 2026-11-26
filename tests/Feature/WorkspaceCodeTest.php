<?php

use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/** @return array{0: Workspace, 1: Machine, 2: Machine} */
function codeWorkspace(): array
{
    $workspace = Workspace::factory()->create(['slug' => 'northwind', 'name' => 'Northwind']);
    $web = Machine::factory()->for($workspace)->create(['name' => 'web-1', 'provider' => 'Hetzner', 'region' => 'fsn1']);
    $db = Machine::factory()->for($workspace)->create(['name' => 'db-1', 'provider' => 'DigitalOcean', 'backup_schedule' => 'daily']);
    $web->forceFill(['inventory' => ['scanned_at' => now()->getTimestamp(), 'complete' => true, 'listeners' => [], 'services' => [
        ['key' => 'systemd:nginx.service', 'name' => 'nginx', 'kind' => 'proxy', 'manager' => 'systemd', 'status' => 'running', 'ports' => [80, 443]],
        ['key' => 'docker:abc', 'name' => 'scheduler', 'kind' => 'container', 'manager' => 'docker', 'status' => 'running', 'ports' => []],
    ]]])->save();
    $db->forceFill(['inventory' => ['scanned_at' => now()->getTimestamp(), 'complete' => true, 'listeners' => [], 'services' => [
        ['key' => 'systemd:postgresql.service', 'name' => 'postgres', 'kind' => 'database', 'manager' => 'systemd', 'status' => 'running', 'ports' => [5432]],
    ]]])->save();
    $workspace->monitors()->create(['name' => 'Shop', 'type' => 'http', 'url' => 'https://shop.northwind.dev', 'interval_seconds' => 60, 'timeout_seconds' => 10, 'status' => 'up']);

    $project = $workspace->projects()->create(['name' => 'Shop']);
    $environment = $project->production();
    $app = $project->nodes()->create(['machine_id' => $web->id, 'environment_id' => $environment->id, 'name' => 'web', 'kind' => 'app']);
    $database = $project->nodes()->create(['machine_id' => $db->id, 'environment_id' => $environment->id, 'name' => 'db', 'kind' => 'database', 'template' => 'postgres']);
    $project->links()->create(['app_node_id' => $app->id, 'resource_node_id' => $database->id])->forceFill(['state' => 'applied', 'env' => ['DATABASE_URL']])->save();
    DaemonJob::query()->create([
        'id' => (string) Str::uuid(), 'machine_id' => $web->id, 'user_id' => $workspace->owner_id, 'kind' => 'deploy', 'service' => 'web', 'state' => 'succeeded',
        'command' => ['job' => ['type' => 'deploy', 'service' => 'web', 'repo' => 'https://github.com/northwind/shop', 'domains' => ['shop.northwind.dev'], 'port' => 3000, 'env' => [
            'APP_KEY' => 'base64:do-not-show-this',
            'DATABASE_URL' => 'postgres://shop:hunter2@db/shop',
            'NEXT_TELEMETRY_DISABLED' => '1',
        ]]],
        'result' => [],
    ]);

    return [$workspace, $web, $db];
}

/** @param  array<string, mixed>  $page */
function codeFile(array $page, string $path): string
{
    foreach ($page['props']['code']['files'] as $file) {
        if ($file['path'] === $path) {
            return implode("\n", $file['lines']);
        }
    }

    throw new RuntimeException("No file {$path}");
}

test('the workspace is written out as files from what it really runs', function () {
    [$workspace] = codeWorkspace();
    $member = User::factory()->create();
    $workspace->members()->attach($member, ['role' => 'member']);

    $response = $this->actingAs($member)->get(route('workspace.code.index', $workspace))->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->component('workspace/code/WorkspaceCode')
        ->where('code.machines', 2)
        ->where('code.services', 3)
        ->where('code.monitors', 1)
        ->where('code.projects', 1)
        ->where('code.secrets', 1)
        ->where('code.open', 'projects/shop.yml')
        ->where('code.files', fn ($files) => collect($files)->pluck('path')->all() === ['machines/db-1.yml', 'machines/web-1.yml', 'projects/shop.yml', 'backups.yml', 'monitors.yml', 'policies.yml', 'serveros.yml'])
        ->where('sample.repo', 'northwind/infra')
        ->has('sample.needsLook', 2));

    $page = $response->viewData('page');
    $shop = codeFile($page, 'projects/shop.yml');
    expect($shop)
        ->toContain('repo: northwind/shop')
        ->toContain('    machine: web-1')
        ->toContain('    domain: shop.northwind.dev')
        ->toContain('      APP_KEY: ${{ secrets.APP_KEY }}')
        ->toContain('      DATABASE_URL: ${{ services.db.DATABASE_URL }}')
        ->toContain('      NEXT_TELEMETRY_DISABLED: ${{ vars.NEXT_TELEMETRY_DISABLED }}')
        ->toContain('    template: postgres')
        ->toContain('    backups: daily')
        ->toContain('  - https://shop.northwind.dev');
    expect(codeFile($page, 'machines/web-1.yml'))
        ->toContain('machine: web-1')
        ->toContain('provider: hetzner')
        ->toContain('    ports: [80, 443]');

    $everything = collect($page['props']['code']['files'])->flatMap(fn (array $file) => $file['lines'])->implode("\n");
    expect($everything)->not->toContain('do-not-show-this')->not->toContain('hunter2');
});

test('a workspace with nothing in it gets the empty files', function () {
    $workspace = Workspace::factory()->create();

    $this->actingAs($workspace->owner)
        ->get(route('workspace.code.index', $workspace))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('code.machines', 0)
            ->where('code.files', fn ($files) => collect($files)->pluck('path')->all() === ['backups.yml', 'monitors.yml', 'policies.yml', 'serveros.yml'])
            ->where('sample.needsLook', []));
});

test('only people who can manage machines are offered to connect GitHub', function () {
    [$workspace] = codeWorkspace();
    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);

    $this->actingAs($workspace->owner)
        ->get(route('workspace.code.index', $workspace))
        ->assertInertia(fn (Assert $page) => $page
            ->where('github.connected', false)
            ->where('github.connectUrl', route('workspace.github.app', $workspace, false)));

    $this->actingAs($viewer)
        ->get(route('workspace.code.index', $workspace))
        ->assertInertia(fn (Assert $page) => $page->where('github.connectUrl', null));
});

test('a connected GitHub account names the repository', function () {
    [$workspace] = codeWorkspace();
    $workspace->githubInstallations()->create(['installation_id' => 42, 'account_login' => 'Northwind-HQ', 'account_type' => 'Organization', 'repository_ids' => []]);

    $this->actingAs($workspace->owner)
        ->get(route('workspace.code.index', $workspace))
        ->assertInertia(fn (Assert $page) => $page
            ->where('github.connected', true)
            ->where('github.account', 'Northwind-HQ')
            ->where('github.connectUrl', null)
            ->where('sample.repo', 'northwind-hq/infra'));
});

test('set up code writes the prompt for the newest project, or the one asked for', function () {
    [$workspace, $web] = codeWorkspace();
    $docs = $workspace->projects()->create(['name' => 'Docs']);
    $docs->forceFill(['created_at' => now()->subDay()])->save();
    $docs->nodes()->create(['machine_id' => $web->id, 'environment_id' => $docs->production()->id, 'name' => 'docs', 'kind' => 'app']);

    $this->actingAs($workspace->owner)
        ->get(route('workspace.code.setup', $workspace))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('workspace/code/SetUpCode')
            ->where('project.name', 'Shop')
            ->where('project.environment', 'Production')
            ->where('sample.repo', 'northwind/shop')
            ->where('sample.stack', 'next')
            ->where('mcpUrl', route('mcp', ['workspace' => 'northwind']))
            ->has('projects', 2));

    $this->actingAs($workspace->owner)
        ->get(route('workspace.code.setup', ['workspace' => $workspace, 'project' => $docs->uid]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('project.name', 'Docs')
            ->where('sample.repo', 'northwind/docs'));
});

test('set up code and review changes say so when there is no project yet', function (string $route) {
    $workspace = Workspace::factory()->create();
    Machine::factory()->for($workspace)->create();

    $this->actingAs($workspace->owner)
        ->get(route($route, $workspace))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('project', null)
            ->where('sample', null));
})->with(['workspace.code.setup', 'workspace.code.changes']);

test('review changes plans a sample pull request on the real machines', function () {
    [$workspace, $web, $db] = codeWorkspace();

    $this->actingAs($workspace->owner)
        ->get(route('workspace.code.changes', $workspace))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('workspace/code/ReviewChanges')
            ->where('project.name', 'Shop')
            ->where('sample.author', Str::slug($workspace->owner->name))
            ->has('sample.changes', 4)
            ->where('sample.changes.0.machine.name', 'db-1')
            ->where('sample.changes.1.machine.name', 'web-1')
            ->where('sample.changes.1.title', 'Change web from 2 to 3 copies, and set REDIS_URL')
            ->where('sample.changes.3.risk.tone', 'red')
            ->where('sample.machines.0.id', $web->id)
            ->where('sample.machines.0.changes', 2)
            ->where('sample.machines.1.id', $db->id));
});

test('two projects with the same name get their own files', function () {
    [$workspace] = codeWorkspace();
    $workspace->projects()->create(['name' => 'shop']);

    $this->actingAs($workspace->owner)
        ->get(route('workspace.code.index', $workspace))
        ->assertInertia(fn (Assert $page) => $page
            ->where('code.files', fn ($files) => collect($files)->pluck('path')->filter(fn ($path) => str_starts_with($path, 'projects/'))->values()->all() === ['projects/shop.yml', 'projects/shop-2.yml'])
            ->where('code.open', 'projects/shop-2.yml'));
});
