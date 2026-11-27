<?php

use App\Enums\Plan;
use App\Jobs\AdvanceIntegrationOperation;
use App\Models\Activity;
use App\Models\DaemonEnrolment;
use App\Models\Integration;
use App\Models\IntegrationOperation;
use App\Models\IntegrationServer;
use App\Models\Machine;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Activity\ActivityLine;
use App\Support\Integrations\IntegrationFailed;
use App\Support\Integrations\Servers\CloudServers;
use App\Support\Integrations\Servers\ServerOperations;
use App\Support\Monitors\HostResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeProxmox;

beforeEach(function () {
    Sleep::fake();
    app()->instance(HostResolver::class, new class extends HostResolver
    {
        public function resolve(string $host): array
        {
            $bare = trim($host, '[]');
            if (filter_var($bare, FILTER_VALIDATE_IP) !== false) {
                return $this->allowed($bare) ? [$bare] : [];
            }
            if ($bare === FakeProxmox::HOST) {
                return [FakeProxmox::IP];
            }
            $ips = ['pve.lan' => ['192.168.1.10'], 'metadata.example.com' => ['169.254.169.254']][$bare] ?? [];
            foreach ($ips as $ip) {
                if (! $this->allowed($ip)) {
                    return [];
                }
            }

            return $ips;
        }
    });
});

/** @param  array<string, string>  $credentials */
function pveAccount(Workspace $workspace, array $credentials = []): Integration
{
    $credentials = [...['url' => 'https://pve.example.com:8006', 'token' => FakeProxmox::TOKEN, 'fingerprint' => ''], ...$credentials];

    return $workspace->integrations()->create([
        'provider' => 'proxmox',
        'label' => 'homelab',
        'credentials' => $credentials,
        'fingerprint' => Integration::fingerprintOf('proxmox', $credentials),
        'meta' => ['nodes' => 2, 'servers' => 0],
        'status' => Integration::CONNECTED,
        'last_checked_at' => now(),
    ]);
}

/** @param  array<string, string>  $credentials */
function pveConnect(Workspace $workspace, array $credentials = []): TestResponse
{
    return test()->actingAs($workspace->owner)
        ->from($workspace->url('/integrations'))
        ->post($workspace->url('/integrations/connect/proxmox'), ['credentials' => [...['url' => 'https://pve.example.com:8006', 'token' => FakeProxmox::TOKEN, 'fingerprint' => ''], ...$credentials]]);
}

/** @return array{0: Workspace, 1: Machine, 2: Integration, 3: FakeProxmox} */
function pveLinkedMachine(): array
{
    $pve = FakeProxmox::install()->vm(100, 'web-1')->withAgentAddresses(100, ['198.51.100.7/24']);
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $machine = Machine::factory()->for($workspace)->create(['name' => 'web-1', 'ip_address' => '198.51.100.7']);
    $integration = pveAccount($workspace);
    app(CloudServers::class)->sync($integration);
    $pve->calls = [];

    return [$workspace, $machine, $integration, $pve];
}

function pveUrl(Machine $machine, string $path): string
{
    return $machine->workspace->url("/machines/{$machine->id}/server{$path}");
}

/** @return array<string, mixed> */
function pveDeferred(TestResponse $response): array
{
    $deferred = [];
    $response->assertInertia(function (Assert $page) use (&$deferred) {
        $page->loadDeferredProps(function (Assert $reload) use (&$deferred) {
            $deferred = $reload->toArray()['props'];
        });
    });

    return $deferred;
}

test('connecting Proxmox tidies the address and token, names it after the cluster and links VMs and containers by address', function () {
    $pve = FakeProxmox::install()
        ->vm(100, 'web-1', 'pve1')->withAgentAddresses(100, ['198.51.100.7/24', '2001:db8:aa::7/64'])
        ->vm(101, 'db-1', 'pve2', ['agent' => '0', 'ipconfig0' => 'ip=10.0.0.4/24,gw=10.0.0.1'])
        ->vm(102, 'spare', 'pve1', [], ['status' => 'stopped'])
        ->vm(9000, 'ubuntu-24-04', 'pve1', [], ['template' => 1, 'status' => 'stopped'])
        ->container(105, 'pihole', '198.51.100.9/24');
    $workspace = Workspace::factory()->create();
    $web = Machine::factory()->for($workspace)->create(['name' => 'web-1', 'ip_address' => '198.51.100.7']);
    $db = Machine::factory()->for($workspace)->create(['name' => 'db-1', 'ip_address' => '10.0.0.4']);
    $pihole = Machine::factory()->for($workspace)->create(['name' => 'pihole', 'ip_address' => '198.51.100.9']);

    pveConnect($workspace, ['url' => 'pve.example.com', 'token' => 'PVEAPIToken='.FakeProxmox::TOKEN])->assertSessionHasNoErrors()->assertRedirect();

    $integration = Integration::query()->sole();
    $rows = IntegrationServer::query()->get()->keyBy('server_id');
    expect($integration->label)->toBe('homelab')
        ->and($integration->credentials)->toBe(['url' => 'https://pve.example.com:8006', 'token' => FakeProxmox::TOKEN, 'fingerprint' => ''])
        ->and(DB::table('integrations')->value('credentials'))->not->toContain('fake-proxmox-secret')
        ->and($integration->meta)->toMatchArray([
            'address' => 'pve.example.com:8006', 'version' => '8.2.4', 'cluster' => 'homelab', 'nodes' => 2,
            'vms' => 3, 'containers' => 1, 'templates' => 1, 'servers' => 4, 'token' => 'serveros@pve!panel', 'pinned' => false, 'missing' => null,
        ])
        ->and($rows->pluck('server_id')->sort()->values()->all())->toBe(['100', '101', '102', '105'])
        ->and($rows['100']->machine_id)->toBe($web->id)
        ->and($rows['101']->machine_id)->toBe($db->id)
        ->and($rows['105']->machine_id)->toBe($pihole->id)
        ->and($rows['102']->machine_id)->toBeNull()
        ->and($rows['100']->details)->toMatchArray(['type' => 'qemu', 'typeLabel' => 'VM', 'cores' => 2, 'memoryGb' => 4, 'diskGb' => 32, 'location' => 'pve1', 'status' => 'running', 'ipv4' => '198.51.100.7', 'ipv6' => '2001:db8:aa::7/128'])
        ->and($rows['102']->details)->toMatchArray(['status' => 'off', 'ipv4' => null])
        ->and($rows['105']->details)->toMatchArray(['type' => 'lxc', 'typeLabel' => 'Container', 'cores' => 1, 'memoryGb' => 1, 'diskGb' => 8]);

    expect(collect($pve->calls)->pluck('host')->unique()->values()->all())->toBe([FakeProxmox::HOST])
        ->and(collect($pve->calls)->pluck('resolve')->unique()->values()->all())->toBe([['pve.example.com:8006:'.FakeProxmox::IP]])
        ->and(collect($pve->calls)->pluck('pin')->filter()->all())->toBe([]);
    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'PVEAPIToken='.FakeProxmox::TOKEN));
    expect(ActivityLine::sentence(Activity::query()->where('action', 'integration.connect')->sole()))->toBe($workspace->owner->name.' connected homelab (Proxmox)');

    $show = $this->actingAs($workspace->owner)->get($workspace->url('/integrations/'.$integration->id));
    $show->assertInertia(fn (Assert $page) => $page
        ->where('integration.summary', '2 nodes · 4 servers')
        ->where('integration.facts', [
            ['label' => 'Address', 'value' => 'pve.example.com:8006'],
            ['label' => 'Proxmox VE', 'value' => '8.2.4'],
            ['label' => 'Cluster', 'value' => 'homelab'],
            ['label' => 'Nodes', 'value' => '2'],
            ['label' => 'VMs', 'value' => '3'],
            ['label' => 'Containers', 'value' => '1'],
            ['label' => 'Token ID', 'value' => 'serveros@pve!panel'],
            ['label' => 'Certificate', 'value' => 'Trusted authority'],
        ])
        ->where('provider.fields.0.key', 'url')
        ->where('provider.fields.1.secret', true)
        ->where('provider.fields.2.key', 'fingerprint')
        ->where('usage', fn ($usage) => collect($usage)->contains('1 container is listed read only. ServerOS doesn’t change containers.')));
    $deferred = pveDeferred($show);
    $list = collect($deferred['servers']['list'])->keyBy('id');
    expect(collect($deferred['servers']['list'])->firstWhere('name', 'pihole')['can'])->toBe(['servers'])
        ->and(collect($deferred['servers']['list'])->firstWhere('name', 'web-1')['can'])->toContain('resize', 'power', 'snapshots', 'delete_servers')
        ->and($list)->toHaveCount(4);
    foreach ([$show->getContent(), json_encode($deferred), Activity::query()->get()->toJson(), $integration->toJson()] as $text) {
        expect($text)->not->toContain('fake-proxmox-secret');
    }

    $this->actingAs($workspace->owner)->get($workspace->url("/machines/{$web->id}"))
        ->assertInertia(fn (Assert $page) => $page
            ->where('cloudServer.integration.name', 'Proxmox')
            ->where('cloudServer.rules.sizing', 'custom')
            ->where('cloudServer.rules.powerOff', false));
    $this->actingAs($workspace->owner)->get($workspace->url("/machines/{$pihole->id}"))
        ->assertInertia(fn (Assert $page) => $page->where('cloudServer.can', ['servers']));
    $this->actingAs($workspace->owner)->postJson(pveUrl($pihole, '/reboot'))->assertNotFound();
    $this->actingAs($workspace->owner)->postJson(pveUrl($pihole, '/snapshots'), ['name' => 'x'])->assertNotFound();
});

test('a pinned certificate fingerprint is checked in the handshake, against the address that was checked', function () {
    $pve = FakeProxmox::install()->vm(100, 'web-1');
    $pve->certificate = FakeProxmox::FINGERPRINT;
    $workspace = Workspace::factory()->create();
    $shown = strtoupper(implode(':', str_split(FakeProxmox::FINGERPRINT, 2)));

    pveConnect($workspace)->assertSessionHasErrors(['credentials.fingerprint' => 'ServerOS doesn’t trust the certificate at pve.example.com. Proxmox makes its own, so pin it: paste its SHA-256 fingerprint from the node’s System › Certificates.']);
    pveConnect($workspace, ['fingerprint' => str_repeat('0f', 32)])->assertSessionHasErrors(['credentials.fingerprint' => 'The certificate at pve.example.com doesn’t match the fingerprint you pinned. If you renewed it, connect again with the new fingerprint.']);
    expect(collect($pve->calls)->pluck('path')->unique()->values()->all())->toBe(['version'])
        ->and(collect($pve->calls)->where('pin', '!=', null)->count())->toBe(1);
    $pve->calls = [];
    pveConnect($workspace, ['fingerprint' => 'not a fingerprint'])->assertSessionHasErrors(['credentials.fingerprint' => 'That isn’t a SHA-256 fingerprint. It’s 64 hex digits, in pairs like AB:CD:EF.']);
    expect($pve->calls)->toBe([])
        ->and(Integration::query()->count())->toBe(0);

    pveConnect($workspace, ['fingerprint' => 'SHA256 Fingerprint='.$shown])->assertSessionHasNoErrors();

    $integration = Integration::query()->sole();
    expect($integration->credential('fingerprint'))->toBe(FakeProxmox::FINGERPRINT)
        ->and($integration->meta)->toMatchArray(['pinned' => true, 'fingerprint' => $shown])
        ->and(collect($pve->calls)->pluck('host')->unique()->values()->all())->toBe([FakeProxmox::IP])
        ->and(collect($pve->calls)->pluck('hostHeader')->unique()->values()->all())->toBe(['pve.example.com:8006'])
        ->and(collect($pve->calls)->pluck('verify')->unique()->values()->all())->toBe([false])
        ->and(collect($pve->calls)->pluck('pin')->unique()->values()->all())->toBe([FakeProxmox::FINGERPRINT])
        ->and(collect($pve->calls)->pluck('resolve')->filter()->all())->toBe([])
        ->and(IntegrationServer::query()->sole()->server_id)->toBe('100');
    $this->actingAs($workspace->owner)->get($workspace->url('/integrations/'.$integration->id))
        ->assertInertia(fn (Assert $page) => $page->where('integration.facts', fn ($facts) => collect($facts)->contains(['label' => 'Certificate', 'value' => 'Pinned, ending '.substr($shown, -11)])));

    $pve->certificate = str_repeat('ee', 32);
    $this->actingAs($workspace->owner)->post($workspace->url("/integrations/{$integration->id}/check"))->assertRedirect();
    expect($integration->fresh()->last_error)->toBe('The certificate at pve.example.com doesn’t match the fingerprint you pinned. If you renewed it, connect again with the new fingerprint.');
});

test('a Proxmox address in a private network is refused on the hosted panel before anything is sent', function (string $url) {
    FakeProxmox::install();
    $workspace = Workspace::factory()->create();

    pveConnect($workspace, ['url' => $url])->assertSessionHasErrors('credentials.url');

    expect(Integration::query()->count())->toBe(0);
    Http::assertNothingSent();
})->with([
    'plain http' => ['http://pve.example.com:8006'],
    'loopback' => ['https://127.0.0.1:8006'],
    'a private address' => ['https://192.168.1.10:8006'],
    'a name that points inside' => ['https://pve.lan:8006'],
    'link local' => ['https://169.254.169.254'],
    'a path in it' => ['https://pve.example.com:8006/ui'],
    'a password in it' => ['https://root:secret@pve.example.com'],
]);

test('a self-hosted panel reaches Proxmox on its own network once private addresses are allowed, but never link-local', function () {
    $pve = FakeProxmox::install()->vm(100, 'web-1');
    config()->set(['serveros.edition' => 'self-hosted', 'monitors.allow_private' => false]);
    $workspace = Workspace::factory()->create();

    pveConnect($workspace, ['url' => 'pve.lan'])->assertSessionHasErrors(['credentials.url' => 'ServerOS can’t reach pve.lan, or it points into a private network. To use a private address, set MONITORS_ALLOW_PRIVATE=true.']);
    Http::assertNothingSent();

    config()->set('monitors.allow_private', true);
    pveConnect($workspace, ['url' => 'https://metadata.example.com'])->assertSessionHasErrors('credentials.url');
    Http::assertNothingSent();

    pveConnect($workspace, ['url' => 'pve.lan'])->assertSessionHasNoErrors();
    expect(Integration::query()->sole()->credential('url'))->toBe('https://pve.lan:8006')
        ->and(collect($pve->calls)->pluck('resolve')->unique()->values()->all())->toBe([['pve.lan:8006:192.168.1.10']]);
});

test('a token Proxmox turns down, or one that can’t see VMs, is never saved, and missing privileges are shown', function () {
    $pve = FakeProxmox::install()->vm(100, 'web-1');
    $workspace = Workspace::factory()->create();

    pveConnect($workspace, ['token' => 'serveros@pve!panel=wrong-secret-for-tests'])
        ->assertSessionHasErrors(['credentials.token' => 'Proxmox turned the token down. Check it’s copied in full and hasn’t been deleted.']);
    pveConnect($workspace, ['token' => 'just-a-secret'])
        ->assertSessionHasErrors(['credentials.token' => 'That isn’t a Proxmox API token. Paste it as user@realm!tokenid=secret.']);
    $pve->permissions = ['/' => ['Sys.Audit' => 1]];
    pveConnect($workspace)->assertSessionHasErrors(['credentials.token' => 'This token can’t see any VMs. Give it the PVEVMAdmin role on /, or untick Privilege Separation.']);
    expect(Integration::query()->count())->toBe(0);

    $pve->permissions = ['/vms' => ['VM.Audit' => 1, 'VM.PowerMgmt' => 1, 'VM.Config.CPU' => 1, 'VM.Config.Memory' => 1, 'VM.Config.Disk' => 1, 'VM.Config.Cloudinit' => 1, 'VM.Clone' => 1, 'VM.Allocate' => 1], '/storage' => ['Datastore.AllocateSpace' => 1]];
    $pve->cluster = null;
    $pve->nodes = ['pve1' => $pve->nodes['pve1']];
    pveConnect($workspace)->assertSessionHasNoErrors();

    $integration = Integration::query()->sole();
    expect($integration->label)->toBe('pve1')
        ->and($integration->meta['missing'])->toBe('VM.Snapshot, VM.Monitor');
    $this->actingAs($workspace->owner)->get($workspace->url('/integrations/'.$integration->id))
        ->assertInertia(fn (Assert $page) => $page->where('integration.facts', fn ($facts) => collect($facts)->contains(['label' => 'Cluster', 'value' => 'Single node'])
            && collect($facts)->contains(['label' => 'Missing privileges', 'value' => 'VM.Snapshot, VM.Monitor'])));
});

test('a new VM is cloned from the template, sized, given cloud-init, started, and ServerOS goes in through the guest agent', function () {
    config()->set('daemon.install_ready', true);
    Queue::fake([AdvanceIntegrationOperation::class]);
    $pve = FakeProxmox::install()
        ->vm(9000, 'ubuntu-24-04', 'pve2', [], ['template' => 1, 'status' => 'stopped'])
        ->vm(9001, 'debian-12-plain', 'pve1', ['agent' => '0'], ['template' => 1, 'status' => 'stopped'])
        ->vm(9002, 'no-cloud-init', 'pve1', ['ide2' => 'none,media=cdrom'], ['template' => 1, 'status' => 'stopped']);
    $pve->taskPolls = 2;
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $integration = pveAccount($workspace);

    $options = $this->actingAs($workspace->owner)->getJson($workspace->url("/integrations/{$integration->id}/server-options"))->assertOk();
    expect($options->json('rules.sizing'))->toBe('custom')
        ->and($options->json('types'))->toBe([])
        ->and(collect($options->json('locations'))->pluck('name')->all())->toBe(['pve1', 'pve2'])
        ->and(collect($options->json('images'))->pluck('label')->all())->toBe(['ubuntu-24-04', 'debian-12-plain', 'no-cloud-init'])
        ->and($options->json('images.0'))->toMatchArray(['id' => '9000', 'recommended' => true, 'location' => 'pve2', 'size' => ['cores' => 2, 'memoryGb' => 4, 'diskGb' => 32], 'agent' => true, 'cloudInit' => true])
        ->and($options->json('images.1'))->toMatchArray(['agent' => false, 'cloudInit' => true])
        ->and($options->json('images.2'))->toMatchArray(['agent' => true, 'cloudInit' => false]);

    $key = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFakeKeyForTestsOnly sam@laptop';
    $form = ['name' => 'web-3', 'image' => '9000', 'cores' => 4, 'memory_gb' => 8, 'disk_gb' => 64, 'user' => 'deploy', 'ssh_keys' => $key, 'address' => '192.168.1.50/24', 'gateway' => '192.168.1.1'];
    $post = fn (array $changes = []) => $this->actingAs($workspace->owner)->postJson($workspace->url("/integrations/{$integration->id}/servers"), [...$form, ...$changes]);
    $post(['disk_gb' => 20])->assertJsonValidationErrors(['disk_gb' => 'The template’s disk is 32 GB, so it can’t be smaller.']);
    $post(['ssh_keys' => 'not a key'])->assertJsonValidationErrors('ssh_keys');
    $post(['address' => '192.168.1.50'])->assertJsonValidationErrors('address');
    $post(['image' => '100'])->assertJsonValidationErrors('image');
    expect($pve->called('POST'))->toBe([]);

    $post()->assertCreated()->assertJsonPath('operation.steps.0', ['key' => 'create', 'label' => 'Make the server', 'state' => 'current']);

    expect($pve->bodyOf('POST', 'nodes/pve2/qemu/9000/clone'))->toBe(['newid' => 200, 'name' => 'web-3', 'full' => 1, 'description' => 'Made from ServerOS.']);
    $operation = IntegrationOperation::query()->sole();
    $row = IntegrationServer::query()->sole();
    expect($operation->only(['kind', 'step']))->toBe(['kind' => 'create', 'step' => 'create'])
        ->and($operation->action_id)->toStartWith('UPID:pve2:')
        ->and($operation->input)->toMatchArray(['name' => 'web-3', 'location' => 'pve2', 'image' => '9000', 'size' => ['cores' => 4, 'memoryGb' => 8, 'diskGb' => 64], 'user' => 'deploy', 'public_keys' => [$key], 'address' => '192.168.1.50/24', 'gateway' => '192.168.1.1'])
        ->and(json_encode($operation->input))->not->toContain('--token')
        ->and($row->only(['server_id', 'name']))->toBe(['server_id' => '200', 'name' => 'web-3'])
        ->and(ActivityLine::sentence(Activity::query()->where('action', 'machine.server_create')->sole()))->toBe($workspace->owner->name.' made the server web-3 at Proxmox (4 vCPU · 8 GB · 64 GB in pve2)');

    $operations = app(ServerOperations::class);
    expect($operations->advance($operation))->toBe(5)
        ->and($operation->step)->toBe('create')
        ->and($operations->advance($operation))->toBe(5)
        ->and($operation->step)->toBe('boot');
    expect($pve->bodyOf('PUT', 'nodes/pve2/qemu/200/config'))->toBe([
        'cores' => 4, 'memory' => 8192, 'ciuser' => 'deploy', 'sshkeys' => rawurlencode($key), 'ipconfig0' => 'ip=192.168.1.50/24,gw=192.168.1.1',
    ])
        ->and($pve->bodyOf('PUT', 'nodes/pve2/qemu/200/resize'))->toBe(['disk' => 'scsi0', 'size' => '64G'])
        ->and($pve->called('POST'))->toContain('POST nodes/pve2/qemu/200/status/start')
        ->and($pve->guests[200]['config'])->toMatchArray(['cores' => '4', 'memory' => '8192', 'ciuser' => 'deploy', 'scsi0' => 'local-lvm:vm-200-disk-0,size=64G']);

    expect($operations->advance($operation))->toBe(5)
        ->and($operations->advance($operation))->toBe(20)
        ->and($operation->fresh()->step)->toBe('install')
        ->and($pve->guests[200]['status'])->toBe('running');

    expect($operations->advance($operation))->toBe(20);
    $exec = collect($pve->execs)->first();
    expect($exec['command'])->toBe(['bash', '-s'])
        ->and($exec['input'])->toContain('cloud-init status --wait')->toContain('get.docker.com')->toContain('--token=')
        ->and(ServerOperations::props($operation->fresh()))->toMatchArray(['note' => 'Installing ServerOS through the guest agent.', 'command' => null]);
    preg_match('/--token=\x27([a-f0-9]{64})\x27/', $exec['input'], $match);
    $enrolment = DaemonEnrolment::query()->sole();
    expect(hash('sha256', $match[1] ?? ''))->toBe($enrolment->token_hash);

    $operations->advance($operation);
    $operations->advance($operation);
    expect($operation->fresh()->result['runner'])->toBe('ran');

    $machine = Machine::factory()->for($workspace)->create(['name' => 'web-3']);
    app(CloudServers::class)->claim($enrolment, $machine);
    expect($row->fresh()->machine_id)->toBe($machine->id)
        ->and($operation->fresh()->state)->toBe('done');
});

test('a template without the guest agent shows the one-line command to run, and a silent agent falls back to it', function () {
    config()->set('daemon.install_ready', true);
    Queue::fake([AdvanceIntegrationOperation::class]);
    $pve = FakeProxmox::install()
        ->vm(9001, 'debian-12-plain', 'pve1', ['agent' => '0'], ['template' => 1, 'status' => 'stopped'])
        ->vm(9000, 'ubuntu-24-04', 'pve1', [], ['template' => 1, 'status' => 'stopped', 'agent' => ['up' => false, 'interfaces' => []]]);
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $integration = pveAccount($workspace);
    $operations = app(ServerOperations::class);
    $make = function (string $name, string $image) use ($workspace, $integration, $operations): IntegrationOperation {
        $this->actingAs($workspace->owner)->postJson($workspace->url("/integrations/{$integration->id}/servers"), [
            'name' => $name, 'image' => $image, 'cores' => 2, 'memory_gb' => 4, 'disk_gb' => 32,
        ])->assertCreated();
        $operation = IntegrationOperation::query()->latest('id')->first();
        for ($i = 0; $i < 6 && $operation->step !== 'install'; $i++) {
            $operations->advance($operation);
        }

        return $operation;
    };

    $plain = $make('plain-1', '9001');
    expect($pve->bodyOf('PUT', 'nodes/pve1/qemu/200/config'))->toBe(['ipconfig0' => 'ip=dhcp'])
        ->and($pve->called('PUT'))->not->toContain('PUT nodes/pve1/qemu/200/resize');
    $operations->advance($plain);
    $props = ServerOperations::props($plain->fresh());
    expect($props['note'])->toBe('It has no guest agent, so run this on it as root. It joins when it’s done.')
        ->and($props['command'])->toStartWith('(command -v docker >/dev/null 2>&1 || curl -fsSL https://get.docker.com | sh) && curl -fsSL ')
        ->and($props['command'])->toContain('--token=')
        ->and($pve->execs)->toBe([]);
    $this->actingAs($workspace->owner)->getJson($workspace->url("/integrations/{$integration->id}/operations/{$plain->id}"))
        ->assertOk()->assertJsonPath('operation.command', $props['command']);

    $silent = $make('quiet-1', '9000');
    $operations->advance($silent);
    expect(ServerOperations::props($silent->fresh())['note'])->toBe('Waiting for its guest agent to answer, to run the install.')
        ->and(ServerOperations::props($silent->fresh())['command'])->toBeNull();
    $this->travel(11)->minutes();
    $operations->advance($silent);
    expect(ServerOperations::props($silent->fresh()))->toMatchArray(['note' => 'Its guest agent didn’t answer, so run this on it as root. It joins when it’s done.'])
        ->and(ServerOperations::props($silent->fresh())['command'])->toContain('--token=');

    $silent->forceFill(['state' => 'failed'])->save();
    expect(ServerOperations::props($silent->fresh())['command'])->toBeNull();
});

test('an install the guest agent ran that failed fails the change with its output, without the token', function () {
    config()->set('daemon.install_ready', true);
    Queue::fake([AdvanceIntegrationOperation::class]);
    $pve = FakeProxmox::install()->vm(9000, 'ubuntu-24-04', 'pve1', [], ['template' => 1, 'status' => 'stopped']);
    $pve->execPolls = 0;
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $integration = pveAccount($workspace);
    $this->actingAs($workspace->owner)->postJson($workspace->url("/integrations/{$integration->id}/servers"), [
        'name' => 'web-4', 'image' => '9000', 'cores' => 2, 'memory_gb' => 4, 'disk_gb' => 32,
    ])->assertCreated();
    $operation = IntegrationOperation::query()->sole();
    $operations = app(ServerOperations::class);
    for ($i = 0; $i < 6 && $operation->step !== 'install'; $i++) {
        $operations->advance($operation);
    }
    $operations->advance($operation);
    $pid = array_key_first($pve->execs);
    $token = DaemonEnrolment::query()->sole();
    $pve->execs[$pid]['exitcode'] = 1;
    $pve->execs[$pid]['out'] = 'enrol failed for token '.str_repeat('ab', 32).': this machine is already enrolled';

    $operations->advance($operation);

    expect($operation->fresh()->state)->toBe('failed')
        ->and($operation->fresh()->error)->toBe('The install stopped with an error: enrol failed for token …: this machine is already enrolled')
        ->and($token->fresh()->expires_at->isPast())->toBeTrue();
});

test('cores and memory change live where Proxmox can, restart it where it can’t, and the disk only grows', function () {
    [$workspace, $machine, $integration, $pve] = pveLinkedMachine();
    $pve->guests[100]['config'] = [...$pve->guests[100]['config'], 'hotplug' => 'network,disk,usb,memory', 'numa' => 1];

    $types = $this->actingAs($workspace->owner)->getJson(pveUrl($machine, '/types'))->assertOk();
    expect($types->json('sizing'))->toBe('custom')
        ->and($types->json('limits'))->toBe(['maxCores' => 16, 'maxMemoryGb' => 64, 'minDiskGb' => 32, 'liveCores' => false, 'liveMemory' => true])
        ->and($types->json('server.cores'))->toBe(2);

    $resize = fn (array $size) => $this->actingAs($workspace->owner)->postJson(pveUrl($machine, '/resize'), $size);
    $resize(['cores' => 32, 'memory_gb' => 4, 'disk_gb' => 32])->assertJsonValidationErrors(['cores' => 'Its host has 16 cores, so it can’t have more.']);
    $resize(['cores' => 2, 'memory_gb' => 4, 'disk_gb' => 16])->assertJsonValidationErrors(['disk_gb' => 'Disks only grow. It has 32 GB now.']);
    $resize(['cores' => 2, 'memory_gb' => 4, 'disk_gb' => 32])->assertJsonValidationErrors(['form' => 'That’s the size it has now.']);
    expect($pve->called('PUT'))->toBe([]);

    $resize(['cores' => 2, 'memory_gb' => 8, 'disk_gb' => 40])->assertCreated();
    $live = IntegrationOperation::query()->sole();
    expect($live->state)->toBe('done')
        ->and($pve->bodyOf('PUT', 'nodes/pve1/qemu/100/resize'))->toBe(['disk' => 'scsi0', 'size' => '40G'])
        ->and($pve->bodyOf('PUT', 'nodes/pve1/qemu/100/config'))->toBe(['memory' => 8192])
        ->and($pve->called('POST'))->toBe([])
        ->and(ServerOperations::props($live)['steps'])->toBe([['key' => 'change', 'label' => 'Change the size', 'state' => 'done']])
        ->and(ServerOperations::props($live)['note'])->toBeNull()
        ->and($machine->cloudServer->details)->toMatchArray(['cores' => 2, 'memoryGb' => 8, 'diskGb' => 40])
        ->and(ActivityLine::sentence(Activity::query()->where('action', 'machine.server_resize')->sole()))->toBe($workspace->owner->name.' started resizing web-1 from 2 vCPU · 4 GB · 32 GB to 2 vCPU · 8 GB · 40 GB');

    $pve->calls = [];
    $resize(['cores' => 4, 'memory_gb' => 8, 'disk_gb' => 40])->assertCreated();
    $restarted = IntegrationOperation::query()->latest('id')->first();
    expect($restarted->state)->toBe('done')
        ->and($pve->bodyOf('PUT', 'nodes/pve1/qemu/100/config'))->toBe(['cores' => 4])
        ->and($pve->called('POST'))->toBe(['POST nodes/pve1/qemu/100/status/reboot'])
        ->and($pve->guests[100]['config']['cores'])->toBe('4')
        ->and($pve->guests[100]['pending'])->toBe([]);

    $pve->calls = [];
    $resize(['cores' => 6, 'memory_gb' => 8, 'disk_gb' => 40, 'restart' => false])->assertCreated();
    $later = IntegrationOperation::query()->latest('id')->first();
    expect($later->state)->toBe('done')
        ->and($pve->called('POST'))->toBe([])
        ->and($pve->guests[100]['pending'])->toBe(['cores' => '6'])
        ->and(ServerOperations::props($later)['note'])->toBe('Saved. The new size applies the next time it restarts.');
});

test('a restart Proxmox couldn’t do after a resize says the size is saved for the next one', function () {
    [$workspace, $machine, $integration, $pve] = pveLinkedMachine();
    Queue::fake([AdvanceIntegrationOperation::class]);

    $this->actingAs($workspace->owner)->postJson(pveUrl($machine, '/resize'), ['cores' => 4, 'memory_gb' => 4, 'disk_gb' => 32])->assertCreated();
    $operation = IntegrationOperation::query()->sole();
    $operations = app(ServerOperations::class);
    $operations->advance($operation);
    $pve->failTask((string) $operation->action_id, 'VM quit/powerdown failed - got timeout');
    $pve->finishTasks();
    $operations->advance($operation);

    expect($operation->fresh()->state)->toBe('failed')
        ->and($operation->fresh()->error)->toBe('The new size is saved, but it didn’t restart: VM quit/powerdown failed - got timeout. It applies the next time it restarts.');
});

test('restart reboots the VM, power cycle stops it and starts it once the stop is done, and both need permission', function () {
    [$workspace, $machine, $integration, $pve] = pveLinkedMachine();

    $this->actingAs($workspace->owner)->postJson(pveUrl($machine, '/reboot'))->assertOk()
        ->assertJson(['message' => 'Restarting web-1 through Proxmox.']);
    $this->actingAs($workspace->owner)->postJson(pveUrl($machine, '/power-cycle'))->assertOk()
        ->assertJsonPath('operation.kind', 'power_cycle');

    expect($pve->called('POST'))->toBe(['POST nodes/pve1/qemu/100/status/reboot', 'POST nodes/pve1/qemu/100/status/stop', 'POST nodes/pve1/qemu/100/status/start'])
        ->and($pve->guests[100]['status'])->toBe('running')
        ->and(IntegrationOperation::query()->sole()->only(['kind', 'state']))->toBe(['kind' => 'power_cycle', 'state' => 'done'])
        ->and(ActivityLine::sentence(Activity::query()->where('action', 'machine.server_power_cycle')->sole()))->toBe($workspace->owner->name.' power cycled web-1 through Proxmox');

    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);
    $this->actingAs($viewer)->postJson(pveUrl($machine, '/reboot'))->assertForbidden();

    $pve->failNext('POST', 'nodes/pve1/qemu/100/status/reboot', 500, ['message' => "VM is locked (backup)\n"]);
    $this->actingAs($workspace->owner)->postJson(pveUrl($machine, '/reboot'))
        ->assertJsonValidationErrors(['form' => 'Proxmox said: VM is locked (backup).']);
    $pve->failNext('POST', 'nodes/pve1/qemu/100/status/reboot', 403, ['message' => "Permission check failed (/vms/100, VM.PowerMgmt)\n"]);
    $this->actingAs($workspace->owner)->postJson(pveUrl($machine, '/reboot'))
        ->assertJsonValidationErrors(['form' => 'This token isn’t allowed to do that in Proxmox (Permission check failed (/vms/100, VM.PowerMgmt)). Give it a role with that privilege, like PVEVMAdmin on /.']);
    expect(collect($pve->called('POST'))->filter(fn (string $c) => str_ends_with($c, '/reboot'))->count())->toBe(3);
});

test('snapshots are the VM’s own, newest first, named from what was typed, and deleted by VM and name', function () {
    [$workspace, $machine, $integration, $pve] = pveLinkedMachine();
    $pve->snapshotOf(100, 'before-upgrade', 'before upgrade', 1763000000)->snapshotOf(100, 'nightly', '', 1763900000);
    $pve->taskPolls = 10;

    $this->actingAs($workspace->owner)->getJson(pveUrl($machine, '/snapshots'))->assertOk()
        ->assertJsonPath('snapshots', [
            ['id' => '100-nightly', 'name' => 'nightly', 'status' => 'available', 'sizeGb' => null, 'diskGb' => null, 'createdAt' => date(DATE_ATOM, 1763900000)],
            ['id' => '100-before-upgrade', 'name' => 'before upgrade', 'status' => 'available', 'sizeGb' => null, 'diskGb' => null, 'createdAt' => date(DATE_ATOM, 1763000000)],
        ]);

    $taken = $this->actingAs($workspace->owner)->postJson(pveUrl($machine, '/snapshots'), ['name' => 'Before upgrade!'])->assertCreated();
    expect($taken->json('snapshot'))->toMatchArray(['id' => '100-before-upgrade-2', 'name' => 'Before upgrade!', 'status' => 'creating'])
        ->and($pve->bodyOf('POST', 'nodes/pve1/qemu/100/snapshot'))->toBe(['snapname' => 'before-upgrade-2', 'description' => 'Before upgrade!']);
    $this->actingAs($workspace->owner)->getJson(pveUrl($machine, '/snapshots'))->assertJsonPath('snapshots.0.status', 'creating');
    $this->actingAs($workspace->owner)->deleteJson(pveUrl($machine, '/snapshots/100-before-upgrade-2'))
        ->assertJsonValidationErrors(['form' => 'It’s still being taken. Delete it once it’s ready.']);

    $this->actingAs($workspace->owner)->postJson(pveUrl($machine, '/snapshots'), ['name' => '2026 nightly'])->assertCreated()->assertJsonPath('snapshot.id', '100-s-2026-nightly');
    $this->actingAs($workspace->owner)->deleteJson(pveUrl($machine, '/snapshots/100-nightly'))->assertOk();
    $this->actingAs($workspace->owner)->deleteJson(pveUrl($machine, '/snapshots/101-nightly'))->assertNotFound();

    expect($pve->called('DELETE'))->toBe(['DELETE nodes/pve1/qemu/100/snapshot/nightly'])
        ->and(array_keys($pve->guests[100]['snapshots']))->toBe(['before-upgrade', 'before-upgrade-2', 's-2026-nightly'])
        ->and(Activity::query()->orderBy('id')->pluck('action')->all())->toBe(['machine.snapshot_create', 'machine.snapshot_create', 'machine.snapshot_delete']);
});

test('deleting the server stops the VM first, then destroys it with its disks and backup jobs', function () {
    [$workspace, $machine, $integration, $pve] = pveLinkedMachine();

    $this->actingAs($workspace->owner)->delete(pveUrl($machine, ''), ['confirm' => 'web-2'])->assertSessionHasErrors('confirm');
    $this->actingAs($workspace->owner)->delete(pveUrl($machine, ''), ['confirm' => 'web-1'])->assertRedirect();

    expect($pve->called('POST'))->toBe(['POST nodes/pve1/qemu/100/status/stop'])
        ->and($pve->called('DELETE'))->toBe(['DELETE nodes/pve1/qemu/100'])
        ->and(collect($pve->calls)->firstWhere('method', 'DELETE')['query'])->toBe(['purge' => '1', 'destroy-unreferenced-disks' => '1'])
        ->and($pve->guests[100]['lock'])->toBe('destroyed')
        ->and(Machine::query()->find($machine->id))->toBeNull()
        ->and(IntegrationServer::query()->count())->toBe(0)
        ->and(ActivityLine::sentence(Activity::query()->where('action', 'machine.server_delete')->sole()))->toBe($workspace->owner->name.' deleted the server of web-1 at Proxmox, and removed the machine');
});

test('Proxmox’s own errors on reads aren’t tried again, but a node it can’t reach is', function () {
    [$workspace, $machine, $integration, $pve] = pveLinkedMachine();

    $pve->failNext('GET', 'cluster/resources', 596, ['message' => "Connection timed out\n"]);
    expect(app(CloudServers::class)->sync($integration)->count())->toBe(1)
        ->and($pve->called('GET'))->toContain('GET cluster/resources');
    expect(collect($pve->called('GET'))->filter(fn (string $c) => $c === 'GET cluster/resources')->count())->toBe(2);

    $pve->calls = [];
    $pve->failNext('GET', 'cluster/resources', 500, ['message' => "cluster not ready - no quorum?\n"]);
    expect(fn () => app(CloudServers::class)->sync($integration))->toThrow(IntegrationFailed::class, 'Proxmox said: cluster not ready - no quorum?');
    expect($pve->called('GET'))->toBe(['GET cluster/resources'])
        ->and($integration->fresh()->status)->toBe(Integration::CONNECTED);
});
