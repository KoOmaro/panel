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
use App\Support\Integrations\OVHcloud\OVHcloudClient;
use App\Support\Integrations\Servers\CloudServers;
use App\Support\Integrations\Servers\ServerOperations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeOVHcloud;

function ovhAccount(Workspace $workspace): Integration
{
    $credentials = FakeOVHcloud::credentials(project: FakeOVHcloud::PROJECT);

    return $workspace->integrations()->create([
        'provider' => 'ovhcloud',
        'label' => 'Acme production',
        'credentials' => $credentials,
        'fingerprint' => Integration::fingerprintOf('ovhcloud', $credentials),
        'meta' => ['project' => FakeOVHcloud::PROJECT, 'projectName' => 'Acme production', 'endpoint' => 'ovh-eu', 'subsidiary' => 'FR', 'currency' => 'EUR', 'servers' => 0],
        'status' => Integration::CONNECTED,
        'last_checked_at' => now(),
    ]);
}

/** @param  array<string, string>  $credentials */
function ovhConnect(Workspace $workspace, array $credentials = []): TestResponse
{
    return test()->actingAs($workspace->owner)
        ->from($workspace->url('/integrations'))
        ->post($workspace->url('/integrations/connect/ovhcloud'), ['credentials' => [...FakeOVHcloud::credentials(), ...$credentials]]);
}

/** @return array{0: Workspace, 1: Machine, 2: Integration, 3: FakeOVHcloud, 4: string} */
function ovhLinkedMachine(string $flavor = 'b2-7'): array
{
    $id = FakeOVHcloud::id(1);
    $ovh = FakeOVHcloud::install()->instance($id, 'web-1', '198.51.100.7', '2001:db8:aa::7', ['flavor' => $flavor]);
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $machine = Machine::factory()->for($workspace)->create(['name' => 'web-1', 'ip_address' => '198.51.100.7']);
    $integration = ovhAccount($workspace);
    app(CloudServers::class)->sync($integration);
    $ovh->calls = [];

    return [$workspace, $machine, $integration, $ovh, $id];
}

function ovhUrl(Machine $machine, string $path): string
{
    return $machine->workspace->url("/machines/{$machine->id}/server{$path}");
}

function ovhProject(string $rest = ''): string
{
    return 'cloud/project/'.FakeOVHcloud::PROJECT.$rest;
}

beforeEach(function () {
    Sleep::fake();
});

test('connecting OVHcloud signs every call on OVHcloud’s clock, picks the only project and links instances and dedicated servers by address', function () {
    $ovh = FakeOVHcloud::install()
        ->instance(FakeOVHcloud::id(1), 'web-1', '198.51.100.7')
        ->instance(FakeOVHcloud::id(2), 'v6-only', null, '2001:db8:77::5')
        ->instance(FakeOVHcloud::id(3), 'new-one', null, null, ['status' => 'BUILD', 'region' => 'SBG5', 'flavor' => 'd2-2'])
        ->dedicatedServer('ns3012345.ip-51-68-1.eu', '51.68.1.2');
    $workspace = Workspace::factory()->create();
    $web = Machine::factory()->for($workspace)->create(['name' => 'web-1', 'ip_address' => '198.51.100.7']);
    $db = Machine::factory()->for($workspace)->create(['name' => 'db-1', 'ip_address' => '10.0.0.4']);
    $db->forceFill(['facts' => ['interfaces' => [['name' => 'ens3', 'addresses' => ['10.0.0.4/24', '2001:db8:77::5/64']]]]])->save();
    $neighbour = Machine::factory()->for($workspace)->create(['name' => 'db-2', 'ip_address' => '10.0.0.5']);
    $neighbour->forceFill(['facts' => ['interfaces' => [['name' => 'ens3', 'addresses' => ['2001:db8:77::6/64']]]]])->save();
    $metal = Machine::factory()->for($workspace)->create(['name' => 'metal-1', 'ip_address' => '51.68.1.2']);

    ovhConnect($workspace)->assertSessionHasNoErrors()->assertRedirect();

    $integration = Integration::query()->sole();
    $rows = IntegrationServer::query()->get()->keyBy('server_id');
    expect($integration->label)->toBe('Acme production')
        ->and($integration->credentials)->toBe(FakeOVHcloud::credentials(project: FakeOVHcloud::PROJECT))
        ->and($integration->meta)->toBe(['account' => 'sr12345-ovh', 'project' => FakeOVHcloud::PROJECT, 'projectName' => 'Acme production', 'endpoint' => 'ovh-eu', 'subsidiary' => 'FR', 'currency' => 'EUR', 'servers' => 4, 'dedicated' => 1])
        ->and(DB::table('integrations')->value('credentials'))->not->toContain(FakeOVHcloud::APPLICATION_SECRET)->not->toContain(FakeOVHcloud::CONSUMER_KEY)
        ->and($rows[FakeOVHcloud::id(1)]->machine_id)->toBe($web->id)
        ->and($rows[FakeOVHcloud::id(2)]->machine_id)->toBe($db->id)
        ->and($rows[FakeOVHcloud::id(3)]->machine_id)->toBeNull()
        ->and($rows[FakeOVHcloud::id(3)]->details)->toMatchArray(['ipv4' => null, 'status' => 'starting', 'type' => 'd2-2', 'city' => 'Strasbourg'])
        ->and($rows[FakeOVHcloud::id(1)]->details)->toMatchArray(['type' => 'b2-7', 'cores' => 2, 'memoryGb' => 7, 'diskGb' => 50, 'location' => 'GRA11', 'city' => 'Gravelines', 'country' => 'FR', 'status' => 'running', 'ipv6' => null])
        ->and($rows[FakeOVHcloud::id(2)]->details['ipv6'])->toBe('2001:db8:77::5/128')
        ->and($rows['dedicated:ns3012345.ip-51-68-1.eu']->machine_id)->toBe($metal->id)
        ->and($rows['dedicated:ns3012345.ip-51-68-1.eu']->details)->toMatchArray([
            'status' => 'running', 'type' => 'rise-1', 'typeLabel' => 'RISE-1 - Intel Xeon-E 2386G', 'cores' => 6, 'memoryGb' => 32, 'diskGb' => 1024,
            'location' => 'rbx8', 'city' => 'Roubaix', 'country' => 'FR', 'ipv4' => '51.68.1.2', 'ipv6' => '2001:41d0:303:1a00::/56',
        ])
        ->and($neighbour->fresh()->cloudServer)->toBeNull();

    $signed = collect($ovh->calls)->reject(fn (array $call) => in_array($call['path'], ['auth/time', 'order/catalog/public/cloud'], true));
    expect($signed)->not->toBeEmpty()
        ->and($signed->every(fn (array $call) => $call['host'] === 'eu.api.ovh.com' && abs((int) $call['headers']['X-Ovh-Timestamp'] - $ovh->now()) <= 2))->toBeTrue()
        ->and($ovh->clockReads)->toBe(1);
    $me = $signed->firstWhere('path', 'me');
    expect($me['headers']['X-Ovh-Signature'])->toBe('$1$'.sha1(FakeOVHcloud::APPLICATION_SECRET.'+'.FakeOVHcloud::CONSUMER_KEY.'+GET+https://eu.api.ovh.com/1.0/me++'.$me['headers']['X-Ovh-Timestamp']));

    expect(ActivityLine::sentence(Activity::query()->where('action', 'integration.connect')->sole()))->toBe($workspace->owner->name.' connected Acme production (OVHcloud)')
        ->and(Activity::query()->get()->toJson())->not->toContain(FakeOVHcloud::APPLICATION_SECRET)->not->toContain(FakeOVHcloud::CONSUMER_KEY);

    $this->actingAs($workspace->owner)->get($workspace->url('/integrations/'.$integration->id))
        ->assertInertia(fn (Assert $page) => $page
            ->where('integration.facts', [
                ['label' => 'Project', 'value' => 'Acme production'],
                ['label' => 'OVHcloud account', 'value' => 'sr12345-ovh'],
                ['label' => 'Region', 'value' => 'Europe'],
                ['label' => 'Servers', 'value' => '4'],
                ['label' => 'Dedicated servers, listed only', 'value' => '1'],
            ])
            ->where('usage', [
                'db-1, metal-1, web-1 are linked to their servers here.',
                'On those machines you can resize, restart, power cycle, take snapshots and delete the server from ServerOS.',
                '1 dedicated server is listed here. ServerOS only reads it.',
                'metal-1 is on a dedicated server, so it has no server actions here.',
            ])
            ->where('provider.fields.0.key', 'endpoint')
            ->where('provider.fields.0.value', 'ovh-eu')
            ->where('provider.fields.0.options.1.link', 'https://ca.api.ovh.com/createToken/?GET=/me&GET=/cloud/project&GET=/cloud/project/*&GET=/dedicated/server&GET=/dedicated/server/*&POST=/cloud/project/*&DELETE=/cloud/project/*')
            ->where('provider.fields.2.value', null)
            ->where('provider.fields.2.secret', true)
            ->where('provider.fields.4.required', false)
            ->where('provider.fields.1.required', true)
            ->etc());
    $props = $this->actingAs($workspace->owner)->get($workspace->url('/integrations/'.$integration->id))->getContent();
    expect($props)->not->toContain(FakeOVHcloud::APPLICATION_SECRET)->not->toContain(FakeOVHcloud::CONSUMER_KEY)->not->toContain(FakeOVHcloud::APPLICATION_KEY);

    $this->actingAs($workspace->owner)->get($workspace->url("/machines/{$web->id}"))
        ->assertInertia(fn (Assert $page) => $page
            ->where('cloudServer.integration.name', 'OVHcloud')
            ->where('cloudServer.can', ['servers', 'create_servers', 'resize', 'power', 'snapshots', 'delete_servers'])
            ->where('cloudServer.rules.powerOff', false)
            ->where('cloudServer.rules.disk', 'always')
            ->where('cloudServer.rules.down', true)
            ->where('cloudServer.rules.gracefulRestart', true)
            ->where('cloudServer.rules.snapshotsPerServer', true));
    $this->actingAs($workspace->owner)->get($workspace->url("/machines/{$metal->id}"))
        ->assertInertia(fn (Assert $page) => $page->where('cloudServer.can', ['servers']));
});

test('a dedicated server is only read: restart, resize, snapshots and delete aren’t offered', function () {
    $ovh = FakeOVHcloud::install()->dedicatedServer('ns3012345.ip-51-68-1.eu', '51.68.1.2', 'gra2', 'poweroff');
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $metal = Machine::factory()->for($workspace)->create(['name' => 'metal-1', 'ip_address' => '51.68.1.2']);
    $integration = ovhAccount($workspace);
    $rows = app(CloudServers::class)->sync($integration);
    $ovh->calls = [];

    expect($rows->sole()->details)->toMatchArray(['status' => 'off', 'city' => 'Gravelines']);
    $this->actingAs($workspace->owner)->postJson(ovhUrl($metal, '/reboot'))->assertNotFound();
    $this->actingAs($workspace->owner)->postJson(ovhUrl($metal, '/power-cycle'))->assertNotFound();
    $this->actingAs($workspace->owner)->getJson(ovhUrl($metal, '/types'))->assertNotFound();
    $this->actingAs($workspace->owner)->getJson(ovhUrl($metal, '/snapshots'))->assertNotFound();
    $this->actingAs($workspace->owner)->delete(ovhUrl($metal, ''), ['confirm' => 'metal-1'])->assertNotFound();
    expect($ovh->called())->toBe([]);
});

test('keys that can’t read dedicated servers still connect, with only the project’s instances', function () {
    $ovh = FakeOVHcloud::install()->instance(FakeOVHcloud::id(1), 'web-1', '198.51.100.7')->dedicatedServer('ns1.ip-51-68-1.eu', '51.68.1.2');
    $ovh->denied = ['GET dedicated/server', 'GET dedicated/server/*'];
    $workspace = Workspace::factory()->create();

    ovhConnect($workspace)->assertSessionHasNoErrors();

    expect(Integration::query()->sole()->meta['dedicated'])->toBe(0)
        ->and(IntegrationServer::query()->pluck('server_id')->all())->toBe([FakeOVHcloud::id(1)]);
});

test('keys OVHcloud turns down are never saved, and each refusal lands on the field to fix', function () {
    $ovh = FakeOVHcloud::install();
    $workspace = Workspace::factory()->create();

    ovhConnect($workspace, ['consumer_key' => 'wrongconsumerkey0123456789abcdef'])
        ->assertSessionHasErrors(['credentials.consumer_key' => 'OVHcloud turned the consumer key down. It may have expired or been revoked. Make new keys and connect again.']);
    ovhConnect($workspace, ['application_secret' => 'wrongsecret0123456789abcdefabcd'])
        ->assertSessionHasErrors(['credentials.application_secret' => 'OVHcloud turned the application secret down. Check it’s copied in full.']);
    ovhConnect($workspace, ['application_key' => 'wrongappkey0123'])
        ->assertSessionHasErrors(['credentials.application_key' => 'OVHcloud doesn’t know this application key. Check it’s copied in full and that the region is where you made it.']);
    ovhConnect($workspace, ['endpoint' => 'ovh-asia'])->assertSessionHasErrors('credentials.endpoint');

    $ovh->denied = ['GET cloud/project'];
    ovhConnect($workspace)
        ->assertSessionHasErrors(['credentials.application_key' => 'These keys aren’t allowed to do that. Make new keys from the link in the connect form, which asks for what ServerOS needs.']);
    $ovh->denied = [];

    $ovh->projects['b2c3d4e5f60718293a4b5c6d7e8f9001'] = ['description' => 'Staging', 'status' => 'ok'];
    $ovh->projects['c3d4e5f60718293a4b5c6d7e8f900112'] = ['description' => 'Old', 'status' => 'deleted'];
    ovhConnect($workspace)
        ->assertSessionHasErrors(['credentials.project' => 'This account has 2 Public Cloud projects: Acme production ('.FakeOVHcloud::PROJECT.'), Staging (b2c3d4e5f60718293a4b5c6d7e8f9001). Paste the ID of the one to connect.']);
    ovhConnect($workspace, ['project' => 'ffffffffffffffffffffffffffffffff'])
        ->assertSessionHasErrors(['credentials.project' => 'These keys can’t see a Public Cloud project with that ID. Check it, or leave it empty when the account has one project.']);

    expect(Integration::query()->count())->toBe(0);

    ovhConnect($workspace, ['project' => 'b2c3d4e5f60718293a4b5c6d7e8f9001'])->assertSessionHasNoErrors();
    ovhConnect($workspace, ['project' => FakeOVHcloud::PROJECT])->assertSessionHasNoErrors();
    expect(Integration::query()->orderBy('id')->pluck('label')->all())->toBe(['Staging', 'Acme production']);

    unset($ovh->projects['b2c3d4e5f60718293a4b5c6d7e8f9001']);
    ovhConnect($workspace)->assertSessionHasErrors(['credentials.application_key' => 'This account is connected already, as Acme production.']);
    expect(Integration::query()->count())->toBe(2);
});

test('the endpoint picks the API: OVHcloud US is signed against its own address', function () {
    $ovh = FakeOVHcloud::install();
    $workspace = Workspace::factory()->create();

    ovhConnect($workspace, ['endpoint' => 'ovh-us'])->assertSessionHasNoErrors();

    expect(collect($ovh->calls)->pluck('host')->unique()->values()->all())->toBe(['api.us.ovhcloud.com'])
        ->and(Integration::query()->sole()->meta['endpoint'])->toBe('ovh-us');
});

test('a clock OVHcloud says is off is read again on the next call', function () {
    [$workspace, $machine, $integration, $ovh, $id] = ovhLinkedMachine();
    $ovh->failNext('POST', ovhProject("/instance/{$id}/reboot"), 400, ['errorCode' => 'QUERY_TIME_OUT', 'message' => 'Query out of time', 'class' => 'Client::BadRequest']);
    $reads = $ovh->clockReads;

    $this->actingAs($workspace->owner)->postJson(ovhUrl($machine, '/reboot'))
        ->assertJsonValidationErrors(['form' => 'OVHcloud says the request’s clock was off. Try again.']);
    $this->actingAs($workspace->owner)->postJson(ovhUrl($machine, '/reboot'))->assertOk();

    expect($ovh->clockReads)->toBe($reads + 1);
});

test('making an instance looks up the flavor and image in its region, sends cloud-init that installs ServerOS, and links it when it joins', function () {
    config()->set('daemon.install_ready', true);
    Queue::fake([AdvanceIntegrationOperation::class]);
    $ovh = FakeOVHcloud::install();
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $integration = ovhAccount($workspace);

    $plans = $this->actingAs($workspace->owner)->getJson($workspace->url("/integrations/{$integration->id}/server-options"))->assertOk();
    expect(collect($plans->json('types'))->pluck('name')->all())->toBe(['d2-2', 'd2-4', 'b2-7', 'c2-7', 'b2-15', 'b2-7-flex', 'b2-15-flex'])
        ->and(collect($plans->json('locations'))->pluck('name')->all())->toBe(['GRA11', 'SBG5', 'BHS5'])
        ->and($plans->json('locations.2'))->toBe(['name' => 'BHS5', 'city' => 'Beauharnois', 'country' => 'CA', 'zone' => 'NA'])
        ->and($plans->json('types.0.cpu'))->toBe('shared')
        ->and($plans->json('types.2.cpu'))->toBe('dedicated')
        ->and($plans->json('types.2'))->toMatchArray(['cores' => 2, 'memoryGb' => 7, 'diskGb' => 50, 'locations' => ['BHS5', 'GRA11', 'SBG5']])
        ->and($plans->json('types.2.prices.GRA11'))->toEqual(['monthly' => 49.71, 'hourly' => 0.0681])
        ->and($plans->json('types.4.locations'))->toBe(['GRA11', 'SBG5'])
        ->and(collect($plans->json('images'))->pluck('id')->all())->toBe(['Ubuntu 25.04', 'Ubuntu 24.04', 'Ubuntu 22.04', 'Debian 12', 'Debian 11'])
        ->and(collect($plans->json('images'))->firstWhere('recommended', true)['id'])->toBe('Ubuntu 24.04')
        ->and(collect($plans->json('keys'))->pluck('name')->all())->toBe(['sam@laptop', 'ci-deploy'])
        ->and($plans->json('currency'))->toBe('EUR');

    $this->actingAs($workspace->owner)->postJson($workspace->url("/integrations/{$integration->id}/servers"), [
        'name' => 'web-3', 'location' => 'SBG5', 'type' => 'b2-7', 'image' => 'Ubuntu 24.04', 'keys' => ['c2FtQGxhcHRvcA==', 'Y2ktZGVwbG95'],
    ])->assertCreated();

    $body = $ovh->bodyOf('POST', ovhProject('/instance'));
    expect($body)->toMatchArray([
        'name' => 'web-3', 'region' => 'SBG5', 'flavorId' => FakeOVHcloud::flavorId('SBG5', 'b2-7'), 'imageId' => FakeOVHcloud::imageId('SBG5', 'Ubuntu 24.04'),
        'monthlyBilling' => false, 'sshKeyId' => 'c2FtQGxhcHRvcA==',
    ]);
    $userData = (string) $body['userData'];
    expect($userData)->toStartWith("#cloud-config\nssh_authorized_keys:\n  - \"ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFakeCiKeyForTestsOnly ci@deploy\"\nhostname: web-3\n")
        ->toContain('get.docker.com')
        ->not->toContain('FakeLaptopKey');
    preg_match('/--token=\x27([a-f0-9]{64})\x27/', $userData, $match);
    $enrolment = DaemonEnrolment::query()->sole();
    expect(hash('sha256', $match[1] ?? ''))->toBe($enrolment->token_hash);

    $operation = IntegrationOperation::query()->sole();
    $row = IntegrationServer::query()->sole();
    expect($operation->only(['kind', 'step', 'action_id']))->toBe(['kind' => 'create', 'step' => 'boot', 'action_id' => null])
        ->and($row->details)->toMatchArray(['ipv4' => null, 'type' => 'b2-7', 'status' => 'starting', 'city' => 'Strasbourg'])
        ->and(ActivityLine::sentence(Activity::query()->where('action', 'machine.server_create')->sole()))->toBe($workspace->owner->name.' made the server web-3 at OVHcloud (b2-7 in Strasbourg)');

    $operations = app(ServerOperations::class);
    expect($operations->advance($operation))->toBe(5)
        ->and($operation->step)->toBe('boot')
        ->and($operations->advance($operation))->toBe(20)
        ->and($operation->fresh()->step)->toBe('install')
        ->and($row->fresh()->details['ipv4'])->toStartWith('203.0.113.')
        ->and($row->fresh()->details['status'])->toBe('running');

    $machine = Machine::factory()->for($workspace)->create(['name' => 'web-3']);
    app(CloudServers::class)->claim($enrolment, $machine);
    expect($row->fresh()->machine_id)->toBe($machine->id)
        ->and($operation->fresh()->state)->toBe('done');
});

test('types are still offered without prices when the catalogue can’t be read, and a refused instance gives its join token back', function () {
    config()->set('daemon.install_ready', true);
    Queue::fake([AdvanceIntegrationOperation::class]);
    $ovh = FakeOVHcloud::install();
    $ovh->catalogueDown = true;
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $integration = ovhAccount($workspace);

    $plans = $this->actingAs($workspace->owner)->getJson($workspace->url("/integrations/{$integration->id}/server-options"))->assertOk();
    expect($plans->json('types'))->toHaveCount(7)
        ->and($plans->json('types.0.prices'))->toBe([]);

    $ovh->failNext('POST', ovhProject('/instance'), 403, ['errorCode' => 'FORBIDDEN', 'message' => 'Quota exceeded for instances in this region', 'class' => 'Client::Forbidden']);
    $this->actingAs($workspace->owner)->postJson($workspace->url("/integrations/{$integration->id}/servers"), [
        'name' => 'web-3', 'location' => 'GRA11', 'type' => 'b2-7', 'image' => 'Debian 12',
    ])->assertJsonValidationErrors(['form' => 'Quota exceeded for instances in this region.']);

    expect($ovh->called('POST'))->toBe(['POST '.ovhProject('/instance')])
        ->and(DaemonEnrolment::query()->sole()->expires_at->isPast())->toBeTrue()
        ->and(IntegrationOperation::query()->count())->toBe(0);
});

test('a classic instance is offered models with a disk at least as big, and the resize is followed until it lands', function () {
    [$workspace, $machine, $integration, $ovh, $id] = ovhLinkedMachine();

    $types = $this->actingAs($workspace->owner)->getJson(ovhUrl($machine, '/types'))->assertOk();
    expect(collect($types->json('types'))->pluck('name')->all())->toBe(['d2-4', 'c2-7', 'b2-15'])
        ->and($types->json('current.name'))->toBe('b2-7')
        ->and($types->json('current.monthly'))->toEqual(49.71)
        ->and($types->json('needsPowerOff'))->toBeFalse();

    $this->actingAs($workspace->owner)->postJson(ovhUrl($machine, '/resize'), ['type' => 'd2-2'])->assertJsonValidationErrors('type');
    $this->actingAs($workspace->owner)->postJson(ovhUrl($machine, '/resize'), ['type' => 'b2-15-flex'])->assertJsonValidationErrors('type');

    $ovh->calls = [];
    $this->actingAs($workspace->owner)->postJson(ovhUrl($machine, '/resize'), ['type' => 'b2-15'])->assertCreated();

    expect($ovh->called('POST'))->toBe(['POST '.ovhProject("/instance/{$id}/resize")])
        ->and($ovh->bodyOf('POST', ovhProject("/instance/{$id}/resize")))->toBe(['flavorId' => FakeOVHcloud::flavorId('GRA11', 'b2-15')])
        ->and($ovh->instances[$id]['flavorId'])->toBe(FakeOVHcloud::flavorId('GRA11', 'b2-15'));
    $operation = IntegrationOperation::query()->sole();
    expect($operation->state)->toBe('done')
        ->and(ServerOperations::props($operation)['steps'])->toBe([['key' => 'change', 'label' => 'Change the type', 'state' => 'done']])
        ->and($machine->cloudServer->details)->toMatchArray(['type' => 'b2-15', 'diskGb' => 100, 'cores' => 4, 'status' => 'running']);
    expect(ActivityLine::sentence(Activity::query()->where('action', 'machine.server_resize')->sole()))->toBe($workspace->owner->name.' started resizing web-1 from b2-7 to b2-15');
});

test('a flex instance can move to any other flex model, smaller ones too, but not to a classic one', function () {
    [$workspace, $machine] = ovhLinkedMachine('b2-15-flex');

    $types = $this->actingAs($workspace->owner)->getJson(ovhUrl($machine, '/types'))->assertOk();
    expect(collect($types->json('types'))->pluck('name')->all())->toBe(['b2-7-flex']);
    $this->actingAs($workspace->owner)->postJson(ovhUrl($machine, '/resize'), ['type' => 'b2-15'])->assertJsonValidationErrors('type');
});

test('when OVHcloud turns a resize down, it fails with the reason and the instance keeps its model', function () {
    [$workspace, $machine, $integration, $ovh, $id] = ovhLinkedMachine();
    $ovh->failNext('POST', ovhProject("/instance/{$id}/resize"), 400, ['errorCode' => 'BAD_REQUEST', 'message' => 'Instance is locked by another operation', 'class' => 'Client::BadRequest']);

    $this->actingAs($workspace->owner)->postJson(ovhUrl($machine, '/resize'), ['type' => 'c2-7'])->assertCreated();

    $operation = IntegrationOperation::query()->sole();
    expect($operation->state)->toBe('failed')
        ->and($operation->error)->toBe('Instance is locked by another operation.')
        ->and($ovh->called('POST'))->toBe(['POST '.ovhProject("/instance/{$id}/resize")])
        ->and($ovh->instances[$id]['flavorId'])->toBe(FakeOVHcloud::flavorId('GRA11', 'b2-7'));
});

test('restart is a soft reboot, power cycle a hard one, and both need permission', function () {
    [$workspace, $machine, $integration, $ovh, $id] = ovhLinkedMachine();

    $this->actingAs($workspace->owner)->postJson(ovhUrl($machine, '/reboot'))->assertOk()
        ->assertJson(['message' => 'Restarting web-1 through OVHcloud.']);
    expect($ovh->bodyOf('POST', ovhProject("/instance/{$id}/reboot")))->toBe(['type' => 'soft']);
    $this->actingAs($workspace->owner)->postJson(ovhUrl($machine, '/power-cycle'))->assertOk();

    expect($ovh->called('POST'))->toBe(['POST '.ovhProject("/instance/{$id}/reboot"), 'POST '.ovhProject("/instance/{$id}/reboot")])
        ->and($ovh->bodyOf('POST', ovhProject("/instance/{$id}/reboot")))->toBe(['type' => 'hard'])
        ->and(Activity::query()->orderBy('id')->pluck('action')->all())->toBe(['machine.server_reboot', 'machine.server_power_cycle'])
        ->and(ActivityLine::sentence(Activity::query()->where('action', 'machine.server_power_cycle')->sole()))->toBe($workspace->owner->name.' power cycled web-1 through OVHcloud');

    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);
    $this->actingAs($viewer)->postJson(ovhUrl($machine, '/power-cycle'))->assertForbidden();
    $other = Machine::factory()->for($workspace)->create();
    $this->actingAs($workspace->owner)->postJson(ovhUrl($other, '/reboot'))->assertNotFound();
});

test('snapshots are the instance’s own, newest first, and can be taken and deleted once ready', function () {
    [$workspace, $machine, $integration, $ovh, $id] = ovhLinkedMachine();
    $older = FakeOVHcloud::flavorId('snapshot', 'older');
    $newer = FakeOVHcloud::flavorId('snapshot', 'newer');
    $elsewhere = FakeOVHcloud::flavorId('snapshot', 'elsewhere');
    $ovh->snapshots[$older] = FakeOVHcloud::snapshotOf($older, $id, 'before upgrade', created: '2026-11-02T09:00:00Z');
    $ovh->snapshots[$newer] = FakeOVHcloud::snapshotOf($newer, $id, 'web-1 nightly', created: '2026-11-20T09:00:00Z');
    $ovh->snapshots[$elsewhere] = FakeOVHcloud::snapshotOf($elsewhere, FakeOVHcloud::id(9), 'db-1 nightly');

    $this->actingAs($workspace->owner)->getJson(ovhUrl($machine, '/snapshots'))->assertOk()
        ->assertJsonCount(2, 'snapshots')
        ->assertJsonPath('snapshots.0', ['id' => $newer, 'name' => 'web-1 nightly', 'status' => 'available', 'sizeGb' => 2.37, 'diskGb' => 50, 'createdAt' => '2026-11-20T09:00:00Z'])
        ->assertJsonPath('snapshots.1.id', $older);
    expect($ovh->queryOf('GET', ovhProject('/snapshot')))->toBe(['instanceId' => $id]);

    $taken = $this->actingAs($workspace->owner)->postJson(ovhUrl($machine, '/snapshots'), ['name' => 'before resize'])->assertCreated();
    expect($taken->json('snapshot.status'))->toBe('creating')
        ->and($taken->json('snapshot.id'))->not->toBe('')
        ->and($ovh->bodyOf('POST', ovhProject("/instance/{$id}/snapshot")))->toBe(['snapshotName' => 'before resize']);

    $this->actingAs($workspace->owner)->deleteJson(ovhUrl($machine, '/snapshots/'.$taken->json('snapshot.id')))
        ->assertJsonValidationErrors(['form' => 'It’s still being taken. Delete it once it’s ready.']);
    $this->actingAs($workspace->owner)->deleteJson(ovhUrl($machine, '/snapshots/'.$elsewhere))->assertNotFound();
    $this->actingAs($workspace->owner)->deleteJson(ovhUrl($machine, '/snapshots/'.$older))->assertOk();

    expect($ovh->called('DELETE'))->toBe(['DELETE '.ovhProject("/snapshot/{$older}")])
        ->and(array_keys($ovh->snapshots))->toHaveCount(3)
        ->and(Activity::query()->orderBy('id')->pluck('action')->all())->toBe(['machine.snapshot_create', 'machine.snapshot_delete']);
});

test('deleting the server deletes the instance and can keep the machine', function () {
    [$workspace, $machine, $integration, $ovh, $id] = ovhLinkedMachine();

    $this->actingAs($workspace->owner)->delete(ovhUrl($machine, ''), ['confirm' => 'web-2'])->assertSessionHasErrors('confirm');
    $this->actingAs($workspace->owner)->delete(ovhUrl($machine, ''), ['confirm' => 'web-1', 'keep' => true])->assertRedirect();

    expect($ovh->called('DELETE'))->toBe(['DELETE '.ovhProject("/instance/{$id}")])
        ->and($ovh->instances)->toBe([])
        ->and($machine->fresh())->not->toBeNull()
        ->and(IntegrationServer::query()->count())->toBe(0)
        ->and(ActivityLine::sentence(Activity::query()->where('action', 'machine.server_delete')->sole()))->toBe($workspace->owner->name.' deleted the server of web-1 at OVHcloud, and kept the machine');
});

test('busy OVHcloud reads are tried again with a fresh signature, but a POST is never sent twice after a server error', function () {
    [$workspace, $machine, $integration, $ovh, $id] = ovhLinkedMachine();

    $ovh->failNext('GET', ovhProject('/instance'), 503);
    expect(app(CloudServers::class)->sync($integration)->count())->toBe(1)
        ->and(array_values(array_filter($ovh->called('GET'), fn (string $c) => $c === 'GET '.ovhProject('/instance'))))->toHaveCount(2);
    Sleep::assertSleptTimes(1);

    $ovh->calls = [];
    $ovh->failNext('POST', ovhProject("/instance/{$id}/reboot"), 500);
    $this->actingAs($workspace->owner)->postJson(ovhUrl($machine, '/reboot'))
        ->assertJsonValidationErrors(['form' => 'OVHcloud isn’t answering properly right now. Try again in a few minutes.']);
    expect($ovh->called('POST'))->toBe(['POST '.ovhProject("/instance/{$id}/reboot")]);
});

test('the signature covers the exact URL with its query and the exact body', function () {
    expect(OVHcloudClient::signature('secret', 'consumer', 'post', 'https://eu.api.ovh.com/1.0/cloud/project/p/instance', '{"name":"web-1"}', 1700000000))
        ->toBe('$1$'.sha1('secret+consumer+POST+https://eu.api.ovh.com/1.0/cloud/project/p/instance+{"name":"web-1"}+1700000000'));

    [$workspace, $machine, $integration, $ovh, $id] = ovhLinkedMachine();
    $this->actingAs($workspace->owner)->getJson(ovhUrl($machine, '/types'))->assertOk();
    $call = collect($ovh->calls)->first(fn (array $call) => $call['path'] === ovhProject('/flavor') && $call['query'] !== []);
    expect($call['query'])->toBe(['region' => 'GRA11'])
        ->and($call['headers']['X-Ovh-Signature'])->toBe(OVHcloudClient::signature(FakeOVHcloud::APPLICATION_SECRET, FakeOVHcloud::CONSUMER_KEY, 'GET', 'https://eu.api.ovh.com/1.0/'.ovhProject('/flavor').'?region=GRA11', '', (int) $call['headers']['X-Ovh-Timestamp']));
    Http::assertSent(fn ($request) => $request->hasHeader('X-Ovh-Application', FakeOVHcloud::APPLICATION_KEY) && ! str_contains($request->url(), FakeOVHcloud::APPLICATION_SECRET));
});
