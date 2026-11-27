<?php

use App\Enums\Plan;
use App\Models\Machine;
use App\Models\Workspace;
use App\Support\Workflows\WorkflowSteps;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/** @return array{0: Workspace, 1: Machine} */
function editorWorkspace(): array
{
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $machine = Machine::factory()->for($workspace)->create(['name' => 'web-1']);
    $machine->forceFill([
        'uid' => (string) Str::uuid(),
        'inventory' => ['services' => [
            ['key' => 'docker:aaa', 'name' => 'serveros-shop-0123456789ab', 'details' => ['managed' => 'true', 'image' => 'ghcr.io/acme/shop:1']],
        ]],
    ])->save();

    return [$workspace, $machine];
}

test('the editor gets the latest run to draw on the canvas, and names services and monitors like the rest of the panel', function () {
    [$workspace, $machine] = editorWorkspace();
    $monitor = $workspace->monitors()->create([
        'name' => 'Shop', 'type' => 'http', 'url' => 'https://shop.example.com/health',
        'interval_seconds' => 60, 'timeout_seconds' => 10, 'status' => 'up', 'alerts' => true,
    ]);
    $graph = WorkflowSteps::clean(['nodes' => [
        ['id' => 'trigger', 'kind' => 'trigger', 'x' => 0, 'y' => 0, 'title' => '', 'description' => '', 'rules' => ['event' => 'monitor.down', 'monitor_id' => $monitor->id]],
        ['id' => 'restart', 'kind' => 'restart', 'x' => 300, 'y' => 0, 'title' => '', 'description' => '', 'rules' => ['machine_id' => $machine->id, 'service' => 'docker:aaa']],
    ], 'edges' => [['id' => 'e1', 'source' => 'trigger', 'port' => 'out', 'target' => 'restart']]]);
    $workflow = $workspace->workflows()->create([
        'name' => 'Restart shop', 'graph' => $graph, 'enabled' => false, 'run_as' => $workspace->owner_id, 'trigger' => 'monitor.down',
    ]);
    foreach (['failed', 'succeeded'] as $state) {
        $workflow->runs()->create([
            'state' => $state,
            'trigger' => ['kind' => 'monitor.down', 'summary' => 'Shop went down', 'data' => []],
            'graph' => $graph,
            'steps' => ['trigger' => ['state' => 'succeeded', 'message' => '1 alert', 'port' => 'out', 'edges' => ['e1']]],
            'frontier' => [],
            'logs' => [],
            'started_at' => now()->subMinutes(3),
            'finished_at' => now()->subMinutes(2),
        ]);
    }

    $this->actingAs($workspace->owner)->get($workspace->url("/workflows/{$workflow->id}"))
        ->assertInertia(fn (Assert $page) => $page
            ->component('workspace/WorkflowEditor')
            ->where('run', null)
            ->where('lastRun.state', 'succeeded')
            ->where('lastRun.steps.trigger.edges', ['e1'])
            ->has('lastRun.graph.nodes', 2)
            ->where('options.monitors.0.target', 'shop.example.com')
            ->where('options.machines.0.services.0.label', 'shop')
            ->where('options.machines.0.services.0.image', 'ghcr.io/acme/shop:1'));
});

test('a workflow that never ran has no last run', function () {
    [$workspace] = editorWorkspace();
    $workflow = $workspace->workflows()->create([
        'name' => 'Nothing yet', 'graph' => ['nodes' => [['id' => 'trigger', 'kind' => 'trigger', 'x' => 0, 'y' => 0, 'title' => '', 'description' => '', 'rules' => ['event' => 'manual']]], 'edges' => []],
        'enabled' => false, 'run_as' => $workspace->owner_id, 'trigger' => 'manual',
    ]);

    $this->actingAs($workspace->owner)->get($workspace->url("/workflows/{$workflow->id}"))
        ->assertInertia(fn (Assert $page) => $page->where('lastRun', null));
});
