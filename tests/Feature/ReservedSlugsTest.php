<?php

use App\Models\Workspace;
use App\Support\Workspaces\Dashboard;
use App\Support\WorkspaceSlugs;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;

test('every fixed first path segment on the dashboard host is a reserved workspace slug', function () {
    $taken = collect(Router::getRoutes()->getRoutes())
        ->filter(fn (Route $route) => in_array($route->getDomain(), [null, Dashboard::host()], true) && ! str_starts_with($route->uri(), '{'))
        ->map(fn (Route $route) => explode('/', $route->uri(), 2)[0])
        ->filter(fn (string $segment) => strlen($segment) >= WorkspaceSlugs::MIN_LENGTH && strlen($segment) <= WorkspaceSlugs::MAX_LENGTH
            && preg_match(WorkspaceSlugs::PATTERN, $segment) === 1 && ! str_starts_with($segment, 'livewire-'))
        ->unique()->sort()->values();

    expect($taken->diff(WorkspaceSlugs::RESERVED)->values()->all())->toBe([]);
});

test('reserved words can’t be taken as a workspace slug', function (string $slug) {
    expect(app(WorkspaceSlugs::class)->problemWith($slug))->toBe('That name is reserved.');
})->with(['cloud', 'login', 'dashboard', 'settings', 'api', 'oauth', 'invitations', 'themes']);

test('fixed pages win over a workspace with a reserved slug', function () {
    $workspace = Workspace::factory()->create();
    $workspace->forceFill(['slug' => 'workspaces'])->save();

    $this->actingAs($workspace->owner)->get('https://'.Dashboard::host().'/workspaces')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('workspaces/Index'));
    expect(route('workspace.home', ['workspace' => 'acme']))->toBe(Dashboard::url('acme'));
});

test('the slug check lists workspaces whose slug is now reserved', function () {
    Workspace::factory()->create(['slug' => 'fine-one']);
    Workspace::factory()->create()->forceFill(['slug' => 'cloud'])->save();
    Workspace::factory()->create()->forceFill(['slug' => 'oauth'])->save();

    $this->artisan('workspaces:reserved-slugs')
        ->expectsOutputToContain('cloud')
        ->expectsOutputToContain('oauth')
        ->doesntExpectOutputToContain('fine-one')
        ->assertFailed();
});

test('the slug check passes when no slug collides', function () {
    Workspace::factory()->create(['slug' => 'fine-one']);

    $this->artisan('workspaces:reserved-slugs')->assertSuccessful();
});
