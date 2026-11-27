<?php

use App\Models\SavedView;
use App\Models\User;
use App\Models\Workspace;
use Inertia\Testing\AssertableInertia as Assert;

/** @param  array<string, mixed>  $filters */
function savedView(Workspace $workspace, User $user, string $name = 'Down on web-1', array $filters = ['status' => ['down'], 'machines' => ['web-1']], int $position = 1): SavedView
{
    return SavedView::query()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'page' => 'projects',
        'name' => $name,
        'filters' => ['q' => null, 'status' => [], 'machines' => [], 'sort' => 'newest', 'env' => null, ...$filters],
        'position' => $position,
    ]);
}

test('anyone in the workspace can save a view of the projects list, and it opens', function () {
    $workspace = Workspace::factory()->create();
    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);

    $response = $this->actingAs($viewer)->post($workspace->url('/saved-views'), [
        'page' => 'projects',
        'name' => '  Down on web-1 ',
        'filters' => ['q' => ' shop ', 'status' => ['down', 'pending', 'down'], 'machines' => ['web-1'], 'sort' => 'name', 'env' => 'Staging'],
    ]);

    $view = SavedView::query()->sole();
    $response->assertRedirect($workspace->url("/projects?view={$view->id}"));
    expect($view->user_id)->toBe($viewer->id)
        ->and($view->workspace_id)->toBe($workspace->id)
        ->and($view->name)->toBe('Down on web-1')
        ->and($view->filters)->toBe(['q' => 'shop', 'status' => ['down', 'pending'], 'machines' => ['web-1'], 'sort' => 'name', 'env' => 'Staging']);
});

test('a view saved with no filters keeps the defaults', function () {
    $workspace = Workspace::factory()->create();

    $this->actingAs($workspace->owner)->post($workspace->url('/saved-views'), ['page' => 'projects', 'name' => 'New view', 'filters' => []])->assertRedirect();

    expect(SavedView::query()->sole()->filters)->toBe(['q' => null, 'status' => [], 'machines' => [], 'sort' => 'newest', 'env' => null]);
});

test('the projects list shows only your own views, in order, and opens the one in the address', function () {
    $workspace = Workspace::factory()->create();
    $teammate = User::factory()->create();
    $workspace->members()->attach($teammate, ['role' => 'member']);
    $second = savedView($workspace, $workspace->owner, 'Second', position: 2);
    $first = savedView($workspace, $workspace->owner, 'First', position: 1);
    $theirs = savedView($workspace, $teammate, 'Theirs');
    $elsewhere = Workspace::factory()->create(['owner_id' => $workspace->owner_id]);
    savedView($elsewhere, $workspace->owner, 'Other workspace');

    $this->actingAs($workspace->owner)->get($workspace->url("/projects?view={$second->id}"))
        ->assertInertia(fn (Assert $page) => $page
            ->component('workspace/Projects')
            ->where('activeView', $second->id)
            ->has('savedViews', 2)
            ->where('savedViews.0.id', $first->id)
            ->where('savedViews.0.name', 'First')
            ->where('savedViews.1.id', $second->id)
            ->where('savedViews.1.filters', ['q' => null, 'status' => ['down'], 'machines' => ['web-1'], 'sort' => 'newest', 'env' => null]));

    $this->actingAs($workspace->owner)->get($workspace->url("/projects?view={$theirs->id}"))
        ->assertInertia(fn (Assert $page) => $page->where('activeView', null)->has('savedViews', 2));
    $this->actingAs($workspace->owner)->get($workspace->url('/projects?view=nope'))
        ->assertInertia(fn (Assert $page) => $page->where('activeView', null));
    $this->actingAs($teammate)->get($workspace->url('/projects'))
        ->assertInertia(fn (Assert $page) => $page->has('savedViews', 1)->where('savedViews.0.name', 'Theirs'));
});

test('you can rename a view and update it with the current filters', function () {
    $workspace = Workspace::factory()->create();
    $view = savedView($workspace, $workspace->owner);

    $this->actingAs($workspace->owner)->patch($workspace->url("/saved-views/{$view->id}"), ['name' => 'Broken'])->assertRedirect();
    expect($view->fresh()->name)->toBe('Broken')
        ->and($view->fresh()->filters['status'])->toBe(['down']);

    $this->actingAs($workspace->owner)->patch($workspace->url("/saved-views/{$view->id}"), ['filters' => ['status' => ['stopped'], 'sort' => 'status']])->assertRedirect();
    expect($view->fresh()->name)->toBe('Broken')
        ->and($view->fresh()->filters)->toBe(['q' => null, 'status' => ['stopped'], 'machines' => [], 'sort' => 'status', 'env' => null]);
});

test('duplicating a view puts the copy right after it and opens it', function () {
    $workspace = Workspace::factory()->create();
    $first = savedView($workspace, $workspace->owner, 'First', position: 1);
    $last = savedView($workspace, $workspace->owner, 'Last', ['q' => 'shop'], position: 2);

    $response = $this->actingAs($workspace->owner)->post($workspace->url("/saved-views/{$first->id}/duplicate"));

    $copy = SavedView::query()->latest('id')->first();
    $response->assertRedirect($workspace->url("/projects?view={$copy->id}"));
    expect($copy->name)->toBe('First copy')
        ->and($copy->filters)->toBe($first->filters)
        ->and(SavedView::query()->orderBy('position')->pluck('name')->all())->toBe(['First', 'First copy', 'Last'])
        ->and($last->fresh()->position)->toBe(3);
});

test('you can delete a view', function () {
    $workspace = Workspace::factory()->create();
    $view = savedView($workspace, $workspace->owner);

    $this->actingAs($workspace->owner)->delete($workspace->url("/saved-views/{$view->id}"))->assertRedirect();

    expect(SavedView::query()->count())->toBe(0);
});

test('nobody else can change or delete your views, even the workspace owner', function () {
    $workspace = Workspace::factory()->create();
    $member = User::factory()->create();
    $workspace->members()->attach($member, ['role' => 'member']);
    $view = savedView($workspace, $member);

    $this->actingAs($workspace->owner)->patch($workspace->url("/saved-views/{$view->id}"), ['name' => 'Mine now'])->assertNotFound();
    $this->actingAs($workspace->owner)->post($workspace->url("/saved-views/{$view->id}/duplicate"))->assertNotFound();
    $this->actingAs($workspace->owner)->delete($workspace->url("/saved-views/{$view->id}"))->assertNotFound();

    expect($view->fresh()->name)->toBe('Down on web-1')
        ->and(SavedView::query()->count())->toBe(1);
});

test('a view can’t be reached through another workspace, or by people outside it', function () {
    $workspace = Workspace::factory()->create();
    $view = savedView($workspace, $workspace->owner);
    $other = Workspace::factory()->create(['owner_id' => $workspace->owner_id]);
    $stranger = User::factory()->create();

    $this->actingAs($workspace->owner)->patch($other->url("/saved-views/{$view->id}"), ['name' => 'Moved'])->assertNotFound();
    $this->actingAs($workspace->owner)->delete($other->url("/saved-views/{$view->id}"))->assertNotFound();
    $this->actingAs($stranger)->post($workspace->url('/saved-views'), ['page' => 'projects', 'name' => 'Mine', 'filters' => []])->assertForbidden();
    $this->actingAs($stranger)->delete($workspace->url("/saved-views/{$view->id}"))->assertForbidden();

    expect($view->fresh()->name)->toBe('Down on web-1');
});

test('views only take the filters the page understands', function (array $payload, string $error) {
    $workspace = Workspace::factory()->create();

    $this->actingAs($workspace->owner)->post($workspace->url('/saved-views'), [
        'page' => 'projects',
        'name' => 'View',
        'filters' => ['sort' => 'newest'],
        ...$payload,
    ])->assertSessionHasErrors($error);

    expect(SavedView::query()->count())->toBe(0);
})->with([
    'unknown page' => [['page' => 'billing'], 'page'],
    'no name' => [['name' => ' '], 'name'],
    'long name' => [['name' => str_repeat('a', 61)], 'name'],
    'unknown filter' => [['filters' => ['owner' => 'sam']], 'filters'],
    'unknown status' => [['filters' => ['status' => ['broken']]], 'filters.status.0'],
    'unknown sort' => [['filters' => ['sort' => 'random']], 'filters.sort'],
    'status not a list' => [['filters' => ['status' => 'down']], 'filters.status'],
    'machine not text' => [['filters' => ['machines' => [['web-1']]]], 'filters.machines.0'],
    'long search' => [['filters' => ['q' => str_repeat('a', 201)]], 'filters.q'],
    'no filters' => [['filters' => null], 'filters'],
]);

test('updating a view checks its filters too', function () {
    $workspace = Workspace::factory()->create();
    $view = savedView($workspace, $workspace->owner);

    $this->actingAs($workspace->owner)->patch($workspace->url("/saved-views/{$view->id}"), ['filters' => ['sort' => 'random']])->assertSessionHasErrors('filters.sort');
    $this->actingAs($workspace->owner)->patch($workspace->url("/saved-views/{$view->id}"), ['name' => ''])->assertSessionHasErrors('name');

    expect($view->fresh()->filters['sort'])->toBe('newest')
        ->and($view->fresh()->name)->toBe('Down on web-1');
});

test('each person keeps a limited number of views per list', function () {
    $workspace = Workspace::factory()->create();
    foreach (range(1, SavedView::MAX_PER_PAGE) as $i) {
        savedView($workspace, $workspace->owner, "View {$i}", position: $i);
    }

    $this->actingAs($workspace->owner)->post($workspace->url('/saved-views'), ['page' => 'projects', 'name' => 'One more', 'filters' => ['sort' => 'name']])->assertSessionHasErrors('name');
    $this->actingAs($workspace->owner)->post($workspace->url('/saved-views/'.SavedView::query()->first()->id.'/duplicate'))->assertSessionHasErrors('name');

    expect(SavedView::query()->count())->toBe(SavedView::MAX_PER_PAGE);
});

test('a workspace’s views go with it', function () {
    $workspace = Workspace::factory()->create();
    savedView($workspace, $workspace->owner);

    $workspace->delete();

    expect(SavedView::query()->count())->toBe(0);
});
