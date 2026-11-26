<?php

use App\Enums\Plan;
use App\Models\Machine;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Extensions\Extensions;
use App\Support\Extensions\UpgradeLink;
use Inertia\Testing\AssertableInertia as Assert;

function guideUrl(Workspace $workspace, string $path = '/'): string
{
    return $workspace->url().$path;
}

/** @return array<string, bool> */
function guideSteps(array $guide): array
{
    return collect($guide['groups'])->flatMap(fn (array $group) => collect($group['steps'])->pluck('done', 'key'))->all();
}

test('a new workspace gets the guide on every page, with the plan locked', function () {
    $workspace = Workspace::factory()->create();
    app(Extensions::class)->setUpgradeLink(new UpgradeLink(label: 'Billing', route: 'workspace.settings.general'));

    $this->actingAs($workspace->owner)
        ->get(guideUrl($workspace, '/machines'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('setupGuide.percent', 0)
            ->where('setupGuide.groups.3.key', 'plan')
            ->where('setupGuide.groups.3.locked', 'Unlocks once a machine is connected')
            ->where('setupGuide.groups.0.locked', null));
});

test('steps tick off as the workspace fills in', function () {
    $workspace = Workspace::factory()->create();
    app(Extensions::class)->setUpgradeLink(new UpgradeLink(label: 'Billing', route: 'workspace.settings.general'));
    Machine::factory()->for($workspace)->create(['services_count' => 2, 'last_backup_at' => null,
        'inventory' => ['services' => [['name' => 'nginx', 'details' => ['managed' => 'true']], ['name' => 'redis', 'details' => []]]]]);
    $workspace->members()->attach(User::factory()->create(), ['role' => 'admin']);

    $this->actingAs($workspace->owner)
        ->get(guideUrl($workspace))
        ->assertInertia(function (Assert $page) {
            $guide = $page->toArray()['props']['setupGuide'];

            expect(guideSteps($guide))->toMatchArray([
                'machine' => true,
                'service' => true,
                'monitor' => false,
                'backups' => false,
                'members' => true,
                'security' => false,
                'plan' => false,
            ])->and($guide['percent'])->toBe(42)
                ->and($guide['groups'][3]['locked'])->toBeNull();
        });
});

test('hiding the guide puts it away for that person only', function () {
    $workspace = Workspace::factory()->create();
    $teammate = User::factory()->create();
    $workspace->members()->attach($teammate, ['role' => 'admin']);

    $this->actingAs($workspace->owner)->post(guideUrl($workspace, '/onboarding/dismiss'));

    $this->actingAs($workspace->owner)
        ->get(guideUrl($workspace))
        ->assertInertia(fn (Assert $page) => $page->where('setupGuide', null));
    $this->actingAs($teammate)
        ->get(guideUrl($workspace))
        ->assertInertia(fn (Assert $page) => $page->whereNot('setupGuide', null));
});

test('the sidebar keeps the guide while steps are left, even once it is hidden', function () {
    $workspace = Workspace::factory()->create();

    $this->actingAs($workspace->owner)->post(guideUrl($workspace, '/onboarding/dismiss'));

    $this->actingAs($workspace->owner)
        ->get(guideUrl($workspace))
        ->assertInertia(fn (Assert $page) => $page->where('setupGuide', null)->where('setupStepsLeft', fn (int $left) => $left > 0));
});

test('the sidebar drops the guide once every step is done', function () {
    $owner = User::factory()->withTwoFactor()->create();
    $workspace = Workspace::factory()->for($owner, 'owner')->create(['plan' => Plan::Pro]);
    Machine::factory()->for($workspace)->create(['last_backup_at' => now(),
        'inventory' => ['services' => [['name' => 'nginx', 'details' => ['managed' => 'true']]]]]);
    $workspace->monitors()->create(['name' => 'Shop', 'type' => 'http', 'url' => 'https://example.com/', 'status' => 'up', 'interval_seconds' => 60, 'timeout_seconds' => 10]);
    $workspace->members()->attach(User::factory()->create(), ['role' => 'admin']);

    $this->actingAs($owner)
        ->get(guideUrl($workspace))
        ->assertInertia(fn (Assert $page) => $page->where('setupGuide', null)->where('setupStepsLeft', 0));
});

test('pages outside a workspace have no guide', function () {
    $this->actingAs(User::factory()->create())
        ->get('/settings/profile')
        ->assertInertia(fn (Assert $page) => $page->where('setupGuide', null));
});

test('the setup guide page shows the same steps the sidebar counts', function () {
    $workspace = Workspace::factory()->create();
    app(Extensions::class)->setUpgradeLink(new UpgradeLink(label: 'Billing', route: 'workspace.settings.general'));
    Machine::factory()->for($workspace)->create(['last_backup_at' => null]);

    $this->actingAs($workspace->owner)
        ->get(guideUrl($workspace, '/setup'))
        ->assertOk()
        ->assertInertia(function (Assert $page) {
            $props = $page->toArray()['props'];

            expect($props['guide'])->toBe($props['setupGuide'])
                ->and($props['hidden'])->toBeFalse()
                ->and(collect($props['guide']['groups'])->pluck('key')->all())->toBe(['connect', 'safe', 'team', 'plan'])
                ->and(collect(guideSteps($props['guide']))->reject()->count())->toBe(6);
        });
});

test('the setup guide page keeps the steps after the guide is hidden', function () {
    $workspace = Workspace::factory()->create();

    $this->actingAs($workspace->owner)->post(guideUrl($workspace, '/onboarding/dismiss'));

    $this->actingAs($workspace->owner)
        ->get(guideUrl($workspace, '/setup'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('workspace/SetupGuide')
            ->where('setupGuide', null)
            ->where('hidden', true)
            ->where('guide.percent', 0)
            ->where('guide.groups.0.steps.0.key', 'machine'));
});

test('step links stay inside the workspace', function () {
    $workspace = Workspace::factory()->create();

    $this->actingAs($workspace->owner)
        ->get(guideUrl($workspace, '/setup'))
        ->assertInertia(function (Assert $page) use ($workspace) {
            $hrefs = collect($page->toArray()['props']['guide']['groups'])->flatMap(fn (array $group) => collect($group['steps'])->pluck('href', 'key'));

            expect($hrefs->all())->toMatchArray([
                'machine' => $workspace->url('machines'),
                'service' => $workspace->url('services'),
                'monitor' => $workspace->url('monitors'),
                'backups' => $workspace->url('backups'),
                'members' => $workspace->url('settings/members'),
                'security' => $workspace->url('settings/security'),
            ]);
        });
});

test('done steps say what got done', function () {
    $workspace = Workspace::factory()->create();
    Machine::factory()->for($workspace)->count(2)->create(['provider' => 'Hetzner', 'last_backup_at' => now()->subHours(3)]);
    Machine::factory()->for($workspace)->create(['provider' => 'Vultr', 'last_backup_at' => null]);
    foreach (['Shop', 'API'] as $name) {
        $workspace->monitors()->create(['name' => $name, 'type' => 'http', 'url' => 'https://example.com/', 'status' => 'up', 'interval_seconds' => 60, 'timeout_seconds' => 10]);
    }
    $workspace->members()->attach(User::factory()->create(['name' => 'Maya Patel']), ['role' => 'admin']);

    $this->actingAs($workspace->owner)
        ->get(guideUrl($workspace, '/setup'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('results.machine.summary', '3 machines connected')
            ->where('results.machine.logos', [['name' => 'Hetzner', 'icon' => null], ['name' => 'Vultr', 'icon' => null]])
            ->where('results.monitor.summary', '2 monitors, checked every minute')
            ->where('results.backups.summary', 'Last backup 3 hours ago')
            ->where('results.members.summary', '2 members')
            ->has('results.members.people', 2)
            ->where('results.security.summary', null)
            ->where('discordUrl', config('serveros.brand.discord_url')));
});
