<?php

namespace Tests\Support;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Stand-ins for the AI APIs the panel calls: Anthropic, OpenAI, OpenRouter
 * and one Ollama server. Each checks its key, lists its models, answers a
 * chat with token usage, records every call and can fail the next one.
 */
final class FakeAiProviders
{
    public const string ANTHROPIC = 'fake-anthropic-key-for-tests';

    public const string OPENAI = 'fake-openai-key-for-tests';

    public const string OPENROUTER = 'fake-openrouter-key-for-tests';

    public const string OLLAMA_TOKEN = 'fake-ollama-token-for-tests';

    public const string OLLAMA = 'https://ollama.acme.dev';

    /** @var list<array{provider: string, method: string, url: string, path: string, headers: array<string, list<string>>, body: array<string, mixed>}> */
    public array $calls = [];

    /** @var array<string, list<array{status: int, body: array<string, mixed>}>> */
    private array $failures = [];

    /** Whether the fake Ollama asks for a token, like one behind a proxy. */
    public bool $ollamaToken = false;

    /** @var list<array{name: string, modified_at: string, details: array<string, string>}> */
    public array $ollamaModels = [
        ['name' => 'qwen3-coder:30b', 'modified_at' => '2026-11-20T10:00:00Z', 'details' => ['parameter_size' => '30.5B']],
        ['name' => 'llama3.3:latest', 'modified_at' => '2026-10-01T10:00:00Z', 'details' => ['parameter_size' => '70.6B']],
        ['name' => 'nomic-embed-text:latest', 'modified_at' => '2026-09-01T10:00:00Z', 'details' => ['parameter_size' => '137M']],
    ];

    public static function install(bool $strict = true): self
    {
        $fake = new self;
        if ($strict) {
            Http::preventStrayRequests();
        }
        Http::fake([
            'api.anthropic.com/*' => fn (Request $r) => $fake->handle('anthropic', $r),
            'api.openai.com/*' => fn (Request $r) => $fake->handle('openai', $r),
            'openrouter.ai/*' => fn (Request $r) => $fake->handle('openrouter', $r),
            'ollama.acme.dev/*' => fn (Request $r) => $fake->handle('ollama', $r),
            'ollama.internal:11434/*' => fn (Request $r) => $fake->handle('ollama', $r),
        ]);

        return $fake;
    }

    /** @param  array<string, mixed>  $body */
    public function failNext(string $provider, string $path, int $status, array $body = []): self
    {
        $this->failures["{$provider} {$path}"][] = ['status' => $status, 'body' => $body];

        return $this;
    }

    /** @return list<array{provider: string, method: string, url: string, path: string, headers: array<string, list<string>>, body: array<string, mixed>}> */
    public function callsTo(string $provider, ?string $path = null): array
    {
        return array_values(array_filter($this->calls, fn (array $c) => $c['provider'] === $provider && ($path === null || $c['path'] === $path)));
    }

    public function handle(string $provider, Request $request): PromiseInterface
    {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        $body = $request->method() === 'GET' ? [] : (array) $request->data();
        $this->calls[] = ['provider' => $provider, 'method' => $request->method(), 'url' => $request->url(), 'path' => $path, 'headers' => $request->headers(), 'body' => $body];

        if (! $this->authorised($provider, $request)) {
            return match ($provider) {
                'anthropic' => Http::response(['type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key']], 401),
                'openrouter' => Http::response(['error' => ['code' => 401, 'message' => 'No auth credentials found']], 401),
                'ollama' => Http::response('Unauthorized', 401),
                default => Http::response(['error' => ['message' => 'Incorrect API key provided.', 'type' => 'invalid_request_error', 'code' => 'invalid_api_key']], 401),
            };
        }
        if (($this->failures["{$provider} {$path}"] ?? []) !== []) {
            $failure = array_shift($this->failures["{$provider} {$path}"]);

            return Http::response($failure['body'] === [] ? ['error' => ['message' => 'Overloaded']] : $failure['body'], $failure['status']);
        }

        return match ("{$provider} {$request->method()} {$path}") {
            'anthropic GET /v1/models' => Http::response([
                'data' => [
                    ['type' => 'model', 'id' => 'claude-opus-5-5', 'display_name' => 'Claude Opus 5.5', 'created_at' => '2026-09-21T00:00:00Z'],
                    ['type' => 'model', 'id' => 'claude-sonnet-5-5', 'display_name' => 'Claude Sonnet 5.5', 'created_at' => '2026-09-01T00:00:00Z'],
                    ['type' => 'model', 'id' => 'claude-haiku-5-5', 'display_name' => 'Claude Haiku 5.5', 'created_at' => '2026-08-01T00:00:00Z'],
                ],
                'has_more' => false, 'first_id' => 'claude-opus-5-5', 'last_id' => 'claude-haiku-5-5',
            ], 200, ['anthropic-organization-id' => 'org-acme-1234', 'anthropic-workspace-id' => 'wrkspc_acme_default', 'request-id' => 'req_fake']),
            'anthropic POST /v1/messages' => Http::response([
                'id' => 'msg_fake', 'type' => 'message', 'role' => 'assistant', 'model' => (string) ($body['model'] ?? ''),
                'content' => [['type' => 'text', 'text' => $this->reply($body)]],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 120, 'cache_read_input_tokens' => 30, 'output_tokens' => 40],
            ]),
            'openai GET /v1/models' => Http::response(['object' => 'list', 'data' => [
                ['id' => 'gpt-6.1-sol', 'object' => 'model', 'created' => 1790000000],
                ['id' => 'gpt-5', 'object' => 'model', 'created' => 1750000000],
                ['id' => 'text-embedding-3-large', 'object' => 'model', 'created' => 1700000000],
            ]], 200, ['openai-organization' => 'acme-labs', 'openai-version' => '2020-10-01']),
            'openai POST /v1/responses' => Http::response([
                'id' => 'resp_fake', 'status' => 'completed', 'model' => (string) ($body['model'] ?? ''),
                'output' => [['type' => 'message', 'status' => 'completed', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => $this->reply($body)]]]],
                'usage' => ['input_tokens' => 200, 'output_tokens' => 60, 'total_tokens' => 260],
            ]),
            'openrouter GET /api/v1/key' => Http::response(['data' => [
                'label' => 'ServerOS panel', 'limit' => 25, 'limit_reset' => 'monthly', 'limit_remaining' => 18.5,
                'usage' => 6.5, 'usage_daily' => 0.5, 'usage_weekly' => 2, 'usage_monthly' => 6.5, 'is_free_tier' => false,
            ]]),
            'openrouter GET /api/v1/models' => Http::response(['data' => [
                ['id' => 'qwen/qwen3-coder', 'name' => 'Qwen: Qwen3 Coder', 'created' => 1785000000],
                ['id' => 'anthropic/claude-sonnet-5.5', 'name' => 'Anthropic: Claude Sonnet 5.5', 'created' => 1790000000],
                ['id' => 'openrouter/auto', 'name' => 'Auto Router', 'created' => 1700000000],
            ]]),
            'openrouter POST /api/v1/chat/completions', 'ollama POST /v1/chat/completions' => Http::response($this->completion($body)),
            'ollama GET /api/tags' => Http::response(['models' => array_map(fn (array $m) => [...$m, 'model' => $m['name'], 'size' => 1000, 'digest' => 'abc'], $this->ollamaModels)]),
            'ollama GET /api/version' => Http::response(['version' => '0.13.0']),
            default => Http::response(['error' => ['message' => 'Not found']], 404),
        };
    }

    private function authorised(string $provider, Request $request): bool
    {
        $bearer = $request->header('Authorization')[0] ?? null;

        return match ($provider) {
            'anthropic' => ($request->header('x-api-key')[0] ?? null) === self::ANTHROPIC,
            'openai' => $bearer === 'Bearer '.self::OPENAI,
            'openrouter' => str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/models') && $bearer === null || $bearer === 'Bearer '.self::OPENROUTER,
            default => ! $this->ollamaToken || $bearer === 'Bearer '.self::OLLAMA_TOKEN,
        };
    }

    /** @param  array<string, mixed>  $body */
    private function reply(array $body): string
    {
        $asked = json_encode($body) ?: '';

        return str_contains($asked, 'readme') || str_contains($asked, 'JSON') ? '{"title":"ok"}' : 'Hello ServerOS, this is the model.';
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function completion(array $body): array
    {
        return [
            'id' => 'chatcmpl-fake', 'object' => 'chat.completion', 'model' => (string) ($body['model'] ?? ''),
            'choices' => [['index' => 0, 'finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => $this->reply($body)]]],
            'usage' => ['prompt_tokens' => 80, 'completion_tokens' => 20, 'total_tokens' => 100],
        ];
    }
}
