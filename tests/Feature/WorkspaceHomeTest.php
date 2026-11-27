<?php

use App\Models\AgentFix;
use App\Models\Backup;
use App\Models\BackupDestination;
use App\Models\Machine;
use App\Models\MachinePackageUpdate;
use App\Models\MachineVulnerability;
use App\Models\User;
use App\Models\Vulnerability;
use App\Models\Workspace;
use App\Support\Extensions\Extensions;
use App\Support\Extensions\UpgradeLink;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

function homeUrl(Workspace $workspace): string
{
    return $workspace->url().'/';
}

function homeDestination(Workspace $workspace, string $name, string $endpoint): BackupDestination
{
    $destination = new BackupDestination(['name' => $name, 'type' => 's3', 'settings' => [
        'endpoint' => $endpoint, 'region' => 'auto', 'bucket' => 'b', 'access_key' => 'k', 'secret_key' => 's', 'path_style' => true, 'prefix' => '',
    ]]);
    $destination->workspace_id = $workspace->id;
    $destination->save();

    return $destination;
}

test('a new workspace gets the empty home, with the setup steps left to the setup guide', function () {
    $workspace = Workspace::factory()->create();
    app(Extensions::class)->setUpgradeLink(new UpgradeLink(label: 'Billing', route: 'workspace.settings.general'));

    $this->actingAs($workspace->owner)
        ->get(homeUrl($workspace))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('workspace/Home')
            ->where('workspace.host', $workspace->host())
            ->where('overview.machines', 0)
            ->where('overview.averageCpu', null)
            ->where('busiest', [])
            ->where('security.package', null)
            ->where('backups.covered', 0)
            ->missing('onboarding')
            ->missing('onboardingDismissed')
            ->where('setupGuide.percent', 0)
            ->has('issues', 0));
});

test('the overview counts machines online, services running, monitor uptime and the CPU of those reporting', function () {
    $workspace = Workspace::factory()->create();
    $service = fn (string $key, string $status) => ['key' => $key, 'name' => $key, 'kind' => 'app', 'manager' => 'docker', 'status' => $status, 'ports' => []];
    $machine = Machine::factory()->for($workspace)->create(['cpu_percent' => 30, 'services_count' => 3]);
    $machine->forceFill(['inventory' => ['scanned_at' => 1, 'complete' => true, 'services' => [$service('docker:a', 'running'), $service('docker:b', 'stopped'), $service('docker:c', 'running')], 'listeners' => []]])->save();
    Machine::factory()->for($workspace)->create(['cpu_percent' => null, 'services_count' => 0]);
    Machine::factory()->for($workspace)->create(['name' => 'search-1', 'cpu_percent' => 99, 'services_count' => 1])
        ->forceFill(['uid' => (string) Str::uuid(), 'last_seen_at' => now()->subMinutes(12)])->save();
    Machine::factory()->create(['cpu_percent' => 99]);
    $monitor = fn (string $name, string $status, bool $paused, array $daily) => $workspace->monitors()->create(['name' => $name, 'type' => 'http', 'url' => 'https://'.$name.'.example.com/', 'status' => $status, 'paused' => $paused, 'interval_seconds' => 60, 'timeout_seconds' => 10])
        ->forceFill(['daily' => $daily])->save();
    $monitor('shop', 'up', false, [now()->toDateString() => [1000, 999, 0], now()->subDays(40)->toDateString() => [100, 0, 0]]);
    $monitor('api', 'down', false, [now()->toDateString() => [1000, 997, 0]]);
    $monitor('old', 'up', true, [now()->toDateString() => [1000, 0, 0]]);

    $this->actingAs($workspace->owner)->get(homeUrl($workspace))->assertInertia(fn (Assert $page) => $page
        ->where('overview', [
            'machines' => 3,
            'online' => 2,
            'offline' => ['search-1'],
            'connecting' => 0,
            'services' => 4,
            'running' => 2,
            'serviceProblems' => 0,
            'monitors' => 3,
            'monitorsDown' => 1,
            'uptime' => 99.8,
            'averageCpu' => 30,
            'reporting' => 1,
        ]));
});

test('the busiest five machines by CPU are shown, leaving out machines that went quiet', function () {
    $workspace = Workspace::factory()->create();
    foreach (['a' => 10, 'b' => 86, 'c' => 71, 'd' => 34, 'e' => 22, 'f' => 18] as $name => $cpu) {
        Machine::factory()->for($workspace)->create(['name' => $name, 'cpu_percent' => $cpu]);
    }
    Machine::factory()->for($workspace)->create(['name' => 'quiet', 'cpu_percent' => 95])
        ->forceFill(['uid' => (string) Str::uuid(), 'last_seen_at' => now()->subHour()])->save();
    Machine::factory()->for($workspace)->create(['name' => 'new', 'cpu_percent' => null]);

    $this->actingAs($workspace->owner)->get(homeUrl($workspace))->assertInertia(fn (Assert $page) => $page
        ->where('busiest', fn ($busiest) => collect($busiest)->map(fn ($m) => [$m['name'], $m['cpu']])->all() === [
            ['b', 86], ['c', 71], ['d', 34], ['e', 22], ['f', 18],
        ]));
});

test('what needs a look is listed worst first, each with a short line about it', function () {
    $workspace = Workspace::factory()->create();
    $connected = fn (array $attrs) => tap(Machine::factory()->for($workspace)->create(['environment' => 'production', 'last_backup_at' => now()]))
        ->forceFill(['uid' => (string) Str::uuid(), 'last_seen_at' => now(), ...$attrs])->save();
    $connected(['name' => 'healthy', 'disks' => [['mount' => '/', 'used' => 10 << 30, 'total' => 80 << 30]], 'disk_percent' => 12]);
    $connected(['name' => 'gone', 'last_seen_at' => now()->subMinutes(10)]);
    $connected(['name' => 'full', 'disks' => [['mount' => '/', 'used' => 76 << 30, 'total' => 80 << 30]], 'disk_percent' => 95]);
    $connected(['name' => 'filling', 'disks' => [['mount' => '/var/lib/docker', 'used' => 70 << 30, 'total' => 80 << 30]], 'disk_percent' => 87, 'backup_schedule' => 'daily', 'last_backup_at' => now()->subDays(3)]);
    $connected(['name' => 'tight', 'memory_used_mb' => 3994, 'facts' => ['memory_bytes' => 4 << 30]]);
    $workspace->monitors()->create(['name' => 'Shop', 'type' => 'http', 'url' => 'https://shop.example.com/', 'status' => 'down', 'last_error' => 'Returned 502.', 'interval_seconds' => 60, 'timeout_seconds' => 10, 'status_changed_at' => now()->subMinutes(4), 'ssl_expires_at' => now()->addDays(5)]);
    $monitor = $workspace->monitors()->value('id');

    $this->actingAs($workspace->owner)
        ->get(homeUrl($workspace))
        ->assertInertia(fn (Assert $page) => $page
            ->where('issues', fn ($issues) => collect($issues)->pluck('title')->all() === [
                'Shop is down',
                'gone stopped reporting',
                'full is 95% full',
                'filling is 87% full',
                'tight is using 97% of its memory',
                'Shop’s certificate expires in 4 days',
                'filling hasn’t been backed up since 3 days ago',
            ])
            ->where('issues', fn ($issues) => collect($issues)->pluck('brief')->all() === [
                'Returned 502. Down for 4 minutes.',
                'Its daemon stopped answering 10 minutes ago.',
                'Its main disk has 4 GB left.',
                'The disk at /var/lib/docker has 10 GB left.',
                '3.9 GB of 4 GB in use.',
                'It expires on '.now()->addDays(5)->format('j M').'.',
                'It’s set to back up daily, but no backup has finished.',
            ])
            ->where('issues.2.detail', fn ($d) => str_contains($d, 'Its main disk has 4 GB left'))
            ->where('issues.0.href', $workspace->url('/monitors/'.$monitor))
            ->where('issues.0.dismissible', true)
            ->where('overview.offline', ['gone']));
});

test('the worst vulnerable package is drawn with the machines it is on, and asks for a look when it is serious', function () {
    $workspace = Workspace::factory()->create();
    [$web, $api, $db, $worker] = collect(['web-1', 'api-1', 'db-1', 'worker-1'])->map(fn ($name) => Machine::factory()->for($workspace)->create(['name' => $name]))->all();
    $critical = Vulnerability::query()->create(['cve' => 'CVE-2026-1', 'severity' => 'critical', 'exploited' => true]);
    $critical2 = Vulnerability::query()->create(['cve' => 'CVE-2026-2', 'severity' => 'critical']);
    $high = Vulnerability::query()->create(['cve' => 'CVE-2026-3', 'severity' => 'high']);
    $find = fn (Machine $machine, Vulnerability $v, string $source) => MachineVulnerability::query()->create(['machine_id' => $machine->id, 'vulnerability_id' => $v->id, 'source' => $source, 'packages' => [$source], 'detected_at' => now()]);
    foreach ([$web, $api, $db, $worker] as $machine) {
        $find($machine, $critical, 'openssl');
        $find($machine, $critical2, 'openssl');
    }
    $find($web, $high, 'sudo');
    MachineVulnerability::query()->create(['machine_id' => $api->id, 'vulnerability_id' => $high->id, 'source' => 'linux', 'packages' => ['linux'], 'detected_at' => now(), 'resolved_at' => now()]);
    foreach ([$web, $api, $db] as $machine) {
        MachinePackageUpdate::query()->create(['machine_id' => $machine->id, 'name' => 'openssl', 'installed' => '3.0.1', 'candidate' => '3.0.2', 'security' => true]);
    }
    MachinePackageUpdate::query()->create(['machine_id' => $web->id, 'name' => 'vim', 'installed' => '9.0', 'candidate' => '9.1', 'security' => false]);

    $this->actingAs($workspace->owner)->get(homeUrl($workspace))->assertInertia(fn (Assert $page) => $page
        ->where('security.package', 'openssl')
        ->where('security.severity', 'critical')
        ->where('security.cves', 2)
        ->where('security.exploited', 1)
        ->where('security.machines', [['id' => $web->id, 'name' => 'web-1'], ['id' => $api->id, 'name' => 'api-1'], ['id' => $db->id, 'name' => 'db-1']])
        ->where('security.affected', 4)
        ->where('security.critical', 2)
        ->where('security.high', 1)
        ->where('security.updates', 3)
        ->where('issues.0.title', '2 critical CVEs in openssl')
        ->where('issues.0.brief', 'One is being exploited. 3 security updates are waiting.')
        ->where('issues.0.href', route('workspace.updates.index', ['workspace' => $workspace->slug]))
        ->where('issues.0.dismissible', false));
});

test('with no known vulnerabilities the security card follows the update waiting on the most machines, without asking for a look', function () {
    $workspace = Workspace::factory()->create();
    [$a, $b] = Machine::factory()->for($workspace)->count(2)->create()->all();
    foreach ([[$a, 'curl'], [$b, 'curl'], [$a, 'zlib']] as [$machine, $name]) {
        MachinePackageUpdate::query()->create(['machine_id' => $machine->id, 'name' => $name, 'installed' => '1', 'candidate' => '2', 'security' => true]);
    }

    $this->actingAs($workspace->owner)->get(homeUrl($workspace))->assertInertia(fn (Assert $page) => $page
        ->where('security.package', 'curl')
        ->where('security.severity', null)
        ->where('security.cves', 0)
        ->where('security.affected', 2)
        ->where('security.updates', 3)
        ->has('issues', 0));
});

test('the backups card counts what is covered, puts failures first and names where backups go', function () {
    $this->freezeTime();
    $workspace = Workspace::factory()->create();
    $r2 = homeDestination($workspace, 'R2', 'https://acc123.r2.cloudflarestorage.com');
    $b2 = homeDestination($workspace, 'B2', 's3.eu-central-003.backblazeb2.com');
    $machine = fn (string $name, ?BackupDestination $to, string $schedule = 'daily') => tap(Machine::factory()->for($workspace)->create(['name' => $name]),
        fn (Machine $m) => $m->forceFill(['backup_destination_id' => $to?->id, 'backup_schedule' => $schedule])->save());
    $api = $machine('api-1', $r2);
    $db = $machine('db-1', $r2);
    $machine('web-1', $b2, 'weekly');
    $machine('staging-1', null, 'off');
    $backup = fn (Machine $m, string $state, string $trigger, $at) => tap(new Backup(['service' => 'docker:a', 'service_name' => 'a', 'trigger' => $trigger, 'state' => $state]), function (Backup $b) use ($m, $at) {
        $b->machine_id = $m->id;
        $b->created_at = $at;
        $b->save();
    });
    $backup($db, 'failed', 'scheduled', now()->subHours(5));
    $backup($api, 'succeeded', 'scheduled', now()->subHours(6));
    $backup($api, 'succeeded', 'pre-deploy', now()->subHour());
    $backup($db, 'succeeded', 'manual', now()->subHours(2));
    $backup($db, 'failed', 'scheduled', now()->subHours(4));

    $this->actingAs($workspace->owner)->get(homeUrl($workspace))->assertInertia(fn (Assert $page) => $page
        ->where('backups.machines', 4)
        ->where('backups.covered', 3)
        ->where('backups.failed', 1)
        ->where('backups.schedule', 'daily')
        ->where('backups.lastRunAt', now()->subHours(4)->toIso8601String())
        ->where('backups.sources', [
            ['id' => $db->id, 'name' => 'db-1', 'database' => false, 'failed' => true],
            ['id' => $api->id, 'name' => 'api-1', 'database' => false, 'failed' => false],
        ])
        ->where('backups.destinations', [
            ['id' => $r2->id, 'name' => 'R2', 'logo' => 'cloudflarestorage.com'],
            ['id' => $b2->id, 'name' => 'B2', 'logo' => 'backblazeb2.com'],
        ]));
});

test('fixes waiting for approval ask for a look', function () {
    $workspace = Workspace::factory()->create();
    $machine = Machine::factory()->for($workspace)->create();
    $fix = AgentFix::query()->create(['workspace_id' => $workspace->id, 'machine_id' => $machine->id, 'state' => 'needs_approval', 'title' => 'nginx is stale']);

    $this->actingAs($workspace->owner)->get(homeUrl($workspace))->assertInertia(fn (Assert $page) => $page
        ->where('issues.0.title', 'A fix needs your approval')
        ->where('issues.0.brief', 'nginx is stale')
        ->where('issues.0.action', 'Review')
        ->where('issues.0.href', route('workspace.fixes.index', ['workspace' => $workspace->slug, 'fix' => $fix->id]))
        ->where('issues.0.dismissible', false));
});

test('telemetry keeps real disks and how full the fullest one is', function () {
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    $machine = Machine::factory()->create();
    $machine->forceFill(['uid' => (string) Str::uuid()])->save();
    $disk = fn ($mount, $used, $free) => ['mount' => $mount, 'used_bytes' => $used, 'free_bytes' => $free, 'inodes_used' => 0, 'inodes_free' => 0, 'read_bytes' => 0, 'write_bytes' => 0];

    $this->postJson('/api/gateway/machines/'.$machine->uid.'/ingest', ['envelopes' => [['v' => 1, 'kind' => 'telemetry', 'payload' => ['samples' => [[
        'ts' => time(), 'cpu_percent' => 5, 'mem_used' => 1 << 30,
        'disks' => [$disk('/', 30 << 30, 50 << 30), $disk('/data', 90 << 30, 10 << 30), $disk('/snap/core/1', 1 << 30, 0), $disk('/boot', 100 << 20, 400 << 20)],
    ]]]]]], ['X-Gateway-Secret' => 'test-gateway-secret'])->assertOk();

    $machine->refresh();
    expect($machine->disk_percent)->toBe(90)
        ->and(array_column($machine->disks, 'mount'))->toBe(['/data', '/']);
});

test('the setup guide can be skipped and resumed per person', function () {
    $workspace = Workspace::factory()->create();
    $teammate = User::factory()->create();
    $workspace->members()->attach($teammate, ['role' => 'admin']);

    $this->actingAs($workspace->owner)
        ->post(homeUrl($workspace).'onboarding/dismiss')
        ->assertRedirect();

    $this->actingAs($workspace->owner)
        ->get(homeUrl($workspace).'setup')
        ->assertInertia(fn (Assert $page) => $page->where('hidden', true)->where('setupGuide', null));

    $this->actingAs($teammate)
        ->get(homeUrl($workspace).'setup')
        ->assertInertia(fn (Assert $page) => $page->where('hidden', false));

    $this->actingAs($workspace->owner)->delete(homeUrl($workspace).'onboarding/dismiss');

    $this->actingAs($workspace->owner)
        ->get(homeUrl($workspace).'setup')
        ->assertInertia(fn (Assert $page) => $page->where('hidden', false));
});

test('outsiders cannot skip a workspace setup guide', function () {
    $workspace = Workspace::factory()->create();

    $this->actingAs(User::factory()->create())
        ->post(homeUrl($workspace).'onboarding/dismiss')
        ->assertForbidden();
});
