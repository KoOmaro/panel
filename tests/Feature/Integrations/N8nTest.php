<?php

use App\Enums\Plan;
use App\Models\Activity;
use App\Models\Integration;
use App\Models\Machine;
use App\Models\McpToken;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\Workspace;
use App\Support\Activity\ActivityLine;
use App\Support\Integrations\N8n\N8nStep;
use App\Support\Integrations\N8n\N8nWorkflows;
use App\Support\Monitors\HostResolver;
use App\Support\Workflows\WorkflowEngine;
use App\Support\Workflows\WorkflowSteps;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeN8n;

beforeEach(function () {
    Sleep::fake();
    Notification::fake();
    config()->set('app.url', 'https://panel.example.com');
    $this->withoutDefer();

    app()->instance(HostResolver::class, new class extends HostResolver
    {
        protected function addresses(string $host): array
        {
            return str_ends_with($host, '.internal') || $host === 'localhost' ? ['10.9.9.9'] : ['93.184.215.14'];
        }
    });
});

function n8nConnect(Workspace $workspace, string $key = FakeN8n::KEY, ?string $url = 'https://acme.app.n8n.cloud', ?User $as = null): TestResponse
{
    return test()->actingAs($as ?? $workspace->owner)
        ->from($workspace->url('/integrations'))
        ->post($workspace->url('/integrations/connect/n8n'), ['credentials' => ['token' => $key, 'url' => $url]]);
}

function n8nAccount(Workspace $workspace, string $url = 'https://acme.app.n8n.cloud'): Integration
{
    return $workspace->integrations()->create([
        'provider' => 'n8n',
        'label' => 'acme.app.n8n.cloud',
        'credentials' => ['token' => FakeN8n::KEY, 'url' => $url],
        'fingerprint' => Integration::fingerprintOf('n8n', ['token' => FakeN8n::KEY, 'url' => $url]),
        'meta' => ['instance' => $url, 'workflows' => 1, 'active' => 1, 'webhooks' => 1],
        'status' => Integration::CONNECTED,
        'last_checked_at' => now(),
        'created_by' => $workspace->owner_id,
    ]);
}

function n8nMember(Workspace $workspace, string $role): User
{
    $user = User::factory()->create();
    $workspace->members()->attach($user, ['role' => $role]);

    return $user;
}

/**
 * @param  array<string, mixed>  $rules
 * @param  list<array{0: string, 1: string, 2: array<string, mixed>, 3?: string, 4?: string}>  $after
 */
function n8nFlow(Workspace $workspace, array $rules, array $after = [], string $event = 'manual'): Workflow
{
    $nodes = [
        ['id' => 't', 'kind' => 'trigger', 'x' => 0, 'y' => 0, 'title' => '', 'description' => '', 'rules' => ['event' => $event]],
        ['id' => 'n', 'kind' => 'n8n', 'x' => 0, 'y' => 200, 'title' => '', 'description' => '', 'rules' => $rules],
    ];
    $edges = [['id' => 'e0', 'source' => 't', 'port' => 'out', 'target' => 'n']];
    foreach ($after as $i => $step) {
        $nodes[] = ['id' => $step[0], 'kind' => $step[1], 'x' => 0, 'y' => 400 + $i * 200, 'title' => '', 'description' => '', 'rules' => $step[2]];
        $edges[] = ['id' => "e{$step[0]}", 'source' => $step[3] ?? 'n', 'port' => $step[4] ?? 'out', 'target' => $step[0]];
    }

    return $workspace->workflows()->create([
        'name' => 'Tell n8n', 'graph' => WorkflowSteps::clean(['nodes' => $nodes, 'edges' => $edges]), 'enabled' => true,
        'run_as' => $workspace->owner_id, 'trigger' => $event,
    ]);
}

function n8nMachine(Workspace $workspace): Machine
{
    $machine = Machine::factory()->for($workspace)->create(['name' => 'web-1']);
    $machine->forceFill([
        'uid' => (string) Str::uuid(),
        'last_seen_at' => now(),
        'cpu_percent' => 41,
        'inventory' => ['services' => [['key' => 'docker:aaa', 'name' => 'shop', 'kind' => 'app', 'status' => 'exited', 'details' => ['managed' => 'true']]]],
    ])->save();

    return $machine;
}

test('connecting n8n lists its workflows with the API key, keeps the key encrypted and the address tidy', function () {
    $n8n = FakeN8n::install();
    $n8n->workflow('Deploy alerts');
    $n8n->workflow('Nightly report', null, false);
    $workspace = Workspace::factory()->create();

    n8nConnect($workspace, url: 'acme.app.n8n.cloud/api/v1/')->assertSessionHasNoErrors()->assertRedirect();

    $integration = Integration::query()->sole();
    expect($integration->label)->toBe('acme.app.n8n.cloud')
        ->and($integration->credential('token'))->toBe(FakeN8n::KEY)
        ->and($integration->credential('url'))->toBe('https://acme.app.n8n.cloud')
        ->and(DB::table('integrations')->value('credentials'))->not->toContain(FakeN8n::KEY)
        ->and($integration->meta)->toBe(['instance' => 'https://acme.app.n8n.cloud', 'workflows' => 2, 'active' => 1, 'webhooks' => 1])
        ->and($n8n->called())->toBe(['GET workflows'])
        ->and($n8n->calls[0]['query'])->toMatchArray(['limit' => '250', 'excludePinnedData' => 'true']);
    Http::assertSent(fn (HttpRequest $r) => $r->hasHeader('X-N8N-API-KEY', FakeN8n::KEY) && str_starts_with($r->url(), 'https://acme.app.n8n.cloud/api/v1/'));

    $activity = Activity::query()->where('action', 'integration.connect')->sole();
    expect(ActivityLine::sentence($activity))->toBe($workspace->owner->name.' connected acme.app.n8n.cloud (n8n)')
        ->and(json_encode($activity->meta))->not->toContain(FakeN8n::KEY);

    $page = $this->actingAs($workspace->owner)->get($workspace->url('/integrations/'.$integration->id))->assertOk();
    $deferred = null;
    $page->assertInertia(function (Assert $p) use (&$deferred) {
        $p->where('integration.summary', '2 workflows on acme.app.n8n.cloud')
            ->where('provider.capabilities.0.key', 'workflows')
            ->where('provider.fields.1.value', null)
            ->loadDeferredProps(function (Assert $reload) use (&$deferred) {
                $deferred = $reload->toArray()['props'];
            });
    });
    expect($deferred['account']['workflows'])->toHaveCount(2)
        ->and($deferred['account']['workflows'][0])->toMatchArray(['name' => 'Deploy alerts', 'active' => true, 'problem' => null, 'webhook' => ['node' => 'Webhook', 'method' => 'POST', 'answers' => false]])
        ->and($deferred['account']['workflows'][1]['problem'])->toBe('It doesn’t start with a Webhook trigger. Add one in n8n to start it from ServerOS.')
        ->and($deferred['account']['api']['baseUrl'])->toBe('https://panel.example.com/api/v1')
        ->and($deferred['account']['api']['examples'])->toHaveCount(2)
        ->and(json_decode($deferred['account']['api']['examples'][0]['json'], true)['nodes'][0])->toMatchArray([
            'type' => 'n8n-nodes-base.httpRequest',
            'parameters' => ['url' => 'https://panel.example.com/api/v1/machines', 'authentication' => 'genericCredentialType', 'genericAuthType' => 'httpHeaderAuth', 'options' => []],
        ])
        ->and(json_encode($page->viewData('page')['props']).json_encode($deferred))->not->toContain(FakeN8n::KEY);
});

test('an n8n address in a private network, or one that isn’t https, is refused before anything is sent', function (string $url) {
    FakeN8n::install();
    $workspace = Workspace::factory()->create();

    n8nConnect($workspace, url: $url)->assertSessionHasErrors('credentials.url');

    expect(Integration::query()->count())->toBe(0);
    Http::assertNothingSent();
})->with([
    'plain http' => ['http://n8n.example.com'],
    'loopback' => ['https://127.0.0.1'],
    'a private address' => ['https://10.0.0.5:5678'],
    'link local' => ['https://169.254.169.254'],
    'a name that points inside' => ['https://n8n.internal'],
    'a password in it' => ['https://user:secret@n8n.example.com'],
]);

test('a self-hosted panel can reach its own n8n on a private address when it allows that', function () {
    config()->set('serveros.edition', 'self-hosted');
    config()->set('monitors.allow_private', true);
    $n8n = FakeN8n::install();
    $workspace = Workspace::factory()->create();

    n8nConnect($workspace, url: 'http://10.0.0.5:5678')->assertSessionHasNoErrors();

    expect(Integration::query()->sole()->credential('url'))->toBe('http://10.0.0.5:5678')
        ->and($n8n->calls[0]['host'])->toBe('10.0.0.5');
});

test('a key n8n turns down is never saved, and only people who manage machines connect n8n', function () {
    FakeN8n::install();
    $workspace = Workspace::factory()->create();

    n8nConnect($workspace, 'fake-wrong-n8n-key-for-tests')->assertSessionHasErrors(['credentials.token' => 'n8n turned the API key down. Check it’s copied in full and hasn’t expired.']);
    n8nConnect($workspace, as: n8nMember($workspace, 'viewer'))->assertForbidden();

    expect(Integration::query()->count())->toBe(0);
});

test('the webhook ServerOS calls is read from the workflow’s nodes, as n8n builds its production URL', function () {
    $instance = 'https://acme.app.n8n.cloud';
    $hook = fn (array $parameters, array $node = []) => N8nWorkflows::webhook([
        'name' => 'Webhook', 'type' => 'n8n-nodes-base.webhook', 'webhookId' => 'abc-123', 'parameters' => $parameters, ...$node,
    ], 'wf1', $instance);

    expect($hook(['httpMethod' => 'POST', 'path' => '/deploy hook']))->toMatchArray(['url' => $instance.'/webhook/deploy%20hook', 'method' => 'POST', 'answers' => false, 'problem' => null])
        ->and($hook(['httpMethod' => 'POST', 'path' => ''])['url'])->toBe($instance.'/webhook/abc-123')
        ->and($hook(['httpMethod' => ['GET', 'PATCH', 'POST'], 'path' => 'x', 'responseMode' => 'lastNode']))->toMatchArray(['method' => 'POST', 'answers' => true])
        ->and($hook(['httpMethod' => 'PUT', 'path' => 'x', 'responseMode' => 'responseNode']))->toMatchArray(['method' => 'PUT', 'answers' => true])
        ->and($hook(['path' => 'x'])['problem'])->toBe('Its webhook only takes GET. Set its HTTP Method to POST.')
        ->and($hook(['httpMethod' => 'POST', 'path' => 'orders/:id']))->toMatchArray(['url' => null, 'problem' => 'Its webhook path has a route parameter in it. Use a fixed path to start it from ServerOS.'])
        ->and($hook(['httpMethod' => 'POST', 'path' => 'x', 'authentication' => 'headerAuth'])['problem'])->toBe('Its webhook asks for a login, which ServerOS can’t send. Set its Authentication to None and keep the long random path n8n made.')
        ->and(N8nWorkflows::webhook(['name' => 'My Hook', 'parameters' => ['httpMethod' => 'POST', 'path' => 'go']], 'wf1', $instance)['url'])->toBe($instance.'/webhook/wf1/my%20hook/go');

    $disabled = N8nWorkflows::describe(['id' => 'wf2', 'name' => 'Off', 'nodes' => [['name' => 'Webhook', 'type' => 'n8n-nodes-base.webhook', 'disabled' => true, 'parameters' => ['httpMethod' => 'POST', 'path' => 'x']]]], $instance);
    expect($disabled['problem'])->toBe('Its Webhook trigger is turned off in n8n.');

    $n8n = FakeN8n::install();
    $n8n->workflow('Deploy alerts', ['httpMethod' => ['GET', 'POST'], 'path' => 'deploy', 'responseMode' => 'lastNode']);
    $n8n->workflow('Lookup', ['path' => 'lookup']);
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $account = n8nAccount($workspace);

    $body = $this->actingAs($workspace->owner)->getJson($workspace->url("/integrations/{$account->id}/n8n/workflows"))->assertOk()->json();
    expect($body['error'])->toBeNull()
        ->and($body['workflows'][0])->toMatchArray(['name' => 'Deploy alerts', 'webhook' => ['node' => 'Webhook', 'method' => 'POST', 'answers' => true], 'problem' => null, 'href' => 'https://acme.app.n8n.cloud/workflow/wf000001'])
        ->and($body['workflows'][1]['problem'])->toBe('Its webhook only takes GET. Set its HTTP Method to POST.')
        ->and(json_encode($body))->not->toContain('/webhook/');

    $this->actingAs(n8nMember($workspace, 'viewer'))->getJson($workspace->url("/integrations/{$account->id}/n8n/workflows"))->assertForbidden();
});

test('a workflow step that doesn’t wait posts what happened to the webhook and goes on once n8n has it', function () {
    $n8n = FakeN8n::install();
    $id = $n8n->workflow('Deploy alerts');
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $account = n8nAccount($workspace);
    $machine = n8nMachine($workspace);
    $monitor = $workspace->monitors()->create(['name' => 'Shop', 'type' => 'http', 'url' => 'https://shop.example.com', 'status' => 'down', 'last_error' => 'Timed out', 'interval_seconds' => 60, 'timeout_seconds' => 10]);
    $workflow = n8nFlow($workspace, ['integration' => $account->id, 'workflow' => $id, 'workflow_name' => 'Deploy alerts', 'wait' => 'no', 'body' => '{"reason": "{{trigger}}"}'], event: 'monitor.down');
    expect(WorkflowSteps::problems(WorkflowSteps::clean($workflow->graph), $workspace))->toBe([]);

    $run = app(WorkflowEngine::class)->start($workflow, 'monitor.down', 'Shop went down', ['monitor_id' => $monitor->id, 'monitor' => 'Shop', 'machine_id' => $machine->id, 'machine' => 'web-1', 'service_key' => 'docker:aaa', 'service' => 'shop']);

    expect($run->state)->toBe('succeeded')
        ->and($run->steps['n'])->toMatchArray(['state' => 'succeeded', 'port' => 'out', 'status' => 200, 'response' => ['message' => 'Workflow was started']])
        ->and($run->steps['n']['message'])->toStartWith('Deploy alerts answered 200 in ')
        ->and($n8n->called())->toBe(['GET workflows/'.$id])
        ->and($n8n->deliveries)->toHaveCount(1);
    $sent = $n8n->deliveries[0];
    expect($sent['method'])->toBe('POST')
        ->and($sent['path'])->toBe('deploy-hook')
        ->and($sent['body'])->toMatchArray([
            'event' => 'monitor.down',
            'summary' => 'Shop went down',
            'workspace' => ['name' => $workspace->name, 'slug' => $workspace->slug, 'url' => $workspace->url()],
            'workflow' => ['id' => $workflow->id, 'name' => 'Tell n8n', 'run' => $run->id, 'run_url' => $workspace->url("/workflows/{$workflow->id}?run={$run->id}"), 'step' => 'n'],
            'service' => ['key' => 'docker:aaa', 'name' => 'shop', 'status' => 'exited', 'kind' => 'app', 'managed' => true],
            'input' => ['reason' => 'Shop went down'],
        ])
        ->and($sent['body']['machine'])->toMatchArray(['id' => $machine->id, 'name' => 'web-1', 'online' => true, 'cpu_percent' => 41])
        ->and($sent['body']['monitor'])->toMatchArray(['id' => $monitor->id, 'name' => 'Shop', 'status' => 'down', 'last_error' => 'Timed out', 'target' => 'https://shop.example.com'])
        ->and($sent['body'])->not->toHaveKey('callback_url')
        ->and(json_encode($sent['body']))->not->toContain(FakeN8n::KEY);
    Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/webhook/') && ! $r->hasHeader('X-N8N-API-KEY'));
});

test('a step that waits for the answer exposes it to later steps, and a check can branch on it', function () {
    $n8n = FakeN8n::install();
    $id = $n8n->workflow('Decide', ['httpMethod' => 'POST', 'path' => 'decide', 'responseMode' => 'lastNode']);
    $n8n->answer('decide', 200, ['restart' => 'yes', 'note' => 'disk is fine']);
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $account = n8nAccount($workspace);
    $workflow = n8nFlow($workspace, ['integration' => $account->id, 'workflow' => $id, 'wait' => 'answer'], [
        ['b', 'branch', ['condition' => 'n8n_field', 'field' => 'restart', 'value' => 'yes']],
        ['a', 'alert', ['subject' => 'n8n says {{n8n.note}}', 'to' => 'team'], 'b', 'true'],
    ]);

    $run = app(WorkflowEngine::class)->start($workflow, 'manual', 'Run by hand', [], $workspace->owner);

    expect($run->state)->toBe('succeeded')
        ->and($run->steps['n'])->toMatchArray(['state' => 'succeeded', 'phase' => null, 'response' => ['restart' => 'yes', 'note' => 'disk is fine']])
        ->and($run->steps['b'])->toMatchArray(['state' => 'succeeded', 'port' => 'true'])
        ->and($run->steps['a']['message'])->toBe('Sent “n8n says disk is fine” to the team')
        ->and(collect($run->logs)->pluck('message')->implode("\n"))->toContain('Started Decide in n8n, waiting for its answer');

    $viewer = n8nMember($workspace, 'viewer');
    $this->actingAs($viewer)->get($workspace->url("/workflows/{$workflow->id}?run={$run->id}"))->assertOk()
        ->assertInertia(fn (Assert $p) => $p->missing('run.steps.n.response')->where('run.steps.n.state', 'succeeded'));
});

test('a step that waits for a callback hands n8n a one-off URL and finishes when n8n posts to it', function () {
    $n8n = FakeN8n::install();
    $id = $n8n->workflow('Long job');
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $account = n8nAccount($workspace);
    $workflow = n8nFlow($workspace, ['integration' => $account->id, 'workflow' => $id, 'wait' => 'callback', 'minutes' => 15], [
        ['a', 'alert', ['subject' => 'Done: {{n8n.result}}']],
    ]);

    $run = app(WorkflowEngine::class)->start($workflow, 'manual', 'Run by hand', [], $workspace->owner);

    expect($run->state)->toBe('running')
        ->and($run->steps['n'])->toMatchArray(['state' => 'running', 'phase' => 'callback', 'timeout' => 15]);
    $callback = $n8n->deliveries[0]['body']['callback_url'];
    expect($callback)->toStartWith('https://panel.example.com/api/n8n/callback/'.$run->id.'/n/')
        ->and(json_encode($run->steps))->not->toContain(basename($callback));

    $this->postJson(dirname($callback).'/'.str_repeat('0', 64), ['result' => 'nope'])->assertNotFound();
    $this->postJson($callback, ['result' => 'shipped'])->assertOk()->assertJson(['ok' => true]);
    $this->postJson($callback, ['result' => 'again'])->assertStatus(409);

    $run->refresh();
    expect($run->state)->toBe('succeeded')
        ->and($run->steps['n'])->toMatchArray(['state' => 'succeeded', 'message' => 'n8n called back', 'response' => ['result' => 'shipped']])
        ->and($run->steps['a']['message'])->toBe('Sent “Done: shipped” to the team');
});

test('a callback with an error fails the step, and one that never comes runs out of time', function () {
    $n8n = FakeN8n::install();
    $id = $n8n->workflow('Long job');
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $account = n8nAccount($workspace);
    $workflow = n8nFlow($workspace, ['integration' => $account->id, 'workflow' => $id, 'wait' => 'callback', 'minutes' => 5]);

    $first = app(WorkflowEngine::class)->start($workflow, 'manual', 'Run by hand', [], $workspace->owner);
    $this->postJson($n8n->deliveries[0]['body']['callback_url'], ['error' => 'The backup didn’t finish'])->assertOk();
    expect($first->refresh()->state)->toBe('failed')
        ->and($first->steps['n'])->toMatchArray(['state' => 'failed', 'message' => 'n8n called back with an error: The backup didn’t finish']);

    $second = app(WorkflowEngine::class)->start($workflow, 'manual', 'Run by hand', [], $workspace->owner, limited: false);
    $this->travel(6)->minutes();
    app(WorkflowEngine::class)->tick();
    expect($second->refresh()->state)->toBe('failed')
        ->and($second->steps['n']['message'])->toBe('Long job didn’t call back within 5 minutes.');
});

test('an n8n workflow that’s off fails the step with what to do, and can be turned on from ServerOS', function () {
    $n8n = FakeN8n::install();
    $n8n->legacyActivate = false;
    $id = $n8n->workflow('Deploy alerts', active: false);
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $account = n8nAccount($workspace);
    $workflow = n8nFlow($workspace, ['integration' => $account->id, 'workflow' => $id]);

    $run = app(WorkflowEngine::class)->start($workflow, 'manual', 'Run by hand', [], $workspace->owner);
    expect($run->state)->toBe('failed')
        ->and($run->steps['n']['message'])->toBe('Deploy alerts is off in n8n, so its webhook doesn’t answer. Turn it on from the step, or in n8n.')
        ->and($n8n->deliveries)->toBe([]);

    $this->actingAs(n8nMember($workspace, 'viewer'))->postJson($workspace->url("/integrations/{$account->id}/n8n/workflows/{$id}/activate"))->assertForbidden();
    $this->actingAs($workspace->owner)->postJson($workspace->url("/integrations/{$account->id}/n8n/workflows/{$id}/activate"))->assertOk()->assertJson(['active' => true]);

    expect($n8n->workflows[$id]['active'])->toBeTrue()
        ->and($n8n->called('POST'))->toBe(["POST workflows/{$id}/activate", "POST workflows/{$id}/publish"])
        ->and(ActivityLine::sentence(Activity::query()->where('action', 'integration.n8n_activate')->sole()))->toBe($workspace->owner->name.' turned on Deploy alerts in n8n');

    $again = app(WorkflowEngine::class)->start($workflow, 'manual', 'Run by hand', [], $workspace->owner, limited: false);
    expect($again->state)->toBe('succeeded');
});

test('n8n answering with an error, or not at all, fails the step with words', function () {
    $n8n = FakeN8n::install();
    $id = $n8n->workflow('Deploy alerts');
    $n8n->answer('deploy-hook', 500, ['message' => 'Error in workflow']);
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $account = n8nAccount($workspace);
    $workflow = n8nFlow($workspace, ['integration' => $account->id, 'workflow' => $id]);

    $run = app(WorkflowEngine::class)->start($workflow, 'manual', 'Run by hand', [], $workspace->owner);
    expect($run->steps['n']['message'])->toBe('Deploy alerts answered 500: Error in workflow');

    $n8n->failNext('GET', "workflows/{$id}", 404, ['message' => 'Not Found']);
    $gone = app(WorkflowEngine::class)->start($workflow, 'manual', 'Run by hand', [], $workspace->owner, limited: false);
    expect($gone->steps['n']['message'])->toBe('That n8n workflow is gone. Choose another in the step.');
});

test('the step’s problems say what’s missing before it can be turned on', function () {
    FakeN8n::install();
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $problems = fn (array $rules) => WorkflowSteps::problems(WorkflowSteps::clean(n8nFlow($workspace, $rules)->graph), $workspace)['n'] ?? null;

    expect($problems(['integration' => 1, 'workflow' => 'x']))->toBe('Connect n8n first, from Integrations.');
    $account = n8nAccount($workspace);
    expect($problems(['integration' => 999, 'workflow' => 'x']))->toBe('Choose the n8n account.')
        ->and($problems(['integration' => $account->id]))->toBe('Choose the n8n workflow to start.')
        ->and($problems(['integration' => $account->id, 'workflow' => 'x', 'body' => '[1, 2']))->toBe('Extra input is a JSON object, like {"reason": "{{trigger}}"}.')
        ->and($problems(['integration' => $account->id, 'workflow' => 'x', 'body' => '{"count": {{data.count}}}']))->toBeNull();
});

test('the starter workflow gets its own read-only key, kept in n8n as a credential, and disconnecting revokes it', function () {
    $n8n = FakeN8n::install();
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $account = n8nAccount($workspace);

    $this->actingAs(n8nMember($workspace, 'viewer'))->post($workspace->url("/integrations/{$account->id}/n8n/starter"))->assertForbidden();
    $this->actingAs($workspace->owner)->from($workspace->url("/integrations/{$account->id}"))
        ->post($workspace->url("/integrations/{$account->id}/n8n/starter"))->assertSessionHasNoErrors()->assertRedirect();

    $key = McpToken::query()->where('audience', McpToken::API)->sole();
    $credential = array_values($n8n->credentials)[0];
    expect($key->scopes)->toBe(['workspace:read'])
        ->and($key->name)->toBe('n8n starter workflow')
        ->and($credential['type'])->toBe('httpHeaderAuth')
        ->and($credential['data']['name'])->toBe('Authorization')
        ->and($credential['data']['value'])->toStartWith('Bearer sos_')
        ->and(McpToken::hash(substr($credential['data']['value'], 7)))->toBe($key->token_hash);

    $made = $n8n->bodyOf('POST', 'workflows');
    expect($made['name'])->toBe('ServerOS: list machines')
        ->and($made)->not->toHaveKey('active')
        ->and(collect($made['nodes'])->pluck('type')->all())->toBe(['n8n-nodes-base.manualTrigger', 'n8n-nodes-base.httpRequest', 'n8n-nodes-base.stickyNote'])
        ->and($made['nodes'][1]['parameters']['url'])->toBe('https://panel.example.com/api/v1/machines')
        ->and($made['nodes'][1]['credentials'])->toBe(['httpHeaderAuth' => ['id' => $credential['id'], 'name' => 'ServerOS API ('.$workspace->name.')']])
        ->and($made['connections']['Start']['main'][0][0]['node'])->toBe('List ServerOS machines')
        ->and($made['settings'])->toBe(['executionOrder' => 'v1']);

    $secret = substr($credential['data']['value'], 7);
    $account->refresh();
    expect($account->setting('starter'))->toMatchArray(['name' => 'ServerOS: list machines', 'credential' => $credential['id'], 'key' => $key->id])
        ->and(json_encode($account->settings))->not->toContain($secret)
        ->and(Activity::query()->where('action', 'integration.n8n_starter')->sole()->subject)->toBe('ServerOS: list machines');

    $this->getJson('/api/v1/machines', ['Authorization' => 'Bearer '.$secret])->assertOk();

    $page = $this->actingAs($workspace->owner)->get($workspace->url('/integrations/'.$account->id));
    $deferred = null;
    $page->assertInertia(function (Assert $p) use (&$deferred) {
        $p->where('dependents.0', 'The API key ServerOS made for the starter workflow is revoked, so n8n can’t call ServerOS with it.')
            ->loadDeferredProps(function (Assert $reload) use (&$deferred) {
                $deferred = $reload->toArray()['props'];
            });
    });
    expect($deferred['account']['starter'])->toMatchArray(['name' => 'ServerOS: list machines', 'href' => 'https://acme.app.n8n.cloud/workflow/'.$account->setting('starter')['workflow'], 'credential' => true])
        ->and(json_encode($deferred))->not->toContain($secret);

    $this->actingAs($workspace->owner)->delete($workspace->url('/integrations/'.$account->id), ['confirm' => $account->label])->assertRedirect();
    expect($key->refresh()->revoked_at)->not->toBeNull();
});

test('an n8n key that can’t make credentials still gets the starter workflow, without a ServerOS key', function () {
    $n8n = FakeN8n::install();
    $n8n->canMakeCredentials = false;
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $account = n8nAccount($workspace);

    $this->actingAs($workspace->owner)->post($workspace->url("/integrations/{$account->id}/n8n/starter"))->assertSessionHasNoErrors();

    expect(McpToken::query()->active()->count())->toBe(0)
        ->and($n8n->bodyOf('POST', 'workflows')['nodes'][1])->not->toHaveKey('credentials')
        ->and($account->refresh()->setting('starter'))->toMatchArray(['credential' => null, 'key' => 0]);

    $n8n->failNext('POST', 'workflows', 400, ['message' => 'request/body/nodes/0 must NOT have additional properties']);
    $n8n->canMakeCredentials = true;
    $this->actingAs($workspace->owner)->post($workspace->url("/integrations/{$account->id}/n8n/starter"))
        ->assertSessionHasErrors(['starter' => 'Request/body/nodes/0 must NOT have additional properties.']);
    expect(McpToken::query()->active()->count())->toBe(0)
        ->and($n8n->credentials)->toBe([]);
});

test('the account page says what the account is used for', function () {
    $n8n = FakeN8n::install();
    $id = $n8n->workflow('Deploy alerts');
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $account = n8nAccount($workspace);
    $workflow = n8nFlow($workspace, ['integration' => $account->id, 'workflow' => $id]);

    $page = $this->actingAs($workspace->owner)->get($workspace->url('/integrations/'.$account->id))->assertOk();
    $deferred = null;
    $page->assertInertia(function (Assert $p) use (&$deferred) {
        $p->where('usage.0', 'Tell n8n starts an n8n workflow here.')
            ->where('dependents.0', 'Tell n8n can’t start its n8n step any more.')
            ->loadDeferredProps(function (Assert $reload) use (&$deferred) {
                $deferred = $reload->toArray()['props'];
            });
    });
    expect($deferred['account']['workflows'][0]['usedBy'])->toBe([['id' => $workflow->id, 'name' => 'Tell n8n', 'enabled' => true, 'href' => '/workflows/'.$workflow->id]]);

    $this->actingAs($workspace->owner)->get($workspace->url('/workflows/'.$workflow->id))->assertOk()
        ->assertInertia(fn (Assert $p) => $p->where('options.n8n', [['id' => $account->id, 'label' => 'acme.app.n8n.cloud', 'failing' => false]]));
});

test('the run’s callback route only takes the step it was made for', function () {
    FakeN8n::install();
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $run = WorkflowRun::query()->create([
        'workflow_id' => n8nFlow($workspace, ['integration' => n8nAccount($workspace)->id, 'workflow' => 'x'])->id,
        'state' => 'running', 'trigger' => ['kind' => 'manual', 'summary' => 'x'], 'graph' => ['nodes' => [], 'edges' => []],
        'steps' => ['n' => ['state' => 'running', 'phase' => 'callback', 'startedAt' => 100]], 'frontier' => [], 'logs' => [], 'started_at' => now(),
    ]);

    expect(N8nStep::callbackToken($run, 'n', 100))->not->toBe(N8nStep::callbackToken($run, 'n', 101))
        ->and(N8nStep::callbackToken($run, 'n', 100))->not->toBe(N8nStep::callbackToken($run, 'm', 100));
    $this->postJson("/api/n8n/callback/{$run->id}/n/".N8nStep::callbackToken($run, 'n', 101), [])->assertNotFound();
});
