<?php

use App\Models\User;
use App\Models\Workspace;

test('members can pin their workspaces to the sidebar and unpin them', function () {
    $user = User::factory()->create();
    $current = Workspace::factory()->create(['owner_id' => $user->id]);
    $other = Workspace::factory()->create();
    $other->members()->attach($user, ['role' => 'member']);
    $base = $current->url().'/pins/';

    $this->actingAs($user)->post($base.$other->slug)->assertRedirect();

    $pinned = fn () => collect($this->actingAs($user)->get($current->url().'/')->inertiaProps('workspaces'))
        ->pluck('pinnedAt', 'slug');

    expect($pinned()[$other->slug])->not->toBeNull()
        ->and($pinned()[$current->slug])->toBeNull();

    $this->actingAs($user)->delete($base.$other->slug)->assertRedirect();

    expect($pinned()[$other->slug])->toBeNull();
});

test('a workspace you are not in cannot be pinned', function () {
    $user = User::factory()->create();
    $current = Workspace::factory()->create(['owner_id' => $user->id]);
    $stranger = Workspace::factory()->create();

    $this->actingAs($user)->post($current->url().'/pins/'.$stranger->slug)->assertNotFound();
    expect($stranger->members()->whereKey($user->id)->exists())->toBeFalse();
});
