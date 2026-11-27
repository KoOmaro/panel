<?php

use App\Models\Workspace;
use Inertia\Testing\AssertableInertia as Assert;

afterEach(function () {
    if (isset($this->versionFile)) {
        @unlink($this->versionFile);
    }
});

test('the account menu shows the panel version an install runs', function () {
    $this->versionFile = tempnam(sys_get_temp_dir(), 'serveros-version');
    file_put_contents($this->versionFile, "1.11.5\n");
    config()->set('serveros.updates.version_file', $this->versionFile);
    $workspace = Workspace::factory()->create();

    $this->actingAs($workspace->owner)
        ->get($workspace->url().'/')
        ->assertInertia(fn (Assert $page) => $page->where('panelVersion', '1.11.5')->etc());
});

test('the account menu says dev for a build without a version', function () {
    config()->set('serveros.updates.version_file', sys_get_temp_dir().'/serveros-no-version-'.uniqid());
    $workspace = Workspace::factory()->create();

    $this->actingAs($workspace->owner)
        ->get($workspace->url().'/')
        ->assertInertia(fn (Assert $page) => $page->where('panelVersion', 'dev')->etc());
});

test('the account menu knows the person’s role in the workspace', function () {
    $workspace = Workspace::factory()->create();

    $this->actingAs($workspace->owner)
        ->get($workspace->url().'/')
        ->assertInertia(fn (Assert $page) => $page->where('workspaceAccess.role', 'owner')->etc());
});
