<?php

use App\Models\Activity;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Code\CodePlan;
use App\Support\Code\CodeScreens;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/** @return array{0: Workspace, 1: Machine, 2: Machine} */
function codeWorkspace(): array
{
    $workspace = Workspace::factory()->create(['slug' => 'northwind', 'name' => 'Northwind']);
    $web = Machine::factory()->for($workspace)->create(['name' => 'web-1', 'provider' => 'Hetzner', 'region' => 'fsn1']);
    $db = Machine::factory()->for($workspace)->create(['name' => 'db-1', 'provider' => 'DigitalOcean', 'backup_schedule' => 'daily']);
    $web->forceFill(['inventory' => ['scanned_at' => now()->getTimestamp(), 'complete' => true, 'listeners' => [], 'services' => [
        ['key' => 'systemd:nginx.service', 'name' => 'nginx', 'kind' => 'proxy', 'manager' => 'systemd', 'status' => 'running', 'ports' => [80, 443], 'details' => ['managed' => 'true']],
        ['key' => 'docker:abc', 'name' => 'scheduler', 'kind' => 'container', 'manager' => 'docker', 'status' => 'running', 'ports' => []],
    ]]])->save();
    $db->forceFill(['inventory' => ['scanned_at' => now()->getTimestamp(), 'complete' => true, 'listeners' => [], 'services' => [
        ['key' => 'systemd:postgresql.service', 'name' => 'postgres', 'kind' => 'database', 'manager' => 'systemd', 'status' => 'running', 'ports' => [5432], 'details' => ['managed' => 'true']],
    ]]])->save();
    $workspace->monitors()->create(['name' => 'Shop', 'type' => 'http', 'url' => 'https://shop.northwind.dev', 'interval_seconds' => 60, 'timeout_seconds' => 10, 'status' => 'up']);

    $project = $workspace->projects()->create(['name' => 'Shop']);
    $environment = $project->production();
    $app = $project->nodes()->create(['machine_id' => $web->id, 'environment_id' => $environment->id, 'name' => 'web', 'kind' => 'app']);
    $database = $project->nodes()->create(['machine_id' => $db->id, 'environment_id' => $environment->id, 'name' => 'db', 'kind' => 'database', 'template' => 'postgres']);
    $project->links()->create(['app_node_id' => $app->id, 'resource_node_id' => $database->id])->forceFill(['state' => 'applied', 'env' => ['DATABASE_URL']])->save();
    DaemonJob::query()->create([
        'id' => (string) Str::uuid(), 'machine_id' => $web->id, 'user_id' => $workspace->owner_id, 'kind' => 'deploy', 'service' => 'web', 'state' => 'succeeded',
        'command' => ['job' => ['type' => 'deploy', 'service' => 'web', 'repo' => 'https://github.com/northwind/shop', 'commit' => str_repeat('c', 40), 'domains' => ['shop.northwind.dev'], 'port' => 3000, 'env' => [
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

function codeGitHubApp(): void
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    config()->set('services.github_app', [
        'id' => '12345', 'slug' => 'serveros', 'client_id' => 'Iv1.abc', 'client_secret' => 'shh',
        'private_key' => base64_encode($pem),
    ]);
}

/** A workspace with GitHub connected and its code going to northwind/infra, in serveros/. */
function codeRepoWorkspace(): Workspace
{
    codeGitHubApp();
    [$workspace] = codeWorkspace();
    $workspace->githubInstallations()->create(['installation_id' => 42, 'account_login' => 'northwind', 'account_type' => 'Organization', 'repository_ids' => null]);
    $workspace->forceFill(['code_repository' => ['repo' => 'northwind/infra', 'branch' => 'serveros/export', 'folder' => 'serveros', 'private' => true]])->save();

    return $workspace;
}

/** The live shop.yml, changed the way a pull request would change it. */
function proposedShop(Workspace $workspace): string
{
    $live = collect(CodeScreens::export($workspace)['files'])->firstWhere('path', 'projects/shop.yml');
    $yaml = implode("\n", $live['lines'])."\n";

    return str_replace(
        ["    port: 3000\n", "  db:\n", "  - https://shop.northwind.dev\n"],
        ["    port: 3001\n", "  cache:\n    template: redis\n    machine: db-1\n  db:\n", "  - https://shop.northwind.dev\n  - https://shop.northwind.dev/health\n"],
        $yaml,
    );
}

/**
 * GitHub for the code screens: open pull requests on northwind/infra, one of
 * which changes serveros/projects/shop.yml.
 *
 * @param  array<string, mixed>  $overrides  "METHOD path" => response
 */
function fakeCodeGitHub(Workspace $workspace, array $overrides = []): void
{
    $sha = str_repeat('a', 40);
    $shop = proposedShop($workspace);
    $routes = [
        'POST app/installations/42/access_tokens' => Http::response(['token' => 'ghs_token']),
        'GET installation/repositories' => Http::response(['repositories' => [
            ['id' => 1, 'full_name' => 'northwind/infra', 'private' => true, 'default_branch' => 'main', 'pushed_at' => '2026-11-20T10:00:00Z'],
            ['id' => 2, 'full_name' => 'northwind/shop', 'private' => false, 'default_branch' => 'trunk', 'pushed_at' => '2026-11-19T10:00:00Z'],
        ]]),
        'GET repos/northwind/infra/pulls' => Http::response([
            ['number' => 7, 'title' => 'Add Redis and a health check', 'html_url' => 'https://github.com/northwind/infra/pull/7', 'user' => ['login' => 'sam'], 'draft' => false,
                'head' => ['ref' => 'add-redis', 'sha' => $sha], 'base' => ['ref' => 'main'], 'updated_at' => '2026-11-26T10:00:00Z'],
            ['number' => 8, 'title' => 'Fix the readme', 'html_url' => 'https://github.com/northwind/infra/pull/8', 'user' => ['login' => 'lee'], 'draft' => false,
                'head' => ['ref' => 'readme', 'sha' => str_repeat('b', 40)], 'base' => ['ref' => 'main'], 'updated_at' => '2026-11-25T10:00:00Z'],
        ]),
        'GET repos/northwind/infra/pulls/7/files' => Http::response([
            ['filename' => 'serveros/projects/shop.yml', 'status' => 'modified', 'additions' => 4, 'deletions' => 1,
                'patch' => "@@ -14,7 +14,10 @@ services:\n     port: 3000\n-    port: 3000\n+    port: 3001\n+  cache:"],
            ['filename' => 'docs/notes.md', 'status' => 'added', 'additions' => 1, 'deletions' => 0, 'patch' => '@@ -0,0 +1 @@\n+hi'],
        ]),
        'GET repos/northwind/infra/pulls/8/files' => Http::response([
            ['filename' => 'README.md', 'status' => 'modified', 'additions' => 1, 'deletions' => 1, 'patch' => '@@ -1 +1 @@\n-a\n+b'],
        ]),
        'GET repos/northwind/infra/pulls/7/reviews' => Http::response([
            ['user' => ['login' => 'maya'], 'state' => 'APPROVED', 'submitted_at' => '2026-11-26T10:05:00Z'],
            ['user' => ['login' => 'maya'], 'state' => 'COMMENTED', 'submitted_at' => '2026-11-26T10:06:00Z'],
            ['user' => ['login' => 'lee'], 'state' => 'CHANGES_REQUESTED', 'submitted_at' => '2026-11-26T10:07:00Z'],
        ]),
        "GET repos/northwind/infra/commits/{$sha}/check-runs" => Http::response(['message' => 'Resource not accessible by integration'], 403),
        'GET repos/northwind/infra/contents/serveros/projects/shop.yml' => Http::response(['content' => chunk_split(base64_encode($shop), 60, "\n"), 'encoding' => 'base64']),
        ...$overrides,
    ];

    Http::fake(function (HttpRequest $request) use ($routes) {
        $path = trim((string) parse_url($request->url(), PHP_URL_PATH), '/');

        return $routes[$request->method().' '.$path] ?? Http::response(['message' => 'Not Found'], 404);
    });
}

test('the workspace is written out as files from what it really runs', function () {
    [$workspace] = codeWorkspace();
    $member = User::factory()->create();
    $workspace->members()->attach($member, ['role' => 'member']);

    $response = $this->actingAs($member)->get(route('workspace.code.index', $workspace))->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->component('workspace/code/WorkspaceCode')
        ->missing('sample')
        ->where('code.machines', 2)
        ->where('code.services', 3)
        ->where('code.monitors', 1)
        ->where('code.projects', 1)
        ->where('code.secrets', 1)
        ->where('code.open', 'projects/shop.yml')
        ->where('code.files', fn ($files) => collect($files)->pluck('path')->all() === ['machines/db-1.yml', 'machines/web-1.yml', 'projects/shop.yml', 'backups.yml', 'monitors.yml', 'policies.yml', 'serveros.yml'])
        ->where('repository', null)
        ->where('defaults', ['branch' => 'serveros/export', 'folder' => 'serveros'])
        ->where('canManage', true));

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

test('needs a look lists what the files can’t describe, from the real inventory, and writes it into the file', function () {
    [$workspace, $web] = codeWorkspace();
    $web->forceFill(['inventory' => [...$web->inventory, 'services' => [
        ...$web->inventory['services'],
        ['key' => 'systemd:sync.service', 'name' => 'sync', 'kind' => 'other', 'manager' => 'systemd', 'status' => 'running', 'ports' => [], 'details' => ['managed' => 'true']],
    ]]])->save();
    $docs = $workspace->projects()->create(['name' => 'Docs']);
    $docs->nodes()->create(['machine_id' => $web->id, 'environment_id' => $docs->production()->id, 'name' => 'docs', 'kind' => 'app']);

    $response = $this->actingAs($workspace->owner)->get(route('workspace.code.index', $workspace))->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->has('needsLook', 3)
        ->where('needsLook.0.title', 'scheduler on web-1 isn’t managed by ServerOS')
        ->where('needsLook.0.path', 'machines/web-1.yml')
        ->where('needsLook.1.title', 'We can’t tell what sync on web-1 is')
        ->where('needsLook.2.title', 'docs in Docs has no repository')
        ->where('needsLook.2.path', 'projects/docs.yml'));

    $page = $response->viewData('page');
    expect(codeFile($page, 'machines/web-1.yml'))
        ->toContain('# Needs a look: ServerOS found scheduler but doesn’t control it')
        ->toContain('# Needs a look: this file only says how sync runs');
    expect(codeFile($page, 'projects/docs.yml'))->toContain('# Needs a look: docs wasn’t deployed from a GitHub repository');
    expect(codeFile($page, 'projects/shop.yml'))->not->toContain('Needs a look');
});

test('a workspace with nothing in it gets the empty files and nothing to look at', function () {
    $workspace = Workspace::factory()->create();

    $this->actingAs($workspace->owner)
        ->get(route('workspace.code.index', $workspace))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('code.machines', 0)
            ->where('code.files', fn ($files) => collect($files)->pluck('path')->all() === ['backups.yml', 'monitors.yml', 'policies.yml', 'serveros.yml'])
            ->where('needsLook', []));
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
        ->assertInertia(fn (Assert $page) => $page->where('github.connectUrl', null)->where('canManage', false));
});

test('a connected GitHub account with no repository chosen yet invents none', function () {
    [$workspace] = codeWorkspace();
    $workspace->githubInstallations()->create(['installation_id' => 42, 'account_login' => 'Northwind-HQ', 'account_type' => 'Organization', 'repository_ids' => []]);

    $this->actingAs($workspace->owner)
        ->get(route('workspace.code.index', $workspace))
        ->assertInertia(fn (Assert $page) => $page
            ->where('github.connected', true)
            ->where('github.account', 'Northwind-HQ')
            ->where('github.connectUrl', null)
            ->where('repository', null)
            ->missing('sample'));
});

test('the code repository is picked from the repositories the GitHub App reaches, and saving it is recorded', function () {
    codeGitHubApp();
    [$workspace] = codeWorkspace();
    $workspace->githubInstallations()->create(['installation_id' => 42, 'account_login' => 'northwind', 'account_type' => 'Organization', 'repository_ids' => null]);
    fakeCodeGitHub($workspace);
    $url = $workspace->url('/code/repository');

    $this->actingAs($workspace->owner)->put($url, ['repo' => 'northwind/elsewhere', 'branch' => 'serveros/export', 'folder' => 'serveros'])->assertSessionHasErrors('repo');
    $this->actingAs($workspace->owner)->put($url, ['repo' => 'northwind/infra', 'branch' => 'main', 'folder' => 'serveros'])->assertSessionHasErrors('branch');
    $this->actingAs($workspace->owner)->put($url, ['repo' => 'northwind/infra', 'branch' => 'serveros/export', 'folder' => '../outside'])->assertSessionHasErrors('folder');
    expect($workspace->fresh()->code_repository)->toBeNull();

    $this->actingAs($workspace->owner)
        ->put($url, ['repo' => 'Northwind/Infra', 'branch' => '', 'folder' => '/ops/serveros/'])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Your workspace’s code goes to northwind/infra now.');

    expect($workspace->fresh()->code_repository)->toBe(['repo' => 'northwind/infra', 'branch' => 'serveros/export', 'folder' => 'ops/serveros', 'private' => true]);
    $activity = Activity::query()->where('workspace_id', $workspace->id)->where('action', 'github.code_repository')->sole();
    expect($activity->subject)->toBe('northwind/infra')->and($activity->meta)->toMatchArray(['branch' => 'serveros/export', 'folder' => 'ops/serveros']);

    $this->actingAs($workspace->owner)
        ->get(route('workspace.code.index', $workspace))
        ->assertInertia(fn (Assert $page) => $page
            ->where('repository.repo', 'northwind/infra')
            ->where('repository.folder', 'ops/serveros')
            ->where('repository.url', 'https://github.com/northwind/infra')
            ->where('repository.pullRequest', null));
});

test('only people who can manage machines choose the code repository or open pull requests', function () {
    $workspace = codeRepoWorkspace();
    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);
    Http::fake();

    $this->actingAs($viewer)->put($workspace->url('/code/repository'), ['repo' => 'northwind/infra'])->assertForbidden();
    $this->actingAs($viewer)->post($workspace->url('/code/pull-request'))->assertForbidden();
    Http::assertNothingSent();
});

test('download zip has every file, named after the workspace', function () {
    [$workspace] = codeWorkspace();

    $response = $this->actingAs($workspace->owner)->get($workspace->url('/code/download'))->assertOk();

    expect($response->headers->get('content-disposition'))->toContain('northwind.zip');
    $zip = new ZipArchive;
    expect($zip->open($response->baseResponse->getFile()->getPathname()))->toBeTrue();
    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = $zip->getNameIndex($i);
    }
    expect($names)->toBe(['northwind/machines/db-1.yml', 'northwind/machines/web-1.yml', 'northwind/projects/shop.yml', 'northwind/backups.yml', 'northwind/monitors.yml', 'northwind/policies.yml', 'northwind/serveros.yml'])
        ->and($zip->getFromName('northwind/projects/shop.yml'))->toContain('repo: northwind/shop')->toEndWith("\n")
        ->and($zip->getFromName('northwind/projects/shop.yml'))->not->toContain('hunter2');
    $zip->close();
});

test('open pull request writes the files to a new branch and opens one', function () {
    $workspace = codeRepoWorkspace();
    fakeCodeGitHub($workspace, [
        'GET repos/northwind/infra' => Http::response(['default_branch' => 'main']),
        'GET repos/northwind/infra/git/ref/heads/main' => Http::response(['object' => ['sha' => 'base0']]),
        'GET repos/northwind/infra/git/commits/base0' => Http::response(['tree' => ['sha' => 'tree0']]),
        'GET repos/northwind/infra/git/trees/tree0' => Http::response(['tree' => [
            ['path' => 'README.md', 'type' => 'blob', 'sha' => 'r'],
            ['path' => 'serveros/machines/gone.yml', 'type' => 'blob', 'sha' => 'g'],
            ['path' => 'serveros/notes.md', 'type' => 'blob', 'sha' => 'n'],
        ]]),
        'POST repos/northwind/infra/git/blobs' => Http::response(['sha' => 'blob1'], 201),
        'POST repos/northwind/infra/git/trees' => Http::response(['sha' => 'tree1'], 201),
        'POST repos/northwind/infra/git/commits' => Http::response(['sha' => 'commit1'], 201),
        'POST repos/northwind/infra/git/refs' => Http::response(['ref' => 'refs/heads/serveros/export'], 201),
        'GET repos/northwind/infra/pulls' => Http::response([]),
        'POST repos/northwind/infra/pulls' => Http::response(['number' => 12, 'html_url' => 'https://github.com/northwind/infra/pull/12'], 201),
    ]);

    $this->actingAs($workspace->owner)
        ->from($workspace->url('/code'))
        ->post($workspace->url('/code/pull-request'))
        ->assertRedirect($workspace->url('/code'))
        ->assertInertiaFlash('toast.message', 'Opened pull request #12 on northwind/infra.');

    expect($workspace->fresh()->code_repository['pull'])->toBe(['number' => 12, 'url' => 'https://github.com/northwind/infra/pull/12']);
    $activity = Activity::query()->where('workspace_id', $workspace->id)->where('action', 'github.code_pull_request')->sole();
    expect($activity->subject)->toBe('northwind/infra')->and($activity->meta)->toMatchArray(['number' => 12, 'updated' => false]);

    Http::assertSent(function (HttpRequest $request) {
        if (! str_ends_with($request->url(), '/git/trees') || $request->method() !== 'POST') {
            return false;
        }
        $paths = collect($request['tree'])->pluck('sha', 'path');

        return $request['base_tree'] === 'tree0'
            && $paths->has('serveros/projects/shop.yml') && $paths->has('serveros/serveros.yml')
            && $paths->has('serveros/machines/gone.yml') && $paths['serveros/machines/gone.yml'] === null
            && ! $paths->has('serveros/notes.md') && ! $paths->has('README.md');
    });
    Http::assertSent(fn (HttpRequest $request) => str_ends_with($request->url(), '/git/commits') && $request->method() === 'POST' && $request['parents'] === ['base0']);
    Http::assertSent(fn (HttpRequest $request) => str_ends_with($request->url(), '/git/refs') && $request['ref'] === 'refs/heads/serveros/export' && $request['sha'] === 'commit1');
    Http::assertSent(fn (HttpRequest $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/pulls')
        && $request['head'] === 'serveros/export' && $request['base'] === 'main' && $request['title'] === 'Northwind as code');
    Http::assertSent(fn (HttpRequest $request) => str_ends_with($request->url(), '/access_tokens')
        && ($request['permissions']['contents'] ?? null) === 'write' && ($request['permissions']['pull_requests'] ?? null) === 'write');

    $this->actingAs($workspace->owner)
        ->get(route('workspace.code.index', $workspace))
        ->assertInertia(fn (Assert $page) => $page->where('repository.pullRequest.number', 12));
});

test('open pull request commits on top of the branch and updates the pull request already open', function () {
    $workspace = codeRepoWorkspace();
    $files = collect(CodeScreens::export($workspace)['files']);
    $root = implode("\n", $files->firstWhere('path', 'serveros.yml')['lines'])."\n";
    fakeCodeGitHub($workspace, [
        'GET repos/northwind/infra' => Http::response(['default_branch' => 'main']),
        'GET repos/northwind/infra/git/ref/heads/serveros/export' => Http::response(['object' => ['sha' => 'head1']]),
        'GET repos/northwind/infra/git/commits/head1' => Http::response(['tree' => ['sha' => 'tree1']]),
        'GET repos/northwind/infra/git/trees/tree1' => Http::response(['tree' => [
            ['path' => 'serveros/serveros.yml', 'type' => 'blob', 'sha' => sha1('blob '.strlen($root)."\0".$root)],
        ]]),
        'POST repos/northwind/infra/git/blobs' => Http::response(['sha' => 'blob2'], 201),
        'POST repos/northwind/infra/git/trees' => Http::response(['sha' => 'tree2'], 201),
        'POST repos/northwind/infra/git/commits' => Http::response(['sha' => 'commit2'], 201),
        'PATCH repos/northwind/infra/git/refs/heads/serveros/export' => Http::response(['ref' => 'refs/heads/serveros/export']),
        'GET repos/northwind/infra/pulls' => Http::response([['number' => 12, 'html_url' => 'https://github.com/northwind/infra/pull/12']]),
    ]);

    $this->actingAs($workspace->owner)
        ->post($workspace->url('/code/pull-request'))
        ->assertRedirect()
        ->assertInertiaFlash('toast.message', 'Updated pull request #12 on northwind/infra.');

    Http::assertSent(fn (HttpRequest $request) => $request->method() === 'PATCH' && $request['sha'] === 'commit2' && $request['force'] === false);
    Http::assertSent(fn (HttpRequest $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/git/commits') && $request['parents'] === ['head1']);
    Http::assertSent(fn (HttpRequest $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/git/trees')
        && ! collect($request['tree'])->pluck('path')->contains('serveros/serveros.yml'));
    Http::assertNotSent(fn (HttpRequest $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/pulls'));
    Http::assertNotSent(fn (HttpRequest $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/git/refs'));
    expect(Activity::query()->where('action', 'github.code_pull_request')->sole()->meta)->toMatchArray(['updated' => true]);
});

test('open pull request says plainly when GitHub turns it down, and changes nothing', function (int $status, array $headers, string $message) {
    $workspace = codeRepoWorkspace();
    fakeCodeGitHub($workspace, ['GET repos/northwind/infra' => Http::response(['message' => 'nope'], $status, $headers)]);

    $this->actingAs($workspace->owner)
        ->post($workspace->url('/code/pull-request'))
        ->assertRedirect()
        ->assertInertiaFlash('toast.type', 'error')
        ->assertInertiaFlash('toast.message', $message);

    expect($workspace->fresh()->code_repository)->not->toHaveKey('pull')
        ->and(Activity::query()->where('action', 'github.code_pull_request')->exists())->toBeFalse();
    Http::assertNotSent(fn (HttpRequest $request) => $request->method() === 'POST' && str_contains($request->url(), '/git/'));
})->with([
    'gone' => [404, [], 'ServerOS can’t find northwind/infra. It may have been renamed or deleted, or taken out of ServerOS’s GitHub App.'],
    'not allowed' => [403, [], 'ServerOS’s GitHub App isn’t allowed to do that in northwind/infra. Check what it can reach in Settings › GitHub.'],
    'rate limited' => [403, ['X-RateLimit-Remaining' => '0'], 'GitHub’s rate limit was hit. Try again in a few minutes.'],
]);

test('open pull request needs a repository first', function () {
    codeGitHubApp();
    [$workspace] = codeWorkspace();
    Http::fake();

    $this->actingAs($workspace->owner)
        ->post($workspace->url('/code/pull-request'))
        ->assertInertiaFlash('toast.message', 'Pick a repository for your workspace’s code first.');
    Http::assertNothingSent();
});

test('review changes reads the real open pull request that changes the files, its diff and its reviews', function () {
    $workspace = codeRepoWorkspace();
    $db = Machine::query()->where('name', 'db-1')->sole();
    $web = Machine::query()->where('name', 'web-1')->sole();
    fakeCodeGitHub($workspace);

    $this->actingAs($workspace->owner)
        ->get(route('workspace.code.changes', $workspace))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('workspace/code/ReviewChanges')
            ->missing('sample')
            ->where('error', null)
            ->has('pullRequests', 1)
            ->where('pullRequests.0.number', 7)
            ->where('pullRequest.number', 7)
            ->where('pullRequest.title', 'Add Redis and a health check')
            ->where('pullRequest.author', 'sam')
            ->where('pullRequest.from', 'add-redis')
            ->where('pullRequest.to', 'main')
            ->where('pullRequest.commit', 'aaaaaaa')
            ->where('pullRequest.url', 'https://github.com/northwind/infra/pull/7')
            ->has('pullRequest.files', 1)
            ->where('pullRequest.files.0.path', 'serveros/projects/shop.yml')
            ->where('pullRequest.files.0.lines.0', ['no' => null, 'sign' => '@', 'text' => '@@ -14,7 +14,10 @@ services:'])
            ->where('pullRequest.files.0.lines.2', ['no' => 15, 'sign' => '-', 'text' => '    port: 3000'])
            ->where('pullRequest.files.0.lines.3', ['no' => 15, 'sign' => '+', 'text' => '    port: 3001'])
            ->where('pullRequest.reviews', [
                ['author' => 'maya', 'state' => 'approved', 'at' => '2026-11-26T10:05:00Z'],
                ['author' => 'lee', 'state' => 'changes_requested', 'at' => '2026-11-26T10:07:00Z'],
            ])
            ->where('pullRequest.checks', null)
            ->has('pullRequest.changes', 3)
            ->where('pullRequest.changes.0.op', 'change')
            ->where('pullRequest.changes.0.title', 'Change web: set port to 3001')
            ->where('pullRequest.changes.0.path', 'services.web')
            ->where('pullRequest.changes.0.machine.name', 'web-1')
            ->where('pullRequest.changes.0.risk', ['tone' => 'amber', 'label' => 'Restarts web'])
            ->where('pullRequest.changes.1.op', 'add')
            ->where('pullRequest.changes.1.title', 'Add cache on db-1')
            ->where('pullRequest.changes.1.logo', 'redis')
            ->where('pullRequest.changes.2.title', 'Add a monitor for https://shop.northwind.dev/health')
            ->where('pullRequest.unreadable', [])
            ->where('pullRequest.machines', [
                ['id' => $db->id, 'name' => 'db-1', 'provider' => 'DigitalOcean', 'changes' => 1],
                ['id' => $web->id, 'name' => 'web-1', 'provider' => 'Hetzner', 'changes' => 1],
            ]));
});

test('review changes keeps GitHub’s answers for a minute', function () {
    $workspace = codeRepoWorkspace();
    fakeCodeGitHub($workspace);

    $this->actingAs($workspace->owner)->get(route('workspace.code.changes', $workspace))->assertOk();
    $this->actingAs($workspace->owner)->get(route('workspace.code.changes', $workspace))->assertOk();

    $pulls = collect(Http::recorded())->filter(fn (array $pair) => str_ends_with((string) parse_url($pair[0]->url(), PHP_URL_PATH), '/repos/northwind/infra/pulls'));
    expect($pulls)->toHaveCount(1);
});

test('the plan compares a pull request’s files with what runs now, line by line', function () {
    [$workspace] = codeWorkspace();
    $live = ['projects/shop.yml' => [
        'project: shop',
        'services:',
        '  web:',
        '    machine: web-1',
        '    port: 3000',
        '  search:',
        '    template: meilisearch',
        '    machine: web-1',
        'monitors:',
        '  - https://shop.dev',
    ]];
    $proposed = implode("\n", [
        'project: shop',
        'environment: staging',
        'services:',
        '  web:',
        '    machine: web-1',
        '    port: 3001',
        '    env:',
        '      REDIS_URL: ${{ services.cache.url }}',
        'monitors:',
        '  - https://shop.dev',
        '  - https://shop.dev/health',
    ])."\n";

    $plan = CodePlan::make($live, [
        ['path' => 'projects/shop.yml', 'content' => $proposed],
        ['path' => 'projects/broken.yml', 'content' => "services: [\n"],
        ['path' => 'machines/web-2.yml', 'content' => "machine: web-2\nprovider: hetzner\n"],
    ], $workspace->machines()->get());

    expect(collect($plan['changes'])->map(fn (array $change) => [$change['op'], $change['title'], $change['path']])->all())->toBe([
        ['add', 'Set environment of shop to staging', 'environment'],
        ['change', 'Change web: set port to 3001, add env.REDIS_URL', 'services.web'],
        ['remove', 'Remove search from web-1', 'services.search'],
        ['add', 'Add a monitor for https://shop.dev/health', 'monitors[1]'],
        ['add', 'Add the machine web-2', ''],
    ])
        ->and($plan['changes'][1]['diff'])->toBe([
            ['no' => 4, 'sign' => ' ', 'text' => '  web:'],
            ['no' => 5, 'sign' => ' ', 'text' => '    machine: web-1'],
            ['no' => 5, 'sign' => '-', 'text' => '    port: 3000'],
            ['no' => 6, 'sign' => '+', 'text' => '    port: 3001'],
            ['no' => 7, 'sign' => '+', 'text' => '    env:'],
            ['no' => 8, 'sign' => '+', 'text' => '      REDIS_URL: ${{ services.cache.url }}'],
        ])
        ->and($plan['changes'][2]['risk'])->toBe(['tone' => 'red', 'label' => 'Holds data'])
        ->and($plan['changes'][2]['diff'])->toBe([
            ['no' => 6, 'sign' => '-', 'text' => '  search:'],
            ['no' => 7, 'sign' => '-', 'text' => '    template: meilisearch'],
            ['no' => 8, 'sign' => '-', 'text' => '    machine: web-1'],
        ])
        ->and($plan['changes'][3]['diff'])->toBe([['no' => 11, 'sign' => '+', 'text' => '  - https://shop.dev/health']])
        ->and($plan['unreadable'])->toBe(['projects/broken.yml']);
});

test('review changes says when there are no pull requests to review', function () {
    $workspace = codeRepoWorkspace();
    fakeCodeGitHub($workspace, ['GET repos/northwind/infra/pulls' => Http::response([])]);

    $this->actingAs($workspace->owner)
        ->get(route('workspace.code.changes', $workspace))
        ->assertInertia(fn (Assert $page) => $page
            ->where('repository.repo', 'northwind/infra')
            ->where('pullRequests', [])
            ->where('pullRequest', null)
            ->where('error', null));
});

test('review changes says plainly when GitHub can’t be read, without breaking the page', function () {
    $workspace = codeRepoWorkspace();
    fakeCodeGitHub($workspace, ['GET repos/northwind/infra/pulls' => Http::response(['message' => 'API rate limit exceeded'], 403, ['X-RateLimit-Remaining' => '0'])]);

    $this->actingAs($workspace->owner)
        ->get(route('workspace.code.changes', $workspace))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('pullRequest', null)
            ->where('error', 'GitHub’s rate limit was hit. Try again in a few minutes.'));
});

test('review changes asks for GitHub and a repository before reading anything', function () {
    [$workspace] = codeWorkspace();
    Http::fake();

    $this->actingAs($workspace->owner)
        ->get(route('workspace.code.changes', $workspace))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('github.connected', false)
            ->where('repository', null)
            ->where('pullRequests', [])
            ->where('pullRequest', null)
            ->missing('sample'));

    $workspace->githubInstallations()->create(['installation_id' => 42, 'account_login' => 'northwind', 'account_type' => 'Organization', 'repository_ids' => null]);
    $this->actingAs($workspace->owner)
        ->get(route('workspace.code.changes', $workspace))
        ->assertInertia(fn (Assert $page) => $page->where('github.connected', true)->where('repository', null)->where('pullRequest', null));
    Http::assertNothingSent();
});

test('set up code reads the project’s repository, branch and commit from its last deploy', function () {
    [$workspace, $web] = codeWorkspace();
    $web->forceFill(['app_settings' => ['web' => ['deploy_on_push' => true, 'push_branch' => 'release']]])->save();
    $docs = $workspace->projects()->create(['name' => 'Docs']);
    $docs->forceFill(['created_at' => now()->subDay()])->save();
    $docs->nodes()->create(['machine_id' => $web->id, 'environment_id' => $docs->production()->id, 'name' => 'docs', 'kind' => 'app']);

    $this->actingAs($workspace->owner)
        ->get(route('workspace.code.setup', $workspace))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('workspace/code/SetUpCode')
            ->missing('sample')
            ->where('project.name', 'Shop')
            ->where('project.environment', 'Production')
            ->where('source.app', 'web')
            ->where('source.repo', 'northwind/shop')
            ->where('source.url', 'https://github.com/northwind/shop')
            ->where('source.branch', 'release')
            ->where('source.commit', 'ccccccc')
            ->where('source.upload', false)
            ->where('source.deployed', true)
            ->where('source.stack', null)
            ->where('mcpUrl', route('mcp', ['workspace' => 'northwind']))
            ->has('projects', 2));

    $this->actingAs($workspace->owner)
        ->get(route('workspace.code.setup', ['workspace' => $workspace, 'project' => $docs->uid]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('project.name', 'Docs')
            ->where('source.repo', null)
            ->where('source.deployed', false)
            ->where('source.branch', null));
});

test('set up code takes the branch from GitHub when pushes don’t deploy, and the stack ServerOS recognised', function () {
    codeGitHubApp();
    [$workspace, $web] = codeWorkspace();
    $workspace->githubInstallations()->create(['installation_id' => 42, 'account_login' => 'northwind', 'account_type' => 'Organization', 'repository_ids' => null]);
    fakeCodeGitHub($workspace);
    $project = $workspace->projects()->firstOrFail();
    $project->nodes()->where('name', 'web')->update(['template' => 'nextjs']);

    $this->actingAs($workspace->owner)
        ->get(route('workspace.code.setup', $workspace))
        ->assertInertia(fn (Assert $page) => $page
            ->where('source.branch', 'trunk')
            ->where('source.stack', ['slug' => 'nextjs', 'name' => 'Next.js']));
});

test('set up code says it was an upload rather than invent a repository', function () {
    [$workspace, $web] = codeWorkspace();
    DaemonJob::query()->where('service', 'web')->sole()->forceFill(['command' => ['job' => ['type' => 'deploy', 'service' => 'web', 'repo' => 'https://panel.test/api/deploy-sources/'.str_repeat('x', 48).'.git']]])->save();

    $this->actingAs($workspace->owner)
        ->get(route('workspace.code.setup', $workspace))
        ->assertInertia(fn (Assert $page) => $page
            ->where('source.repo', null)
            ->where('source.url', null)
            ->where('source.upload', true));
});

test('set up code says so when there is no project yet', function () {
    $workspace = Workspace::factory()->create();
    Machine::factory()->for($workspace)->create();

    $this->actingAs($workspace->owner)
        ->get(route('workspace.code.setup', $workspace))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('project', null)
            ->where('source', null)
            ->missing('sample'));
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
