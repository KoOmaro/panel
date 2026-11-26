<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Workspaces\Dashboard;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

function cloudUrl(string $path = ''): string
{
    return 'https://cloud.'.config('app.domain').$path;
}

test('workspaces live under their slug on the dashboard host', function () {
    $workspace = Workspace::factory()->create(['slug' => 'northwind']);

    expect(Dashboard::host())->toBe('cloud.'.config('app.domain'))
        ->and($workspace->url())->toBe(cloudUrl('/northwind'))
        ->and($workspace->url('/machines'))->toBe(cloudUrl('/northwind/machines'))
        ->and($workspace->address())->toBe('cloud.'.config('app.domain').'/northwind')
        ->and(route('workspace.projects.index', $workspace))->toBe(cloudUrl('/northwind/projects'));
});

test('members can view their workspace home on the dashboard host', function () {
    $workspace = Workspace::factory()->create(['name' => 'Northwind', 'slug' => 'northwind']);

    $this->actingAs($workspace->owner)
        ->get(cloudUrl('/northwind'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('workspace/Home')
            ->where('workspace.slug', 'northwind')
            ->where('workspace.name', 'Northwind')
            ->where('workspace.url', cloudUrl('/northwind'))
            ->where('appUrl', rtrim(config('app.url'), '/')));
});

test('users cannot view a workspace they do not belong to', function () {
    Workspace::factory()->create(['slug' => 'northwind']);

    $this->actingAs(User::factory()->create())
        ->get(cloudUrl('/northwind'))
        ->assertForbidden();
});

test('invited members can view the workspace', function () {
    $workspace = Workspace::factory()->create(['slug' => 'northwind']);
    $member = User::factory()->create();
    $workspace->members()->attach($member, ['role' => 'member']);

    $this->actingAs($member)
        ->get(cloudUrl('/northwind/machines'))
        ->assertOk();
});

test('unknown workspaces return not found', function () {
    $this->actingAs(User::factory()->create())
        ->get(cloudUrl('/nope'))
        ->assertNotFound();
});

test('guests are sent to the login page on the root domain', function () {
    Workspace::factory()->create(['slug' => 'northwind']);

    $this->get(cloudUrl('/northwind'))
        ->assertRedirect(rtrim(config('app.url'), '/').'/login');
});

test('account pages keep working on the dashboard host', function () {
    $workspace = Workspace::factory()->create(['slug' => 'northwind']);

    $this->actingAs($workspace->owner)->get(cloudUrl('/settings/profile'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('settings/Profile'));
    $this->actingAs($workspace->owner)->get(cloudUrl('/workspaces'))->assertOk();
});

test('the front page sends people where the edition says', function () {
    $this->get(route('home'));
    expect(request()->route()?->getName())->toBe('home');

    $this->followingRedirects()->get(route('home'))->assertOk();

    $workspace = Workspace::factory()->create(['slug' => 'northwind']);
    $this->actingAs($workspace->owner)->get(cloudUrl('/'))->assertRedirect(route('dashboard'));
});

test('the dashboard link opens the default workspace on the dashboard host', function () {
    $workspace = Workspace::factory()->create(['slug' => 'northwind']);

    $this->actingAs($workspace->owner)->get(route('dashboard'))->assertRedirect(cloudUrl('/northwind'));
    $this->actingAs($workspace->owner)->get(route('dashboard', ['to' => '/machines?connect=1']))->assertRedirect(cloudUrl('/northwind/machines?connect=1'));
    $this->actingAs($workspace->owner)->get(cloudUrl('/dashboard'))->assertRedirect(cloudUrl('/northwind'));
});

test('signing in sends inertia requests to the workspace with a full page visit', function () {
    $workspace = Workspace::factory()->create(['slug' => 'northwind']);
    $this->withSession(['url.intended' => $workspace->url()]);

    $this->withHeaders(['X-Inertia' => 'true'])
        ->post(route('login.store'), [
            'email' => $workspace->owner->email,
            'password' => 'password',
        ])
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', cloudUrl('/northwind'));
});

test('old workspace subdomains redirect to the dashboard host for good, keeping the path and query', function () {
    Workspace::factory()->create(['slug' => 'northwind']);
    $old = 'https://northwind.'.config('app.domain');

    $this->get($old.'/')->assertStatus(301)->assertRedirect(cloudUrl('/northwind'));
    $this->get($old.'/projects?tab=apps&sort=name')->assertStatus(301)->assertRedirect(cloudUrl('/northwind/projects?tab=apps&sort=name'));
    $this->get($old.'/machines/7/services')->assertStatus(301)->assertRedirect(cloudUrl('/northwind/machines/7/services'));
});

test('old workspace subdomains redirect other methods with a 308, so the method and body survive', function () {
    Workspace::factory()->create(['slug' => 'northwind']);
    $old = 'https://northwind.'.config('app.domain');

    $this->post($old.'/projects', ['name' => 'Shop'])->assertStatus(308)->assertRedirect(cloudUrl('/northwind/projects'));
    $this->delete($old.'/settings/logo')->assertStatus(308)->assertRedirect(cloudUrl('/northwind/settings/logo'));
});

test('old subdomains of workspaces that do not exist are not found', function () {
    $this->get('https://nope.'.config('app.domain').'/')->assertNotFound();
    $this->get('https://nope.'.config('app.domain').'/machines')->assertNotFound();
});

test('old subdomains still answer the account pages and the API without a redirect', function () {
    Workspace::factory()->create(['slug' => 'northwind']);
    $old = 'https://northwind.'.config('app.domain');

    $this->get($old.'/login')->assertOk();
    $this->getJson($old.'/api/v1/machines')->assertUnauthorized();
});

test('other subdomains of ours are left alone', function () {
    foreach (['docs', 'feedback', 'www', 'status'] as $name) {
        $response = $this->get('https://'.$name.'.'.config('app.domain').'/');
        expect($response->getStatusCode())->not->toBe(301)
            ->and((string) $response->headers->get('Location'))->not->toStartWith('https://cloud.');
    }
});

test('the dashboard host is cloud on ServerOS Cloud, the domain itself when self-hosted, or what DASHBOARD_DOMAIN says', function () {
    config(['serveros.edition' => 'self-hosted', 'serveros.dashboard_domain' => null]);
    expect(Dashboard::host())->toBe(config('app.domain'));

    config(['serveros.edition' => 'cloud']);
    expect(Dashboard::host())->toBe('cloud.'.config('app.domain'));

    config(['serveros.dashboard_domain' => ' Panel.Example.com ']);
    expect(Dashboard::host())->toBe('panel.example.com');
});

test('a bare workspace path from a workspace page resolves inside that workspace', function () {
    $workspace = Workspace::factory()->create(['slug' => 'northwind']);
    $from = ['Referer' => cloudUrl('/northwind/machines')];

    $this->actingAs($workspace->owner)->withHeaders($from)->get(cloudUrl('/projects?tab=apps'))
        ->assertRedirect('/northwind/projects?tab=apps');
    $this->actingAs($workspace->owner)->withHeaders([...$from, 'X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()), 'X-Requested-With' => 'XMLHttpRequest'])->get(cloudUrl('/projects'))
        ->assertOk()
        ->assertJsonPath('component', 'workspace/Projects')
        ->assertJsonPath('url', '/northwind/projects');
    $this->actingAs($workspace->owner)->withHeaders($from)->post(cloudUrl('/projects'), ['name' => 'Shop'])->assertRedirect();
    expect($workspace->projects()->where('name', 'Shop')->exists())->toBeTrue();
    $this->actingAs($workspace->owner)->withHeaders($from)->getJson(cloudUrl('/panel-version'))->assertOk();
});

test('bare paths leave account pages and pages from outside a workspace alone', function () {
    $workspace = Workspace::factory()->create(['slug' => 'northwind']);

    $this->actingAs($workspace->owner)->withHeaders(['Referer' => cloudUrl('/northwind')])->get(cloudUrl('/workspaces'))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->component('workspaces/Index'));
    $this->actingAs($workspace->owner)->withHeaders(['Referer' => cloudUrl('/workspaces')])->get(cloudUrl('/settings/profile'))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->component('settings/Profile'));
    $this->actingAs($workspace->owner)->withHeaders(['Referer' => 'https://evil.example/northwind'])->get(cloudUrl('/projects'))->assertNotFound();
});

test('a pasted bare workspace link opens in the workspace this browser used last', function () {
    $workspace = Workspace::factory()->create(['slug' => 'northwind']);

    $cookie = collect($this->actingAs($workspace->owner)->get(cloudUrl('/northwind/machines'))->assertOk()->headers->getCookies())
        ->first(fn ($cookie) => $cookie->getName() === 'workspace');
    expect($cookie?->getValue())->toBe('northwind')->and($cookie?->isHttpOnly())->toBeTrue();
    $this->actingAs($workspace->owner)->get(cloudUrl('/projects'))->assertNotFound();

    $this->actingAs($workspace->owner)->withUnencryptedCookie('workspace', 'northwind')->get(cloudUrl('/projects'))
        ->assertRedirect('/northwind/projects');
    $this->actingAs($workspace->owner)->get(cloudUrl('/workspaces'))->assertOk();
    $this->actingAs($workspace->owner)->get(cloudUrl('/nope'))->assertNotFound();
});

test('a self-hosted panel opens workspaces at a path on its own domain, so it needs no wildcard DNS', function () {
    config(['serveros.edition' => 'self-hosted', 'serveros.dashboard_domain' => null]);
    $router = app('router');
    $router->setRoutes(new RouteCollection);
    Route::middleware('web')->group(base_path('routes/web.php'));
    Route::middleware('api')->prefix('api')->group(base_path('routes/api.php'));
    Route::middleware('api')->group(base_path('routes/mcp.php'));
    $router->getRoutes()->refreshNameLookups();
    $router->getRoutes()->refreshActionLookups();
    app('url')->setRoutes($router->getRoutes());

    $workspace = Workspace::factory()->create(['slug' => 'northwind']);
    $main = 'https://'.config('app.domain');

    expect($workspace->url())->toBe($main.'/northwind')
        ->and(route('mcp', $workspace))->toBe($main.'/northwind/mcp');
    $this->actingAs($workspace->owner)->get($main.'/northwind/machines')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('workspace/Machines'));
    $this->actingAs($workspace->owner)->get($main.'/dashboard')->assertRedirect($main.'/northwind');
    $this->actingAs($workspace->owner)->get($main.'/settings/profile')->assertOk();
    $this->get('https://northwind.'.config('app.domain').'/machines')->assertStatus(301)->assertRedirect($main.'/northwind/machines');
});
