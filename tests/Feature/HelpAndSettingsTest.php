<?php

use App\Models\User;
use App\Models\Workspace;
use App\Support\Extensions\Extensions;
use App\Support\Extensions\SettingsItem;
use Illuminate\Http\Request;
use Inertia\Testing\AssertableInertia as Assert;

test('Help hands a package’s props to the page, for the workspace and the person', function () {
    app(Extensions::class)->addPageProps('workspace/Help', fn (Request $request, ?Workspace $workspace) => [
        'helpDesk' => "{$workspace?->slug} for {$request->user()?->name}",
    ]);
    $workspace = Workspace::factory()->create(['slug' => 'northwind']);
    $member = User::factory()->create(['name' => 'Ana Souza']);
    $workspace->members()->attach($member, ['role' => 'viewer']);

    $this->actingAs($member)
        ->get(route('workspace.help', $workspace))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('workspace/Help')
            ->where('workspace.slug', 'northwind')
            ->where('helpDesk', 'northwind for Ana Souza'));
});

test('Help works with nothing added by a package', function () {
    app()->instance(Extensions::class, new Extensions);
    $workspace = Workspace::factory()->create();

    $this->actingAs($workspace->owner)
        ->get(route('workspace.help', $workspace))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('workspace/Help')
            ->missing('support')
            ->has('docsUrl'));
});

test('a settings item can say what its page is for', function () {
    $extensions = app(Extensions::class);
    $extensions->addSettingsItem(new SettingsItem(
        key: 'ledger-settings',
        label: 'Ledger',
        icon: 'billing',
        path: '/ledger',
        group: 'workspace',
        component: 'workspace/Ledger',
        description: 'Your invoices, in one place.',
    ));
    $extensions->addSettingsItem(new SettingsItem(
        key: 'quiet-settings',
        label: 'Quiet',
        icon: 'key',
        path: '/settings/quiet',
        group: 'account',
        component: 'workspace/settings/Quiet',
    ));
    $workspace = Workspace::factory()->create();

    $this->actingAs($workspace->owner)
        ->get(route('workspace.settings.general', $workspace))
        ->assertInertia(fn (Assert $page) => $page->where('extensions.settings', fn ($items) => collect($items)->contains(
            fn ($item) => $item['key'] === 'ledger-settings' && $item['description'] === 'Your invoices, in one place.',
        ) && collect($items)->contains(
            fn ($item) => $item['key'] === 'quiet-settings' && $item['description'] === '',
        )));
});

test('every settings page still opens for the workspace’s owner', function (string $route, string $component) {
    $workspace = Workspace::factory()->create();

    $this->actingAs($workspace->owner)
        ->get(route($route, $workspace))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component($component));
})->with([
    'general' => ['workspace.settings.general', 'workspace/settings/General'],
    'members' => ['workspace.members.index', 'workspace/settings/Members'],
    'domains' => ['workspace.settings.domains', 'workspace/settings/Domains'],
    'status page' => ['workspace.settings.status-page', 'workspace/settings/StatusPage'],
    'theme' => ['workspace.settings.theme', 'workspace/settings/Theme'],
    'machine policies' => ['workspace.settings.machine-policies', 'workspace/settings/MachinePolicies'],
    'log storage' => ['workspace.settings.log-storage', 'workspace/settings/LogStorage'],
    'profile' => ['workspace.settings.profile', 'workspace/settings/Profile'],
    'appearance' => ['workspace.settings.appearance', 'workspace/settings/Appearance'],
    'api' => ['workspace.settings.api', 'workspace/settings/Api'],
    'mcp' => ['workspace.settings.mcp', 'workspace/settings/Mcp'],
]);

test('General shares what its alerts, registries and danger zone need', function () {
    $workspace = Workspace::factory()->create(['alert_webhook_url' => 'https://hooks.slack.com/services/T0/B0/x']);
    $workspace->containerRegistries()->create(['host' => 'ghcr.io', 'username' => 'acme-bot', 'password' => 'secret']);
    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);

    $this->actingAs($workspace->owner)
        ->get(route('workspace.settings.general', $workspace))
        ->assertInertia(fn (Assert $page) => $page
            ->where('canManageSettings', true)
            ->where('canDelete', true)
            ->where('alerts.email', true)
            ->where('alerts.webhookSet', true)
            ->where('alerts.webhook', 'https://hooks.slack.com/services/T0/B0/x')
            ->where('registries.0.host', 'ghcr.io')
            ->where('registries.0.username', 'acme-bot'));

    $this->actingAs($viewer)
        ->get(route('workspace.settings.general', $workspace))
        ->assertInertia(fn (Assert $page) => $page
            ->where('canManageSettings', false)
            ->where('canDelete', false)
            ->where('canManageAlerts', false)
            ->where('alerts.webhook', null)
            ->where('registries', null)
            ->where('github', null));
});
