<?php

use App\Enums\Plan;
use App\Models\Activity;
use App\Models\ChatIdentity;
use App\Models\ChatPost;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\Monitor;
use App\Models\TelegramBot;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Integrations\Chat\ChatLinks;
use App\Support\Monitors\MonitorAlerts;
use App\Support\Monitors\MonitorRunner;
use App\Support\Monitors\ProbeResult;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeTelegram;

beforeEach(function () {
    Notification::fake();
    config()->set('app.url', 'https://panel.serveros-test.com');
});

function tgWorkspace(array $attributes = []): Workspace
{
    return Workspace::factory()->onPlan(Plan::Pro)->for(User::factory()->create(['name' => 'Dana']), 'owner')->create(['name' => 'Acme', ...$attributes]);
}

/** A workspace whose bot answers, as it is once its token is saved. */
function tgBot(FakeTelegram $telegram, string $chat = '-1001234567890'): Workspace
{
    $workspace = tgWorkspace();
    test()->actingAs($workspace->owner)->put($workspace->url('/monitor-alerts'), ['email' => false, 'telegram_chat' => $chat, 'telegram_token' => FakeTelegram::TOKEN])->assertSessionHasNoErrors();
    $telegram->calls = [];

    return $workspace->fresh();
}

function tgUpdate(Workspace $workspace, array $update, ?string $secret = null): TestResponse
{
    $bot = TelegramBot::query()->where('workspace_id', $workspace->id)->sole();
    static $id = 1000;
    $id++;

    return test()->postJson('/api/telegram/'.$bot->hook, ['update_id' => $id, ...$update], ['X-Telegram-Bot-Api-Secret-Token' => $secret ?? $bot->secret]);
}

function tgMessage(string $text, int $from = 555, string|int $chat = -1001234567890, string $type = 'supergroup'): array
{
    return ['message' => ['message_id' => 7, 'text' => $text, 'chat' => ['id' => $chat, 'type' => $type], 'from' => ['id' => $from, 'first_name' => 'Dana', 'username' => 'dana']]];
}

function tgPress(string $data, int $from = 555): array
{
    return ['callback_query' => ['id' => 'cb-'.Str::random(6), 'data' => $data, 'from' => ['id' => $from, 'first_name' => 'Dana'], 'message' => ['message_id' => 100, 'chat' => ['id' => -1001234567890]]]];
}

function tgLink(Workspace $workspace, User $user, int $from = 555): void
{
    ChatIdentity::query()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'chat' => 'telegram', 'external_id' => (string) $from, 'external_name' => '@dana']);
}

function tgMachine(Workspace $workspace, array $services = []): Machine
{
    $machine = Machine::factory()->for($workspace)->create(['name' => 'web-1', 'environment' => 'production']);
    $machine->forceFill(['uid' => (string) Str::uuid(), 'last_seen_at' => now(), 'inventory' => ['scanned_at' => now()->getTimestamp(), 'complete' => true, 'listeners' => [], 'services' => $services]])->save();

    return $machine;
}

function tgMonitor(Workspace $workspace, array $overrides = []): Monitor
{
    return $workspace->monitors()->create(['name' => 'Shop', 'type' => 'http', 'url' => 'https://shop.example.com/', 'interval_seconds' => 60, 'timeout_seconds' => 10, 'status' => 'up', 'alerts' => true, ...$overrides]);
}

test('saving the bot token sets its webhook with a secret and lists its commands, and the secret never reaches the page', function () {
    $telegram = FakeTelegram::install();
    $workspace = tgWorkspace();

    $this->actingAs($workspace->owner)->put($workspace->url('/monitor-alerts'), ['email' => true, 'telegram_chat' => '-1001234567890', 'telegram_token' => FakeTelegram::TOKEN])->assertSessionHasNoErrors();

    $bot = TelegramBot::query()->sole();
    expect($telegram->methods())->toBe(['getMe', 'setWebhook', 'setMyCommands'])
        ->and($bot->state)->toBe(TelegramBot::ON)
        ->and($bot->username)->toBe(FakeTelegram::USERNAME)
        ->and($telegram->webhook['url'])->toBe('https://panel.serveros-test.com/api/telegram/'.$bot->hook)
        ->and($telegram->webhook['secret_token'])->toBe($bot->secret)
        ->and(strlen($bot->secret))->toBe(48)
        ->and($telegram->webhook['allowed_updates'])->toBe(['message', 'callback_query'])
        ->and(collect($telegram->commands)->pluck('command')->all())->toBe(['status', 'machine', 'checknow', 'mute', 'link', 'help']);

    $page = $this->actingAs($workspace->owner)->get($workspace->url('/monitors'))
        ->assertInertia(fn (Assert $p) => $p->where('alerts.telegramBot', ['state' => 'on', 'username' => FakeTelegram::USERNAME, 'error' => null]));
    expect($page->getContent())->not->toContain($bot->secret)->not->toContain('fake-telegram-token');

    // Saving again with the same token doesn't call Telegram; removing it takes the webhook off.
    $telegram->calls = [];
    $this->actingAs($workspace->owner)->put($workspace->url('/monitor-alerts'), ['email' => false])->assertSessionHasNoErrors();
    expect($telegram->calls)->toBe([]);
    $this->actingAs($workspace->owner)->put($workspace->url('/monitor-alerts'), ['email' => true, 'telegram_chat' => ''])->assertSessionHasNoErrors();
    expect($telegram->methods())->toBe(['deleteWebhook'])->and(TelegramBot::query()->count())->toBe(0);
});

test('a panel Telegram can’t reach keeps plain alerts and says why, and a refused webhook is shown', function () {
    $telegram = FakeTelegram::install();
    config()->set('app.url', 'https://serveros.test');
    $workspace = tgWorkspace();

    $this->actingAs($workspace->owner)->put($workspace->url('/monitor-alerts'), ['email' => true, 'telegram_chat' => '-1', 'telegram_token' => FakeTelegram::TOKEN])->assertSessionHasNoErrors();
    expect($telegram->calls)->toBe([])
        ->and(TelegramBot::query()->sole())->state->toBe(TelegramBot::UNREACHABLE)
        ->and(TelegramBot::query()->sole()->error)->toContain('public https address');

    config()->set('app.url', 'https://panel.serveros-test.com');
    $telegram->failNext('setWebhook', 400, 'Bad Request: bad webhook: HTTPS url must be provided for webhook');
    $this->actingAs($workspace->owner)->put($workspace->url('/monitor-alerts'), ['email' => true])->assertSessionHasNoErrors();
    expect(TelegramBot::query()->sole())->state->toBe(TelegramBot::FAILED)
        ->and(TelegramBot::query()->sole()->error)->toBe('Telegram can’t send updates to this panel’s address. It needs a public https address.');
});

test('the webhook turns away updates without the right secret, and reads each update once', function () {
    $telegram = FakeTelegram::install();
    $workspace = tgBot($telegram);
    $bot = TelegramBot::query()->sole();

    $this->postJson('/api/telegram/'.$bot->hook, ['update_id' => 1, ...tgMessage('/help')])->assertUnauthorized();
    $this->postJson('/api/telegram/'.$bot->hook, ['update_id' => 1, ...tgMessage('/help')], ['X-Telegram-Bot-Api-Secret-Token' => 'wrong'])->assertUnauthorized();
    $this->postJson('/api/telegram/'.Str::uuid(), ['update_id' => 1, ...tgMessage('/help')], ['X-Telegram-Bot-Api-Secret-Token' => $bot->secret])->assertUnauthorized();
    expect($telegram->calls)->toBe([]);

    $headers = ['X-Telegram-Bot-Api-Secret-Token' => $bot->secret];
    $this->postJson('/api/telegram/'.$bot->hook, ['update_id' => 50, ...tgMessage('/help')], $headers)->assertOk()->assertJson(['ok' => true]);
    $this->postJson('/api/telegram/'.$bot->hook, ['update_id' => 50, ...tgMessage('/help')], $headers)->assertOk()->assertJson(['duplicate' => true]);
    expect($telegram->sent('sendMessage'))->toHaveCount(1)
        ->and($telegram->sent('sendMessage')[0]['text'])->toContain('/checknow <monitor>');
});

test('/status and /machine answer in the alert chat and to linked members, and nobody else', function () {
    $telegram = FakeTelegram::install();
    $workspace = tgBot($telegram);
    tgMachine($workspace, [['key' => 'docker:web', 'name' => 'web', 'status' => 'running', 'details' => ['managed' => 'true']]]);
    $offline = Machine::factory()->for($workspace)->create(['name' => 'db-1']);
    $offline->forceFill(['uid' => (string) Str::uuid(), 'last_seen_at' => now()->subHour()])->save();
    tgMonitor($workspace, ['status' => 'down', 'last_error' => 'Connection refused']);

    tgUpdate($workspace, tgMessage('/status@'.FakeTelegram::USERNAME))->assertOk();
    $reply = $telegram->sent('sendMessage')[0];
    expect($reply['chat_id'])->toBe('-1001234567890')
        ->and($reply['text'])->toContain('Acme: 1 of 2 machines reporting')
        ->and($reply['text'])->toContain('db-1 stopped reporting')
        ->and($reply['text'])->toContain('Shop is down: Connection refused')
        ->and($reply['reply_parameters']['message_id'])->toBe(7);

    $telegram->calls = [];
    tgUpdate($workspace, tgMessage('/machine web-1'))->assertOk();
    expect($telegram->sent('sendMessage')[0]['text'])->toContain('web-1 (production) is reporting.')->toContain('1 service, none down');

    // Another bot's command in the group is left alone.
    $telegram->calls = [];
    tgUpdate($workspace, tgMessage('/status@someone_else_bot'))->assertOk();
    expect($telegram->calls)->toBe([]);

    // A private chat with someone who hasn't linked: no workspace data.
    tgUpdate($workspace, tgMessage('/status', 777, 777, 'private'))->assertOk();
    expect($telegram->sent('sendMessage')[0]['text'])->toContain('doesn’t get alerts from ServerOS')->not->toContain('db-1');

    tgLink($workspace, $workspace->owner, 777);
    $telegram->calls = [];
    tgUpdate($workspace, tgMessage('/status', 777, 777, 'private'))->assertOk();
    expect($telegram->sent('sendMessage')[0]['text'])->toContain('db-1 stopped reporting');
});

test('a one-time code from the profile links a Telegram account, once, and only in its workspace', function () {
    $telegram = FakeTelegram::install();
    $workspace = tgBot($telegram);
    $other = tgBot($telegram);
    $admin = User::factory()->create(['name' => 'Ada']);
    $workspace->members()->attach($admin, ['role' => 'admin']);

    $made = $this->actingAs($admin)->postJson($workspace->url('/settings/chat-accounts/telegram'))->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')->json();
    expect($made['code'])->toMatch('/^[A-Z2-9]{8}$/')
        ->and($made['link'])->toBe('https://t.me/'.FakeTelegram::USERNAME.'?start='.$made['code']);

    tgUpdate($other, tgMessage('/link '.$made['code'], 901))->assertOk();
    expect(ChatIdentity::query()->count())->toBe(0);

    tgUpdate($workspace, tgMessage('/start '.$made['code'], 901, 901, 'private'))->assertOk();
    $identity = ChatIdentity::query()->sole();
    expect($identity->user_id)->toBe($admin->id)
        ->and($identity->external_id)->toBe('901')
        ->and($identity->external_name)->toBe('@dana')
        ->and(collect($telegram->sent('sendMessage'))->last()['text'])->toBe('Linked to Ada in Acme. The buttons on alerts now work for you, as far as your role allows.')
        ->and(Activity::query()->where('action', 'alerts.chat_link')->sole()->user_id)->toBe($admin->id);

    tgUpdate($workspace, tgMessage('/link '.$made['code'], 902))->assertOk();
    expect(ChatIdentity::query()->count())->toBe(1)
        ->and(collect($telegram->sent('sendMessage'))->last()['text'])->toContain('That code didn’t work');

    $this->actingAs($admin)->get($workspace->url('/settings/profile'))
        ->assertInertia(fn (Assert $p) => $p->where('chatAccounts.0.chat', 'telegram')->where('chatAccounts.0.ready', true)->where('chatAccounts.0.linked.name', '@dana'));
    $this->actingAs($admin)->delete($workspace->url('/settings/chat-accounts/telegram'))->assertRedirect();
    expect(ChatIdentity::query()->count())->toBe(0)
        ->and(Activity::query()->where('action', 'alerts.chat_unlink')->exists())->toBeTrue();
});

test('alerts get buttons once the bot answers, and the alert that it’s back edits the same message', function () {
    $telegram = FakeTelegram::install();
    $workspace = tgBot($telegram);
    $monitor = tgMonitor($workspace);

    foreach (range(1, Monitor::CONFIRM_AFTER) as $_) {
        app(MonitorRunner::class)->apply($monitor->fresh('workspace'), ProbeResult::fail('Connection refused'));
    }
    $post = ChatPost::query()->sole();
    $sent = $telegram->sent('sendMessage');
    expect($sent)->toHaveCount(1)
        ->and($sent[0]['text'])->toStartWith('Shop is down: https://shop.example.com/')
        ->and($sent[0]['reply_markup']['inline_keyboard'][0])->toBe([
            ['text' => 'Check now', 'callback_data' => 'check:'.$post->id],
            ['text' => 'Mute 1 hour', 'callback_data' => 'mute:'.$post->id],
        ])
        ->and($sent[0]['reply_markup']['inline_keyboard'][1][0]['url'])->toBe($workspace->url('/monitors/'.$monitor->id))
        ->and($post)->state->toBe(ChatPost::OPEN)->thread->toBe('monitor:'.$monitor->id)->message_id->toBe('100');

    app(MonitorRunner::class)->apply($monitor->fresh('workspace'), new ProbeResult(true, 120, 200));

    expect($telegram->sent('sendMessage'))->toHaveCount(1)
        ->and($telegram->sent('editMessageText'))->toHaveCount(1)
        ->and($telegram->messages[100]['text'])->toStartWith('Shop is back up: https://shop.example.com/')->toContain('Earlier: Shop is down.')
        ->and($telegram->messages[100]['reply_markup']['inline_keyboard'])->toHaveCount(1)
        ->and($post->fresh())->state->toBe(ChatPost::RESOLVED);

    // A message that can't be edited any more: the recovery is posted as a new one.
    foreach (range(1, Monitor::CONFIRM_AFTER) as $_) {
        app(MonitorRunner::class)->apply($monitor->fresh('workspace'), ProbeResult::fail('Connection refused'));
    }
    $telegram->failNext('editMessageText', 400, 'Bad Request: message to edit not found');
    app(MonitorRunner::class)->apply($monitor->fresh('workspace'), new ProbeResult(true, 120, 200));
    expect($telegram->sent('sendMessage'))->toHaveCount(3);
});

test('alert buttons refuse people who haven’t linked or whose role can’t, and run as the linked member', function () {
    $telegram = FakeTelegram::install();
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    Http::fake(['127.0.0.1:8090/*' => Http::response(['queued' => true], 202)]);
    $workspace = tgBot($telegram);
    $machine = tgMachine($workspace, [['key' => 'docker:web', 'name' => 'web', 'status' => 'stopped', 'details' => ['managed' => 'true']]]);
    $monitor = tgMonitor($workspace);
    app(MonitorAlerts::class)->notify($workspace, 'service.down', 'web stopped on web-1', ['It stopped.'], $workspace->url('/machines/'.$machine->id), true, 'Open the service', [
        'machine' => ['id' => $machine->id, 'name' => 'web-1'], 'service' => ['key' => 'docker:web', 'name' => 'web'],
    ]);
    app(MonitorAlerts::class)->notify($workspace, 'monitor.down', 'Shop is down', ['Refused.'], $workspace->url('/monitors/'.$monitor->id), true, 'Open the monitor', [
        'monitor' => ['id' => $monitor->id, 'name' => 'Shop'],
    ]);
    [$restart, $mute] = ChatPost::query()->orderBy('id')->get()->all();
    expect($telegram->sent('sendMessage')[0]['reply_markup']['inline_keyboard'][0])->toBe([['text' => 'Restart web', 'callback_data' => 'restart:'.$restart->id]]);
    $telegram->calls = [];

    tgUpdate($workspace, tgPress('mute:'.$mute->id))->assertOk();
    $answer = $telegram->sent('answerCallbackQuery')[0];
    expect($answer['show_alert'])->toBeTrue()->and($answer['text'])->toContain('Link your account first')
        ->and($monitor->fresh()->muted_until)->toBeNull();

    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);
    tgLink($workspace, $viewer);
    $telegram->calls = [];
    tgUpdate($workspace, tgPress('mute:'.$mute->id))->assertOk();
    expect($telegram->sent('answerCallbackQuery')[0]['text'])->toContain('can’t do that')
        ->and($monitor->fresh()->muted_until)->toBeNull();

    ChatIdentity::query()->delete();
    tgLink($workspace, $workspace->owner);
    $telegram->calls = [];
    tgUpdate($workspace, tgPress('mute:'.$mute->id))->assertOk();
    expect($monitor->fresh()->muted_until?->isFuture())->toBeTrue()
        ->and($telegram->sent('answerCallbackQuery')[0])->show_alert->toBeFalse()->text->toBe('Muted Shop’s alerts for 1 h 0 min. It’s still checked.')
        ->and($telegram->sent('sendMessage')[0]['text'])->toBe('Dana: Muted Shop’s alerts for 1 h 0 min. It’s still checked.');
    $activity = Activity::query()->where('action', 'monitor.mute')->sole();
    expect($activity->user_id)->toBe($workspace->owner->id)->and($activity->meta)->toMatchArray(['minutes' => 60, 'via' => 'telegram']);

    tgUpdate($workspace, tgPress('restart:'.$restart->id))->assertOk();
    $job = DaemonJob::query()->sole();
    expect($job->kind)->toBe('service_action')
        ->and($job->command['job'])->toBe(['type' => 'service_action', 'service' => 'docker:web', 'action' => 'restart'])
        ->and($job->user_id)->toBe($workspace->owner->id)
        ->and(Activity::query()->where('action', 'service.restart')->sole()->meta['via'] ?? null)->toBe('telegram');

    // A button from another workspace's message does nothing.
    $foreign = ChatPost::query()->create(['workspace_id' => tgWorkspace()->id, 'chat' => 'telegram', 'destination' => '-1', 'event' => 'monitor.down', 'subject' => 'x', 'state' => 'open']);
    $telegram->calls = [];
    tgUpdate($workspace, tgPress('mute:'.$foreign->id))->assertOk();
    expect($telegram->sent('answerCallbackQuery')[0]['text'])->toContain('doesn’t work any more');
});

test('/checknow and /mute find the monitor by name and run as the linked member', function () {
    $telegram = FakeTelegram::install();
    $workspace = tgBot($telegram);
    $monitor = tgMonitor($workspace);
    $ran = [];
    $runner = Mockery::mock(MonitorRunner::class);
    $runner->shouldReceive('run')->andReturnUsing(function ($monitors) use (&$ran) {
        foreach ($monitors as $m) {
            $ran[] = $m->id;
            $m->checks()->create(['checked_at' => now(), 'ok' => true, 'response_ms' => 87, 'status_code' => 200]);
        }
    });
    app()->instance(MonitorRunner::class, $runner);

    tgUpdate($workspace, tgMessage('/checknow shop'))->assertOk();
    expect($ran)->toBe([])->and($telegram->sent('sendMessage')[0]['text'])->toContain('Link your account first');

    tgLink($workspace, $workspace->owner);
    $telegram->calls = [];
    tgUpdate($workspace, tgMessage('/checknow shop'))->assertOk();
    expect($ran)->toBe([$monitor->id])
        ->and(array_column($telegram->sent('sendMessage'), 'text'))->toBe(['Checking Shop now.', 'Shop is up (87 ms).']);

    $telegram->calls = [];
    tgUpdate($workspace, tgMessage('/mute Shop 2h'))->assertOk();
    expect($telegram->sent('sendMessage')[0]['text'])->toBe('Muted Shop’s alerts for 2 h 0 min. It’s still checked.')
        ->and((int) round(now()->diffInMinutes($monitor->fresh()->muted_until)))->toBe(120);

    $telegram->calls = [];
    tgUpdate($workspace, tgMessage('/checknow nope'))->assertOk();
    expect($telegram->sent('sendMessage')[0]['text'])->toBe('No monitor called “nope” in Acme.');
});

test('a link code is refused after too many wrong guesses', function () {
    $workspace = tgWorkspace();
    foreach (range(1, 10) as $_) {
        expect(ChatLinks::redeem('telegram', $workspace, 'AAAAAAAA', '42', null))->toBeNull();
    }
    $code = ChatLinks::make($workspace, $workspace->owner, 'telegram')['code'];
    expect(ChatLinks::redeem('telegram', $workspace, $code, '42', null))->toBeNull()
        ->and(ChatLinks::redeem('telegram', $workspace, $code, '43', null)?->id)->toBe($workspace->owner->id);
});
