<?php

use App\Jobs\AdvanceTailnetChange;
use App\Models\Activity;
use App\Models\DaemonJob;
use App\Models\Integration;
use App\Models\Machine;
use App\Models\TailscaleChange;
use App\Models\TailscaleDevice;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Activity\ActivityLine;
use App\Support\Integrations\Tailscale\TailnetChanges;
use App\Support\Integrations\Tailscale\TailscaleInstall;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeTailscale;

beforeEach(function () {
    Sleep::fake();
    Queue::fake([AdvanceTailnetChange::class]);
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    $this->withoutDefer();
    $this->tailscale = FakeTailscale::install();
    Http::fake(['127.0.0.1:8090/*' => Http::response(['queued' => true], 202)]);
});

/** @param  array<string, string>  $credentials */
function tsAccount(Workspace $workspace, array $credentials = ['token' => FakeTailscale::TOKEN, 'tailnet' => '-', 'client_id' => ''], array $settings = []): Integration
{
    return $workspace->integrations()->create([
        'provider' => 'tailscale',
        'label' => 'tail1234.ts.net',
        'credentials' => $credentials,
        'fingerprint' => Integration::fingerprintOf('tailscale', $credentials),
        'meta' => ['tailnet' => $credentials['tailnet'] ?? '-', 'dns' => 'tail1234.ts.net', 'devices' => 0, 'oauth' => ($credentials['client_id'] ?? '') !== ''],
        'settings' => $settings === [] ? null : $settings,
        'status' => Integration::CONNECTED,
        'last_checked_at' => now(),
        'created_by' => $workspace->owner_id,
    ]);
}

/** @param  array<string, mixed>  $attributes */
function tsMachine(Workspace $workspace, string $name = 'web-1', array $attributes = []): Machine
{
    $machine = Machine::factory()->for($workspace)->create(['name' => $name, 'ip_address' => '203.0.113.7']);
    $machine->forceFill([
        'uid' => (string) Str::uuid(),
        'last_seen_at' => now(),
        'daemon_version' => '0.1.1-pre.50',
        'facts' => ['hostname' => $name, 'interfaces' => [['name' => 'eth0', 'addresses' => ['203.0.113.7/32']]]],
        ...$attributes,
    ])->save();

    return $machine;
}

/** @return list<array<string, mixed>> */
function tsJobsSent(): array
{
    return collect(Http::recorded())->map(fn ($pair) => $pair[0])
        ->filter(fn (HttpRequest $r) => str_ends_with($r->url(), '/commands'))
        ->map(fn (HttpRequest $r) => json_decode((string) json_encode($r['command']['job']), true))->values()->all();
}

function tsFinish(Machine $machine, string $jobId, string $state = 'succeeded', array $extra = []): void
{
    test()->withHeaders(['X-Gateway-Secret' => 'test-gateway-secret'])
        ->postJson('/api/gateway/machines/'.$machine->uid.'/ingest', ['envelopes' => [[
            'v' => 1, 'kind' => 'job_update',
            'payload' => ['job_id' => $jobId, 'state' => $state, 'log' => [], 'result' => [], ...$extra],
        ]]])
        ->assertOk();
}

function tsAdvance(TailscaleChange $change): ?int
{
    return app(TailnetChanges::class)->advance($change->fresh());
}

function tsMember(Workspace $workspace, string $role): User
{
    $user = User::factory()->create();
    $workspace->members()->attach($user, ['role' => $role]);

    return $user;
}

/** @return array<string, mixed> */
function tsAccountProps(Workspace $workspace, Integration $integration): array
{
    $props = null;
    test()->actingAs($workspace->owner)->get($workspace->url('/integrations/'.$integration->id))
        ->assertOk()
        ->assertInertia(function (Assert $page) use (&$props) {
            $page->loadDeferredProps(function (Assert $reload) use (&$props) {
                $props = $reload->toArray()['props']['tailscale'];
            });
        });

    return $props;
}

test('connecting with an access token lists the tailnet’s devices, and the token never leaves the server', function () {
    $workspace = Workspace::factory()->create();
    $this->tailscale->device('laptop', '100.64.0.9');

    $this->actingAs($workspace->owner)
        ->post($workspace->url('/integrations/connect/tailscale'), ['credentials' => ['token' => FakeTailscale::TOKEN, 'tailnet' => '-', 'client_id' => '']])
        ->assertRedirect();

    $integration = Integration::query()->sole();
    expect($integration->label)->toBe('tail1234.ts.net')
        ->and($integration->meta)->toMatchArray(['tailnet' => '-', 'dns' => 'tail1234.ts.net', 'devices' => 1, 'oauth' => false])
        ->and($this->tailscale->called())->toBe(['GET tailnet/-/devices'])
        ->and($this->tailscale->calls[0]['query'])->toBe(['fields' => 'all'])
        ->and(json_encode(Activity::query()->get()->toArray()))->not->toContain(FakeTailscale::TOKEN);

    $this->get($workspace->url('/integrations/'.$integration->id))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('integration.summary', '1 device')
        ->where('provider.capabilities.0.key', 'tailnet')
        ->where('integration.facts.0', ['label' => 'Tailnet', 'value' => 'The token’s own']));
    expect(json_encode($this->get($workspace->url('/integrations/'.$integration->id))->viewData('page')))->not->toContain(FakeTailscale::TOKEN);
});

test('a refused token, an unknown tailnet and an OAuth secret without its client are turned down', function () {
    $workspace = Workspace::factory()->create();

    $this->actingAs($workspace->owner)->post($workspace->url('/integrations/connect/tailscale'), ['credentials' => ['token' => 'not-the-right-token-at-all', 'tailnet' => '-']])
        ->assertSessionHasErrors(['credentials.token' => 'Tailscale turned the token down. Check it’s copied in full and hasn’t been deleted.']);
    $this->post($workspace->url('/integrations/connect/tailscale'), ['credentials' => ['token' => FakeTailscale::TOKEN, 'tailnet' => 'other.example']])
        ->assertSessionHasErrors('credentials.token');
    $this->post($workspace->url('/integrations/connect/tailscale'), ['credentials' => ['token' => 'tskey-client-fake-for-tests-only', 'tailnet' => '-']])
        ->assertSessionHasErrors(['credentials.client_id' => 'That’s an OAuth client secret. Add the client’s ID too.']);

    expect(Integration::query()->count())->toBe(0);
});

test('an OAuth client is swapped for an access token once, then used for every call', function () {
    $workspace = Workspace::factory()->create();

    $this->actingAs($workspace->owner)
        ->post($workspace->url('/integrations/connect/tailscale'), ['credentials' => ['token' => FakeTailscale::CLIENT_SECRET, 'tailnet' => 'acme.example', 'client_id' => FakeTailscale::CLIENT_ID]])
        ->assertRedirect();
    $integration = Integration::query()->sole();
    tsAccountProps($workspace, $integration);

    expect($integration->label)->toBe('acme.example')
        ->and($integration->meta['oauth'])->toBeTrue()
        ->and($this->tailscale->called())->toBe(['POST oauth/token', 'GET tailnet/acme.example/devices', 'GET tailnet/acme.example/devices'])
        ->and($this->tailscale->calls[0]['body'])->toMatchArray(['grant_type' => 'client_credentials', 'client_id' => FakeTailscale::CLIENT_ID]);

    $this->post($workspace->url('/integrations/connect/tailscale'), ['credentials' => ['token' => 'a-wrong-client-secret-value', 'tailnet' => '-', 'client_id' => FakeTailscale::CLIENT_ID]])
        ->assertSessionHasErrors(['credentials.token' => 'Tailscale turned the OAuth client down. Check the client ID and secret are copied in full and the client hasn’t been deleted.']);
});

test('the account page lists devices and matches machines by tailnet address, public endpoint and hostname, only when it’s the only match', function () {
    $workspace = Workspace::factory()->create();
    $integration = tsAccount($workspace);
    $db = tsMachine($workspace, 'db-1', ['ip_address' => '198.51.100.4', 'facts' => ['hostname' => 'db-1', 'interfaces' => [['name' => 'tailscale0', 'addresses' => ['100.64.0.5/32']]]]]);
    $api = tsMachine($workspace, 'api-1', ['ip_address' => '198.51.100.9', 'facts' => ['hostname' => 'srv-9']]);
    $web = tsMachine($workspace, 'web-1', ['ip_address' => '198.51.100.10']);
    tsMachine($workspace, 'twin-a', ['ip_address' => '192.0.2.50', 'facts' => ['hostname' => 'twin']]);
    tsMachine($workspace, 'twin-b', ['ip_address' => '192.0.2.51', 'facts' => ['hostname' => 'twin']]);
    $this->tailscale->device('db-1-old-name', '100.64.0.5');
    $this->tailscale->device('srv-9-renamed', '100.64.0.6', ['clientConnectivity' => ['endpoints' => ['198.51.100.9:41641', '[2001:db8::9]:41641']]]);
    $this->tailscale->device('web-1', '100.64.0.7');
    $this->tailscale->device('twin', '100.64.0.8');
    $this->tailscale->device('friends-laptop', '100.64.0.10', ['isExternal' => true]);

    $props = tsAccountProps($workspace, $integration);

    $byHost = collect($props['devices'])->keyBy('hostname');
    expect($byHost['db-1-old-name']['machine']['id'])->toBe($db->id)
        ->and($byHost['db-1-old-name']['linkedBy'])->toBe('address')
        ->and($byHost['srv-9-renamed']['machine']['id'])->toBe($api->id)
        ->and($byHost['web-1']['machine']['id'])->toBe($web->id)
        ->and($byHost['web-1']['linkedBy'])->toBe('hostname')
        ->and($byHost['web-1'])->toMatchArray(['name' => 'web-1.tail1234.ts.net', 'address' => '100.64.0.7', 'online' => true])
        ->and($byHost['twin']['machine'])->toBeNull()
        ->and($byHost['friends-laptop']['machine'])->toBeNull()
        ->and(collect($props['machines'])->pluck('name')->all())->toBe(['twin-a', 'twin-b'])
        ->and($props['tags'])->toBe(['tag:serveros'])
        ->and($integration->fresh()->meta['devices'])->toBe(5);

    unset($this->tailscale->devices[array_key_first($this->tailscale->devices)]);
    tsAccountProps($workspace, $integration);
    expect(TailscaleDevice::query()->where('machine_id', $db->id)->exists())->toBeFalse();
});

test('add to tailnet makes a one-off key, deploys Tailscale with it, and links the device that turns up', function () {
    $workspace = Workspace::factory()->create();
    $integration = tsAccount($workspace);
    $machine = tsMachine($workspace, 'Web 1');

    $response = $this->actingAs($workspace->owner)->postJson($workspace->url("/machines/{$machine->id}/tailnet"), ['integration' => $integration->id])->assertAccepted();
    expect($response->json('change'))->toMatchArray(['kind' => 'join', 'state' => 'running', 'step' => 'install', 'hostname' => 'web-1']);

    $key = $this->tailscale->lastKey();
    expect($key)->toMatchArray(['reusable' => false, 'preauthorized' => true, 'ephemeral' => false, 'tags' => ['tag:serveros'], 'expirySeconds' => 900, 'description' => 'ServerOS Web 1']);

    $sent = tsJobsSent();
    expect($sent)->toHaveCount(1)
        ->and($sent[0])->toMatchArray(['type' => 'deploy', 'service' => 'tailscale', 'compose_file' => 'compose.yaml', 'snapshot_before' => false])
        ->and($sent[0]['env'])->toBe(['TS_HOSTNAME' => 'web-1', 'TS_EXTRA_ARGS' => '--advertise-tags=tag:serveros', 'TS_AUTHKEY' => $key['key']])
        ->and($sent[0]['files']['compose.yaml'])->toContain('network_mode: host')->toContain('/dev/net/tun')->toContain('NET_ADMIN')->toContain('TS_AUTH_ONCE: "true"');

    $job = DaemonJob::query()->where('kind', 'deploy')->sole();
    $change = TailscaleChange::query()->sole();
    expect(json_encode($job->command))->not->toContain($key['key'])
        ->and(json_encode($change->toArray()))->not->toContain($key['key'])
        ->and(json_encode(Activity::query()->get()->toArray()))->not->toContain($key['key'])
        ->and(Activity::query()->where('action', 'machine.tailnet_join')->sole()->meta)->toBe(['tailnet' => 'tail1234.ts.net'])
        ->and(json_encode($response->json()))->not->toContain($key['key']);
    Queue::assertPushed(AdvanceTailnetChange::class);

    expect(tsAdvance($change))->toBe(5);

    tsFinish($machine, $job->id, 'succeeded');
    expect(tsAdvance($change))->toBe(5)
        ->and($change->fresh()->step)->toBe('connect');

    $this->tailscale->joinWith($key['key'], 'web-1', '100.101.102.103');
    expect(tsAdvance($change))->toBeNull();

    $device = TailscaleDevice::query()->where('machine_id', $machine->id)->sole();
    expect($change->fresh())->state->toBe('done')
        ->and($change->fresh()->result)->toBe(['name' => 'web-1.tail1234.ts.net', 'address' => '100.101.102.103'])
        ->and($device->linked_by)->toBe('joined')
        ->and($device->tags)->toBe(['tag:serveros'])
        ->and($this->tailscale->keys[$key['id']]['deleted'])->toBeTrue();

    $tailnet = $this->get($workspace->url("/machines/{$machine->id}/settings"))->assertOk()->viewData('page')['props']['tailnet'];
    expect($tailnet['device'])->toMatchArray(['name' => 'web-1.tail1234.ts.net', 'address' => '100.101.102.103', 'online' => true])
        ->and($tailnet['device']['integration'])->toBe(['id' => $integration->id, 'label' => 'tail1234.ts.net']);
    $this->get($workspace->url("/machines/{$machine->id}"))->assertOk()->assertInertia(fn (Assert $page) => $page->where('tailnet.device.address', '100.101.102.103'));

    $this->postJson($workspace->url("/machines/{$machine->id}/tailnet"))->assertStatus(409)->assertJson(['error' => 'Web 1 is already on this tailnet, as web-1.tail1234.ts.net.']);
});

test('a device that never turns up fails the change and revokes its key', function () {
    $workspace = Workspace::factory()->create();
    tsAccount($workspace);
    $machine = tsMachine($workspace);
    $this->actingAs($workspace->owner)->postJson($workspace->url("/machines/{$machine->id}/tailnet"))->assertAccepted();
    $change = TailscaleChange::query()->sole();
    tsFinish($machine, (string) $change->job_id);
    tsAdvance($change);

    $this->travel(6)->minutes();
    expect(tsAdvance($change))->toBeNull()
        ->and($change->fresh()->state)->toBe('failed')
        ->and($change->fresh()->error)->toContain('it didn’t turn up on the tailnet')
        ->and(TailnetChanges::props($change->fresh())['canRunCommands'])->toBeTrue()
        ->and($this->tailscale->lastKey()['deleted'])->toBeTrue();
});

test('when the daemon refuses in read-only mode the change says so, offers the commands and revokes the key', function () {
    $workspace = Workspace::factory()->create();
    tsAccount($workspace);
    $machine = tsMachine($workspace);
    $this->actingAs($workspace->owner)->postJson($workspace->url("/machines/{$machine->id}/tailnet"))->assertAccepted();
    $change = TailscaleChange::query()->sole();

    tsFinish($machine, (string) $change->job_id, 'refused', ['error' => ['phase' => 'policy', 'message' => 'machine is in read-only mode']]);
    tsAdvance($change);

    expect($change->fresh()->state)->toBe('failed')
        ->and($change->fresh()->error)->toBe('The daemon on web-1 is in read-only mode, so it can’t change the machine.')
        ->and(TailnetChanges::props($change->fresh())['canRunCommands'])->toBeTrue()
        ->and($this->tailscale->lastKey()['deleted'])->toBeTrue();
});

test('when the panel can’t send work, nothing is made and the commands are offered instead, with a key shown once', function () {
    config()->set('daemon.commands_enabled', false);
    $workspace = Workspace::factory()->create();
    tsAccount($workspace, settings: ['tags' => ['tag:serveros', 'tag:web']]);
    $machine = tsMachine($workspace);

    $this->actingAs($workspace->owner)->postJson($workspace->url("/machines/{$machine->id}/tailnet"))
        ->assertStatus(409)->assertJson(['manual' => true, 'error' => 'Sending work to machines isn’t turned on for this panel.']);
    expect($this->tailscale->keys)->toBe([])
        ->and(TailscaleChange::query()->count())->toBe(0)
        ->and(Activity::query()->count())->toBe(0);

    $response = $this->postJson($workspace->url("/machines/{$machine->id}/tailnet/commands"))->assertCreated();
    $key = $this->tailscale->lastKey();
    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->json('commands'))->toBe([
            'curl -fsSL https://tailscale.com/install.sh | sh',
            'sudo tailscale up --auth-key='.$key['key'].' --hostname=web-1 --advertise-tags=tag:serveros,tag:web',
        ])
        ->and($response->json('change'))->toMatchArray(['manual' => true, 'step' => 'connect'])
        ->and(array_column($response->json('change.steps'), 'state', 'key'))->toBe(['connect' => 'current'])
        ->and(json_encode(TailscaleChange::query()->sole()->toArray()))->not->toContain($key['key'])
        ->and(json_encode(Activity::query()->get()->toArray()))->not->toContain($key['key'])
        ->and(Activity::query()->sole()->action)->toBe('machine.tailnet_key')
        ->and(tsJobsSent())->toBe([]);

    $change = TailscaleChange::query()->sole();
    expect(tsAdvance($change))->toBe(10);
    $this->tailscale->joinWith($key['key'], 'web-1');
    tsAdvance($change);
    expect($change->fresh()->state)->toBe('done')
        ->and(TailscaleDevice::query()->where('machine_id', $machine->id)->value('linked_by'))->toBe('joined');
});

test('Tailscale already on the machine isn’t installed twice, and its commands skip the install', function () {
    $workspace = Workspace::factory()->create();
    tsAccount($workspace);
    $machine = tsMachine($workspace, attributes: ['inventory' => ['services' => [['key' => 'systemd:tailscaled.service', 'name' => 'tailscaled', 'kind' => 'other']]]]);

    $this->actingAs($workspace->owner)->postJson($workspace->url("/machines/{$machine->id}/tailnet"))
        ->assertStatus(409)->assertJson(['manual' => true]);
    expect($this->tailscale->keys)->toBe([]);

    $commands = $this->postJson($workspace->url("/machines/{$machine->id}/tailnet/commands"))->assertCreated()->json('commands');
    expect($commands)->toHaveCount(1)->and($commands[0])->toStartWith('sudo tailscale up --auth-key=');
});

test('a tag the tailnet doesn’t allow comes back in words, and nothing is sent to the machine', function () {
    $workspace = Workspace::factory()->create();
    $integration = tsAccount($workspace);
    $machine = tsMachine($workspace);

    $this->actingAs($workspace->owner)->putJson($workspace->url("/integrations/{$integration->id}/tailnet"), ['tags' => ['tag:prod']])->assertOk()->assertJson(['tags' => ['tag:prod']]);
    expect(ActivityLine::describe(Activity::query()->where('action', 'integration.tailscale_tags')->sole(), false))->toMatchArray(['verb' => 'changed the tags for new machines on', 'after' => 'to tag:prod']);
    $this->putJson($workspace->url("/integrations/{$integration->id}/tailnet"), ['tags' => ['prod']])->assertStatus(422)->assertJsonValidationErrors('tags.0');

    $this->postJson($workspace->url("/machines/{$machine->id}/tailnet"))->assertStatus(409)
        ->assertJson(['error' => 'Tailscale won’t make a key with those tags. Add each tag to tagOwners in your tailnet’s access controls, or change the tags ServerOS uses.']);
    expect(tsJobsSent())->toBe([]);
});

test('remove from tailnet logs out, removes the container and deletes the device', function () {
    $workspace = Workspace::factory()->create();
    $integration = tsAccount($workspace);
    $machine = tsMachine($workspace, attributes: ['inventory' => ['services' => [['key' => 'docker:aaaabbbbcccc', 'name' => 'serveros-tailscale', 'kind' => 'container', 'details' => ['compose_project' => 'serveros-tailscale']]]]]);
    $nodeId = $this->tailscale->device('web-1', '100.64.0.7');
    tsAccountProps($workspace, $integration);

    $this->actingAs($workspace->owner)->deleteJson($workspace->url("/machines/{$machine->id}/tailnet"))->assertAccepted()->assertJson(['commands' => null]);
    expect(tsJobsSent())->toBe([['type' => 'service_exec', 'service' => 'docker:aaaabbbbcccc', 'command' => 'tailscale logout', 'timeout_secs' => 60]]);
    $change = TailscaleChange::query()->sole();

    tsFinish($machine, (string) $change->job_id, 'succeeded', ['result' => ['exit_code' => 0]]);
    expect(tsAdvance($change))->toBe(5)
        ->and(tsJobsSent()[1])->toBe(['type' => 'service_remove', 'service' => 'docker:aaaabbbbcccc', 'delete_data' => true]);

    tsFinish($machine, (string) $change->fresh()->job_id);
    expect(tsAdvance($change))->toBeNull()
        ->and($change->fresh()->state)->toBe('done')
        ->and($this->tailscale->devices)->not->toHaveKey($nodeId)
        ->and($this->tailscale->writes())->toBe(['DELETE device/'.$nodeId])
        ->and(TailscaleDevice::query()->count())->toBe(0)
        ->and(ActivityLine::describe(Activity::query()->where('action', 'machine.tailnet_leave')->sole(), false))->toMatchArray(['verb' => 'took', 'after' => 'off the tail1234.ts.net tailnet']);
});

test('remove from tailnet on an offline machine deletes the device now and gives the commands to clean up', function () {
    $workspace = Workspace::factory()->create();
    $integration = tsAccount($workspace);
    $machine = tsMachine($workspace, attributes: ['last_seen_at' => now()->subHour()]);
    $nodeId = $this->tailscale->device('web-1', '100.64.0.7');
    tsAccountProps($workspace, $integration);

    $this->actingAs($workspace->owner)->deleteJson($workspace->url("/machines/{$machine->id}/tailnet"))
        ->assertAccepted()->assertJson(['commands' => ['sudo tailscale logout']]);

    expect(tsJobsSent())->toBe([])
        ->and($this->tailscale->devices)->not->toHaveKey($nodeId)
        ->and(TailscaleChange::query()->sole()->state)->toBe('done')
        ->and(Activity::query()->where('action', 'machine.tailnet_leave')->sole()->meta)->toBe(['tailnet' => 'tail1234.ts.net', 'manual' => true]);
});

test('the account page adds several machines at once and says which couldn’t', function () {
    $workspace = Workspace::factory()->create();
    $integration = tsAccount($workspace);
    $web = tsMachine($workspace, 'web-1');
    $off = tsMachine($workspace, 'db-1', ['last_seen_at' => now()->subHour()]);
    $elsewhere = tsMachine(Workspace::factory()->create(), 'theirs');

    $this->actingAs($workspace->owner)->postJson($workspace->url("/integrations/{$integration->id}/tailnet/machines"), ['machines' => [$web->id, $elsewhere->id]])
        ->assertStatus(422)->assertJsonValidationErrors('machines.1');

    $results = $this->postJson($workspace->url("/integrations/{$integration->id}/tailnet/machines"), ['machines' => [$web->id, $off->id]])
        ->assertAccepted()->assertJson(['started' => 1])->json('results');
    expect(collect($results)->keyBy('name')['web-1']['ok'])->toBeTrue()
        ->and(collect($results)->keyBy('name')['db-1'])->toMatchArray(['ok' => false, 'manual' => true])
        ->and(collect($results)->keyBy('name')['db-1']['error'])->toContain('db-1 is offline')
        ->and(tsJobsSent())->toHaveCount(1);

    $props = tsAccountProps($workspace, $integration);
    expect(collect($props['machines'])->pluck('name')->all())->toBe(['db-1'])
        ->and($props['machines'][0]['blocked'])->toContain('db-1 is offline')
        ->and($props['changes'][0])->toMatchArray(['kind' => 'join', 'state' => 'running', 'machine' => ['id' => $web->id, 'name' => 'web-1']]);
});

test('only people who manage machines can do any of it, and only in their own workspace', function () {
    $workspace = Workspace::factory()->create();
    $integration = tsAccount($workspace);
    $machine = tsMachine($workspace);
    $viewer = tsMember($workspace, 'viewer');

    $this->actingAs($viewer)->postJson($workspace->url("/machines/{$machine->id}/tailnet"))->assertForbidden();
    $this->postJson($workspace->url("/machines/{$machine->id}/tailnet/commands"))->assertForbidden();
    $this->deleteJson($workspace->url("/machines/{$machine->id}/tailnet"))->assertForbidden();
    $this->putJson($workspace->url("/integrations/{$integration->id}/tailnet"), ['tags' => []])->assertForbidden();
    expect($this->get($workspace->url("/machines/{$machine->id}"))->viewData('page')['props']['tailnet'] ?? null)->toBeNull();

    $other = Workspace::factory()->create();
    $this->actingAs($other->owner)->postJson($other->url("/machines/{$machine->id}/tailnet"))->assertNotFound();
    $this->postJson($other->url("/integrations/{$integration->id}/tailnet/machines"), ['machines' => [$machine->id]])->assertNotFound();
    expect($this->tailscale->calls)->toBe([]);
});

test('disconnecting says which machines stay on the tailnet and changes nothing at Tailscale', function () {
    $workspace = Workspace::factory()->create();
    $integration = tsAccount($workspace);
    tsMachine($workspace);
    $this->tailscale->device('web-1', '100.64.0.7');
    tsAccountProps($workspace, $integration);

    $this->actingAs($workspace->owner)->get($workspace->url('/integrations/'.$integration->id))->assertInertia(fn (Assert $page) => $page
        ->where('dependents', ['1 machine stays on the tailnet, but ServerOS stops showing its address and can’t take it off.'])
        ->where('usage.0', 'web-1 is on this tailnet. Its page shows its tailnet name and address.'));

    $this->delete($workspace->url('/integrations/'.$integration->id), ['confirm' => 'tail1234.ts.net'])->assertRedirect();
    expect(Integration::query()->count())->toBe(0)
        ->and(TailscaleDevice::query()->count())->toBe(0)
        ->and($this->tailscale->writes())->toBe([]);
});

test('the install has the hostname as a DNS label and the commands to clean up match how it was installed', function () {
    $workspace = Workspace::factory()->create();
    expect(TailscaleInstall::hostname(tsMachine($workspace, 'Ünïcode Box_02!')))->toBe('unicode-box-02')
        ->and(TailscaleInstall::hostname(tsMachine($workspace, '!!!')))->toStartWith('machine-')
        ->and(TailscaleInstall::leaveCommands(tsMachine($workspace, 'x', ['inventory' => ['services' => [['key' => 'docker:abc', 'name' => 'serveros-tailscale']]]])))
        ->toBe(['sudo docker exec serveros-tailscale tailscale logout', 'sudo docker rm -f serveros-tailscale', 'sudo docker volume rm serveros-tailscale_state']);
});
