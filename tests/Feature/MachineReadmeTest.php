<?php

use App\Jobs\WriteMachineReadme;
use App\Models\Activity;
use App\Models\Backup;
use App\Models\BackupDestination;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\MachineReadme;
use App\Models\MachineVulnerability;
use App\Models\ProjectMachine;
use App\Models\User;
use App\Models\Vulnerability;
use App\Models\Workspace;
use App\Support\Assistant\AssistantAllowance;
use App\Support\Machines\Readme\MachineReadmes;
use App\Support\Machines\Readme\ReadmeFacts;
use App\Support\Machines\Readme\ReadmeWords;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

const README_COMMIT = '0123456789abcdef0123456789abcdef01234567';

beforeEach(function () {
    config()->set('services.groq.key', '');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
});

/** @return array{0: Workspace, 1: Machine, 2: Machine} */
function readmeMachine(): array
{
    $workspace = Workspace::factory()->create(['slug' => 'northwind', 'name' => 'Northwind']);
    $destination = BackupDestination::query()->forceCreate(['workspace_id' => $workspace->id, 'name' => 'Storage Box', 'type' => 's3', 'status' => 'ok']);
    $db = Machine::factory()->for($workspace)->create(['name' => 'db-1', 'provider' => 'DigitalOcean']);
    $db->forceFill(['backup_schedule' => 'daily', 'backup_destination_id' => $destination->id])->save();

    $web = Machine::factory()->for($workspace)->create(['name' => 'web-1', 'provider' => 'Hetzner', 'ip_address' => '203.0.113.20']);
    $web->forceFill([
        'uid' => (string) Str::uuid(),
        'region' => 'fsn1',
        'tier' => 'CX32',
        'last_seen_at' => now(),
        'cpu_percent' => 12,
        'memory_used_mb' => 2048,
        'backup_schedule' => 'daily',
        'backup_destination_id' => $destination->id,
        'security_updates_count' => 3,
        'updates_count' => 3,
        'os_release' => ['id' => 'ubuntu', 'version_id' => '24.04', 'pretty_name' => 'Ubuntu 24.04.1 LTS'],
        'facts' => [
            'os' => 'Ubuntu', 'os_version' => '24.04.1 LTS', 'cpu_cores' => 4, 'memory_bytes' => 8 * 1024 ** 3,
            'hostname' => 'web-1', 'boot_ts' => now()->subDays(41)->getTimestamp(),
            'hosting' => ['provider' => 'Hetzner', 'region' => 'fsn1', 'instance_type' => 'CX32'],
            'disks' => [
                ['mount' => '/', 'device' => '/dev/sda1', 'fs_type' => 'ext4', 'total_bytes' => 160 * 1024 ** 3],
                ['mount' => '/mnt/uploads', 'device' => '/dev/sdb', 'fs_type' => 'ext4', 'total_bytes' => 100 * 1024 ** 3],
            ],
            'security' => ['ssh' => ['ports' => [22], 'permit_root_login' => 'prohibit-password', 'password_authentication' => false, 'kbd_interactive_authentication' => false, 'use_pam' => true], 'auto_updates' => true],
        ],
        'disks' => [
            ['mount' => '/', 'used' => 40 * 1024 ** 3, 'total' => 160 * 1024 ** 3],
            ['mount' => '/mnt/uploads', 'used' => 18 * 1024 ** 3, 'total' => 100 * 1024 ** 3],
        ],
        'app_settings' => ['shop' => ['deploy_on_push' => true, 'push_branch' => 'main']],
        'inventory' => [
            'scanned_at' => now()->getTimestamp(), 'complete' => true,
            'services' => [
                ['key' => 'systemd:nginx.service', 'name' => 'nginx', 'kind' => 'proxy', 'manager' => 'systemd', 'status' => 'running', 'ports' => [80, 443], 'confidence' => 95, 'details' => ['managed' => 'true']],
                ['key' => 'docker:aaa111', 'name' => 'serveros-shop-'.substr(README_COMMIT, 0, 12), 'kind' => 'container', 'manager' => 'docker', 'status' => 'running', 'ports' => [3000], 'confidence' => 95,
                    'details' => ['managed' => 'true', 'image' => 'serveros/shop:0123456', 'mounts' => '/mnt/uploads:/app/public/uploads']],
                ['key' => 'docker:bbb222', 'name' => 'serveros-redis-redis-1', 'kind' => 'container', 'manager' => 'docker', 'status' => 'running', 'ports' => [6379], 'confidence' => 95,
                    'details' => ['managed' => 'true', 'image' => 'redis:7-alpine', 'compose_project' => 'serveros-redis', 'backup' => 'none']],
                ['key' => 'systemd:fail2ban.service', 'name' => 'fail2ban', 'kind' => 'other', 'manager' => 'systemd', 'status' => 'running', 'ports' => [], 'confidence' => 95, 'details' => []],
            ],
            'listeners' => [
                ['proto' => 'tcp', 'address' => '0.0.0.0', 'port' => 22, 'pid' => 1, 'exe' => '/usr/sbin/sshd', 'user' => 'root'],
                ['proto' => 'tcp', 'address' => '0.0.0.0', 'port' => 80, 'pid' => 2, 'exe' => '/usr/sbin/nginx', 'user' => 'root'],
                ['proto' => 'tcp', 'address' => '0.0.0.0', 'port' => 443, 'pid' => 2, 'exe' => '/usr/sbin/nginx', 'user' => 'root'],
            ],
            'firewall' => ['backend' => 'ufw', 'installed' => true, 'active' => true, 'default_incoming' => 'deny', 'ports' => [
                ['port' => 22, 'proto' => 'tcp', 'exposure' => 'open'], ['port' => 80, 'proto' => 'tcp', 'exposure' => 'open'], ['port' => 443, 'proto' => 'tcp', 'exposure' => 'open'],
            ], 'rules' => []],
            'certificates' => [
                ['path' => '/etc/letsencrypt/live/shop.northwind.dev/cert.pem', 'subject' => 'CN=shop.northwind.dev', 'issuer' => 'R11', 'not_after' => now()->addDays(62)->getTimestamp(), 'names' => ['shop.northwind.dev'], 'renewal' => 'certbot'],
            ],
            'unknown' => [], 'warnings' => [],
        ],
        'inventory_scanned_at' => now(),
    ])->save();

    DaemonJob::query()->create([
        'id' => (string) Str::uuid(), 'machine_id' => $web->id, 'user_id' => $workspace->owner_id, 'kind' => 'deploy', 'service' => 'shop', 'state' => 'succeeded',
        'command' => ['job' => ['type' => 'deploy', 'service' => 'shop', 'repo' => 'https://github.com/northwind/shop', 'commit' => README_COMMIT, 'domains' => ['shop.northwind.dev'], 'port' => 3000]],
        'result' => [],
    ]);

    $project = $workspace->projects()->create(['name' => 'Shop']);
    $environment = $project->production();
    $app = $project->nodes()->create(['machine_id' => $web->id, 'environment_id' => $environment->id, 'name' => 'shop', 'kind' => 'app']);
    $cache = $project->nodes()->create(['machine_id' => $web->id, 'environment_id' => $environment->id, 'name' => 'redis', 'kind' => 'cache', 'template' => 'redis']);
    $database = $project->nodes()->create(['machine_id' => $db->id, 'environment_id' => $environment->id, 'name' => 'postgres', 'kind' => 'database', 'template' => 'postgres']);
    $project->links()->create(['app_node_id' => $app->id, 'resource_node_id' => $cache->id])->forceFill(['state' => 'applied'])->save();
    $project->links()->create(['app_node_id' => $app->id, 'resource_node_id' => $database->id])->forceFill(['state' => 'applied'])->save();
    foreach ([$web, $db] as $i => $member) {
        ProjectMachine::query()->forceCreate(['project_id' => $project->id, 'machine_id' => $member->id, 'host' => $i + 2, 'listen_port' => 51820, 'public_key' => base64_encode(random_bytes(32)), 'state' => 'up']);
    }

    Backup::query()->forceCreate(['machine_id' => $web->id, 'backup_destination_id' => $destination->id, 'service' => 'docker:aaa111', 'service_name' => 'serveros-shop-'.substr(README_COMMIT, 0, 12),
        'trigger' => 'scheduled', 'state' => 'succeeded', 'bytes' => 2048, 'finished_at' => now()->subHours(9)]);

    $openssl = Vulnerability::query()->create(['cve' => 'CVE-2026-1', 'severity' => 'critical', 'exploited' => true]);
    $openssl2 = Vulnerability::query()->create(['cve' => 'CVE-2026-2', 'severity' => 'critical']);
    foreach ([$openssl, $openssl2] as $v) {
        MachineVulnerability::query()->create(['machine_id' => $web->id, 'vulnerability_id' => $v->id, 'source' => 'openssl', 'packages' => ['openssl'], 'fixed_by' => '3.0.13-0ubuntu3.5', 'detected_at' => now()]);
    }

    return [$workspace, $web->fresh(), $db];
}

/** @param  array<string, mixed>  $answer */
function fakeReadmeModel(array $answer): void
{
    config()->set('services.groq.key', 'test-key');
    config()->set('services.groq.model', 'openai/gpt-oss-120b');
    Http::fake(['api.groq.com/*' => Http::response([
        'model' => 'openai/gpt-oss-120b',
        'choices' => [['message' => ['content' => json_encode($answer)]]],
    ])]);
}

/** @return array<string, mixed> */
function goodReadmeAnswer(): array
{
    return [
        'intro' => 'web-1 is a Hetzner CX32 in Falkenstein running Ubuntu 24.04. It serves **shop.northwind.dev**. nginx takes the web traffic and hands it to the shop app, which keeps its cache in Redis here and its data in Postgres on **db-1**. One thing needs you now: !!an openssl bug that’s being exploited!!.',
        'wiring' => 'Requests come in through nginx on port 443 and go to shop. shop uses redis on this machine and reaches postgres on **db-1** over the private network.',
        'services' => [
            'systemd:nginx.service' => 'Takes every request on ports 80 and 443 and passes it to the right app.',
            'app:shop' => 'The Shop website. A push to main on northwind/shop deploys a new version.',
            'container:serveros-redis-redis-1' => 'The Shop’s cache.',
            'made:up' => 'Something that isn’t there.',
        ],
    ];
}

test('the facts come from what the panel stores: wiring, services, data, risks and what looks good', function () {
    [, $web] = readmeMachine();

    $facts = app(ReadmeFacts::class)->build($web);

    expect($facts['machine'])->toMatchArray(['name' => 'web-1', 'provider' => 'Hetzner', 'plan' => 'CX32', 'city' => 'Falkenstein', 'os' => 'Ubuntu 24.04', 'upDays' => 41])
        ->and($facts['sites'])->toBe(['shop.northwind.dev'])
        ->and($facts['open'])->toBe([22, 80, 443]);

    expect($facts['wiring']['entry'])->toMatchArray(['id' => 'systemd:nginx.service', 'name' => 'nginx', 'port' => 443, 'state' => 'live'])
        ->and($facts['wiring']['apps'])->toHaveCount(1)
        ->and($facts['wiring']['apps'][0])->toMatchArray(['id' => 'app:shop', 'name' => 'shop', 'port' => 3000, 'routed' => true])
        ->and(collect($facts['wiring']['deps'])->map(fn (array $d) => [$d['name'], $d['machine']['name'] ?? null, $d['port'], $d['private']])->all())
        ->toBe([['redis', null, 6379, false], ['postgres', 'db-1', null, true]]);

    $services = collect($facts['services'])->keyBy('id');
    expect($services->keys()->all())->toBe(['systemd:nginx.service', 'app:shop', 'container:serveros-redis-redis-1', 'systemd:fail2ban.service'])
        ->and($services['systemd:nginx.service'])->toMatchArray(['how' => 'system', 'ports' => [80, 443]])
        ->and($services['app:shop'])->toMatchArray(['how' => 'docker', 'repo' => 'northwind/shop', 'branch' => 'main', 'deploysOnPush' => true, 'domains' => ['shop.northwind.dev'], 'project' => 'Shop'])
        ->and($services['container:serveros-redis-redis-1'])->toMatchArray(['name' => 'redis', 'kind' => 'cache', 'logo' => 'redis']);

    $data = collect($facts['data'])->keyBy('where');
    expect($data['/mnt/uploads'])->toMatchArray(['what' => 'Uploads', 'size' => 18 * 1024 ** 3, 'tone' => 'good'])
        ->and($data['/mnt/uploads']['backup'])->toStartWith('Every day to Storage Box')
        ->and($data['Inside its container'])->toMatchArray(['what' => 'redis', 'tone' => 'none', 'backup' => 'Not backed up, it’s a cache'])
        ->and($data['postgres on db-1'])->toMatchArray(['tone' => 'good', 'backup' => 'With db-1’s backups']);

    expect(collect($facts['risks'])->pluck('id')->all())->toBe(['security-openssl', "restore-{$web->id}-never"])
        ->and($facts['risks'][0])->toMatchArray([
            'severity' => 'urgent',
            'title' => 'openssl has 2 critical bugs, and one is being exploited.',
            'detail' => 'The fix is in the 3 security updates waiting for web-1.',
            'kind' => 'updates',
            'action' => 'Install updates',
        ])
        ->and($facts['risks'][1]['title'])->toBe('The backups of shop have never been restored.');

    expect(collect($facts['good'])->pluck('id')->all())->toBe(['ports', 'ssh-keys', 'auto-updates', 'backups', 'certificates', 'uptime'])
        ->and($facts['good'][0]['text'])->toBe('Only ports 22, 80 and 443 are open to the internet.')
        ->and($facts['good'][4]['text'])->toBe('HTTPS certificates renew on their own. The next one is due in 62 days.');
});

test('a risk or a good line only shows when a fact backs it', function () {
    $workspace = Workspace::factory()->create();
    $machine = Machine::factory()->for($workspace)->create(['name' => 'bare-1']);
    $machine->forceFill(['uid' => (string) Str::uuid(), 'last_seen_at' => now(), 'backup_schedule' => 'off', 'inventory' => [
        'scanned_at' => now()->getTimestamp(), 'complete' => true, 'listeners' => [],
        'services' => [['key' => 'systemd:redis-server.service', 'name' => 'redis-server', 'kind' => 'cache', 'manager' => 'systemd', 'status' => 'running', 'ports' => [6379], 'details' => []]],
    ]])->save();

    $facts = app(ReadmeFacts::class)->build($machine->fresh());

    expect($facts['risks'])->toBe([])
        ->and($facts['good'])->toBe([])
        ->and($facts['open'])->toBeNull()
        ->and($facts['wiring'])->toBe(['entry' => null, 'apps' => [], 'deps' => []])
        ->and(collect($facts['data'])->pluck('backup')->all())->toBe(['Not backed up, it’s a cache']);
});

test('the words come from the workspace’s model, checked against the facts, and are stored', function () {
    [$workspace, $web] = readmeMachine();
    fakeReadmeModel(goodReadmeAnswer());

    (new WriteMachineReadme($web->id))->handle(app(ReadmeFacts::class), app(ReadmeWords::class));

    $readme = MachineReadme::query()->where('machine_id', $web->id)->sole();
    expect($readme->state)->toBe(MachineReadme::READY)
        ->and($readme->source)->toBe('ai')
        ->and($readme->model)->toBe('openai/gpt-oss-120b')
        ->and($readme->reason)->toBeNull()
        ->and($readme->facts_hash)->toBe(ReadmeWords::hash(app(ReadmeFacts::class)->build($web)))
        ->and($readme->sections['intro'])->toContain('!!an openssl bug that’s being exploited!!')
        ->and($readme->sections['services'])->toHaveKeys(['systemd:nginx.service', 'app:shop'])
        ->and($readme->sections['services'])->not->toHaveKey('made:up')
        ->and(AssistantAllowance::for($workspace)['used'])->toBe(1);

    Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), 'api.groq.com')
        && str_contains((string) $request['messages'][1]['content'], 'shop.northwind.dev')
        && ! str_contains((string) $request['messages'][1]['content'], '203.0.113.20'));
});

test('words that say something the facts don’t back are thrown away for the panel’s own', function (array $change) {
    [, $web] = readmeMachine();
    fakeReadmeModel(array_replace(goodReadmeAnswer(), $change));

    (new WriteMachineReadme($web->id))->handle(app(ReadmeFacts::class), app(ReadmeWords::class));

    $readme = MachineReadme::query()->where('machine_id', $web->id)->sole();
    expect($readme->source)->toBe('panel')
        ->and($readme->reason)->toBe('rejected')
        ->and($readme->sections['intro'])->toStartWith('web-1 is a Hetzner CX32 in Falkenstein running Ubuntu 24.04.');
})->with([
    'a port it made up' => [['wiring' => 'Requests come in through nginx on port 8443.']],
    'a site it made up' => [['intro' => 'web-1 serves **blog.northwind.dev**.']],
    'a machine it made up' => [['intro' => 'It keeps its data on **db-9**.']],
    'a path it made up' => [['wiring' => 'nginx serves files from /var/www/blog.']],
    'a risk in a caption' => [['wiring' => 'Requests come in through nginx. !!It’s broken!!.']],
    'the wrong shape' => [['services' => 'nginx and shop']],
]);

test('with no model, out of messages, or when the model fails, the panel writes the words itself and says why', function (string $reason) {
    [$workspace, $web] = readmeMachine();
    if ($reason === 'quota') {
        fakeReadmeModel(goodReadmeAnswer());
        for ($i = 0; $i < AssistantAllowance::PER_HOUR; $i++) {
            AssistantAllowance::take($workspace);
        }
    } elseif ($reason === 'error') {
        config()->set('services.groq.key', 'test-key');
        Http::fake(['api.groq.com/*' => Http::response(['error' => ['message' => 'overloaded']], 503)]);
    } else {
        Http::fake();
    }

    (new WriteMachineReadme($web->id))->handle(app(ReadmeFacts::class), app(ReadmeWords::class));

    $readme = MachineReadme::query()->where('machine_id', $web->id)->sole();
    expect($readme->source)->toBe('panel')
        ->and($readme->reason)->toBe($reason)
        ->and($readme->sections['intro'])->toBe('web-1 is a Hetzner CX32 in Falkenstein running Ubuntu 24.04. It serves one site, **shop.northwind.dev**. nginx takes the web traffic and hands it to shop. It reaches postgres on **db-1**. One thing needs you now: !!openssl has 2 critical bugs, and one is being exploited!!.')
        ->and($readme->sections['services']['app:shop'])->toBe('Serves shop.northwind.dev. A push to main on northwind/shop deploys a new version.');
    if ($reason !== 'error') {
        Http::assertNothingSent();
    }

    $this->actingAs($workspace->owner)->get($web->workspace->url("/machines/{$web->id}/readme"))
        ->assertInertia(fn (Assert $page) => $page->where('readme.source', 'panel')->where('readme.reason', $reason)->where('readme.writing', false));
})->with(['no_model', 'quota', 'error']);

test('it is written right after the first scan, checked at most once an hour, and written again only when the facts change', function () {
    Queue::fake();
    [$workspace, $web] = readmeMachine();
    $inventory = $web->inventory;
    $web->forceFill(['inventory' => null, 'inventory_scanned_at' => null])->save();

    $this->withHeaders(['X-Gateway-Secret' => 'test-gateway-secret'])
        ->postJson('/api/gateway/machines/'.$web->uid.'/ingest', ['envelopes' => [['v' => 1, 'kind' => 'inventory', 'payload' => $inventory]]])
        ->assertOk();

    expect(MachineReadme::query()->where('machine_id', $web->id)->sole()->state)->toBe(MachineReadme::WRITING);
    Queue::assertPushed(WriteMachineReadme::class, fn (WriteMachineReadme $job) => $job->machineId === $web->id && ! $job->force);

    fakeReadmeModel(goodReadmeAnswer());
    (new WriteMachineReadme($web->id))->handle(app(ReadmeFacts::class), app(ReadmeWords::class));
    (new WriteMachineReadme($web->id))->handle(app(ReadmeFacts::class), app(ReadmeWords::class));
    Http::assertSentCount(1);

    Queue::fake();
    Cache::flush();
    MachineReadmes::changed($web->fresh());
    MachineReadmes::changed($web->fresh());
    Queue::assertPushed(WriteMachineReadme::class, 1);

    $web->forceFill(['cpu_percent' => 99, 'memory_used_mb' => 2300])->save();
    (new WriteMachineReadme($web->id))->handle(app(ReadmeFacts::class), app(ReadmeWords::class));
    Http::assertSentCount(1);

    $web = $web->fresh();
    $services = $web->inventory['services'];
    $services[] = ['key' => 'systemd:cron.service', 'name' => 'cron', 'kind' => 'other', 'manager' => 'systemd', 'status' => 'running', 'ports' => [], 'details' => []];
    $web->forceFill(['inventory' => [...$web->inventory, 'services' => $services]])->save();
    (new WriteMachineReadme($web->id))->handle(app(ReadmeFacts::class), app(ReadmeWords::class));
    Http::assertSentCount(2);
});

test('the page shows the facts at once and the words as Writing until they arrive', function () {
    Queue::fake();
    [$workspace, $web] = readmeMachine();

    $this->actingAs($workspace->owner)->get($workspace->url("/machines/{$web->id}/readme"))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('workspace/MachineShow')
            ->where('readme.writing', true)
            ->where('readme.words', null)
            ->where('readme.facts.wiring.entry.name', 'nginx')
            ->where('readme.facts.risks.0.id', 'security-openssl'));
    Queue::assertPushed(WriteMachineReadme::class);

    fakeReadmeModel(goodReadmeAnswer());
    (new WriteMachineReadme($web->id))->handle(app(ReadmeFacts::class), app(ReadmeWords::class));

    $this->actingAs($workspace->owner)->get($workspace->url("/machines/{$web->id}/readme"))
        ->assertInertia(fn (Assert $page) => $page
            ->where('readme.writing', false)
            ->where('readme.source', 'ai')
            ->where('readme.words.services', fn ($services) => $services['app:shop'] === 'The Shop website. A push to main on northwind/shop deploys a new version.'
                && $services['systemd:fail2ban.service'] === 'A system service.')
            ->where('readme.generatedAt', fn (string $at) => abs(now()->diffInSeconds($at)) < 5));
});

test('the readme opens first the first time each person opens a newly imported machine', function () {
    Queue::fake();
    [$workspace, $web] = readmeMachine();
    MachineReadme::query()->forceCreate(['machine_id' => $web->id, 'state' => MachineReadme::READY, 'sections' => null]);
    $sam = User::factory()->create();
    $workspace->members()->attach($sam, ['role' => 'member']);
    $url = $workspace->url("/machines/{$web->id}");

    $this->actingAs($workspace->owner)->get($url)
        ->assertInertia(fn (Assert $page) => $page->where('defaultTab', 'readme')->has('readme.facts'));
    $this->actingAs($workspace->owner)->get($url)
        ->assertInertia(fn (Assert $page) => $page->where('defaultTab', 'overview')->missing('readme'));
    $this->actingAs($sam)->get($url)
        ->assertInertia(fn (Assert $page) => $page->where('defaultTab', 'readme'));
    $this->actingAs($sam)->get($url.'/overview')
        ->assertInertia(fn (Assert $page) => $page->where('defaultTab', 'overview'));

    expect(array_map('intval', array_keys(MachineReadme::query()->where('machine_id', $web->id)->sole()->seen_by)))->toBe([$workspace->owner_id, $sam->id]);

    $old = Machine::factory()->for($workspace)->create(['created_at' => now()->subDays(30)]);
    MachineReadme::query()->forceCreate(['machine_id' => $old->id, 'state' => MachineReadme::READY]);
    $this->actingAs($sam)->get($workspace->url("/machines/{$old->id}"))
        ->assertInertia(fn (Assert $page) => $page->where('defaultTab', 'overview'));
});

test('anyone in the workspace can have it written again, a few times an hour', function () {
    Queue::fake();
    [$workspace, $web] = readmeMachine();
    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);
    $url = $workspace->url("/machines/{$web->id}/readme");

    $this->actingAs($viewer)->from($url)->post($url)->assertRedirect($url);

    expect(MachineReadme::query()->where('machine_id', $web->id)->sole()->state)->toBe(MachineReadme::WRITING);
    Queue::assertPushed(WriteMachineReadme::class, fn (WriteMachineReadme $job) => $job->force);
    expect(Activity::query()->where('action', 'machine.readme_write')->sole()->subject)->toBe('web-1');

    for ($i = 1; $i < MachineReadmes::WRITES_PER_HOUR; $i++) {
        $this->actingAs($viewer)->post($url)->assertRedirect();
    }
    $this->actingAs($viewer)->post($url)
        ->assertRedirect()
        ->assertInertiaFlash('toast.type', 'error');
    Queue::assertPushed(WriteMachineReadme::class, 1);
    expect(Activity::query()->where('action', 'machine.readme_write')->count())->toBe(MachineReadmes::WRITES_PER_HOUR);
    expect(RateLimiter::attempts(MachineReadmes::writeKey($web)))->toBe(MachineReadmes::WRITES_PER_HOUR);
});

test('the readme copies as Markdown with every section', function () {
    [$workspace, $web] = readmeMachine();
    fakeReadmeModel(goodReadmeAnswer());
    (new WriteMachineReadme($web->id))->handle(app(ReadmeFacts::class), app(ReadmeWords::class));

    $markdown = app(MachineReadmes::class)->page($web->fresh(), $workspace->owner)['markdown'];

    expect($markdown)
        ->toStartWith("# web-1\n")
        ->toContain('One thing needs you now: **an openssl bug that’s being exploited**.')
        ->toContain("## How it’s wired\n\n- Internet → nginx `:443`\n- nginx `:443` → shop `:3000`\n- shop `:3000` → redis `:6379`, postgres on db-1, over the private network")
        ->toContain('| nginx | Takes every request on ports 80 and 443 and passes it to the right app. | System | `:80` `:443` |')
        ->toContain('| Uploads | `/mnt/uploads` | 18 GB | Every day to Storage Box')
        ->toContain('- **Urgent:** **openssl has 2 critical bugs, and one is being exploited.** The fix is in the 3 security updates waiting for web-1.')
        ->toContain('- Only ports 22, 80 and 443 are open to the internet.')
        ->not->toContain('!!');
});

test('add to the code repository commits the readme beside the machine’s file, in the workspace’s pull request', function () {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    config()->set('services.github_app', ['id' => '12345', 'slug' => 'serveros', 'client_id' => 'Iv1.abc', 'client_secret' => 'shh', 'private_key' => base64_encode($pem)]);
    [$workspace, $web] = readmeMachine();
    $workspace->githubInstallations()->create(['installation_id' => 42, 'account_login' => 'northwind', 'account_type' => 'Organization', 'repository_ids' => null]);
    $workspace->forceFill(['code_repository' => ['repo' => 'northwind/infra', 'branch' => 'serveros/export', 'folder' => 'serveros', 'private' => true]])->save();
    MachineReadme::query()->forceCreate(['machine_id' => $web->id, 'state' => MachineReadme::READY, 'sections' => ReadmeWords::panel(app(ReadmeFacts::class)->build($web)), 'source' => 'panel', 'reason' => 'no_model', 'generated_at' => now()]);
    $routes = [
        'POST app/installations/42/access_tokens' => Http::response(['token' => 'ghs_token']),
        'GET installation/repositories' => Http::response(['repositories' => [['id' => 1, 'full_name' => 'northwind/infra', 'private' => true, 'default_branch' => 'main']]]),
        'GET repos/northwind/infra' => Http::response(['default_branch' => 'main']),
        'GET repos/northwind/infra/git/ref/heads/serveros/export' => Http::response(['object' => ['sha' => 'head1']]),
        'GET repos/northwind/infra/git/commits/head1' => Http::response(['tree' => ['sha' => 'tree1']]),
        'GET repos/northwind/infra/git/trees/tree1' => Http::response(['tree' => [['path' => 'serveros/machines/web-1.yml', 'type' => 'blob', 'sha' => 'y']]]),
        'POST repos/northwind/infra/git/blobs' => Http::response(['sha' => 'blob1'], 201),
        'POST repos/northwind/infra/git/trees' => Http::response(['sha' => 'tree2'], 201),
        'POST repos/northwind/infra/git/commits' => Http::response(['sha' => 'commit2'], 201),
        'PATCH repos/northwind/infra/git/refs/heads/serveros/export' => Http::response(['ref' => 'refs/heads/serveros/export']),
        'GET repos/northwind/infra/pulls' => Http::response([['number' => 12, 'html_url' => 'https://github.com/northwind/infra/pull/12']]),
    ];
    Http::fake(function (HttpRequest $request) use ($routes) {
        return $routes[$request->method().' '.trim((string) parse_url($request->url(), PHP_URL_PATH), '/')] ?? Http::response(['message' => 'Not Found'], 404);
    });
    $url = $workspace->url("/machines/{$web->id}/readme/github");

    $this->actingAs($workspace->owner)->get($workspace->url("/machines/{$web->id}/readme"))
        ->assertInertia(fn (Assert $page) => $page->where('readme.repository', ['repo' => 'northwind/infra', 'file' => 'web-1.md', 'connected' => true, 'pullRequest' => null])->where('readme.canCommit', true));

    $this->actingAs($workspace->owner)->post($url)
        ->assertRedirect()
        ->assertInertiaFlash('toast.message', 'Added web-1.md to pull request #12 on northwind/infra.');

    Http::assertSent(fn (HttpRequest $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/git/blobs')
        && str_starts_with((string) base64_decode((string) $request['content']), "# web-1\n"));
    Http::assertSent(fn (HttpRequest $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/git/trees')
        && collect($request['tree'])->pluck('path')->all() === ['serveros/machines/web-1.md']);
    Http::assertSent(fn (HttpRequest $request) => $request->method() === 'PATCH' && $request['sha'] === 'commit2');
    Http::assertNotSent(fn (HttpRequest $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/pulls'));
    expect(Activity::query()->where('action', 'github.machine_readme')->sole()->meta)->toMatchArray(['number' => 12, 'file' => 'serveros/machines/web-1.md'])
        ->and($workspace->fresh()->code_repository['pull'])->toBe(['number' => 12, 'url' => 'https://github.com/northwind/infra/pull/12']);

    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);
    $this->actingAs($viewer)->post($url)->assertForbidden();
});

test('without a code repository, adding it says where to set one up', function () {
    [$workspace, $web] = readmeMachine();
    Http::fake();

    $this->actingAs($workspace->owner)->get($workspace->url("/machines/{$web->id}/readme"))
        ->assertInertia(fn (Assert $page) => $page->where('readme.repository', null));
    $this->actingAs($workspace->owner)->post($workspace->url("/machines/{$web->id}/readme/github"))
        ->assertRedirect()
        ->assertInertiaFlash('toast.message', 'Pick a repository for your workspace’s code first.');
    Http::assertNothingSent();
    expect(Activity::query()->where('action', 'github.machine_readme')->exists())->toBeFalse();
});

test('another workspace can’t read the readme or have it written', function () {
    Queue::fake();
    [$workspace, $web] = readmeMachine();
    $other = Workspace::factory()->create();
    $mine = Machine::factory()->for($other)->create();

    $this->actingAs($other->owner)->get($workspace->url("/machines/{$web->id}/readme"))->assertForbidden();
    $this->actingAs($other->owner)->post($workspace->url("/machines/{$web->id}/readme"))->assertForbidden();
    $this->actingAs($other->owner)->get($other->url("/machines/{$web->id}/readme"))->assertNotFound();
    $this->actingAs($other->owner)->post($other->url("/machines/{$web->id}/readme"))->assertNotFound();
    $this->actingAs($other->owner)->post($other->url("/machines/{$web->id}/readme/github"))->assertNotFound();

    expect(MachineReadme::query()->where('machine_id', $web->id)->exists())->toBeFalse()
        ->and($mine->id)->not->toBe($web->id);
    Queue::assertNothingPushed();
});
