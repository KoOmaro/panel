<?php

use App\Models\Activity;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\MachineSshAccess;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\SshAccessNotice;
use App\Support\Machines\SshAccess;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

const SAM_KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOa5UVLh9q5PqF8muKTP2DGKLJrZn2qznkNYi2utyZPM sam@laptop';
const SAM_PRINT = 'SHA256:VeLpNV/SxMNZHKMk84vTfBNuN52oogR9Bjc6lnyjnJ4';
const MAYA_KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAID3ubrWIidC40h+ZxYSYIaudOGNpgpQmmMymSHis6Jzp maya@laptop';
const MAYA_PRINT = 'SHA256:/0yqGWgTMB6OHeRdsuaadhD9R0nY0bhZ6qo/LJkfJXU';
const LEO_KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAII0f60yGQh4H30UzqGNBIiq2z8VO+u8wAM/MJTfft7fj';
const LEO_PRINT = 'SHA256:wiUx8hYNQIJ18u9ja8Vh90t7D6aQRssj+8/5ZCXrNMI';

/** @return array{0: Workspace, 1: Machine, 2: string, 3: User, 4: User} */
function sshMachine(bool $online = true): array
{
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    Http::fake(['127.0.0.1:8090/*' => Http::response(['queued' => true], 202)]);
    Notification::fake();

    $workspace = Workspace::factory()->create(['name' => 'Acme']);
    $machine = Machine::factory()->for($workspace)->create(['name' => 'web-1', 'ip_address' => '203.0.113.20']);
    $machine->forceFill([
        'uid' => (string) Str::uuid(),
        'last_seen_at' => $online ? now() : now()->subHour(),
        'facts' => ['hostname' => 'web-1', 'security' => [
            'ssh' => ['ports' => [22], 'permit_root_login' => 'prohibit-password', 'password_authentication' => false, 'kbd_interactive_authentication' => false, 'use_pam' => true, 'source' => 'sshd -T'],
            'logins' => ['window_secs' => 86400, 'failed' => 132, 'sources' => 41, 'root' => 98, 'accepted_password' => 0],
        ]],
    ])->save();

    $member = User::factory()->create(['name' => 'Maya Patel', 'email' => 'maya@acme.dev']);
    $workspace->members()->attach($member, ['role' => 'member']);
    $admin = User::factory()->create(['name' => 'Ada Admin', 'email' => 'ada@acme.dev']);
    $workspace->members()->attach($admin, ['role' => 'admin']);

    return [$workspace, $machine, $workspace->url()."/machines/{$machine->id}", $member, $admin];
}

/** @return list<array<string, mixed>> */
function sshJobsSent(): array
{
    return collect(Http::recorded())->map(fn ($pair) => $pair[0])->filter(fn ($r) => str_ends_with($r->url(), '/commands'))->map(fn ($r) => $r['command']['job'])->values()->all();
}

function sshJobUpdate(Machine $machine, string $jobId, string $state, ?array $error = null): void
{
    test()->withHeaders(['X-Gateway-Secret' => 'test-gateway-secret'])
        ->postJson('/api/gateway/machines/'.$machine->uid.'/ingest', ['envelopes' => [[
            'v' => 1, 'kind' => 'job_update',
            'payload' => array_filter(['job_id' => $jobId, 'state' => $state, 'error' => $error, 'result' => $state === 'succeeded' ? ['keys' => 2] : null]),
        ]]])
        ->assertOk();
}

/** @param  array<string, mixed>  $columns */
function sshRow(Machine $machine, ?User $user, string $key, array $columns = []): MachineSshAccess
{
    $parsed = SshAccess::parseKey($key);
    $row = new MachineSshAccess;
    $row->forceFill([
        'workspace_id' => $machine->workspace_id,
        'machine_id' => $machine->id,
        'user_id' => $user?->id,
        'email' => $user->email ?? 'jordan@northstudio.dev',
        'name' => $user->name ?? 'Jordan Lee',
        'external' => $user === null,
        'login' => 'serveros',
        'public_key' => $key,
        'key_type' => $parsed['type'] ?? null,
        'fingerprint' => $parsed['fingerprint'] ?? null,
        'key_comment' => $parsed['comment'] ?? null,
        'state' => MachineSshAccess::ACTIVE,
        ...$columns,
    ])->save();

    return $row;
}

test('a public key’s fingerprint is the one ssh-keygen shows, and anything else is refused', function () {
    expect(SshAccess::parseKey(MAYA_KEY))->toMatchArray(['type' => 'ssh-ed25519', 'comment' => 'maya@laptop', 'fingerprint' => MAYA_PRINT])
        ->and(SshAccess::parseKey(LEO_KEY))->toMatchArray(['comment' => null, 'fingerprint' => LEO_PRINT])
        ->and(SshAccess::parseKey('ssh-ed25519 notbase64!!'))->toBeNull()
        ->and(SshAccess::parseKey('ssh-dss AAAAB3NzaC1kc3MAAACBAP'))->toBeNull()
        ->and(SshAccess::parseKey('ssh-rsa AAAAC3NzaC1lZDI1NTE5AAAAID3ubrWIidC40h+ZxYSYIaudOGNpgpQmmMymSHis6Jzp'))->toBeNull()
        ->and(SshAccess::parseKey("-----BEGIN OPENSSH PRIVATE KEY-----\nb3BlbnNzaC1rZXktdjEAAAAA"))->toBeNull();
});

test('the Access tab lists the keys ServerOS added, the machine’s SSH settings and failed sign-ins', function () {
    [$workspace, $machine, $url, $member] = sshMachine();
    sshRow($machine, $member, MAYA_KEY, ['added_at' => now()->subHour()]);
    sshRow($machine, null, SAM_KEY, ['expires_at' => now()->addDays(30)]);
    sshRow($machine, $workspace->owner, LEO_KEY, ['state' => MachineSshAccess::REMOVED]);

    $this->actingAs($workspace->owner)->get("{$url}/access")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('workspace/MachineShow')
        ->where('access.canApprove', true)
        ->where('access.login', 'serveros')
        ->where('access.address', '203.0.113.20')
        ->where('access.ssh.passwords', false)
        ->where('access.ssh.rootLogin', 'prohibit-password')
        ->where('access.ssh.ports', [22])
        ->where('access.signIns.failed', 132)
        ->where('access.signIns.root', 98)
        ->where('access.signIns.hours', 24)
        ->has('access.people', 2)
        ->where('access.people.0.name', 'Maya Patel')
        ->where('access.people.0.role', 'Member')
        ->where('access.people.0.fingerprint', MAYA_PRINT)
        ->where('access.people.0.keyType', 'ED25519')
        ->where('access.people.1.external', true)
        ->where('access.people.1.email', 'jordan@northstudio.dev')
        ->has('access.members', 3)
        ->etc());

    $this->actingAs($member)->get("{$url}/access")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('access.canApprove', false)
        ->where('access.members', [])
        ->where('access.people.0.mine', true)
        ->etc());
});

test('an owner gives a member access with their key: the add job goes out and the member hears about it', function () {
    [$workspace, $machine, $url, $member] = sshMachine();

    $this->actingAs($workspace->owner)->post("{$url}/access", [
        'people' => [['userId' => $member->id, 'key' => MAYA_KEY]],
        'login' => 'serveros',
    ])->assertRedirect();

    $access = MachineSshAccess::query()->sole();
    expect($access->state)->toBe(MachineSshAccess::ADDING)
        ->and($access->user_id)->toBe($member->id)
        ->and($access->fingerprint)->toBe(MAYA_PRINT)
        ->and($access->decided_by_id)->toBe($workspace->owner->id)
        ->and($access->expires_at)->toBeNull()
        ->and(sshJobsSent())->toBe([['type' => 'ssh_key', 'user' => 'serveros', 'action' => 'add', 'public_key' => MAYA_KEY]])
        ->and(DaemonJob::query()->find($access->job_id)->kind)->toBe('ssh_key')
        ->and(Activity::query()->where('action', 'machine.ssh_access.give')->sole()->meta)->toMatchArray(['access_id' => $access->id, 'login' => 'serveros', 'fingerprint' => MAYA_PRINT]);
    Notification::assertSentTo($member, SshAccessNotice::class, fn (SshAccessNotice $n) => $n->kind === 'given');
});

test('a member given access without a key adds their own, and only then does the machine change', function () {
    [$workspace, $machine, $url, $member] = sshMachine();

    $this->actingAs($workspace->owner)->post("{$url}/access", ['people' => [['userId' => $member->id]], 'login' => 'serveros'])->assertRedirect();
    $access = MachineSshAccess::query()->sole();
    expect($access->state)->toBe(MachineSshAccess::WAITING_KEY)->and(sshJobsSent())->toBe([]);
    Notification::assertSentTo($member, SshAccessNotice::class, fn (SshAccessNotice $n) => $n->kind === 'waiting_key');

    $this->actingAs($member)->post("{$url}/access/request", ['key' => MAYA_KEY])->assertRedirect()->assertSessionHasNoErrors();

    expect($access->fresh()->state)->toBe(MachineSshAccess::ADDING)
        ->and($access->fresh()->fingerprint)->toBe(MAYA_PRINT)
        ->and(MachineSshAccess::query()->count())->toBe(1)
        ->and(sshJobsSent())->toHaveCount(1)
        ->and(Activity::query()->where('action', 'machine.ssh_access.key')->exists())->toBeTrue();
});

test('someone outside the workspace needs a key pasted for them and an end date, and gets no account or email', function () {
    [$workspace, $machine, $url] = sshMachine();
    $this->actingAs($workspace->owner);

    $this->post("{$url}/access", ['people' => [['email' => 'priya@contractor.io']], 'login' => 'serveros', 'expiresInDays' => 30])
        ->assertSessionHasErrors('people.0.key');
    $this->post("{$url}/access", ['people' => [['email' => 'priya@contractor.io', 'key' => SAM_KEY]], 'login' => 'serveros'])
        ->assertSessionHasErrors('expiresInDays');
    $this->post("{$url}/access", ['people' => [['email' => 'priya@contractor.io', 'key' => SAM_KEY]], 'login' => 'serveros', 'expiresInDays' => 45])
        ->assertSessionHasErrors('expiresInDays');

    $this->post("{$url}/access", ['people' => [['email' => 'Priya@Contractor.io', 'key' => SAM_KEY]], 'login' => 'serveros', 'expiresInDays' => 30])
        ->assertRedirect()->assertSessionHasNoErrors();

    $access = MachineSshAccess::query()->sole();
    expect($access->external)->toBeTrue()
        ->and($access->user_id)->toBeNull()
        ->and($access->email)->toBe('priya@contractor.io')
        ->and($access->expires_at->toDateString())->toBe(now()->addDays(30)->toDateString())
        ->and(User::query()->where('email', 'priya@contractor.io')->exists())->toBeFalse();
    Notification::assertNothingSent();
});

test('a key that isn’t a public key, or already has access, is refused, and so is any login but serveros', function () {
    [$workspace, $machine, $url, $member] = sshMachine();
    sshRow($machine, $member, MAYA_KEY);
    $this->actingAs($workspace->owner);

    $this->post("{$url}/access", ['people' => [['userId' => $workspace->owner->id, 'key' => 'not a key']], 'login' => 'serveros'])->assertSessionHasErrors('people.0.key');
    $this->post("{$url}/access", ['people' => [['userId' => $workspace->owner->id, 'key' => MAYA_KEY]], 'login' => 'serveros'])->assertSessionHasErrors('people.0.key');
    $this->post("{$url}/access", ['people' => [['userId' => $workspace->owner->id, 'key' => SAM_KEY]], 'login' => 'root'])->assertSessionHasErrors('login');
    $this->post("{$url}/access", ['people' => [['userId' => User::factory()->create()->id, 'key' => SAM_KEY]], 'login' => 'serveros'])->assertSessionHasErrors('people.0.userId');

    expect(MachineSshAccess::query()->count())->toBe(1)->and(sshJobsSent())->toBe([]);
});

test('only people who manage members give, approve or turn down access', function () {
    [$workspace, $machine, $url, $member, $admin] = sshMachine();
    $request = sshRow($machine, $admin, SAM_KEY, ['state' => MachineSshAccess::REQUESTED, 'requested_at' => now()]);

    $this->actingAs($member)->post("{$url}/access", ['people' => [['userId' => $member->id, 'key' => MAYA_KEY]], 'login' => 'serveros'])->assertForbidden();
    $this->actingAs($member)->post("{$url}/access/{$request->id}/approve")->assertForbidden();
    $this->actingAs($member)->post("{$url}/access/{$request->id}/decline")->assertForbidden();
    $this->actingAs($member)->delete("{$url}/access")->assertForbidden();
    $this->actingAs($member)->get("{$url}/access/{$request->id}")->assertForbidden();

    $this->actingAs($admin)->post("{$url}/access/{$request->id}/approve")->assertRedirect();
    expect($request->fresh()->state)->toBe(MachineSshAccess::ADDING);
});

test('a member asks, the owners and admins are told, and approving sends the key', function () {
    [$workspace, $machine, $url, $member, $admin] = sshMachine();

    $this->actingAs($member)->post("{$url}/access/request", ['key' => MAYA_KEY, 'note' => 'Checking the queue'])->assertRedirect()->assertSessionHasNoErrors();

    $access = MachineSshAccess::query()->sole();
    expect($access->state)->toBe(MachineSshAccess::REQUESTED)
        ->and($access->note)->toBe('Checking the queue')
        ->and(sshJobsSent())->toBe([])
        ->and(Activity::query()->where('action', 'machine.ssh_access.request')->sole()->user_id)->toBe($member->id);
    Notification::assertSentTo([$workspace->owner, $admin], SshAccessNotice::class, fn (SshAccessNotice $n) => $n->kind === 'requested');
    Notification::assertNotSentTo($member, SshAccessNotice::class);

    $this->actingAs($member)->post("{$url}/access/request", ['key' => SAM_KEY])->assertSessionHasErrors('key');

    $this->actingAs($workspace->owner)->get("{$url}/access/{$access->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('workspace/SshAccessApproval')
        ->where('canDecide', true)
        ->where('access.fingerprint', MAYA_PRINT)
        ->where('access.name', 'Maya Patel')
        ->where('access.note', 'Checking the queue')
        ->where('machine.name', 'web-1'));
    $this->actingAs($member)->get("{$url}/access/{$access->id}")->assertOk()->assertInertia(fn (Assert $page) => $page->where('canDecide', false)->where('access.mine', true));

    $this->actingAs($workspace->owner)->post("{$url}/access/{$access->id}/approve")->assertRedirect();

    expect($access->fresh()->state)->toBe(MachineSshAccess::ADDING)
        ->and($access->fresh()->decided_by_id)->toBe($workspace->owner->id)
        ->and(sshJobsSent())->toBe([['type' => 'ssh_key', 'user' => 'serveros', 'action' => 'add', 'public_key' => MAYA_KEY]])
        ->and(Activity::query()->where('action', 'machine.ssh_access.approve')->exists())->toBeTrue();
    Notification::assertSentTo($member, SshAccessNotice::class, fn (SshAccessNotice $n) => $n->kind === 'approved');

    $this->actingAs($workspace->owner)->post("{$url}/access/{$access->id}/approve")->assertSessionHasErrors('access');
});

test('turning a request down changes nothing on the machine and tells the person', function () {
    [$workspace, $machine, $url, $member] = sshMachine();
    $access = sshRow($machine, $member, MAYA_KEY, ['state' => MachineSshAccess::REQUESTED, 'requested_at' => now()]);

    $this->actingAs($workspace->owner)->post("{$url}/access/{$access->id}/decline")->assertRedirect();

    expect($access->fresh()->state)->toBe(MachineSshAccess::DECLINED)
        ->and(sshJobsSent())->toBe([])
        ->and(Activity::query()->where('action', 'machine.ssh_access.decline')->exists())->toBeTrue();
    Notification::assertSentTo($member, SshAccessNotice::class, fn (SshAccessNotice $n) => $n->kind === 'declined');
});

test('someone who can approve adds their own key straight away', function () {
    [$workspace, $machine, $url] = sshMachine();

    $this->actingAs($workspace->owner)->post("{$url}/access/request", ['key' => SAM_KEY])->assertRedirect()->assertSessionHasNoErrors();

    expect(MachineSshAccess::query()->sole()->state)->toBe(MachineSshAccess::ADDING)
        ->and(sshJobsSent())->toHaveCount(1);
    Notification::assertNothingSent();
});

test('an offline machine can’t take a key, so nothing is added and the reason comes back', function () {
    [$workspace, $machine, $url, $member] = sshMachine(online: false);
    $request = sshRow($machine, $member, MAYA_KEY, ['state' => MachineSshAccess::REQUESTED, 'requested_at' => now()]);
    $this->actingAs($workspace->owner);

    $this->post("{$url}/access", ['people' => [['userId' => $workspace->owner->id, 'key' => SAM_KEY]], 'login' => 'serveros'])->assertSessionHasErrors('access');
    $this->post("{$url}/access/{$request->id}/approve")->assertSessionHasErrors('access');

    expect(MachineSshAccess::query()->count())->toBe(1)
        ->and($request->fresh()->state)->toBe(MachineSshAccess::REQUESTED)
        ->and(sshJobsSent())->toBe([]);
});

test('the machine’s answer makes the key active, or says why it couldn’t be added', function () {
    [$workspace, $machine, $url, $member] = sshMachine();
    $this->actingAs($workspace->owner)->post("{$url}/access", ['people' => [['userId' => $member->id, 'key' => MAYA_KEY], ['userId' => $workspace->owner->id, 'key' => SAM_KEY]], 'login' => 'serveros']);
    [$maya, $sam] = MachineSshAccess::query()->orderBy('id')->get()->all();

    sshJobUpdate($machine, $maya->job_id, 'running');
    expect($maya->fresh()->state)->toBe(MachineSshAccess::ADDING);
    sshJobUpdate($machine, $maya->job_id, 'succeeded');
    sshJobUpdate($machine, $sam->job_id, 'failed', ['phase' => 'ssh', 'message' => 'user serveros does not exist']);

    expect($maya->fresh()->state)->toBe(MachineSshAccess::ACTIVE)
        ->and($maya->fresh()->added_at)->not->toBeNull()
        ->and($sam->fresh()->state)->toBe(MachineSshAccess::FAILED)
        ->and($sam->fresh()->error)->toContain('sudo useradd -m -s /bin/bash serveros')
        ->and(Activity::query()->where('action', 'machine.ssh_access.failed')->sole()->meta['access_id'])->toBe($sam->id);

    $this->post("{$url}/access/{$sam->id}/retry")->assertRedirect();
    expect($sam->fresh()->state)->toBe(MachineSshAccess::ADDING)->and(sshJobsSent())->toHaveCount(3);
});

test('removing someone sends the remove job, and the key is gone once the machine says so', function () {
    [$workspace, $machine, $url, $member] = sshMachine();
    $access = sshRow($machine, $member, MAYA_KEY);

    $this->actingAs($workspace->owner)->delete("{$url}/access/{$access->id}")->assertRedirect();

    expect($access->fresh()->state)->toBe(MachineSshAccess::REMOVING)
        ->and($access->fresh()->removed_by_id)->toBe($workspace->owner->id)
        ->and(sshJobsSent())->toBe([['type' => 'ssh_key', 'user' => 'serveros', 'action' => 'remove', 'public_key' => MAYA_KEY]])
        ->and(Activity::query()->where('action', 'machine.ssh_access.revoke')->exists())->toBeTrue();

    sshJobUpdate($machine, $access->fresh()->job_id, 'succeeded');
    expect($access->fresh()->state)->toBe(MachineSshAccess::REMOVED)->and($access->fresh()->removed_at)->not->toBeNull();

    $this->get("{$url}/access")->assertInertia(fn (Assert $page) => $page->has('access.people', 0)->etc());
});

test('a removal the machine refuses leaves the key listed with why', function () {
    [$workspace, $machine, $url, $member] = sshMachine();
    $access = sshRow($machine, $member, MAYA_KEY);
    $this->actingAs($workspace->owner)->delete("{$url}/access/{$access->id}");

    sshJobUpdate($machine, $access->fresh()->job_id, 'refused', ['phase' => 'policy', 'message' => 'machine is in read-only mode']);

    expect($access->fresh()->state)->toBe(MachineSshAccess::ACTIVE)
        ->and($access->fresh()->error)->toContain('read-only mode');
});

test('people can withdraw their own request or remove their own key, but not someone else’s', function () {
    [$workspace, $machine, $url, $member, $admin] = sshMachine();
    $mine = sshRow($machine, $member, MAYA_KEY, ['state' => MachineSshAccess::REQUESTED, 'requested_at' => now()]);
    $theirs = sshRow($machine, $admin, SAM_KEY);

    $this->actingAs($member)->delete("{$url}/access/{$theirs->id}")->assertForbidden();
    $this->actingAs($member)->delete("{$url}/access/{$mine->id}")->assertRedirect();

    expect($mine->fresh()->state)->toBe(MachineSshAccess::REMOVED)
        ->and(sshJobsSent())->toBe([])
        ->and(Activity::query()->where('action', 'machine.ssh_access.withdraw')->exists())->toBeTrue();
});

test('remove everyone takes off every key ServerOS added and drops waiting requests', function () {
    [$workspace, $machine, $url, $member, $admin] = sshMachine();
    $active = sshRow($machine, $member, MAYA_KEY);
    $waiting = sshRow($machine, $admin, SAM_KEY, ['state' => MachineSshAccess::REQUESTED]);

    $this->actingAs($workspace->owner)->delete("{$url}/access")->assertRedirect();

    expect($active->fresh()->state)->toBe(MachineSshAccess::REMOVING)
        ->and($waiting->fresh()->state)->toBe(MachineSshAccess::REMOVED)
        ->and(sshJobsSent())->toHaveCount(1)
        ->and(Activity::query()->where('action', 'machine.ssh_access.revoke_all')->sole()->meta['count'])->toBe(2);
});

test('access ends on its date and when someone leaves the workspace', function () {
    [$workspace, $machine, , $member, $admin] = sshMachine();
    $expired = sshRow($machine, null, SAM_KEY, ['expires_at' => now()->subMinute()]);
    $later = sshRow($machine, null, LEO_KEY, ['expires_at' => now()->addDay()]);
    $askedTooLate = sshRow($machine, $admin, LEO_KEY, ['state' => MachineSshAccess::REQUESTED, 'login' => 'serveros', 'expires_at' => now()->subMinute()]);
    $leaver = sshRow($machine, $member, MAYA_KEY);
    $workspace->members()->detach($member);

    $this->artisan('ssh-access:end')->assertSuccessful();

    expect($expired->fresh()->state)->toBe(MachineSshAccess::REMOVING)
        ->and($later->fresh()->state)->toBe(MachineSshAccess::ACTIVE)
        ->and($askedTooLate->fresh()->state)->toBe(MachineSshAccess::REMOVED)
        ->and($leaver->fresh()->state)->toBe(MachineSshAccess::REMOVING)
        ->and(collect(sshJobsSent())->pluck('public_key')->all())->toBe([SAM_KEY, MAYA_KEY])
        ->and(collect(sshJobsSent())->pluck('action')->unique()->all())->toBe(['remove'])
        ->and(Activity::query()->where('action', 'machine.ssh_access.end')->pluck('meta')->pluck('reason')->sort()->values()->all())->toBe(['expired', 'expired', 'left']);

    $this->artisan('ssh-access:end')->assertSuccessful();
    expect(sshJobsSent())->toHaveCount(2);
});

test('expired keys on an offline machine wait for it to come back', function () {
    [, $machine] = sshMachine(online: false);
    $expired = sshRow($machine, null, SAM_KEY, ['expires_at' => now()->subMinute()]);

    $this->artisan('ssh-access:end')->assertSuccessful();

    expect($expired->fresh()->state)->toBe(MachineSshAccess::ACTIVE)
        ->and($expired->fresh()->error)->toContain('offline')
        ->and(Activity::query()->where('action', 'machine.ssh_access.end')->exists())->toBeFalse();
});

test('a machine that never answers stops being waited on', function () {
    [, $machine, , $member] = sshMachine();
    $job = DaemonJob::query()->create(['id' => (string) Str::uuid(), 'machine_id' => $machine->id, 'kind' => 'ssh_key', 'command' => [], 'state' => 'queued', 'log' => []]);
    $access = sshRow($machine, $member, MAYA_KEY, ['state' => MachineSshAccess::ADDING, 'job_id' => $job->id]);
    DaemonJob::query()->whereKey($job->id)->update(['updated_at' => now()->subMinutes(20)]);
    MachineSshAccess::query()->whereKey($access->id)->update(['updated_at' => now()->subMinutes(20)]);

    $this->artisan('ssh-access:end')->assertSuccessful();

    expect($access->fresh()->state)->toBe(MachineSshAccess::FAILED)->and($access->fresh()->error)->toContain('didn’t answer');
});

test('another workspace’s access can’t be seen or changed', function () {
    [$workspace, $machine, $url, $member] = sshMachine();
    $other = Workspace::factory()->create();
    $otherMachine = Machine::factory()->for($other)->create();
    $foreign = sshRow($otherMachine, null, SAM_KEY, ['state' => MachineSshAccess::REQUESTED]);

    $this->actingAs($workspace->owner)->get("{$url}/access/{$foreign->id}")->assertNotFound();
    $this->actingAs($workspace->owner)->post("{$url}/access/{$foreign->id}/approve")->assertNotFound();
    $this->actingAs($workspace->owner)->delete("{$url}/access/{$foreign->id}")->assertNotFound();
    expect($foreign->fresh()->state)->toBe(MachineSshAccess::REQUESTED);
});
