<?php

use App\Models\User;
use App\Models\Workspace;
use Inertia\Testing\AssertableInertia as Assert;

test('members can open the new dashboard screens', function (string $route, string $component) {
    $workspace = Workspace::factory()->create(['slug' => 'northwind']);
    $member = User::factory()->create();
    $workspace->members()->attach($member, ['role' => 'member']);

    $this->actingAs($member)
        ->get(route($route, $workspace))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component($component)
            ->where('workspace.slug', 'northwind'));
})->with([
    'setup guide' => ['workspace.setup', 'workspace/SetupGuide'],
    'code' => ['workspace.code.index', 'workspace/code/WorkspaceCode'],
    'set up code' => ['workspace.code.setup', 'workspace/code/SetUpCode'],
    'review changes' => ['workspace.code.changes', 'workspace/code/ReviewChanges'],
    'help' => ['workspace.help', 'workspace/Help'],
]);

test('people outside the workspace cannot open the new dashboard screens', function (string $route) {
    $workspace = Workspace::factory()->create(['slug' => 'northwind']);

    $this->actingAs(User::factory()->create())
        ->get(route($route, $workspace))
        ->assertForbidden();
})->with(['workspace.setup', 'workspace.code.index', 'workspace.code.setup', 'workspace.code.changes', 'workspace.help']);
