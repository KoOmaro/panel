<?php

use App\Enums\Plan;
use App\Jobs\DiagnoseFix;
use App\Models\Activity;
use App\Models\AgentFix;
use App\Models\Integration;
use App\Models\Machine;
use App\Models\User;
use App\Models\WorkflowRun;
use App\Models\Workspace;
use App\Models\WorkspaceAiKey;
use App\Models\WorkspaceAiModel;
use App\Models\WorkspaceAiUsage;
use App\Support\Activity\ActivityLine;
use App\Support\Ai\AiFeatures;
use App\Support\Ai\AiProviders;
use App\Support\Assistant\AssistantAllowance;
use App\Support\Fixes\FixDiagnoser;
use App\Support\Machines\Readme\ReadmeFacts;
use App\Support\Machines\Readme\ReadmeWords;
use App\Support\Monitors\HostResolver;
use App\Support\Workflows\AgentSpec;
use App\Support\Workflows\WorkflowSteps;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeAiProviders;

beforeEach(function () {
    Sleep::fake();
    config()->set('app.url', 'https://panel.example.com');
    config()->set('app.name', 'ServerOS');
    config()->set('services.groq.key', 'fake-groq-key-for-tests');
    config()->set('services.groq.model', 'openai/gpt-oss-120b');
    $this->withoutDefer();
    app()->instance(HostResolver::class, new class extends HostResolver
    {
        protected function addresses(string $host): array
        {
            return [str_ends_with($host, '.internal') ? '10.0.0.5' : '104.18.2.115'];
        }
    });
});

/** @param  array<string, string>  $credentials */
function aipConnect(Workspace $workspace, string $provider, array $credentials, ?User $as = null): TestResponse
{
    return test()->actingAs($as ?? $workspace->owner)
        ->from($workspace->url('/integrations'))
        ->post($workspace->url("/integrations/connect/{$provider}"), ['credentials' => $credentials]);
}

function aipKey(Workspace $workspace, string $provider, string $key, ?string $url = null, ?string $model = null): WorkspaceAiKey
{
    return WorkspaceAiKey::query()->create(['workspace_id' => $workspace->id, 'provider' => $provider, 'key' => $key, 'hint' => '…'.substr($key, -4), 'base_url' => $url, 'model' => $model]);
}

function aipRun(Workspace $workspace, string $kind = 'manual'): WorkflowRun
{
    $graph = WorkflowSteps::clean(['nodes' => [['id' => 't', 'kind' => 'trigger', 'rules' => ['event' => 'manual']]], 'edges' => []]);
    $workflow = $workspace->workflows()->create(['name' => 'W', 'graph' => $graph, 'enabled' => false, 'run_as' => $workspace->owner_id, 'trigger' => 'manual']);

    return $workflow->runs()->create(['state' => 'running', 'user_id' => $workspace->owner_id, 'trigger' => ['kind' => $kind, 'summary' => 'x', 'data' => []], 'graph' => $graph, 'steps' => [], 'frontier' => [], 'logs' => [], 'started_at' => now()]);
}

test('Anthropic connects from the Integrations page into the same key Settings uses, with its organization', function () {
    $fake = FakeAiProviders::install();
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();

    $response = aipConnect($workspace, 'anthropic', ['key' => FakeAiProviders::ANTHROPIC])->assertSessionHasNoErrors();

    $integration = Integration::query()->sole();
    $key = WorkspaceAiKey::query()->sole();
    $response->assertRedirect($workspace->url('/integrations/'.$integration->id));
    expect($key->only(['provider', 'key', 'hint']))->toBe(['provider' => 'anthropic', 'key' => FakeAiProviders::ANTHROPIC, 'hint' => '…ests'])
        ->and($integration->credentials)->toBe([])
        ->and($integration->meta)->toMatchArray(['organization' => 'org-acme-1234', 'workspace' => 'wrkspc_acme_default', 'models' => 3])
        ->and(DB::table('workspace_ai_keys')->value('key'))->not->toContain('fake-anthropic')
        ->and(DB::table('integrations')->value('credentials'))->not->toContain('fake-anthropic')
        ->and(json_encode(Activity::query()->get()->toArray()))->not->toContain(FakeAiProviders::ANTHROPIC);
    expect($fake->callsTo('anthropic', '/v1/models')[0]['headers']['x-api-key'])->toBe([FakeAiProviders::ANTHROPIC]);

    $this->actingAs($workspace->owner)->get($workspace->url('/settings/mcp'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('ai.providers.0.provider', 'anthropic')
            ->where('ai.providers.0.connected', true)
            ->where('ai.providers.0.accountId', $integration->id)
            ->where('ai.agentUses', 'Anthropic · claude-sonnet-5-5'))
        ->assertDontSee(FakeAiProviders::ANTHROPIC, false);

    $this->actingAs($workspace->owner)->get($workspace->url('/integrations/'.$integration->id))
        ->assertOk()
        ->assertDontSee(FakeAiProviders::ANTHROPIC, false)
        ->assertInertia(fn (Assert $page) => $page
            ->where('integration.facts.0', ['label' => 'Organization', 'value' => 'org-acme-1234'])
            ->where('integration.summary', 'Used by Ask AI and 2 more')
            ->where('usage.0', 'Ask AI uses claude-sonnet-5-5 from this account.')
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('account.kind', 'ai')
                ->where('account.models.0.id', 'claude-opus-5-5')
                ->where('account.defaultModel', 'claude-sonnet-5-5')
                ->has('account.features', 5)
                ->where('account.usage.total.requests', 0)));
});

test('a refused key isn’t kept, and connecting AI keys needs the settings permission', function () {
    FakeAiProviders::install();
    $workspace = Workspace::factory()->onPlan(Plan::Business)->create();

    aipConnect($workspace, 'openai', ['key' => 'fake-wrong-key-for-tests-0000'])
        ->assertSessionHasErrors(['credentials.key' => 'OpenAI didn’t accept that key.']);
    expect(WorkspaceAiKey::query()->count())->toBe(0)->and(Integration::query()->count())->toBe(0);

    $member = User::factory()->create();
    $workspace->members()->attach($member, ['role' => 'member']);
    aipConnect($workspace, 'openai', ['key' => FakeAiProviders::OPENAI], $member)->assertForbidden();

    $role = $workspace->customRoles()->create(['name' => 'Settings', 'permissions' => ['manage-settings']]);
    $keeper = User::factory()->create();
    $workspace->members()->attach($keeper, ['role' => 'viewer', 'custom_role_id' => $role->id]);
    aipConnect($workspace, 'openai', ['key' => FakeAiProviders::OPENAI], $keeper)->assertSessionHasNoErrors();

    expect(Integration::query()->sole()->meta)->toMatchArray(['organization' => 'acme-labs', 'models' => 2]);
    aipConnect($workspace, 'openai', ['key' => FakeAiProviders::OPENAI])
        ->assertSessionHasErrors(['credentials.key' => 'OpenAI is connected already. Disconnect it first to connect another account.']);
});

test('OpenRouter is checked with its key and models endpoints and always sends its app headers', function () {
    $fake = FakeAiProviders::install();
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();

    aipConnect($workspace, 'openrouter', ['key' => FakeAiProviders::OPENROUTER])->assertSessionHasNoErrors();

    $integration = Integration::query()->sole();
    expect($integration->meta)->toMatchArray(['key_label' => 'ServerOS panel', 'limit' => 25, 'limit_remaining' => 18.5, 'usage_monthly' => 6.5, 'free_tier' => false, 'models' => 3])
        ->and(WorkspaceAiKey::query()->sole()->only(['provider', 'base_url']))->toBe(['provider' => 'openrouter', 'base_url' => null]);
    foreach ($fake->callsTo('openrouter') as $call) {
        expect($call['headers']['HTTP-Referer'])->toBe(['https://panel.example.com'])
            ->and($call['headers']['X-OpenRouter-Title'])->toBe(['ServerOS'])
            ->and($call['headers']['X-Title'])->toBe(['ServerOS']);
    }
    expect(array_column($fake->callsTo('openrouter'), 'path'))->toBe(['/api/v1/key', '/api/v1/models']);

    AiFeatures::choose($workspace, AiFeatures::ASSISTANT, 'openrouter', 'qwen/qwen3-coder', null);
    $this->actingAs($workspace->owner)->post($workspace->url('/assistant'), ['message' => 'Hi'])->assertOk()->streamedContent();

    $chat = $fake->callsTo('openrouter', '/api/v1/chat/completions')[0];
    expect($chat['body']['model'])->toBe('qwen/qwen3-coder')
        ->and($chat['headers']['Authorization'])->toBe(['Bearer '.FakeAiProviders::OPENROUTER])
        ->and($chat['headers']['HTTP-Referer'])->toBe(['https://panel.example.com'])
        ->and($chat['headers']['X-OpenRouter-Title'])->toBe(['ServerOS'])
        ->and(WorkspaceAiUsage::query()->sole()->only(['provider', 'model', 'feature', 'requests', 'input_tokens', 'output_tokens']))
        ->toBe(['provider' => 'openrouter', 'model' => 'qwen/qwen3-coder', 'feature' => 'assistant', 'requests' => 1, 'input_tokens' => 80, 'output_tokens' => 20]);

    $this->actingAs($workspace->owner)->get($workspace->url('/integrations/'.$integration->id))
        ->assertInertia(fn (Assert $page) => $page
            ->where('integration.facts', fn ($facts) => collect($facts)->contains(['label' => 'Credit left', 'value' => '$18.50']))
            ->loadDeferredProps(fn (Assert $reload) => $reload->where('account.models.0.id', 'anthropic/claude-sonnet-5.5')));
});

test('Ollama takes an address and an optional token, lists its own models and keeps the first as the default', function () {
    $fake = FakeAiProviders::install();
    $fake->ollamaToken = true;
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();

    aipConnect($workspace, 'ollama', ['url' => FakeAiProviders::OLLAMA.'/v1/', 'token' => ''])
        ->assertSessionHasErrors(['credentials.token' => 'ollama.acme.dev didn’t accept that token.']);
    expect(WorkspaceAiKey::query()->count())->toBe(0);

    aipConnect($workspace, 'ollama', ['url' => FakeAiProviders::OLLAMA.'/v1/', 'token' => FakeAiProviders::OLLAMA_TOKEN])->assertSessionHasNoErrors();

    $key = WorkspaceAiKey::query()->sole();
    expect($key->only(['provider', 'base_url', 'model', 'key']))->toBe(['provider' => 'ollama', 'base_url' => FakeAiProviders::OLLAMA, 'model' => 'qwen3-coder:30b', 'key' => FakeAiProviders::OLLAMA_TOKEN])
        ->and(Integration::query()->sole()->meta)->toMatchArray(['version' => '0.13.0', 'host' => 'ollama.acme.dev', 'models' => 2]);
    expect(collect($fake->callsTo('ollama'))->pluck('headers.Authorization')->filter()->unique()->values()->all())->toBe([['Bearer '.FakeAiProviders::OLLAMA_TOKEN]]);
    expect(app(AiProviders::class)->models($key))->toBe([
        ['id' => 'qwen3-coder:30b', 'label' => 'qwen3-coder:30b · 30.5B'],
        ['id' => 'llama3.3:latest', 'label' => 'llama3.3:latest · 70.6B'],
    ]);

    $ai = app(AiProviders::class)->forFeature($workspace, AiFeatures::FIXES);
    expect($ai)->toMatchArray(['provider' => 'ollama', 'model' => 'qwen3-coder:30b', 'url' => FakeAiProviders::OLLAMA.'/v1', 'own' => true]);
});

test('an Ollama without a token sends no Authorization header', function () {
    $fake = FakeAiProviders::install();
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();

    aipConnect($workspace, 'ollama', ['url' => FakeAiProviders::OLLAMA])->assertSessionHasNoErrors();

    expect(WorkspaceAiKey::query()->sole()->only(['key', 'hint']))->toBe(['key' => '', 'hint' => ''])
        ->and(collect($fake->callsTo('ollama'))->pluck('headers.Authorization')->filter()->all())->toBe([]);
});

test('Ollama on ServerOS cloud must be https and public, like GitLab and Proxmox', function (string $url, string $error) {
    $fake = FakeAiProviders::install();
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();

    aipConnect($workspace, 'ollama', ['url' => $url])->assertSessionHasErrors(['credentials.url' => $error]);

    expect(WorkspaceAiKey::query()->count())->toBe(0)->and($fake->calls)->toBe([]);
})->with([
    'plain http' => ['http://ollama.acme.dev:11434', 'The address has to start with https://.'],
    'a private name' => ['https://ollama.internal:11434', 'Couldn’t find ollama.internal, or it points into a private network.'],
    'a private address' => ['https://192.168.1.20:11434', 'Couldn’t find 192.168.1.20, or it points into a private network.'],
    'the metadata service' => ['https://169.254.169.254', 'Couldn’t find 169.254.169.254, or it points into a private network.'],
    'not an address' => ['ollama please', 'That isn’t a web address.'],
]);

test('a self-hosted panel can reach an Ollama on its own network once private addresses are allowed, but never link-local', function () {
    config()->set(['serveros.edition' => 'self-hosted', 'monitors.allow_private' => false]);
    FakeAiProviders::install();
    $workspace = Workspace::factory()->create();

    aipConnect($workspace, 'ollama', ['url' => 'http://ollama.internal:11434'])
        ->assertSessionHasErrors(['credentials.url' => 'Couldn’t find ollama.internal, or it points into a private network. To use one, set MONITORS_ALLOW_PRIVATE=true.']);

    config()->set('monitors.allow_private', true);
    aipConnect($workspace, 'ollama', ['url' => 'http://169.254.169.254'])
        ->assertSessionHasErrors(['credentials.url' => 'Couldn’t find 169.254.169.254, or it points into a private network. To use one, set MONITORS_ALLOW_PRIVATE=true.']);
    aipConnect($workspace, 'ollama', ['url' => 'http://ollama.internal:11434'])->assertSessionHasNoErrors();

    expect(WorkspaceAiKey::query()->sole()->base_url)->toBe('http://ollama.internal:11434');
});

test('model lists come live from the provider and are kept for an hour', function () {
    $fake = FakeAiProviders::install();
    Cache::flush();
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $key = aipKey($workspace, 'openai', FakeAiProviders::OPENAI);

    $first = app(AiProviders::class)->modelOptions($workspace);
    app(AiProviders::class)->modelOptions($workspace);

    expect($first)->toBe([['provider' => 'openai', 'label' => 'OpenAI', 'default' => 'gpt-5', 'models' => [['id' => 'gpt-6.1-sol', 'label' => 'gpt-6.1-sol'], ['id' => 'gpt-5', 'label' => 'gpt-5']]]])
        ->and($fake->callsTo('openai', '/v1/models'))->toHaveCount(1)
        ->and(app(AiProviders::class)->models($key, fresh: true))->toHaveCount(2)
        ->and($fake->callsTo('openai', '/v1/models'))->toHaveCount(2);
});

test('each feature runs on the provider and model picked for it', function () {
    $fake = FakeAiProviders::install();
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    aipKey($workspace, 'anthropic', FakeAiProviders::ANTHROPIC);
    aipKey($workspace, 'openai', FakeAiProviders::OPENAI);
    aipKey($workspace, 'ollama', '', FakeAiProviders::OLLAMA, 'llama3.3:latest');
    $put = fn (string $feature, ?string $choice) => $this->actingAs($workspace->owner)->put($workspace->url('/settings/ai/models'), ['feature' => $feature, 'choice' => $choice]);

    $put('fixes', 'openai:gpt-6.1-sol')->assertSessionHasNoErrors();
    $put('readme', 'ollama:qwen3-coder:30b')->assertSessionHasNoErrors();
    $put('assistant', 'custom:some-model')->assertSessionHasErrors(['choice' => 'Connect that provider first.']);
    $put('agents', 'custom:x')->assertSessionHasErrors('choice');
    $put('fixes', 'openai:not a model')->assertSessionHasErrors(['choice' => 'That isn’t a model name.']);
    expect(Activity::query()->where('action', 'ai.models')->where('subject', 'Server fixes')->sole()->meta)->toBe(['model' => 'OpenAI · gpt-6.1-sol'])
        ->and(ActivityLine::describe(Activity::query()->where('action', 'ai.models')->first(), false)['verb'])->toBe('changed the model for');

    // Ask AI wasn't picked, so it runs on the first key: Anthropic.
    $this->actingAs($workspace->owner)->post($workspace->url('/assistant'), ['message' => 'Hi'])->assertOk()->streamedContent();
    expect($fake->callsTo('anthropic', '/v1/messages')[0]['body']['model'])->toBe('claude-sonnet-5-5');

    // Server fixes run on the OpenAI model picked for them.
    $machine = Machine::factory()->for($workspace)->create(['name' => 'web-1']);
    $fix = AgentFix::query()->create(['workspace_id' => $workspace->id, 'machine_id' => $machine->id, 'state' => 'investigating']);
    (new DiagnoseFix($fix->id))->handle(app(FixDiagnoser::class));
    expect($fake->callsTo('openai', '/v1/responses')[0]['body']['model'])->toBe('gpt-6.1-sol')
        ->and($fix->refresh()->model)->toBe('gpt-6.1-sol');

    // The readme writer runs on Ollama, through its OpenAI-compatible API.
    $machine->forceFill(['uid' => (string) Str::uuid(), 'last_seen_at' => now(), 'inventory' => ['scanned_at' => now()->getTimestamp(), 'complete' => true, 'listeners' => [], 'services' => [
        ['key' => 'systemd:redis-server.service', 'name' => 'redis-server', 'kind' => 'cache', 'manager' => 'systemd', 'status' => 'running', 'ports' => [6379], 'details' => []],
    ]]])->save();
    app(ReadmeWords::class)->write($workspace, app(ReadmeFacts::class)->build($machine->fresh()));
    $chat = $fake->callsTo('ollama', '/v1/chat/completions')[0];
    expect($chat['body']['model'])->toBe('qwen3-coder:30b')
        ->and($chat['headers']['Authorization'] ?? null)->toBeNull();

    // Putting a feature back to automatic follows the first key again.
    $put('readme', null)->assertSessionHasNoErrors();
    expect(app(AiProviders::class)->forFeature($workspace, AiFeatures::README)['provider'])->toBe('anthropic');

    expect(WorkspaceAiUsage::query()->orderBy('provider')->get(['provider', 'feature', 'model', 'input_tokens', 'output_tokens'])->toArray())->toBe([
        ['provider' => 'anthropic', 'feature' => 'assistant', 'model' => 'claude-sonnet-5-5', 'input_tokens' => 150, 'output_tokens' => 40],
        ['provider' => 'ollama', 'feature' => 'readme', 'model' => 'qwen3-coder:30b', 'input_tokens' => 80, 'output_tokens' => 20],
        ['provider' => 'openai', 'feature' => 'fixes', 'model' => 'gpt-6.1-sol', 'input_tokens' => 200, 'output_tokens' => 60],
    ]);
});

test('agents and workflow agent steps take the agent and model picked for them, and OpenCode gets the OpenRouter or Ollama account', function () {
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    aipKey($workspace, 'anthropic', FakeAiProviders::ANTHROPIC);
    aipKey($workspace, 'openrouter', FakeAiProviders::OPENROUTER);
    aipKey($workspace, 'ollama', FakeAiProviders::OLLAMA_TOKEN, FakeAiProviders::OLLAMA, 'qwen3-coder:30b');
    AiFeatures::choose($workspace, AiFeatures::WORKFLOWS, 'anthropic', 'claude-opus-5-5', null);
    AiFeatures::choose($workspace, AiFeatures::AGENTS, 'openrouter', 'qwen/qwen3-coder', null);

    $workflow = AgentSpec::job($workspace, aipRun($workspace), 'a', 'docker:agent', ['agent' => 'claude', 'prompt' => 'Hi']);
    $explicit = AgentSpec::job($workspace, aipRun($workspace), 'a', 'docker:agent', ['agent' => 'claude', 'prompt' => 'Hi', 'model' => 'sonnet']);
    $router = AgentSpec::job($workspace, aipRun($workspace), 'a', 'docker:agent', ['agent' => 'opencode', 'prompt' => 'Hi', 'model' => 'openrouter/qwen/qwen3-coder']);
    $local = AgentSpec::job($workspace, aipRun($workspace), 'a', 'docker:agent', ['agent' => 'opencode', 'prompt' => 'Hi', 'model' => 'ollama/qwen3-coder:30b']);
    $task = AgentSpec::job($workspace, aipRun($workspace, 'agent_task'), 'a', 'docker:agent', ['agent' => 'claude', 'prompt' => 'Hi']);

    expect($workflow['env']['SERVEROS_AGENT_MODEL'])->toBe('claude-opus-5-5')
        ->and($workflow['env']['ANTHROPIC_API_KEY'])->toBe(FakeAiProviders::ANTHROPIC)
        ->and($explicit['env']['SERVEROS_AGENT_MODEL'])->toBe('sonnet')
        ->and($router['env']['OPENROUTER_API_KEY'])->toBe(FakeAiProviders::OPENROUTER)
        ->and($router['env'])->not->toHaveKey('ANTHROPIC_API_KEY')
        ->and($local['env']['OLLAMA_URL'])->toBe(FakeAiProviders::OLLAMA)
        ->and($local['env']['OLLAMA_API_KEY'])->toBe(FakeAiProviders::OLLAMA_TOKEN)
        ->and($local['env']['SERVEROS_AGENT_SCRIPT'])->toContain('{env:OLLAMA_API_KEY}')
        ->and($task['env'])->not->toHaveKey('SERVEROS_AGENT_MODEL')
        ->and(AiFeatures::agentDefault($workspace, AiFeatures::AGENTS))->toBe(['agent' => 'opencode', 'model' => 'openrouter/qwen/qwen3-coder', 'provider' => 'openrouter']);
});

test('Test it sends a tiny prompt, shows the reply and how long it took, and counts like any message', function () {
    $fake = FakeAiProviders::install();
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    aipKey($workspace, 'anthropic', FakeAiProviders::ANTHROPIC);

    $this->actingAs($workspace->owner)->postJson($workspace->url('/settings/ai/anthropic/test'), ['model' => 'claude-haiku-5-5'])
        ->assertOk()
        ->assertJson(['reply' => 'Hello ServerOS, this is the model.', 'model' => 'claude-haiku-5-5', 'input' => 150, 'output' => 40])
        ->assertJsonStructure(['ms']);

    expect($fake->callsTo('anthropic', '/v1/messages')[0]['body'])->toMatchArray(['model' => 'claude-haiku-5-5', 'max_tokens' => 120])
        ->and(AssistantAllowance::for($workspace)['used'])->toBe(1)
        ->and(WorkspaceAiUsage::query()->sole()->only(['feature', 'requests']))->toBe(['feature' => 'test', 'requests' => 1])
        ->and(Activity::query()->where('action', 'ai.test')->sole()->meta)->toBe(['ok' => true, 'model' => 'claude-haiku-5-5']);

    $fake->failNext('anthropic', '/v1/messages', 529);
    $this->actingAs($workspace->owner)->postJson($workspace->url('/settings/ai/anthropic/test'))
        ->assertStatus(422)
        ->assertJsonPath('error', 'Your Anthropic key didn’t answer (529): Overloaded. Try again in a minute.');

    $this->actingAs($workspace->owner)->postJson($workspace->url('/settings/ai/openai/test'))->assertNotFound();
    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);
    $this->actingAs($viewer)->postJson($workspace->url('/settings/ai/anthropic/test'))->assertForbidden();
});

test('the account page shows the last 30 days of tokens by model', function () {
    FakeAiProviders::install();
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    aipKey($workspace, 'openai', FakeAiProviders::OPENAI);
    WorkspaceAiUsage::query()->create(['workspace_id' => $workspace->id, 'day' => now()->toDateString(), 'provider' => 'openai', 'model' => 'gpt-5', 'feature' => 'assistant', 'requests' => 3, 'input_tokens' => 1200, 'output_tokens' => 300]);
    WorkspaceAiUsage::query()->create(['workspace_id' => $workspace->id, 'day' => now()->subDays(3)->toDateString(), 'provider' => 'openai', 'model' => 'gpt-5', 'feature' => 'fixes', 'requests' => 1, 'input_tokens' => 800, 'output_tokens' => 200]);
    WorkspaceAiUsage::query()->create(['workspace_id' => $workspace->id, 'day' => now()->subDays(40)->toDateString(), 'provider' => 'openai', 'model' => 'gpt-5', 'feature' => 'fixes', 'requests' => 9, 'input_tokens' => 9000, 'output_tokens' => 9000]);
    WorkspaceAiUsage::query()->create(['workspace_id' => $workspace->id, 'day' => now()->toDateString(), 'provider' => 'anthropic', 'model' => 'claude-opus-5-5', 'feature' => 'assistant', 'requests' => 1, 'input_tokens' => 5, 'output_tokens' => 5]);
    $this->actingAs($workspace->owner)->get($workspace->url('/integrations'));
    $integration = Integration::query()->where('provider', 'openai')->sole();

    $this->actingAs($workspace->owner)->get($workspace->url('/integrations/'.$integration->id))
        ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $reload) => $reload
            ->has('account.usage.days', 30)
            ->where('account.usage.days.29', ['day' => now()->toDateString(), 'input' => 1200, 'output' => 300, 'requests' => 3])
            ->where('account.usage.total', ['requests' => 4, 'input' => 2000, 'output' => 500])
            ->where('account.usage.models', [['model' => 'gpt-5', 'requests' => 4, 'input' => 2000, 'output' => 500, 'features' => ['Ask AI', 'Server fixes']]])));
});

test('disconnecting warns which features fall back, then forgets the key and every pick of it', function () {
    FakeAiProviders::install();
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    aipConnect($workspace, 'anthropic', ['key' => FakeAiProviders::ANTHROPIC])->assertSessionHasNoErrors();
    AiFeatures::choose($workspace, AiFeatures::README, 'anthropic', 'claude-haiku-5-5', null);
    $integration = Integration::query()->sole();

    $this->actingAs($workspace->owner)->get($workspace->url('/integrations/'.$integration->id))
        ->assertInertia(fn (Assert $page) => $page->where('dependents', [
            'Ask AI moves to ServerOS’s own model.',
            'Server fixes moves to ServerOS’s own model.',
            'Machine readmes moves to ServerOS’s own model.',
        ]));
    $this->actingAs($workspace->owner)->get($workspace->url('/settings/mcp'))
        ->assertInertia(fn (Assert $page) => $page->where('ai.providers.0.fallbacks.0', 'Ask AI moves to ServerOS’s own model.'));

    $this->actingAs($workspace->owner)->delete($workspace->url('/integrations/'.$integration->id))
        ->assertSessionHasErrors(['confirm' => 'Type Anthropic to disconnect it.']);
    $this->actingAs($workspace->owner)->delete($workspace->url('/integrations/'.$integration->id), ['confirm' => 'Anthropic'])
        ->assertRedirect($workspace->url('/integrations'));

    expect(WorkspaceAiKey::query()->count())->toBe(0)
        ->and(WorkspaceAiModel::query()->count())->toBe(0)
        ->and(Integration::query()->count())->toBe(0)
        ->and(app(AiProviders::class)->forFeature($workspace, AiFeatures::ASSISTANT))->toMatchArray(['provider' => 'groq', 'own' => false])
        ->and(app(AiProviders::class)->forFeature($workspace, AiFeatures::README)['own'])->toBeFalse();
});

test('with another key connected, disconnecting says where each feature moves', function () {
    FakeAiProviders::install();
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    aipKey($workspace, 'anthropic', FakeAiProviders::ANTHROPIC);
    aipKey($workspace, 'openai', FakeAiProviders::OPENAI);

    expect(AiFeatures::fallbacks($workspace, 'anthropic'))->toBe([
        'Ask AI moves to OpenAI · gpt-5.',
        'Server fixes moves to OpenAI · gpt-5.',
        'Machine readmes moves to OpenAI · gpt-5.',
    ])->and(AiFeatures::fallbacks($workspace, 'openai'))->toBe([]);
});

test('a key connected in Settings gets its account page, and removing it there takes the page away', function () {
    FakeAiProviders::install();
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();

    $this->actingAs($workspace->owner)->put($workspace->url('/settings/ai/openrouter'), ['key' => FakeAiProviders::OPENROUTER, 'model' => 'qwen/qwen3-coder'])
        ->assertSessionHasNoErrors();

    $integration = Integration::query()->sole();
    expect($integration->only(['provider', 'label', 'status']))->toBe(['provider' => 'openrouter', 'label' => 'OpenRouter', 'status' => 'connected'])
        ->and($integration->meta['limit'])->toBe(25)
        ->and(WorkspaceAiKey::query()->sole()->model)->toBe('qwen/qwen3-coder');

    $this->actingAs($workspace->owner)->post($workspace->url('/integrations/'.$integration->id.'/check'))->assertSessionHasNoErrors();
    expect($integration->refresh()->status)->toBe('connected');

    $this->actingAs($workspace->owner)->delete($workspace->url('/settings/ai/openrouter'))->assertSessionHasNoErrors();
    expect(Integration::query()->count())->toBe(0);
    $this->actingAs($workspace->owner)->get($workspace->url('/integrations/'.$integration->id))->assertNotFound();
});

test('keys saved before integrations had accounts get one, keep their dates, and Check again reads the key', function () {
    $fake = FakeAiProviders::install();
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $key = aipKey($workspace, 'anthropic', FakeAiProviders::ANTHROPIC);
    $key->forceFill(['created_at' => now()->subMonth(), 'verified_at' => now()->subMonth()])->save();

    $this->actingAs($workspace->owner)->get($workspace->url('/integrations'))->assertOk();
    $integration = Integration::query()->sole();
    expect($integration->created_at->toDateString())->toBe(now()->subMonth()->toDateString())
        ->and($integration->credentials)->toBe([]);

    $this->actingAs($workspace->owner)->post($workspace->url('/integrations/'.$integration->id.'/check'))->assertSessionHasNoErrors();
    expect($integration->refresh()->meta['organization'])->toBe('org-acme-1234')
        ->and($fake->callsTo('anthropic', '/v1/models')[0]['headers']['x-api-key'])->toBe([FakeAiProviders::ANTHROPIC]);

    $key->forceFill(['key' => 'fake-revoked-key-for-tests'])->save();
    $this->actingAs($workspace->owner)->post($workspace->url('/integrations/'.$integration->id.'/check'));
    expect($integration->refresh()->only(['status', 'last_error']))->toBe(['status' => 'failing', 'last_error' => 'Anthropic didn’t accept that key.']);
});
