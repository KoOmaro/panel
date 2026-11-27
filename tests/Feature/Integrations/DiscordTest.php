<?php

use App\Enums\Plan;
use App\Models\Activity;
use App\Models\ChatIdentity;
use App\Models\ChatPost;
use App\Models\DaemonJob;
use App\Models\Integration;
use App\Models\Machine;
use App\Models\Monitor;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Health\JobAlerts;
use App\Support\Integrations\Chat\ChatLinks;
use App\Support\Integrations\Discord\DiscordClient;
use App\Support\Integrations\Discord\DiscordSignature;
use App\Support\Integrations\WorkspaceIntegrations;
use App\Support\Monitors\MonitorAlerts;
use App\Support\Monitors\MonitorRunner;
use App\Support\Monitors\ProbeResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeDiscord;

beforeEach(function () {
    Notification::fake();
    config()->set('app.url', 'https://panel.serveros-test.com');
});

function dcWorkspace(): Workspace
{
    return Workspace::factory()->onPlan(Plan::Pro)->for(User::factory()->create(['name' => 'Dana']), 'owner')->create(['name' => 'Acme']);
}

function dcAccount(Workspace $workspace, array $settings = ['alerts' => FakeDiscord::ALERTS, 'alerts_name' => 'alerts']): Integration
{
    $credentials = ['guild_id' => FakeDiscord::GUILD];

    return $workspace->integrations()->create([
        'provider' => 'discord',
        'label' => 'Acme ops',
        'credentials' => $credentials,
        'fingerprint' => Integration::fingerprintOf('discord', $credentials),
        'meta' => ['guild' => 'Acme ops', 'commands' => 6],
        'settings' => $settings,
        'status' => Integration::CONNECTED,
        'last_checked_at' => now(),
    ]);
}

function dcInteract(FakeDiscord $discord, array $interaction): TestResponse
{
    $body = (string) json_encode(['id' => (string) Str::uuid(), 'application_id' => FakeDiscord::APP, 'token' => 'fake-interaction-token', 'version' => 1, ...$interaction]);
    $headers = [...$discord->sign($body), 'CONTENT_TYPE' => 'application/json'];

    return test()->call('POST', '/api/discord/interactions', [], [], [], collect($headers)->mapWithKeys(fn ($v, $k) => [$k === 'CONTENT_TYPE' ? $k : 'HTTP_'.strtoupper(str_replace('-', '_', $k)) => $v])->all(), $body);
}

function dcCommand(string $name, array $options = [], string $user = '9001', string $channel = FakeDiscord::ALERTS): array
{
    return ['type' => 2, 'guild_id' => FakeDiscord::GUILD, 'channel_id' => $channel, 'member' => ['user' => ['id' => $user, 'username' => 'dana', 'global_name' => 'Dana L']],
        'data' => ['name' => $name, 'type' => 1, 'options' => array_map(fn ($k, $v) => ['name' => $k, 'value' => $v, 'type' => is_int($v) ? 4 : 3], array_keys($options), $options)]];
}

function dcButton(string $customId, string $user = '9001'): array
{
    return ['type' => 3, 'guild_id' => FakeDiscord::GUILD, 'channel_id' => FakeDiscord::ALERTS, 'member' => ['user' => ['id' => $user, 'username' => 'dana']],
        'data' => ['custom_id' => $customId, 'component_type' => 2], 'message' => ['id' => '1']];
}

test('without a Discord app on this panel the row keeps webhook alerts and says so', function () {
    config()->set('services.discord.application_id', null);
    $workspace = dcWorkspace();
    $workspace->forceFill(['alert_webhook_url' => 'https://discord.com/api/webhooks/1/x'])->save();

    $items = collect(app(WorkspaceIntegrations::class)->props($workspace, $workspace->owner)['integrations'])->keyBy('key');
    expect($items['discord']['connected'])->toBeTrue()
        ->and($items['discord']['action'])->toBe(['label' => 'Manage', 'alerts' => true])
        ->and($items['discord']['note'])->toBe('Alerts go through a Discord webhook here. Buttons and commands need a Discord app set up on this ServerOS install.');

    $this->actingAs($workspace->owner)->get($workspace->url('/integrations/discord/install'))->assertNotFound();
    $this->postJson('/api/discord/interactions', ['type' => 1])->assertNotFound();
});

test('connecting adds the bot through Discord’s invite, proves the server with the code, and registers its commands', function () {
    $discord = FakeDiscord::install();
    $workspace = dcWorkspace();

    $items = collect(app(WorkspaceIntegrations::class)->props($workspace, $workspace->owner)['integrations'])->keyBy('key');
    expect($items['discord']['action'])->toBe(['label' => 'Connect', 'href' => '/integrations/discord/install'])->and($items['discord']['note'])->toBeNull();

    $go = $this->actingAs($workspace->owner)->get($workspace->url('/integrations/discord/install'));
    $go->assertRedirect();
    $url = (string) $go->headers->get('Location');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    expect($url)->toStartWith('https://discord.com/oauth2/authorize?')
        ->and($query)->toMatchArray(['client_id' => FakeDiscord::APP, 'scope' => 'bot applications.commands', 'permissions' => '84992', 'response_type' => 'code', 'integration_type' => '0', 'redirect_uri' => 'https://panel.serveros-test.com/discord/callback'])
        ->and($url)->not->toContain(FakeDiscord::SECRET)->not->toContain(FakeDiscord::BOT_TOKEN);

    // A state from somewhere else, or a code Discord doesn't know, connects nothing.
    $this->actingAs($workspace->owner)->get('/discord/callback?code='.FakeDiscord::CODE.'&state=forged&guild_id='.FakeDiscord::GUILD)->assertRedirect(route('workspaces.index'));
    $this->actingAs($workspace->owner)->get($workspace->url('/integrations/discord/install'));
    $state = session('discord_connect_state');
    $this->actingAs($workspace->owner)->get('/discord/callback?code=wrong&state='.urlencode($state))->assertRedirect($workspace->url('/integrations'));
    expect(Integration::query()->count())->toBe(0);

    $this->actingAs($workspace->owner)->get($workspace->url('/integrations/discord/install'));
    $state = session('discord_connect_state');
    $back = $this->actingAs($workspace->owner)->get('/discord/callback?code='.FakeDiscord::CODE.'&state='.urlencode($state).'&guild_id=999&permissions=84992');

    $integration = Integration::query()->sole();
    $back->assertRedirect($workspace->url('/integrations/'.$integration->id));
    expect($integration)->label->toBe('Acme ops')->provider->toBe('discord')
        ->and($integration->credential('guild_id'))->toBe(FakeDiscord::GUILD)
        ->and($discord->called('POST', '#^oauth2/token/revoke$#'))->toHaveCount(1)
        ->and(collect($discord->commands[FakeDiscord::GUILD])->pluck('name')->all())->toBe(['status', 'machine', 'checknow', 'mute', 'link', 'help'])
        ->and($discord->commands[FakeDiscord::GUILD])->toBe(DiscordClient::COMMANDS)
        ->and($integration->meta['commands'])->toBe(6)
        ->and(Activity::query()->where('action', 'integration.connect')->sole()->meta)->toMatchArray(['provider' => 'Discord']);

    // The same server can't be connected by a second workspace.
    $other = dcWorkspace();
    $this->actingAs($other->owner)->get($other->url('/integrations/discord/install'));
    $this->actingAs($other->owner)->get('/discord/callback?code='.FakeDiscord::CODE.'&state='.urlencode(session('discord_connect_state')))->assertRedirect($other->url('/integrations'));
    expect(Integration::query()->count())->toBe(1);
});

test('the account page lists the server’s channels, and the picked ones must be in the server', function () {
    $discord = FakeDiscord::install();
    $workspace = dcWorkspace();
    $integration = dcAccount($workspace, []);

    $this->actingAs($workspace->owner)->get($workspace->url('/integrations/'.$integration->id))
        ->assertInertia(fn (Assert $p) => $p->component('workspace/IntegrationShow')
            ->loadDeferredProps(fn (Assert $p) => $p
                ->where('account.channels', [['id' => FakeDiscord::ALERTS, 'name' => 'alerts'], ['id' => FakeDiscord::DEPLOYS, 'name' => 'deploys']])
                ->where('account.alerts', null)
                ->where('account.commands', true)));

    $url = $workspace->url('/integrations/'.$integration->id.'/discord');
    $this->actingAs($workspace->owner)->put($url, ['alerts' => '1399999999999999999'])->assertSessionHasErrors('alerts');
    $this->actingAs($workspace->owner)->put($url, ['alerts' => FakeDiscord::ALERTS, 'deploys' => FakeDiscord::DEPLOYS])->assertSessionHasNoErrors();
    expect($integration->fresh()->settings)->toMatchArray(['alerts' => FakeDiscord::ALERTS, 'alerts_name' => 'alerts', 'deploys' => FakeDiscord::DEPLOYS, 'deploys_name' => 'deploys'])
        ->and(Activity::query()->where('action', 'integration.routing')->count())->toBe(1);

    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);
    $this->actingAs($viewer)->put($url, ['alerts' => null])->assertForbidden();

    $this->actingAs($workspace->owner)->postJson($workspace->url('/integrations/'.$integration->id.'/discord/test'))->assertOk()->assertJson(['ok' => true]);
    expect(collect($discord->messages)->last()['channel'])->toBe(FakeDiscord::ALERTS);
});

test('interactions are checked against the app’s Ed25519 key before anything is read', function () {
    $discord = FakeDiscord::install();
    $body = '{"type":1}';

    $this->call('POST', '/api/discord/interactions', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body)->assertUnauthorized();
    $headers = $discord->sign($body);
    $this->call('POST', '/api/discord/interactions', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SIGNATURE_ED25519' => $headers['X-Signature-Ed25519'], 'HTTP_X_SIGNATURE_TIMESTAMP' => $headers['X-Signature-Timestamp']], '{"type":1 }')->assertUnauthorized();
    $this->call('POST', '/api/discord/interactions', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SIGNATURE_ED25519' => $headers['X-Signature-Ed25519'], 'HTTP_X_SIGNATURE_TIMESTAMP' => '1'], $body)->assertUnauthorized();
    $this->call('POST', '/api/discord/interactions', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SIGNATURE_ED25519' => $headers['X-Signature-Ed25519'], 'HTTP_X_SIGNATURE_TIMESTAMP' => $headers['X-Signature-Timestamp']], $body)
        ->assertOk()->assertExactJson(['type' => 1]);

    $other = sodium_crypto_sign_keypair();
    $forged = bin2hex(sodium_crypto_sign_detached('1'.$body, sodium_crypto_sign_secretkey($other)));
    expect(DiscordSignature::valid($discord->publicKey, $forged, '1', $body))->toBeFalse()
        ->and(DiscordSignature::valid($discord->publicKey, 'not-hex', '1', $body))->toBeFalse()
        ->and(DiscordSignature::valid('short', $forged, '1', $body))->toBeFalse()
        ->and($discord->calls)->toBe([]);
});

test('slash commands read for the alerts channel and linked members, and /link ties the Discord account', function () {
    $discord = FakeDiscord::install();
    $workspace = dcWorkspace();
    dcAccount($workspace);
    $machine = Machine::factory()->for($workspace)->create(['name' => 'web-1', 'environment' => 'production']);
    $machine->forceFill(['uid' => (string) Str::uuid(), 'last_seen_at' => now()])->save();

    $status = dcInteract($discord, dcCommand('status'))->assertOk()->json();
    expect($status['type'])->toBe(4)->and($status['data']['flags'])->toBe(64)
        ->and($status['data']['content'])->toContain('All good in Acme: 1 machine reporting');

    expect(dcInteract($discord, dcCommand('status', [], '9001', '1300000000000000002'))->json('data.content'))->toContain('Link your account first');

    $code = ChatLinks::make($workspace, $workspace->owner, 'discord')['code'];
    expect(dcInteract($discord, dcCommand('link', ['code' => $code], '9001', '1300000000000000002'))->json('data.content'))->toBe('Linked to Dana in Acme. The buttons on alerts now work for you, as far as your role allows.')
        ->and(ChatIdentity::query()->sole())->external_id->toBe('9001')->external_name->toBe('Dana L')->chat->toBe('discord')
        ->and(dcInteract($discord, dcCommand('machine', ['name' => 'web-1'], '9001', '1300000000000000002'))->json('data.content'))->toContain('web-1 (production) is reporting.');

    // Another server's interactions don't reach this workspace.
    $stranger = dcCommand('status');
    $stranger['guild_id'] = '1299999999999999999';
    expect(dcInteract($discord, $stranger)->json('data.content'))->toContain('isn’t connected to ServerOS');
});

test('alerts post to the picked channel with buttons, the buttons check permissions, and the recovery edits the alert', function () {
    $discord = FakeDiscord::install();
    $workspace = dcWorkspace();
    $integration = dcAccount($workspace);
    $monitor = $workspace->monitors()->create(['name' => 'Shop', 'type' => 'http', 'url' => 'https://shop.example.com/', 'interval_seconds' => 60, 'timeout_seconds' => 10, 'status' => 'up', 'alerts' => true]);

    foreach (range(1, Monitor::CONFIRM_AFTER) as $_) {
        app(MonitorRunner::class)->apply($monitor->fresh('workspace'), ProbeResult::fail('Connection refused'));
    }
    $post = ChatPost::query()->sole();
    [$id, $message] = [array_key_first($discord->messages), $discord->messages[array_key_first($discord->messages)]];
    expect($message['channel'])->toBe(FakeDiscord::ALERTS)
        ->and($message['body']['embeds'][0])->toMatchArray(['title' => 'Shop is down', 'color' => 0xE5484D])
        ->and($message['body']['allowed_mentions'])->toBe(['parse' => []])
        ->and($message['body']['components'][0]['components'])->toBe([
            ['type' => 2, 'style' => 1, 'label' => 'Check now', 'custom_id' => 'serveros:check:'.$post->id],
            ['type' => 2, 'style' => 2, 'label' => 'Mute 1 hour', 'custom_id' => 'serveros:mute:'.$post->id],
            ['type' => 2, 'style' => 5, 'label' => 'Open the monitor', 'url' => $workspace->url('/monitors/'.$monitor->id)],
        ])
        ->and($post)->integration_id->toBe($integration->id)->message_id->toBe((string) $id);

    expect(dcInteract($discord, dcButton('serveros:mute:'.$post->id))->json('data'))->toMatchArray(['flags' => 64])
        ->and($monitor->fresh()->muted_until)->toBeNull();

    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);
    ChatIdentity::query()->create(['workspace_id' => $workspace->id, 'user_id' => $viewer->id, 'chat' => 'discord', 'external_id' => '9002']);
    expect(dcInteract($discord, dcButton('serveros:mute:'.$post->id, '9002'))->json('data.content'))->toContain('can’t do that')
        ->and($monitor->fresh()->muted_until)->toBeNull();

    ChatIdentity::query()->create(['workspace_id' => $workspace->id, 'user_id' => $workspace->owner->id, 'chat' => 'discord', 'external_id' => '9001']);
    $muted = dcInteract($discord, dcButton('serveros:mute:'.$post->id))->json();
    expect($muted['data'])->not->toHaveKey('flags')
        ->and($muted['data']['content'])->toBe('Dana: Muted Shop’s alerts for 1 h 0 min. It’s still checked.')
        ->and($monitor->fresh()->muted_until?->isFuture())->toBeTrue()
        ->and(Activity::query()->where('action', 'monitor.mute')->sole()->meta)->toMatchArray(['via' => 'discord']);

    app(MonitorRunner::class)->apply($monitor->fresh('workspace'), new ProbeResult(true, 120, 200));
    expect($discord->called('POST', '#^channels/\d+/messages$#'))->toHaveCount(1)
        ->and($discord->called('PATCH', '#^channels/'.FakeDiscord::ALERTS.'/messages/'.$id.'$#'))->toHaveCount(1)
        ->and($discord->messages[$id]['body']['embeds'][0])->toMatchArray(['title' => 'Shop is back up', 'color' => 0x30A46C])
        ->and($discord->messages[$id]['body']['embeds'][0]['description'])->toContain('Earlier: Shop is down.')
        ->and(collect($discord->messages[$id]['body']['components'][0]['components'])->pluck('style')->all())->toBe([5])
        ->and($post->fresh()->state)->toBe(ChatPost::RESOLVED);
});

test('deploys go to the deploys channel, and a server that removed the bot is kept as it was', function () {
    $discord = FakeDiscord::install();
    $workspace = dcWorkspace();
    dcAccount($workspace, ['alerts' => FakeDiscord::ALERTS, 'alerts_name' => 'alerts', 'deploys' => FakeDiscord::DEPLOYS, 'deploys_name' => 'deploys']);
    $machine = Machine::factory()->for($workspace)->create(['name' => 'web-1']);
    $job = DaemonJob::query()->create(['id' => (string) Str::uuid(), 'machine_id' => $machine->id, 'kind' => 'deploy', 'service' => 'shop', 'state' => 'succeeded', 'command' => [], 'log' => []]);

    app(JobAlerts::class)->deployFinished($job);
    $message = collect($discord->messages)->sole();
    expect($message['channel'])->toBe(FakeDiscord::DEPLOYS)
        ->and($message['body']['embeds'][0])->toMatchArray(['title' => 'shop on web-1 deployed', 'color' => 0x30A46C]);

    $failed = DaemonJob::query()->create(['id' => (string) Str::uuid(), 'machine_id' => $machine->id, 'kind' => 'deploy', 'service' => 'shop', 'state' => 'failed', 'command' => [], 'log' => [], 'error' => ['message' => 'Build failed']]);
    app(JobAlerts::class)->deployFinished($failed);
    expect(collect($discord->messages)->pluck('channel')->all())->toBe([FakeDiscord::DEPLOYS, FakeDiscord::DEPLOYS])
        ->and(collect($discord->messages)->last()['body']['embeds'][0]['title'])->toBe('shop on web-1 failed to deploy');

    $discord->failNext('POST channels/'.FakeDiscord::ALERTS.'/messages', 403, ['message' => 'Missing Permissions', 'code' => 50013]);
    app(MonitorAlerts::class)->notify($workspace, 'backup.failed', 'db couldn’t be backed up', ['Disk full.'], $workspace->url('/backups'), true, 'Open backups');
    expect(ChatPost::query()->latest('id')->first())->state->toBe(ChatPost::FAILED)
        ->error->toBe('The bot isn’t allowed to post there. In Discord, give it View Channel, Send Messages and Embed Links in that channel.');
});

test('disconnecting takes the commands off, leaves the server and unlinks members', function () {
    $discord = FakeDiscord::install();
    $workspace = dcWorkspace();
    $integration = dcAccount($workspace);
    ChatIdentity::query()->create(['workspace_id' => $workspace->id, 'user_id' => $workspace->owner->id, 'chat' => 'discord', 'external_id' => '9001']);

    $this->actingAs($workspace->owner)->delete($workspace->url('/integrations/'.$integration->id), ['confirm' => 'Acme ops'])->assertRedirect($workspace->url('/integrations'));

    expect(Integration::query()->count())->toBe(0)
        ->and(ChatIdentity::query()->count())->toBe(0)
        ->and($discord->called('PUT', '#/guilds/'.FakeDiscord::GUILD.'/commands$#')[0]['body'])->toBe([])
        ->and($discord->called('DELETE', '#^users/@me/guilds/'.FakeDiscord::GUILD.'$#'))->toHaveCount(1)
        ->and(DB::table('integrations')->count())->toBe(0);
});

test('Check again registers the commands again and marks a server that removed the bot as failing', function () {
    $discord = FakeDiscord::install();
    $workspace = dcWorkspace();
    $integration = dcAccount($workspace);

    $this->actingAs($workspace->owner)->post($workspace->url('/integrations/'.$integration->id.'/check'))->assertRedirect();
    expect($discord->called('PUT', '#/guilds/'.FakeDiscord::GUILD.'/commands$#'))->toHaveCount(1);

    $discord->inGuild = false;
    $this->actingAs($workspace->owner)->post($workspace->url('/integrations/'.$integration->id.'/check'))->assertRedirect();
    expect($integration->fresh()->status)->toBe(Integration::FAILING);
});
