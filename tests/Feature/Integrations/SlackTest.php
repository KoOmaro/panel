<?php

use App\Models\Activity;
use App\Models\ChatIdentity;
use App\Models\ChatPost;
use App\Models\DaemonJob;
use App\Models\Integration;
use App\Models\Machine;
use App\Models\Monitor;
use App\Models\SlackMessage;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Activity\ActivityLine;
use App\Support\Integrations\Chat\ChatAlert;
use App\Support\Integrations\Chat\ChatTargets;
use App\Support\Integrations\Slack\SlackChat;
use App\Support\Integrations\Slack\SlackSignature;
use App\Support\Monitors\MonitorRunner;
use App\Support\Monitors\ProbeResult;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeSlack;

const SLACK_OLD = 'aaaaaaaaaaaa1111111111111111111111111111';
const SLACK_NEW = 'bbbbbbbbbbbb2222222222222222222222222222';
const SLACK_NEWEST = 'cccccccccccc3333333333333333333333333333';
const SLACK_RESPONSE_URL = 'https://hooks.slack.com/actions/T0FAKE001/1/fake-response';

beforeEach(function () {
    Sleep::fake();
    Notification::fake();
    // A test is one app for many requests, so jobs left for after the response
    // would run again at the end of every later request. Run them in the request.
    Bus::withoutDispatchingAfterResponses();
});

/** @param  array<string, mixed>|null  $settings */
function slackAccount(Workspace $workspace, ?array $settings = null): Integration
{
    return $workspace->integrations()->create([
        'provider' => 'slack',
        'label' => 'Acme Slack',
        'credentials' => ['bot_token' => FakeSlack::TOKEN],
        'fingerprint' => Integration::fingerprintOf('slack', ['account' => FakeSlack::TEAM]),
        'meta' => ['team_id' => FakeSlack::TEAM, 'team' => 'Acme Slack', 'team_url' => 'https://acme-fake.slack.com/', 'scopes' => 'chat:write,commands,channels:read,groups:read,users:read,users:read.email'],
        'settings' => $settings ?? ['alerts' => ['id' => 'C0ALERTS1', 'name' => 'ops'], 'deploys' => ['id' => 'C0DEPLOY1', 'name' => 'deploys']],
        'status' => Integration::CONNECTED,
        'last_checked_at' => now(),
    ]);
}

function slackMember(Workspace $workspace, string $role): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $workspace->members()->attach($user, ['role' => $role]);

    return $user;
}

function slackKnows(FakeSlack $slack, string $id, User $user, string $name = 'sam'): void
{
    $slack->users[$id] = ['id' => $id, 'team_id' => FakeSlack::TEAM, 'name' => $name, 'deleted' => false, 'is_bot' => false, 'profile' => ['email' => $user->email]];
}

/** @param  array<string, string>  $fields */
function slackSigned(string $path, array $fields, ?int $at = null, string $secret = FakeSlack::SECRET): TestResponse
{
    $body = http_build_query($fields);
    $ts = (string) ($at ?? now()->getTimestamp());

    return test()->call('POST', '/api/slack/'.$path, $fields, [], [], [
        'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_SLACK_REQUEST_TIMESTAMP' => $ts,
        'HTTP_X_SLACK_SIGNATURE' => SlackSignature::sign($secret, $ts, $body),
    ], $body);
}

/** @param  array<string, mixed>  $message */
function slackPress(array $message, string $actionId, string $slackUser = 'U0SAM0001', ?int $at = null, string $secret = FakeSlack::SECRET): TestResponse
{
    $button = FakeSlack::button($message, $actionId);
    expect($button)->not->toBeNull("No {$actionId} button");

    return slackSigned('interactivity', ['payload' => (string) json_encode([
        'type' => 'block_actions',
        'team' => ['id' => FakeSlack::TEAM, 'domain' => 'acme-fake'],
        'user' => ['id' => $slackUser, 'username' => 'sam', 'name' => 'sam', 'team_id' => FakeSlack::TEAM],
        'api_app_id' => 'A0FAKEAPP',
        'response_url' => SLACK_RESPONSE_URL,
        'channel' => ['id' => (string) ($message['channel'] ?? ''), 'name' => 'ops'],
        'actions' => [['action_id' => $actionId, 'block_id' => 'b', 'value' => $button['value'] ?? null, 'type' => 'button', 'action_ts' => '1']],
    ])], $at, $secret);
}

/** @param  array<string, string>  $extra */
function slackCommand(string $text, string $slackUser = 'U0SAM0001', array $extra = []): TestResponse
{
    return slackSigned('commands', ['team_id' => FakeSlack::TEAM, 'channel_id' => 'C0ALERTS1', 'user_id' => $slackUser, 'user_name' => 'sam', 'command' => '/serveros', 'text' => $text, 'response_url' => 'https://hooks.slack.com/commands/T0FAKE001/1/fake', ...$extra]);
}

function slackMonitor(Workspace $workspace): Monitor
{
    return $workspace->monitors()->create([
        'name' => 'Shop', 'type' => 'http', 'url' => 'https://shop.example.com/',
        'interval_seconds' => 60, 'timeout_seconds' => 10, 'status' => 'up', 'alerts' => true,
    ]);
}

function slackDown(Monitor $monitor): void
{
    foreach (range(1, Monitor::CONFIRM_AFTER) as $_) {
        app(MonitorRunner::class)->apply($monitor->fresh('workspace'), ProbeResult::fail('Connection refused'));
    }
}

/** @return array<string, mixed> the message as Slack has it now */
function slackMessage(FakeSlack $slack, string $channel = 'C0ALERTS1', int $index = 0): array
{
    $ts = array_keys($slack->messages[$channel] ?? [])[$index] ?? null;
    expect($ts)->not->toBeNull("Nothing in {$channel}");

    return [...$slack->messages[$channel][$ts], 'channel' => $channel, 'ts' => $ts];
}

function slackGateway(): void
{
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    Http::fake(['127.0.0.1:8090/*' => Http::response(['queued' => true], 202)]);
}

function slackMachine(Workspace $workspace): Machine
{
    $machine = Machine::factory()->for($workspace)->create(['name' => 'web-1', 'environment' => 'production']);
    $machine->forceFill(['uid' => (string) Str::uuid(), 'last_seen_at' => now(), 'inventory' => ['services' => [
        ['key' => 'docker:old', 'name' => 'serveros-shop-aaaaaaaaaaaa', 'kind' => 'app', 'status' => 'stopped', 'details' => ['managed' => 'true']],
        ['key' => 'docker:new', 'name' => 'serveros-shop-bbbbbbbbbbbb', 'kind' => 'app', 'status' => 'running', 'details' => ['managed' => 'true']],
    ]]])->save();

    return $machine;
}

function slackDeploy(Machine $machine, string $commit, $at, string $state = 'succeeded'): DaemonJob
{
    $job = DaemonJob::query()->create([
        'id' => (string) Str::uuid(),
        'machine_id' => $machine->id,
        'user_id' => $machine->workspace->owner_id,
        'kind' => 'deploy',
        'service' => 'shop',
        'state' => $state,
        'command' => ['job' => ['type' => 'deploy', 'service' => 'shop', 'repo' => 'https://github.com/acme/shop', 'commit' => $commit, 'domains' => ['shop.example.com']]],
        'result' => ['commit' => $commit],
    ]);
    $job->forceFill(['created_at' => $at])->save();

    return $job;
}

test('with no Slack app on the install, the Slack row keeps webhook alerts and says why, and Slack’s routes aren’t there', function () {
    FakeSlack::install();
    config()->set('services.slack_app', ['client_id' => null, 'client_secret' => null, 'signing_secret' => null]);
    $workspace = Workspace::factory()->create();

    $items = collect($this->actingAs($workspace->owner)->get($workspace->url('/integrations'))->assertOk()->viewData('page')['props']['integrations'])->keyBy('key');
    expect($items['slack']['action'])->toBe(['label' => 'Connect', 'alerts' => true])
        ->and($items['slack']['note'])->toContain('ServerOS Slack app');

    $this->actingAs($workspace->owner)->get($workspace->url('/integrations/oauth/slack'))->assertRedirect($workspace->url('/integrations'));
    slackCommand('status')->assertNotFound();
});

test('Slack connects through OAuth v2, keeps the bot token encrypted, and connecting again updates the same account', function () {
    $slack = FakeSlack::install();
    $workspace = Workspace::factory()->create();

    $items = collect($this->actingAs($workspace->owner)->get($workspace->url('/integrations'))->viewData('page')['props']['integrations'])->keyBy('key');
    expect($items['slack']['action'])->toBe(['label' => 'Connect', 'href' => '/integrations/oauth/slack'])
        ->and($items['slack']['note'])->toBeNull();

    $go = $this->actingAs($workspace->owner)->get($workspace->url('/integrations/oauth/slack'))->assertRedirect();
    $to = (string) $go->headers->get('Location');
    parse_str((string) parse_url($to, PHP_URL_QUERY), $query);
    expect($to)->toStartWith('https://slack.com/oauth/v2/authorize?')
        ->and($query['client_id'])->toBe('fake-client-id')
        ->and(explode(',', $query['scope']))->toBe(['chat:write', 'commands', 'channels:read', 'groups:read', 'users:read', 'users:read.email'])
        ->and($query['redirect_uri'])->toBe(route('integrations.oauth.callback', ['provider' => 'slack']));

    $this->actingAs($workspace->owner)->get('/integrations/oauth/slack/callback?'.http_build_query(['code' => 'wrong', 'state' => $query['state']]))
        ->assertRedirect($workspace->url('/integrations'));
    expect(Integration::query()->count())->toBe(0);

    $other = User::factory()->create();
    $this->actingAs($other)->get('/integrations/oauth/slack/callback?'.http_build_query(['code' => FakeSlack::CODE, 'state' => $query['state']]))
        ->assertRedirect(route('workspaces.index'));
    expect(Integration::query()->count())->toBe(0);

    $this->actingAs($workspace->owner)->get('/integrations/oauth/slack/callback?'.http_build_query(['code' => FakeSlack::CODE, 'state' => $query['state']]))->assertRedirect();
    $integration = Integration::query()->sole();
    expect($integration->provider)->toBe('slack')
        ->and($integration->label)->toBe('Acme Slack')
        ->and($integration->credential('bot_token'))->toBe(FakeSlack::TOKEN)
        ->and($integration->meta['team_id'])->toBe(FakeSlack::TEAM)
        ->and($integration->meta['team_url'])->toBe('https://acme-fake.slack.com/')
        ->and(DB::table('integrations')->value('credentials'))->not->toContain(FakeSlack::TOKEN)
        ->and(ActivityLine::sentence(Activity::query()->where('action', 'integration.connect')->sole()))->toContain('connected Acme Slack (Slack)');

    $integration->forceFill(['status' => Integration::FAILING, 'last_error' => 'Slack turned the app’s token down.'])->save();
    $this->actingAs($workspace->owner)->get('/integrations/oauth/slack/callback?'.http_build_query(['code' => FakeSlack::CODE, 'state' => $query['state']]))->assertRedirect($workspace->url('/integrations/'.$integration->id));
    expect(Integration::query()->count())->toBe(1)
        ->and($integration->fresh()->status)->toBe(Integration::CONNECTED)
        ->and(Activity::query()->where('action', 'integration.reconnect')->count())->toBe(1);

    $this->actingAs($workspace->owner)->get('/integrations/oauth/slack/callback?error=access_denied&state='.urlencode($query['state']))->assertRedirect($workspace->url('/integrations'));
    $this->actingAs($workspace->owner)->post($workspace->url('/integrations/connect/slack'), ['credentials' => []])->assertNotFound();

    $page = $this->actingAs($workspace->owner)->get($workspace->url('/integrations/'.$integration->id))->assertOk();
    expect($page->getContent())->not->toContain(FakeSlack::TOKEN);
});

test('the account page lists the channels the app can see, and saving picks one per purpose', function () {
    $slack = FakeSlack::install();
    $workspace = Workspace::factory()->create();
    $integration = slackAccount($workspace, []);

    $this->actingAs($workspace->owner)->get($workspace->url('/integrations/'.$integration->id))
        ->assertInertia(fn (Assert $page) => $page
            ->where('provider.capabilities.0.key', 'channel_alerts')
            ->loadDeferredProps(fn (Assert $page) => $page
                ->where('account.kind', 'slack')
                ->where('account.alerts', null)
                ->has('account.channels', 4)
                ->where('account.channels.0.name', 'deploys')));

    $this->actingAs($workspace->owner)->put($workspace->url("/integrations/{$integration->id}/channels"), ['alerts' => 'C0NOPE001', 'deploys' => null])
        ->assertSessionHasErrors('alerts');
    $this->actingAs($workspace->owner)->put($workspace->url("/integrations/{$integration->id}/channels"), ['alerts' => 'G0PRIVATE', 'deploys' => 'C0DEPLOY1'])
        ->assertSessionHasNoErrors()->assertRedirect();

    $integration->refresh();
    expect($integration->settings['alerts'])->toBe(['id' => 'G0PRIVATE', 'name' => 'oncall'])
        ->and($integration->settings['deploys'])->toBe(['id' => 'C0DEPLOY1', 'name' => 'deploys'])
        ->and($integration->provider()->summary($integration))->toBe('Alerts to #oncall, deploys to #deploys')
        ->and(Activity::query()->where('action', 'integration.routing')->count())->toBe(1);

    $member = slackMember($workspace, 'viewer');
    $this->actingAs($member)->put($workspace->url("/integrations/{$integration->id}/channels"), ['alerts' => 'C0ALERTS1'])->assertForbidden();

    $this->actingAs($workspace->owner)->post($workspace->url("/integrations/{$integration->id}/channels/test"))->assertSessionHas('inertia.flash_data.toast.type', 'success');
    expect($slack->posted()[0]['channel'])->toBe('G0PRIVATE')
        ->and($slack->posted()[0]['text'])->toContain('Test from ServerOS');
});

test('a monitor going down posts Block Kit with Check now, Mute and Open, and coming back changes that message to fixed', function () {
    $slack = FakeSlack::install();
    $workspace = Workspace::factory()->create();
    $integration = slackAccount($workspace);
    $monitor = slackMonitor($workspace);

    slackDown($monitor);

    expect($slack->posted())->toHaveCount(1);
    $message = slackMessage($slack);
    expect($message['text'])->toBe('Shop is down')
        ->and(FakeSlack::text($message))->toContain('Connection refused')
        ->and(FakeSlack::actionIds($message))->toBe(['serveros.check', 'serveros.mute', 'serveros.open'])
        ->and(FakeSlack::button($message, 'serveros.open')['url'])->toBe($workspace->url('/monitors/'.$monitor->id));
    $post = ChatPost::query()->sole();
    expect($post->chat)->toBe('slack')->and($post->state)->toBe(ChatPost::OPEN)->and($post->integration_id)->toBe($integration->id)
        ->and(SlackMessage::query()->sole()->ts)->toBe($message['ts']);

    app(MonitorRunner::class)->apply($monitor->fresh('workspace'), new ProbeResult(true, 120, 200));

    expect($slack->posted())->toHaveCount(1)
        ->and($slack->posted('chat.update'))->toHaveCount(1);
    $fixed = slackMessage($slack);
    expect($fixed['ts'])->toBe($message['ts'])
        ->and($fixed['text'])->toBe('Fixed: Shop is down')
        ->and(FakeSlack::text($fixed))->toContain('(fixed)')
        ->and(FakeSlack::actionIds($fixed))->toBe(['serveros.open'])
        ->and($post->fresh()->state)->toBe(ChatPost::RESOLVED);
});

test('a deploy failing goes to the deploys channel, not alerts, and a channel the app isn’t in fails the post with how to fix it', function () {
    $slack = FakeSlack::install();
    $workspace = Workspace::factory()->create();
    slackAccount($workspace, ['alerts' => ['id' => 'C0RANDOM1', 'name' => 'random'], 'deploys' => ['id' => 'C0DEPLOY1', 'name' => 'deploys']]);
    $machine = slackMachine($workspace);
    $job = slackDeploy($machine, SLACK_NEW, now(), 'failed');

    app(ChatTargets::class)->deliver(new ChatAlert($workspace, 'deploy.failed', 'shop on web-1 failed to deploy', ['The build failed.'], $workspace->url('/machines/'.$machine->id), true, 'Open the deploy', ['machine' => ['id' => $machine->id, 'name' => 'web-1'], 'service' => 'shop', 'job_id' => $job->id]));
    expect($slack->posted())->toBe([]);

    app(ChatTargets::class)->deliver(new ChatAlert($workspace, 'machine.disk', 'web-1’s disk is 91% full', ['It’s nearly out of space.'], $workspace->url('/machines/'.$machine->id), true, 'Open the machine', ['machine' => ['id' => $machine->id, 'name' => 'web-1']]));
    $failed = ChatPost::query()->sole();
    expect($failed->state)->toBe(ChatPost::FAILED)
        ->and($failed->error)->toContain('/invite @ServerOS');
});

test('a button press is checked against the signing secret and its time', function () {
    $slack = FakeSlack::install();
    $workspace = Workspace::factory()->create();
    slackAccount($workspace);
    slackKnows($slack, 'U0SAM0001', $workspace->owner);
    $monitor = slackMonitor($workspace);
    slackDown($monitor);
    $message = slackMessage($slack);

    slackPress($message, 'serveros.mute', secret: 'not-the-secret')->assertUnauthorized();
    slackPress($message, 'serveros.mute', at: now()->subMinutes(6)->getTimestamp())->assertUnauthorized();
    $this->call('POST', '/api/slack/interactivity', ['payload' => '{}'])->assertUnauthorized();
    expect($monitor->fresh()->muted_until)->toBeNull();

    slackPress($message, 'serveros.mute')->assertOk();
    expect($monitor->fresh()->muted_until?->isFuture())->toBeTrue();
});

test('Mute runs as the Slack user’s member, found by verified email, and the message says who did it', function () {
    $slack = FakeSlack::install();
    $workspace = Workspace::factory()->create();
    slackAccount($workspace);
    $workspace->owner->forceFill(['email_verified_at' => now()])->save();
    slackKnows($slack, 'U0SAM0001', $workspace->owner);
    $monitor = slackMonitor($workspace);
    slackDown($monitor);

    slackPress(slackMessage($slack), 'serveros.mute')->assertOk();

    expect($monitor->fresh()->muted_until?->between(now()->addMinutes(59), now()->addMinutes(61)))->toBeTrue()
        ->and(ChatIdentity::query()->sole()->only(['chat', 'external_id', 'user_id']))->toBe(['chat' => 'slack', 'external_id' => 'U0SAM0001', 'user_id' => $workspace->owner->id]);
    $mute = Activity::query()->where('action', 'monitor.mute')->sole();
    expect($mute->user_id)->toBe($workspace->owner->id)
        ->and($mute->meta['via'])->toBe('slack')
        ->and($mute->source)->toBe('chat')
        ->and(Activity::query()->where('action', 'alerts.chat_link')->sole()->meta)->toMatchArray(['channel' => 'slack', 'by' => 'email']);

    $updated = slackMessage($slack);
    expect(FakeSlack::text($updated))->toContain($workspace->owner->name.': Muted Shop’s alerts for 1 h');
});

test('an unlinked Slack user gets a one-time link, and the member who opens it is who the presses run as', function () {
    $slack = FakeSlack::install();
    $workspace = Workspace::factory()->create();
    $integration = slackAccount($workspace);
    $admin = slackMember($workspace, 'admin');
    $slack->users['U0DANA001'] = ['id' => 'U0DANA001', 'team_id' => FakeSlack::TEAM, 'name' => 'dana', 'deleted' => false, 'is_bot' => false, 'profile' => ['email' => 'someone-else@example.com']];
    $monitor = slackMonitor($workspace);
    slackDown($monitor);
    $message = slackMessage($slack);

    slackPress($message, 'serveros.mute', 'U0DANA001')->assertOk();
    expect($monitor->fresh()->muted_until)->toBeNull();
    $reply = collect($slack->responses)->last()['body'];
    expect($reply['response_type'])->toBe('ephemeral')
        ->and(FakeSlack::text($reply))->toContain('Link your Slack account');
    $link = (string) FakeSlack::button($reply, 'serveros.link')['url'];
    expect($link)->toStartWith($workspace->url('/integrations/'.$integration->id.'/slack/link/'));

    $outsider = User::factory()->create();
    $this->actingAs($outsider)->get($link)->assertForbidden();

    $this->actingAs($admin)->get($link)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('workspace/SlackLink')->where('state', 'ready')->where('slack.name', 'sam'));
    $this->actingAs($admin)->post($link)->assertRedirect($link);
    $this->actingAs($admin)->get($link)->assertInertia(fn (Assert $page) => $page->where('state', 'done'));
    $this->actingAs($admin)->post($link)->assertSessionHas('inertia.flash_data.toast.type', 'error');
    expect(ChatIdentity::query()->sole()->only(['external_id', 'user_id']))->toBe(['external_id' => 'U0DANA001', 'user_id' => $admin->id])
        ->and(Activity::query()->where('action', 'alerts.chat_link')->sole()->meta['by'])->toBe('link');

    slackPress($message, 'serveros.mute', 'U0DANA001')->assertOk();
    expect($monitor->fresh()->muted_until)->not->toBeNull()
        ->and(Activity::query()->where('action', 'monitor.mute')->sole()->user_id)->toBe($admin->id);
});

test('a member whose role can’t manage machines is told so and nothing changes', function () {
    $slack = FakeSlack::install();
    $workspace = Workspace::factory()->create();
    slackAccount($workspace);
    $viewer = slackMember($workspace, 'viewer');
    slackKnows($slack, 'U0VIEW001', $viewer, 'viewer');
    $monitor = slackMonitor($workspace);
    slackDown($monitor);

    slackPress(slackMessage($slack), 'serveros.mute', 'U0VIEW001')->assertOk();

    expect($monitor->fresh()->muted_until)->toBeNull()
        ->and(FakeSlack::text(collect($slack->responses)->last()['body']))->toContain('can’t do that')
        ->and(Activity::query()->where('action', 'monitor.mute')->exists())->toBeFalse()
        ->and($slack->posted('chat.update'))->toBe([]);
});

test('Check now checks the monitor as the member and notes the result on the message', function () {
    $slack = FakeSlack::install();
    $workspace = Workspace::factory()->create();
    slackAccount($workspace);
    slackKnows($slack, 'U0SAM0001', $workspace->owner);
    $monitor = slackMonitor($workspace);
    slackDown($monitor);
    $this->mock(MonitorRunner::class, function ($mock) use ($monitor) {
        $mock->shouldReceive('run')->once()->andReturnUsing(function () use ($monitor) {
            $monitor->checks()->create(['checked_at' => now(), 'ok' => false, 'response_ms' => null, 'status_code' => null, 'error' => 'Connection refused']);
            $monitor->forceFill(['last_error' => 'Connection refused'])->save();
        });
    });

    slackPress(slackMessage($slack), 'serveros.check')->assertOk();

    $text = FakeSlack::text(slackMessage($slack));
    expect($text)->toContain($workspace->owner->name.': Checking Shop now.')
        ->and($text)->toContain('Shop is still down: Connection refused');
});

test('Restart on a service alert restarts it as the member, and the message says when it’s done', function () {
    $slack = FakeSlack::install();
    slackGateway();
    $workspace = Workspace::factory()->create();
    slackAccount($workspace);
    slackKnows($slack, 'U0SAM0001', $workspace->owner);
    $machine = slackMachine($workspace);

    app(ChatTargets::class)->deliver(new ChatAlert($workspace, 'service.down', 'shop stopped on web-1', ['It exited with code 1.'], $workspace->url('/machines/'.$machine->id), true, 'Open the service', [
        'machine' => ['id' => $machine->id, 'name' => 'web-1'], 'service' => ['key' => 'docker:new', 'name' => 'shop'],
    ]));
    $message = slackMessage($slack);
    expect(FakeSlack::actionIds($message))->toContain('serveros.restart')
        ->and(FakeSlack::button($message, 'serveros.restart')['text']['text'])->toBe('Restart shop');

    slackPress($message, 'serveros.restart')->assertOk();
    $job = DaemonJob::query()->where('kind', 'service_action')->sole();
    expect($job->user_id)->toBe($workspace->owner->id)
        ->and($job->command['job'])->toMatchArray(['service' => 'docker:new', 'action' => 'restart'])
        ->and(SlackMessage::query()->sole()->job_id)->toBe($job->id);

    $job->forceFill(['state' => 'succeeded', 'finished_at' => now()])->save();
    app(SlackChat::class)->jobFinished($job);
    expect(FakeSlack::text(slackMessage($slack)))->toContain('Restarted docker:new.')
        ->and(SlackMessage::query()->sole()->job_id)->toBeNull();
});

test('a deploy notice offers Roll back to the version before, which rolls back as the member and notes it', function () {
    $slack = FakeSlack::install();
    slackGateway();
    $workspace = Workspace::factory()->create();
    slackAccount($workspace);
    slackKnows($slack, 'U0SAM0001', $workspace->owner);
    $machine = slackMachine($workspace);
    slackDeploy($machine, SLACK_OLD, now()->subDay());
    $job = slackDeploy($machine, SLACK_NEW, now()->subMinute());

    app(ChatTargets::class)->deliver(new ChatAlert($workspace, ChatAlert::DEPLOY_NOTICE, 'shop on web-1 deployed', ['It took 42 s.'], $workspace->url("/machines/{$machine->id}?job={$job->id}"), false, 'Open the deploy', [
        'machine' => ['id' => $machine->id, 'name' => 'web-1'], 'service' => 'shop', 'job_id' => $job->id,
    ]));

    expect($slack->messages['C0ALERTS1'] ?? [])->toBe([]);
    $notice = slackMessage($slack, 'C0DEPLOY1');
    expect(FakeSlack::actionIds($notice))->toBe(['serveros.rollback', 'serveros.open'])
        ->and(FakeSlack::button($notice, 'serveros.rollback')['text']['text'])->toBe('Roll back to aaaaaaa')
        ->and(FakeSlack::button($notice, 'serveros.rollback')['confirm']['confirm']['text'])->toBe('Roll back')
        ->and(FakeSlack::text($notice))->toContain('`bbbbbbb`');

    slackPress($notice, 'serveros.rollback')->assertOk();
    $rollback = DaemonJob::query()->where('kind', 'rollback')->sole();
    expect($rollback->command['job'])->toMatchArray(['service' => 'shop', 'release' => SLACK_OLD])
        ->and($rollback->user_id)->toBe($workspace->owner->id)
        ->and(FakeSlack::text(slackMessage($slack, 'C0DEPLOY1')))->toContain('is rolling back to aaaaaaa');

    $rollback->forceFill(['state' => 'succeeded', 'finished_at' => now()])->save();
    app(SlackChat::class)->jobFinished($rollback);
    $after = slackMessage($slack, 'C0DEPLOY1');
    expect(FakeSlack::text($after))->toContain('Rolled back to aaaaaaa.')
        ->and(FakeSlack::actionIds($after))->toBe(['serveros.open']);
});

test('/serveros answers help at once, and status and machine through response_url for linked members', function () {
    $slack = FakeSlack::install();
    $workspace = Workspace::factory()->create();
    slackAccount($workspace);
    slackKnows($slack, 'U0SAM0001', $workspace->owner);
    slackMachine($workspace);

    slackCommand('help')->assertOk()->assertJsonPath('response_type', 'ephemeral');
    expect(json_encode(slackCommand('')->json()))->toContain('deploy <app>');

    slackCommand('status')->assertOk();
    expect(FakeSlack::text(collect($slack->responses)->last()['body']))->toContain($workspace->name);

    slackCommand('machine web-1')->assertOk();
    expect(FakeSlack::text(collect($slack->responses)->last()['body']))->toContain('web-1 (production) is reporting');

    slackCommand('reboot everything')->assertOk();
    expect(FakeSlack::text(collect($slack->responses)->last()['body']))->toContain('There’s no “reboot” command');

    slackSigned('commands', ['team_id' => 'T0OTHER01', 'user_id' => 'U0SAM0001', 'text' => 'status', 'response_url' => 'https://hooks.slack.com/commands/x/1/y'])->assertOk();
    expect(FakeSlack::text(collect($slack->responses)->last()['body']))->toContain('isn’t connected to a ServerOS workspace');
});

test('/serveros deploy finds the latest commit, asks first, and deploys it as the member when confirmed', function () {
    $slack = FakeSlack::install();
    slackGateway();
    Http::fake(['api.github.com/repos/acme/shop' => Http::response(['default_branch' => 'main', 'private' => false]), 'api.github.com/repos/acme/shop/commits/*' => Http::response(['sha' => SLACK_NEWEST])]);
    $workspace = Workspace::factory()->create();
    slackAccount($workspace);
    slackKnows($slack, 'U0SAM0001', $workspace->owner);
    $machine = slackMachine($workspace);
    slackDeploy($machine, SLACK_NEW, now()->subHour());

    slackCommand('deploy nope')->assertOk();
    expect(FakeSlack::text(collect($slack->responses)->last()['body']))->toContain('No app called nope');
    expect(DaemonJob::query()->where('kind', 'deploy')->count())->toBe(1);

    slackCommand('deploy shop')->assertOk();
    $ask = collect($slack->responses)->last()['body'];
    expect(FakeSlack::text($ask))->toContain('Deploy shop on web-1?')
        ->and(FakeSlack::text($ask))->toContain('`bbbbbbb` → `ccccccc`')
        ->and(FakeSlack::actionIds($ask))->toBe(['serveros.deploy', 'serveros.cancel'])
        ->and(DaemonJob::query()->where('kind', 'deploy')->count())->toBe(1);

    slackPress($ask, 'serveros.cancel')->assertOk();
    expect(DaemonJob::query()->where('kind', 'deploy')->count())->toBe(1);

    slackPress($ask, 'serveros.deploy')->assertOk();
    $deploy = DaemonJob::query()->where('kind', 'deploy')->latest('created_at')->first();
    expect($deploy->command['job']['commit'])->toBe(SLACK_NEWEST)
        ->and($deploy->user_id)->toBe($workspace->owner->id)
        ->and(collect($slack->responses)->last()['body']['replace_original'])->toBeTrue()
        ->and(FakeSlack::text(collect($slack->responses)->last()['body']))->toContain('is deploying shop on web-1');

    $viewer = slackMember($workspace, 'viewer');
    slackKnows($slack, 'U0VIEW001', $viewer, 'viewer');
    slackCommand('deploy shop', 'U0VIEW001')->assertOk();
    expect(FakeSlack::text(collect($slack->responses)->last()['body']))->toContain('can’t do that');
});

test('a revoked token marks the account failing, and the posts and people show on the account page', function () {
    $slack = FakeSlack::install();
    $workspace = Workspace::factory()->create();
    $integration = slackAccount($workspace);
    slackKnows($slack, 'U0SAM0001', $workspace->owner);
    $monitor = slackMonitor($workspace);
    slackDown($monitor);
    slackPress(slackMessage($slack), 'serveros.mute')->assertOk();

    $this->actingAs($workspace->owner)->get($workspace->url('/integrations/'.$integration->id))
        ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $page) => $page
            ->where('account.people.0.member', $workspace->owner->name)
            ->where('account.posts.0.subject', 'Shop is down')
            ->where('account.posts.0.url', fn ($url) => str_starts_with((string) $url, 'https://acme-fake.slack.com/archives/C0ALERTS1/p'))));

    $slack->failNext('chat.postMessage', 'token_revoked');
    app(ChatTargets::class)->deliver(new ChatAlert($workspace, 'machine.disk', 'web-1’s disk is 91% full', ['Nearly full.'], $workspace->url('/machines'), true, 'Open the machine'));
    expect($integration->fresh()->status)->toBe(Integration::FAILING)
        ->and(ChatPost::query()->latest('id')->first()->state)->toBe(ChatPost::FAILED);
});
