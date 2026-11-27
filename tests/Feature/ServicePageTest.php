<?php

use App\Enums\Plan;
use App\Models\DaemonJob;
use App\Models\LogEntry;
use App\Models\Machine;
use App\Models\ProjectLink;
use App\Models\TrafficRollup;
use App\Models\Workspace;
use App\Support\Deploys\CommitMessages;
use App\Support\Logs\LogIngest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/** @return array{0: Workspace, 1: Machine, 2: Machine, 3: string} */
function servicePage(): array
{
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $web = Machine::factory()->for($workspace)->create(['name' => 'web-1']);
    $db = Machine::factory()->for($workspace)->create(['name' => 'db-1']);
    $app = fn (string $commit) => ['key' => 'docker:'.substr($commit, 0, 12), 'name' => 'serveros-shop-'.substr($commit, 0, 12), 'kind' => 'container', 'manager' => 'docker',
        'status' => 'running', 'ports' => [3000], 'confidence' => 90, 'details' => ['managed' => 'true', 'image' => 'serveros/shop:'.substr($commit, 0, 7), 'restart_policy' => 'unless-stopped']];
    $web->forceFill([
        'uid' => (string) Str::uuid(),
        'last_seen_at' => now(),
        'inventory' => ['scanned_at' => now()->getTimestamp(), 'complete' => true, 'listeners' => [], 'services' => [
            $app(str_repeat('b', 40)),
            ['key' => 'docker:redis00000001', 'name' => 'serveros-redis-redis-1', 'kind' => 'container', 'manager' => 'compose', 'status' => 'running',
                'ports' => [], 'confidence' => 90, 'details' => ['managed' => 'true', 'compose_project' => 'serveros-redis', 'image' => 'redis:7-alpine']],
            ['key' => 'docker:queue0000001', 'name' => 'shop-queue', 'kind' => 'container', 'manager' => 'compose', 'status' => 'stopped',
                'ports' => [], 'confidence' => 90, 'details' => ['managed' => 'true', 'compose_project' => 'jobs']],
            ['key' => 'docker:mail00000001', 'name' => 'mailer', 'kind' => 'container', 'manager' => 'compose', 'status' => 'running',
                'ports' => [], 'confidence' => 90, 'details' => ['managed' => 'true', 'compose_project' => 'jobs']],
        ]],
        'service_stats' => ['docker:bbbbbbbbbbbb' => ['cpu' => 18.0, 'memUsed' => 400_000_000, 'memLimit' => null, 'pids' => 9, 'netRx' => 0, 'netTx' => 0, 'startedAt' => now()->subDays(3)->getTimestamp(), 'restarts' => 0, 'at' => now()->getTimestamp()]],
    ])->save();
    $db->forceFill([
        'uid' => (string) Str::uuid(),
        'last_seen_at' => now(),
        'inventory' => ['scanned_at' => now()->getTimestamp(), 'complete' => true, 'listeners' => [], 'services' => [
            ['key' => 'docker:pg0000000001', 'name' => 'serveros-shop-db-postgres-1', 'kind' => 'container', 'manager' => 'compose', 'status' => 'running',
                'ports' => [5432], 'confidence' => 90, 'details' => ['managed' => 'true', 'compose_project' => 'serveros-shop-db', 'image' => 'postgres:17']],
        ]],
    ])->save();

    foreach ([[str_repeat('a', 40), 3, null, $workspace->owner_id], [str_repeat('b', 40), 1, 'push', $workspace->owner_id]] as [$commit, $days, $trigger, $user]) {
        DaemonJob::query()->create([
            'id' => (string) Str::uuid(), 'machine_id' => $web->id, 'user_id' => $user, 'kind' => 'deploy', 'service' => 'shop', 'state' => 'succeeded', 'log' => [], 'trigger' => $trigger,
            'command' => ['job' => ['type' => 'deploy', 'service' => 'shop', 'repo' => 'https://github.com/acme/shop', 'commit' => $commit, 'port' => 3000,
                'domains' => ['shop.example.com'], 'env' => ['NODE_ENV' => 'production', 'DATABASE_URL' => 'postgres://secret@db/shop']]],
            'created_at' => now()->subDays($days),
        ]);
    }

    $project = $workspace->projects()->create(['name' => 'Shop']);
    $production = $project->production();
    $shop = $project->nodes()->create(['environment_id' => $production->id, 'machine_id' => $web->id, 'name' => 'shop', 'kind' => 'app']);
    $project->nodes()->create(['environment_id' => $production->id, 'machine_id' => $web->id, 'name' => 'redis', 'kind' => 'cache', 'template' => 'redis']);
    $shopDb = $project->nodes()->create(['environment_id' => $production->id, 'machine_id' => $db->id, 'name' => 'shop-db', 'kind' => 'database', 'template' => 'postgres']);
    (new ProjectLink)->forceFill(['project_id' => $project->id, 'app_node_id' => $shop->id, 'resource_node_id' => $shopDb->id, 'env' => ['DATABASE_URL'], 'state' => 'applied'])->save();

    return [$workspace, $web, $db, $workspace->url("/machines/{$web->id}/services/app:shop")];
}

test('an app’s page shows its deploys, variables, project, database and usage from real records', function () {
    [$workspace, $web, $db, $url] = servicePage();
    $minute = intdiv(now()->getTimestamp(), 60) * 60;
    foreach ([[120, 600, 100.0, 300.0], [300, 1200, 200.0, 400.0]] as [$ago, $requests, $p50, $p95]) {
        TrafficRollup::query()->create(['machine_id' => $web->id, 'site' => 'shop', 'bucket' => $minute - $ago, 'seconds' => 60, 'requests' => $requests,
            'status_2xx' => $requests, 'status_3xx' => 0, 'status_4xx' => 0, 'status_5xx' => 0, 'bytes' => 0, 'p50_ms' => $p50, 'p95_ms' => $p95, 'visitors' => 0]);
    }

    $this->actingAs($workspace->owner)->get($url)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('workspace/ServiceShow')
            ->where('usage.requests', 30)
            ->where('usage.p50', 166.7)
            ->where('usage.p95', 366.7)
            ->where('app.port', 3000)
            ->where('app.https', true)
            ->has('app.versions', 2)
            ->where('app.versions.0.commit', str_repeat('b', 40))
            ->where('app.versions.0.live', true)
            ->where('app.versions.0.push', true)
            ->where('app.versions.0.repo', 'acme/shop')
            ->where('app.versions.1.push', false)
            ->where('app.variables', [
                ['name' => 'DATABASE_URL', 'from' => 'shop-db', 'build' => false],
                ['name' => 'NODE_ENV', 'from' => null, 'build' => false],
            ])
            ->where('stack.kind', 'project')
            ->where('stack.name', 'Shop')
            ->has('stack.members', 1)
            ->where('stack.members.0.name', 'redis')
            ->where('stack.members.0.logo', 'redis')
            ->where('stack.members.0.url', $workspace->url("/machines/{$web->id}/services/docker:redis00000001"))
            ->where('linked.name', 'shop-db')
            ->where('linked.machine', 'db-1')
            ->where('linked.sameMachine', false)
            ->where('linked.url', $workspace->url("/machines/{$db->id}/services/docker:pg0000000001"))
            ->missing('messages')
            ->etc());

    $page = $this->actingAs($workspace->owner)->get($url)->viewData('page');
    expect(json_encode($page['props']))->not->toContain('postgres://secret');
});

test('a Compose service’s stack is the rest of its Compose project, and services without sites show no traffic numbers', function () {
    [$workspace, $web] = servicePage();

    $this->actingAs($workspace->owner)->get($workspace->url("/machines/{$web->id}/services/docker:queue0000001"))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('app', null)
            ->where('linked', null)
            ->where('usage', null)
            ->where('stack.kind', 'compose')
            ->where('stack.name', 'jobs')
            ->has('stack.members', 1)
            ->where('stack.members.0.name', 'mailer')
            ->etc());
});

test('a service’s log lines come from the log store, across an app’s releases, newest last', function () {
    [$workspace, $web, $db, $url] = servicePage();
    $now = (int) (microtime(true) * 1000);
    LogIngest::store($web, ['lines' => [
        ['service' => 'docker:aaaaaaaaaaaa', 'name' => 'serveros-shop-aaaaaaaaaaaa', 'ts' => $now - 7_200_000, 'stream' => 'stdout', 'line' => 'old release started'],
        ['service' => 'docker:bbbbbbbbbbbb', 'name' => 'serveros-shop-bbbbbbbbbbbb', 'ts' => $now - 60_000, 'stream' => 'stdout', 'line' => 'GET / 200 in 4ms'],
        ['service' => 'docker:bbbbbbbbbbbb', 'name' => 'serveros-shop-bbbbbbbbbbbb', 'ts' => $now - 30_000, 'stream' => 'stderr', 'line' => 'warn  - slow query took 1.2s'],
        ['service' => 'docker:redis00000001', 'name' => 'serveros-redis-redis-1', 'ts' => $now - 10_000, 'stream' => 'stdout', 'line' => 'redis is ready'],
    ]]);
    LogIngest::store($db, ['lines' => [
        ['service' => 'docker:bbbbbbbbbbbb', 'name' => 'serveros-shop-bbbbbbbbbbbb', 'ts' => $now, 'stream' => 'stdout', 'line' => 'another machine'],
    ]]);
    $lines = "{$url}/log-lines";

    $this->actingAs($workspace->owner)->getJson($lines.'?lines=100')
        ->assertOk()
        ->assertJsonCount(3, 'lines')
        ->assertJsonPath('lines.0.message', 'old release started')
        ->assertJsonPath('lines.2.level', 'warn')
        ->assertJsonPath('more', false);

    $this->actingAs($workspace->owner)->getJson($lines.'?lines=100&range=1h')
        ->assertJsonCount(2, 'lines')
        ->assertJsonPath('lines.0.message', 'GET / 200 in 4ms');

    $this->actingAs($workspace->owner)->getJson($lines.'?lines=100&q=slow')
        ->assertJsonCount(1, 'lines');

    $last = LogEntry::query()->where('message', 'GET / 200 in 4ms')->value('id');
    $this->actingAs($workspace->owner)->getJson($lines.'?lines=100&after='.$last)
        ->assertJsonCount(1, 'lines')
        ->assertJsonPath('lines.0.message', 'warn  - slow query took 1.2s');

    $this->actingAs($workspace->owner)->getJson($lines.'?lines=42')->assertUnprocessable();

    $this->actingAs($workspace->owner)->get($url)->assertInertia(fn (Assert $page) => $page
        ->where('logs.stored', true)
        ->where('logs.shipping', true)
        ->where('logs.today', fn ($today) => is_int($today))
        ->etc());

    $other = Workspace::factory()->create();
    $this->actingAs($other->owner)->getJson($lines)->assertForbidden();
    $this->actingAs($other->owner)->getJson($other->url("/machines/{$web->id}/services/app:shop/log-lines"))->assertNotFound();
});

test('deployed commits show their message once GitHub has told it, and nothing is asked without the app', function () {
    [$workspace] = servicePage();
    Http::fake();
    $commit = str_repeat('b', 40);

    expect(CommitMessages::for($workspace, 'https://github.com/acme/shop', [$commit]))->toBe([]);
    Http::assertNothingSent();

    Cache::forever("github:commit-message:acme/shop:{$commit}", 'Fix rounding in checkout totals');
    expect(CommitMessages::for($workspace, 'https://github.com/acme/shop', [$commit]))->toBe([$commit => 'Fix rounding in checkout totals'])
        ->and(CommitMessages::for($workspace, 'https://example.com/shop.git', [$commit]))->toBe([]);
});
