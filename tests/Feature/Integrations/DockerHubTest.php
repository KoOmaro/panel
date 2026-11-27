<?php

use App\Enums\Plan;
use App\Models\Activity;
use App\Models\ContainerImage;
use App\Models\ContainerRegistry;
use App\Models\DaemonJob;
use App\Models\DockerHubApp;
use App\Models\Integration;
use App\Models\Machine;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Activity\ActivityLine;
use App\Support\Deploys\ImageDeploy;
use App\Support\Images\ImageUpdates;
use App\Support\Monitors\HostResolver;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeDockerHub;

const HUB_OLD = 'sha256:'.'a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1';
const HUB_NEW = 'sha256:'.'b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2';
const HUB_EDGE = 'sha256:'.'c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3';

beforeEach(function () {
    Sleep::fake();
    Cache::flush();
    config()->set('app.url', 'https://panel.example.com');
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    $this->withoutDefer();
    app()->instance(HostResolver::class, new class extends HostResolver
    {
        protected function addresses(string $host): array
        {
            return ['93.184.215.14'];
        }
    });
});

function hubFake(): FakeDockerHub
{
    $hub = FakeDockerHub::install()
        ->user('liam', FakeDockerHub::TOKEN, ['acme'])
        ->repository('liam/notes', ['latest' => HUB_OLD], private: false)
        ->repository('acme/web', ['latest' => HUB_OLD, 'edge' => HUB_EDGE], port: 3000)
        ->repository('other/secret', ['latest' => HUB_OLD]);
    Http::fake(['127.0.0.1:8090/*' => Http::response(['queued' => true], 202)]);

    return $hub;
}

/** @param  array<string, string>  $credentials */
function hubConnect(Workspace $workspace, array $credentials = [], ?User $as = null): TestResponse
{
    return test()->actingAs($as ?? $workspace->owner)
        ->from($workspace->url('/integrations'))
        ->post($workspace->url('/integrations/connect/docker-hub'), ['credentials' => [
            'username' => 'liam', 'token' => FakeDockerHub::TOKEN, 'organizations' => 'acme', ...$credentials,
        ]]);
}

function hubAccount(Workspace $workspace): Integration
{
    hubConnect($workspace)->assertSessionHasNoErrors();

    return $workspace->integrations()->where('provider', 'docker-hub')->sole();
}

function hubMachine(Workspace $workspace): Machine
{
    $machine = Machine::factory()->for($workspace)->create(['name' => 'web-1']);
    $machine->forceFill([
        'uid' => (string) Str::uuid(),
        'last_seen_at' => now(),
        'daemon_version' => 'v0.1.1-pre.49',
        'facts' => ['docker_version' => '27.3.1'],
        'inventory' => ['services' => []],
    ])->save();

    return $machine;
}

function hubMember(Workspace $workspace, string $role): User
{
    $user = User::factory()->create();
    $workspace->members()->attach($user, ['role' => $role]);

    return $user;
}

/** @return list<array<string, mixed>> */
function hubSentJobs(): array
{
    return collect(Http::recorded())
        ->map(fn ($pair) => $pair[0])
        ->filter(fn (HttpRequest $r) => str_ends_with($r->url(), '/commands'))
        ->map(fn (HttpRequest $r) => $r['command']['job'] ?? [])
        ->values()
        ->all();
}

/** The app as the machine reports it once the deploy worked. */
function hubDeployed(Machine $machine, DaemonJob $job): void
{
    $job->forceFill(['state' => 'succeeded', 'finished_at' => now()])->save();
    $commit = substr((string) $job->command['job']['commit'], 0, 12);
    $machine->forceFill(['inventory' => ['services' => [[
        'key' => 'docker:'.$commit, 'name' => "serveros-{$job->service}-{$commit}", 'kind' => 'container', 'status' => 'running',
        'details' => ['image' => "serveros/{$job->service}:{$commit}", 'image_id' => 'sha256:'.str_repeat('9', 64), 'image_digests' => ''],
    ]]]])->save();
}

function hubDeploy(Workspace $workspace, Machine $machine, array $data = []): TestResponse
{
    return test()->actingAs($workspace->owner)->postJson($workspace->url("/machines/{$machine->id}/deploy/image"), [
        'repository' => 'acme/web', 'tag' => 'latest', 'service' => 'web', 'port' => 3000,
        'health_path' => '/up', 'domain' => 'web.example.com', 'extra_env' => "APP_ENV=production\n# a note\nGREETING=hi there",
        ...$data,
    ]);
}

/** @return array<string, mixed> the page's props, deferred ones loaded */
function hubProps(TestResponse $page): array
{
    $props = [];
    $page->assertOk()->assertInertia(function (Assert $p) use (&$props) {
        $props = $p->toArray()['props'];
        $p->loadDeferredProps(function (Assert $reload) use (&$props) {
            $props = [...$props, ...$reload->toArray()['props']];
        });
    });

    return $props;
}

function hubPush(string $url, string $repository = 'acme/web', string $tag = 'latest'): TestResponse
{
    return test()->postJson(parse_url($url, PHP_URL_PATH), [
        'callback_url' => 'https://registry.hub.docker.com/u/acme/web/hook/abc/',
        'push_data' => ['pushed_at' => 1764200000, 'pusher' => 'liam', 'tag' => $tag],
        'repository' => ['repo_name' => $repository, 'name' => explode('/', $repository)[1], 'namespace' => explode('/', $repository)[0], 'is_private' => true],
    ]);
}

test('connecting Docker Hub checks the token, counts the repositories and keeps the token encrypted', function () {
    $hub = hubFake();
    $workspace = Workspace::factory()->create();

    hubConnect($workspace, ['username' => 'Liam', 'organizations' => 'acme, ACME'])->assertSessionHasNoErrors()->assertRedirect();

    $account = $workspace->integrations()->sole();
    expect($account->provider)->toBe('docker-hub')
        ->and($account->label)->toBe('liam')
        ->and($account->credentials)->toBe(['username' => 'liam', 'token' => FakeDockerHub::TOKEN, 'organizations' => 'acme'])
        ->and($account->meta)->toMatchArray(['username' => 'liam', 'organizations' => 'acme', 'repositories' => 2])
        ->and(DB::table('integrations')->value('credentials'))->not->toContain(FakeDockerHub::TOKEN)
        ->and($hub->calls[0]['body'])->toBe(['identifier' => 'liam', 'secret' => FakeDockerHub::TOKEN])
        ->and($hub->called('hub.docker.com'))->toContain('GET namespaces/liam/repositories', 'GET namespaces/acme/repositories')
        ->and(json_encode(Activity::query()->pluck('meta')))->not->toContain(FakeDockerHub::TOKEN);

    $props = hubProps($this->actingAs($workspace->owner)->get($workspace->url('/integrations/'.$account->id))->assertDontSee(FakeDockerHub::TOKEN));
    expect($props['integration']['summary'])->toBe('@liam · 2 repositories')
        ->and($props['provider']['capabilities'][0]['key'])->toBe('images')
        ->and($props['dockerHub']['login']['state'])->toBe('linked')
        ->and($props['dockerHub']['total'])->toBe(2)
        ->and($props['dockerHub']['namespaces'])->toBe([['name' => 'liam', 'count' => 1], ['name' => 'acme', 'count' => 1]])
        ->and($props['dockerHub']['repositories'][0]['repository'])->toBe('acme/web')
        ->and(json_encode($props))->not->toContain(FakeDockerHub::TOKEN);

    $this->actingAs($workspace->owner)->post($workspace->url('/integrations/connect/docker-hub'), ['credentials' => ['username' => 'liam', 'token' => FakeDockerHub::OTHER_TOKEN]])
        ->assertSessionHasErrors(['credentials.token' => 'Docker Hub is connected already. Disconnect it first to connect another account.']);
});

test('a token Docker Hub turns down, or an organization it can’t see, is never saved', function () {
    hubFake();
    $workspace = Workspace::factory()->create();

    hubConnect($workspace, ['token' => 'fake-docker-hub-wrong-token'])->assertSessionHasErrors('credentials.token');
    hubConnect($workspace, ['organizations' => 'acme, other'])->assertSessionHasErrors(['credentials.organizations' => 'Docker Hub can’t find the organization other, or this account can’t see it.']);
    hubConnect($workspace, ['username' => 'not a name'])->assertSessionHasErrors('credentials.username');

    expect($workspace->integrations()->count())->toBe(0)
        ->and($workspace->containerRegistries()->count())->toBe(0);
});

test('connecting makes the docker.io registry login, or takes over the one for the same user, and disconnecting removes it', function () {
    hubFake();
    $workspace = Workspace::factory()->create();
    $workspace->containerRegistries()->create(['host' => 'docker.io', 'username' => 'liam', 'password' => 'an-old-password']);

    $account = hubAccount($workspace);

    $login = $workspace->containerRegistries()->sole();
    expect($login->integration_id)->toBe($account->id)
        ->and($login->password)->toBe(FakeDockerHub::TOKEN)
        ->and(Activity::query()->where('action', 'registry.update')->value('meta'))->toBe(['username' => 'liam', 'via' => 'Docker Hub']);

    $this->actingAs($workspace->owner)->post($workspace->url("/integrations/{$account->id}/check"))->assertSessionHasNoErrors();
    expect($workspace->containerRegistries()->count())->toBe(1)
        ->and(Activity::query()->where('action', 'registry.update')->count())->toBe(1);

    $this->actingAs($workspace->owner)->get($workspace->url('/integrations'))
        ->assertInertia(fn (Assert $page) => $page->where('connections', fn ($rows) => collect($rows)->where('key', 'docker-hub')->isEmpty()
            && collect($rows)->where('key', 'integration-'.$account->id)->count() === 1));

    $this->actingAs($workspace->owner)->delete($workspace->url("/integrations/{$account->id}"), ['confirm' => 'liam'])->assertRedirect();
    expect($workspace->containerRegistries()->count())->toBe(0)
        ->and(Activity::query()->where('action', 'registry.remove')->exists())->toBeTrue();
});

test('a registry login for another Docker Hub user is kept until someone picks this account for pulls', function () {
    hubFake();
    $workspace = Workspace::factory()->create();
    $workspace->containerRegistries()->create(['host' => 'docker.io', 'username' => 'ci-bot', 'password' => 'bot-password']);

    $account = hubAccount($workspace);
    $login = $workspace->containerRegistries()->sole();
    expect($login->username)->toBe('ci-bot')->and($login->integration_id)->toBeNull();

    $props = hubProps($this->actingAs($workspace->owner)->get($workspace->url('/integrations/'.$account->id)));
    expect($props['dockerHub']['login'])->toBe(['state' => 'other', 'username' => 'ci-bot'])
        ->and($props['usage'][0])->toBe('Deploys pull from Docker Hub with the login for ci-bot saved in Settings, not this account.');
    $this->actingAs($workspace->owner)->get($workspace->url('/integrations'))
        ->assertInertia(fn (Assert $page) => $page->where('connections', fn ($rows) => collect($rows)->firstWhere('key', 'docker-hub')['detail'] === 'Signed in as ci-bot'));

    $this->actingAs($workspace->owner)->post($workspace->url("/integrations/{$account->id}/docker-hub/registry"))->assertRedirect();
    expect($login->fresh())->username->toBe('liam')->password->toBe(FakeDockerHub::TOKEN)->integration_id->toBe($account->id);
});

test('only people who manage machines connect Docker Hub or list its repositories', function () {
    hubFake();
    $workspace = Workspace::factory()->create();
    $viewer = hubMember($workspace, 'viewer');

    hubConnect($workspace, as: $viewer)->assertForbidden();
    hubAccount($workspace);
    $this->actingAs($viewer)->getJson($workspace->url('/docker-hub/repositories'))->assertForbidden();
    $this->actingAs($viewer)->getJson($workspace->url('/docker-hub/tags?repository=acme/web'))->assertForbidden();
});

test('the deploy dialog lists the account’s and its organizations’ repositories, and each one’s tags with push dates and sizes', function () {
    $hub = hubFake();
    $workspace = Workspace::factory()->create();

    $this->actingAs($workspace->owner)->getJson($workspace->url('/docker-hub/repositories'))->assertOk()->assertJson(['connected' => false, 'repositories' => []]);

    $account = hubAccount($workspace);
    $list = $this->actingAs($workspace->owner)->getJson($workspace->url('/docker-hub/repositories'))->assertOk();
    expect($list->json('account'))->toBe(['id' => $account->id, 'label' => 'liam', 'username' => 'liam'])
        ->and(array_column($list->json('repositories'), 'repository'))->toBe(['acme/web', 'liam/notes'])
        ->and($list->json('repositories.0'))->toMatchArray(['namespace' => 'acme', 'name' => 'web', 'private' => true, 'url' => 'https://hub.docker.com/r/acme/web'])
        ->and(json_encode($list->json()))->not->toContain(FakeDockerHub::TOKEN);

    $tags = $this->actingAs($workspace->owner)->getJson($workspace->url('/docker-hub/tags?repository=acme/web'))->assertOk()->json('tags');
    expect(array_column($tags, 'name'))->toBe(['edge', 'latest'])
        ->and($tags[1])->toMatchArray(['pushedAt' => '2026-11-21T09:00:00.000000Z', 'size' => 52_000_001, 'digest' => HUB_OLD, 'platforms' => ['linux/amd64'], 'port' => 3000]);

    $this->actingAs($workspace->owner)->getJson($workspace->url('/docker-hub/tags?repository=other/secret'))->assertNotFound();
    expect($hub->called('hub.docker.com'))->not->toContain('GET namespaces/other/repositories/secret/tags');
});

test('a tag deploys as an app: a Dockerfile pinned to the tag’s digest, the settings step’s port, health, domain and env, and the login sent but never stored', function () {
    hubFake();
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $account = hubAccount($workspace);
    $machine = hubMachine($workspace);

    $response = hubDeploy($workspace, $machine)->assertStatus(202);
    expect($response->json())->toMatchArray(['template' => 'acme/web:latest', 'commit' => ImageDeploy::commitOf(HUB_OLD), 'url' => 'https://web.example.com', 'port' => 3000]);

    $deploy = DaemonJob::query()->findOrFail($response->json('jobId'));
    expect($deploy->command['job'])->toMatchArray([
        'type' => 'deploy', 'service' => 'web', 'repo' => '', 'commit' => ImageDeploy::commitOf(HUB_OLD),
        'files' => ['Dockerfile' => 'FROM docker.io/acme/web:latest@'.HUB_OLD."\n"], 'dockerfile' => 'Dockerfile',
        'env' => ['APP_ENV' => 'production', 'GREETING' => 'hi there'], 'domains' => ['web.example.com'], 'port' => 3000,
        'health' => ['path' => '/up', 'expected_status' => 200, 'timeout_secs' => 30, 'retries' => 5],
    ])->and($deploy->command['job'])->not->toHaveKey('registries');

    $sent = hubSentJobs()[0];
    expect($sent['registries'])->toBe([['host' => 'docker.io', 'username' => 'liam', 'password' => FakeDockerHub::TOKEN]])
        ->and(json_encode(DaemonJob::query()->pluck('command')))->not->toContain(FakeDockerHub::TOKEN)
        ->and(DockerHubApp::query()->sole())->integration_id->toBe($account->id)->repository->toBe('acme/web')->tag->toBe('latest')->digest->toBe(HUB_OLD)
        ->and(Activity::query()->where('action', 'service.deploy')->value('subject'))->toBe('web');

    hubDeployed($machine, $deploy);
    $props = hubProps($this->actingAs($workspace->owner)->get($workspace->url("/machines/{$machine->id}/services/app:web")));
    expect($props['app']['service'])->toBe('web')
        ->and($props['app']['versions'][0])->toMatchArray(['repo' => 'acme/web:latest', 'commit' => ImageDeploy::commitOf(HUB_OLD), 'live' => true])
        ->and($props['dockerHub'])->toMatchArray(['image' => 'acme/web:latest', 'running' => HUB_OLD, 'latest' => HUB_OLD, 'behind' => false, 'url' => 'https://hub.docker.com/r/acme/web'])
        ->and($props['dockerHub']['push'])->toMatchArray(['on' => false, 'available' => true, 'url' => null]);

    hubDeploy($workspace, $machine, ['repository' => 'other/secret'])->assertJsonValidationErrors(['repository' => 'That repository isn’t in this Docker Hub account or its organizations.']);
    hubDeploy($workspace, $machine, ['tag' => 'nope'])->assertJsonValidationErrors('tag');
    hubDeploy($workspace, $machine, ['extra_env' => 'not a line'])->assertJsonValidationErrors(['extra_env' => 'Line 1 should look like KEY=value.']);
    hubDeploy($workspace, $machine, ['port' => null])->assertJsonValidationErrors('port');
});

test('redeploying on push gives the app its own webhook address, since Docker Hub’s API can’t add one, and a push of its tag redeploys it with the new digest', function () {
    $hub = hubFake();
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    hubAccount($workspace);
    $machine = hubMachine($workspace);
    hubDeployed($machine, DaemonJob::query()->findOrFail(hubDeploy($workspace, $machine)->json('jobId')));
    $settings = $workspace->url("/machines/{$machine->id}/apps/web/docker-hub");
    $before = count($hub->calls);

    $on = $this->actingAs($workspace->owner)->putJson($settings, ['redeploy_on_push' => true])->assertOk();
    $url = $on->json('dockerHub.push.url');
    $row = DockerHubApp::query()->sole();
    expect($url)->toStartWith('https://panel.example.com/api/docker-hub/webhook/')->toHaveLength(strlen('https://panel.example.com/api/docker-hub/webhook/') + 40)
        ->and(strlen($url))->toBeLessThanOrEqual(255)
        ->and($on->json('dockerHub.push.firstDeliveryAt'))->toBeNull()
        ->and(collect(array_slice($hub->calls, $before))->where('method', '!=', 'GET')->where('host', 'hub.docker.com')->all())->toBe([])
        ->and(DB::table('docker_hub_apps')->value('hook_token'))->not->toContain(basename($url))
        ->and(Activity::query()->where('action', 'app.image_push_setting')->value('meta'))->toBe(['on' => true, 'image' => 'acme/web:latest']);

    $this->postJson('/api/docker-hub/webhook/'.Str::random(40), [])->assertNotFound();
    hubPush($url, 'acme/api')->assertStatus(422)->assertJson(['error' => 'This address is for acme/web, not acme/api. Add it to that repository’s webhooks instead.']);
    hubPush($url, tag: 'edge')->assertOk()->assertJson(['ignored' => 'Only pushes of latest redeploy this app.']);
    $this->postJson(parse_url($url, PHP_URL_PATH), ['hello' => 'world'])->assertStatus(422);
    expect($row->fresh()->first_delivery_at)->not->toBeNull()
        ->and(DaemonJob::query()->where('state', 'queued')->count())->toBe(0);

    hubPush($url)->assertStatus(202)->assertJson(['redeploying' => 'web']);
    expect(DaemonJob::query()->where('state', 'queued')->count())->toBe(0);

    $hub->push('acme/web', 'latest', HUB_NEW);
    hubPush($url)->assertStatus(202);
    $redeploy = DaemonJob::query()->where('state', 'queued')->sole();
    expect($redeploy->trigger)->toBe('push')
        ->and($redeploy->user_id)->toBe($workspace->owner_id)
        ->and($redeploy->command['job'])->toMatchArray([
            'commit' => ImageDeploy::commitOf(HUB_NEW),
            'files' => ['Dockerfile' => 'FROM docker.io/acme/web:latest@'.HUB_NEW."\n"],
            'env' => ['APP_ENV' => 'production', 'GREETING' => 'hi there'],
            'domains' => ['web.example.com'],
        ])
        ->and($row->fresh())->last_push_digest->toBe(HUB_NEW)->digest->toBe(HUB_NEW)
        ->and(ActivityLine::describe(Activity::query()->where('action', 'app.image_pushed')->sole(), false)['after'])->toBe('because a new image of acme/web:latest was pushed ('.substr(ImageDeploy::commitOf(HUB_NEW), 0, 7).')');

    hubPush($url)->assertStatus(202);
    expect(DaemonJob::query()->where('state', 'queued')->count())->toBe(1);

    $this->actingAs($workspace->owner)->putJson($settings, ['redeploy_on_push' => false])->assertOk()->assertJsonPath('dockerHub.push.on', false);
    hubPush($url)->assertOk()->assertJson(['ignored' => 'Redeploying on push is off for this app.']);

    $renewed = $this->actingAs($workspace->owner)->putJson($settings, ['redeploy_on_push' => true, 'new_address' => true])->json('dockerHub.push.url');
    expect($renewed)->not->toBe($url);
    hubPush($url)->assertNotFound();
});

test('deliveries to one app’s webhook are limited to a few a minute', function () {
    hubFake();
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    hubAccount($workspace);
    $machine = hubMachine($workspace);
    hubDeployed($machine, DaemonJob::query()->findOrFail(hubDeploy($workspace, $machine)->json('jobId')));
    $url = $this->actingAs($workspace->owner)->putJson($workspace->url("/machines/{$machine->id}/apps/web/docker-hub"), ['redeploy_on_push' => true])->json('dockerHub.push.url');
    RateLimiter::clear('docker-hub-push:'.DockerHubApp::query()->value('id'));

    for ($i = 0; $i < 10; $i++) {
        hubPush($url, tag: 'edge')->assertOk();
    }
    hubPush($url)->assertStatus(429);
});

test('only an app deployed from a Docker Hub image, with the account connected, can redeploy on push', function () {
    hubFake();
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $account = hubAccount($workspace);
    $machine = hubMachine($workspace);
    hubDeployed($machine, DaemonJob::query()->findOrFail(hubDeploy($workspace, $machine)->json('jobId')));
    $git = DaemonJob::query()->create(['id' => (string) Str::uuid(), 'machine_id' => $machine->id, 'kind' => 'deploy', 'service' => 'shop', 'state' => 'succeeded',
        'command' => ['job' => ['type' => 'deploy', 'service' => 'shop', 'repo' => 'https://github.com/acme/shop.git', 'commit' => str_repeat('1', 40)]]]);

    $this->actingAs($workspace->owner)->putJson($workspace->url("/machines/{$machine->id}/apps/shop/docker-hub"), ['redeploy_on_push' => true])->assertNotFound();
    $this->actingAs(hubMember($workspace, 'viewer'))->putJson($workspace->url("/machines/{$machine->id}/apps/web/docker-hub"), ['redeploy_on_push' => true])->assertForbidden();

    $this->actingAs($workspace->owner)->delete($workspace->url("/integrations/{$account->id}"), ['confirm' => 'liam']);
    $this->actingAs($workspace->owner)->putJson($workspace->url("/machines/{$machine->id}/apps/web/docker-hub"), ['redeploy_on_push' => true])
        ->assertJsonValidationErrors(['redeploy_on_push' => 'Connect Docker Hub in Integrations first.']);
    expect($git->fresh()->state)->toBe('succeeded');
});

test('disconnecting stops every app redeploying on push and their webhook addresses stop working', function () {
    hubFake();
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $account = hubAccount($workspace);
    $machine = hubMachine($workspace);
    hubDeployed($machine, DaemonJob::query()->findOrFail(hubDeploy($workspace, $machine)->json('jobId')));
    $url = $this->actingAs($workspace->owner)->putJson($workspace->url("/machines/{$machine->id}/apps/web/docker-hub"), ['redeploy_on_push' => true])->json('dockerHub.push.url');

    $props = hubProps($this->actingAs($workspace->owner)->get($workspace->url('/integrations/'.$account->id)));
    expect($props['dependents'])->toBe([
        'Deploys stop pulling private images from Docker Hub: the registry login goes with the account.',
        '1 app stops redeploying when a new image is pushed, and its webhook address stops working.',
    ])->and($props['dockerHub']['apps'][0])->toMatchArray(['app' => 'web', 'machine' => 'web-1', 'redeploy' => true, 'image' => 'acme/web:latest'])
        ->and($props['usage'])->toContain('1 app is deployed from an image here.', '1 app redeploys when a new image is pushed.');

    $this->actingAs($workspace->owner)->delete($workspace->url("/integrations/{$account->id}"))->assertSessionHasErrors('confirm');
    $this->actingAs($workspace->owner)->delete($workspace->url("/integrations/{$account->id}"), ['confirm' => 'liam'])->assertRedirect();

    expect(DockerHubApp::query()->sole())->redeploy_on_push->toBeFalse()->hook_key->toBeNull()->integration_id->toBeNull();
    hubPush($url)->assertNotFound();
});

test('the app page says when the tag has a newer image than the app runs, and Update now redeploys onto it', function () {
    $hub = hubFake();
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    hubAccount($workspace);
    $machine = hubMachine($workspace);
    hubDeployed($machine, DaemonJob::query()->findOrFail(hubDeploy($workspace, $machine)->json('jobId')));
    $page = $workspace->url("/machines/{$machine->id}/services/app:web");
    $update = $workspace->url("/machines/{$machine->id}/apps/web/docker-hub/update");

    $this->actingAs($workspace->owner)->postJson($update)->assertStatus(409)->assertJson(['error' => 'web already runs the newest image of acme/web:latest.']);

    $hub->push('acme/web', 'latest', HUB_NEW);
    Cache::flush();
    expect(hubProps($this->actingAs($workspace->owner)->get($page))['dockerHub'])
        ->toMatchArray(['behind' => true, 'running' => HUB_OLD, 'latest' => HUB_NEW, 'pushedAt' => '2026-11-21T09:00:00.000000Z', 'size' => 52_000_001, 'updating' => null]);

    $job = $this->actingAs($workspace->owner)->postJson($update)->assertStatus(202)->json('jobId');
    expect(DaemonJob::query()->findOrFail($job)->command['job']['commit'])->toBe(ImageDeploy::commitOf(HUB_NEW))
        ->and(DaemonJob::query()->findOrFail($job)->trigger)->toBeNull();
    expect(hubProps($this->actingAs($workspace->owner)->get($page))['dockerHub']['updating'])->toBe($job);
});

test('the image update checks see an app deployed from an image as behind its tag, and updating it from Updates redeploys it', function () {
    $hub = hubFake();
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    hubAccount($workspace);
    $machine = hubMachine($workspace);
    hubDeployed($machine, DaemonJob::query()->findOrFail(hubDeploy($workspace, $machine)->json('jobId')));
    $machine->refresh();

    expect(app(ImageUpdates::class)->check($machine))->toBe(0)
        ->and(ContainerImage::query()->sole())->state->toBe('current')->image->toBe('acme/web:latest');

    $hub->push('acme/web', 'latest', HUB_NEW);
    Cache::flush();
    expect(app(ImageUpdates::class)->check($machine))->toBe(1)
        ->and(ContainerImage::query()->sole())->state->toBe('available')->remote_digest->toBe(HUB_NEW);

    $queued = $this->actingAs($workspace->owner)->postJson($workspace->url('/container-images/update'), ['ids' => [ContainerImage::query()->value('id')]])
        ->assertStatus(202)->json('queued');
    $deploy = DaemonJob::query()->findOrFail(array_values($queued)[0]);
    expect($deploy->kind)->toBe('deploy')
        ->and($deploy->command['job']['files'])->toBe(['Dockerfile' => 'FROM docker.io/acme/web:latest@'.HUB_NEW."\n"])
        ->and(ImageUpdates::updating(ContainerImage::query()->sole())?->id)->toBe($deploy->id);
});

test('the Integrations page offers to connect Docker Hub, and its row says what works', function () {
    hubFake();
    $workspace = Workspace::factory()->create();

    $this->actingAs($workspace->owner)->get($workspace->url('/integrations'))
        ->assertInertia(fn (Assert $page) => $page->where('integrations', fn ($rows) => ($row = collect($rows)->firstWhere('key', 'docker-hub')) !== null
            && $row['action'] === ['label' => 'Connect', 'connect' => 'docker-hub']
            && in_array('Webhooks', $row['tags'], true))
            ->where('providers.docker-hub.multiple', false)
            ->where('providers.docker-hub.fields.1.secret', true));

    $account = hubAccount($workspace);
    $this->actingAs($workspace->owner)->get($workspace->url('/integrations'))
        ->assertInertia(fn (Assert $page) => $page->where('connections', fn ($rows) => collect($rows)->firstWhere('key', 'integration-'.$account->id)['detail'] === '@liam · 2 repositories'));
    expect(ContainerRegistry::query()->sole()->integration_id)->toBe($account->id);
});
