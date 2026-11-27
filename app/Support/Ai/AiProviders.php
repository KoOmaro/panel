<?php

namespace App\Support\Ai;

use App\Models\Workspace;
use App\Models\WorkspaceAiKey;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * @phpstan-type Ai array{provider: string, key: string, model: string, own: bool, url?: string, workspace?: int, feature?: string}
 * @phpstan-type ModelOption array{id: string, label: string}
 */
class AiProviders
{
    public const PROVIDERS = [
        'anthropic' => ['label' => 'Anthropic', 'model' => 'claude-sonnet-5-5', 'prefix' => 'sk-ant-'],
        'openai' => ['label' => 'OpenAI', 'model' => 'gpt-5', 'prefix' => 'sk-'],
        'custom' => ['label' => 'OpenAI-compatible', 'model' => '', 'prefix' => ''],
        'openrouter' => ['label' => 'OpenRouter', 'model' => 'openrouter/auto', 'prefix' => 'sk-or-'],
        'ollama' => ['label' => 'Ollama', 'model' => '', 'prefix' => ''],
    ];

    public const PREFERENCE = ['anthropic', 'openai', 'openrouter', 'ollama', 'custom'];

    /** Spoken to through the OpenAI-compatible Chat Completions API. */
    public const COMPATIBLE = ['custom', 'openrouter', 'ollama'];

    public const OPENROUTER = 'https://openrouter.ai/api/v1';

    public const FALLBACK_MODELS = [
        'anthropic' => ['claude-opus-5-5' => 'Claude Opus 5.5', 'claude-sonnet-5-5' => 'Claude Sonnet 5.5', 'claude-haiku-5-5' => 'Claude Haiku 5.5', 'claude-fable-5-1' => 'Claude Fable 5.1'],
        'openai' => ['gpt-5' => 'GPT-5', 'gpt-5-mini' => 'GPT-5 mini'],
        'custom' => [],
        'openrouter' => ['openrouter/auto' => 'Auto router'],
        'ollama' => [],
    ];

    public const MODEL_PATTERN = '/^[A-Za-z0-9._:\/\[\]-]{1,80}$/';

    private const ANTHROPIC_VERSION = '2023-06-01';

    private const MAX_MODELS = ['openrouter' => 200, 'ollama' => 100];

    public function __construct(private readonly CompatibleEndpoint $endpoint) {}

    /** @return Ai */
    private static function own(string $provider, WorkspaceAiKey $key, ?string $model = null): array
    {
        return ['provider' => $provider, 'key' => $key->key, 'model' => $model ?? ($key->model ?: self::PROVIDERS[$provider]['model']), 'own' => true, 'workspace' => $key->workspace_id]
            + (in_array($provider, self::COMPATIBLE, true) ? ['url' => self::chatBase($provider, $key->base_url)] : []);
    }

    /**
     * A connected key with one of its models, or its default.
     *
     * @return Ai
     */
    public static function using(WorkspaceAiKey $key, ?string $model = null): array
    {
        return self::own($key->provider, $key, $model);
    }

    /** Where a provider's Chat Completions API lives: OpenRouter's is fixed, Ollama's is /v1 on its address. */
    public static function chatBase(string $provider, ?string $base): string
    {
        return match ($provider) {
            'openrouter' => self::OPENROUTER,
            'ollama' => rtrim((string) $base, '/').'/v1',
            default => (string) $base,
        };
    }

    /** An Ollama address as it's kept: its root, without the /v1 or /api a pasted address may end in. */
    public static function ollamaBase(string $url): ?string
    {
        $url = CompatibleEndpoint::normalise($url);

        return $url === null ? null : (string) preg_replace('#/(v1|api)$#', '', $url);
    }

    /** @return array<string, string> */
    public static function openRouterHeaders(): array
    {
        $title = (string) (config('services.openrouter.title') ?: config('app.name'));

        return [
            'HTTP-Referer' => (string) (config('services.openrouter.referer') ?: config('app.url')),
            'X-OpenRouter-Title' => $title,
            'X-Title' => $title,
        ];
    }

    /** @param  array{provider: string, model: string}  $ai */
    public static function seesImages(array $ai): bool
    {
        return match ($ai['provider']) {
            'anthropic' => true,
            // Every current OpenAI chat model reads images; only old text-only ones don't.
            'openai' => preg_match('/^(gpt-3\.5|gpt-4-|gpt-4$|o1-mini|o3-mini)/', $ai['model']) !== 1,
            default => $ai['model'] === config('services.groq.vision_model') || preg_match('/(llama-4|vision|qwen3\.[6-9])/i', $ai['model']) === 1,
        };
    }

    /**
     * The model for a message with images: Ask AI's own model moves to one that can see them.
     *
     * @param  Ai  $ai
     * @return Ai
     */
    public static function forImages(array $ai): array
    {
        $vision = (string) config('services.groq.vision_model');

        return ! $ai['own'] && ! self::seesImages($ai) && $vision !== '' ? [...$ai, 'model' => $vision] : $ai;
    }

    /** @param  Ai  $ai */
    public static function who(array $ai, string $hosted): string
    {
        if (! $ai['own']) {
            return $hosted;
        }

        return $ai['provider'] === 'ollama' ? 'your Ollama' : 'your '.(self::PROVIDERS[$ai['provider']]['label'] ?? $ai['provider']).' key';
    }

    public function key(Workspace|int $workspace, string $provider): ?WorkspaceAiKey
    {
        return WorkspaceAiKey::query()
            ->where('workspace_id', $workspace instanceof Workspace ? $workspace->id : $workspace)
            ->where('provider', $provider)
            ->first();
    }

    /**
     * The first key in the order of preference, or the panel's own model:
     * what a feature uses when nobody picked a model for it.
     *
     * @return Ai
     */
    public function forAgent(Workspace|int $workspace): array
    {
        foreach (self::PREFERENCE as $provider) {
            $key = $this->key($workspace, $provider);
            if ($key !== null) {
                return self::own($provider, $key);
            }
        }

        return self::hosted($workspace);
    }

    /** @return Ai */
    public static function hosted(Workspace|int $workspace): array
    {
        return ['provider' => 'groq', 'key' => (string) config('services.groq.key'), 'model' => (string) config('services.groq.model'), 'own' => false, 'workspace' => $workspace instanceof Workspace ? $workspace->id : $workspace];
    }

    /**
     * What one feature runs on: the provider and model picked for it while
     * that key is connected, otherwise the first key or the panel's model.
     *
     * @return Ai
     */
    public function forFeature(Workspace|int $workspace, string $feature): array
    {
        $choice = AiFeatures::choice($workspace, $feature);
        $key = $choice === null ? null : $this->key($workspace, $choice['provider']);
        $ai = $choice !== null && $key !== null ? self::own($choice['provider'], $key, $choice['model']) : $this->forAgent($workspace);

        return [...$ai, 'feature' => $feature];
    }

    /** @return Ai */
    public function forChat(Workspace|int $workspace, ?string $choice): array
    {
        if ($choice !== null && str_contains($choice, ':')) {
            [$provider, $model] = explode(':', $choice, 2);
            $key = isset(self::PROVIDERS[$provider]) && preg_match(self::MODEL_PATTERN, $model) === 1 ? $this->key($workspace, $provider) : null;
            if ($key !== null) {
                return [...self::own($provider, $key, $model), 'feature' => AiFeatures::ASSISTANT];
            }
        }

        return $this->forFeature($workspace, AiFeatures::ASSISTANT);
    }

    /**
     * Each connected key with the models it can use, read live from the provider and kept for an hour.
     *
     * @param  list<string>|null  $only
     * @return list<array{provider: string, label: string, default: string, models: list<ModelOption>}>
     */
    public function modelOptions(Workspace $workspace, ?array $only = null): array
    {
        $options = [];
        foreach (self::PREFERENCE as $provider) {
            if ($only !== null && ! in_array($provider, $only, true)) {
                continue;
            }
            $key = $this->key($workspace, $provider);
            if ($key === null) {
                continue;
            }
            $default = $key->model ?: self::PROVIDERS[$provider]['model'];
            $models = $this->models($key);
            if ($default !== '' && ! in_array($default, array_column($models, 'id'), true)) {
                array_unshift($models, ['id' => $default, 'label' => $default]);
            }
            $options[] = ['provider' => $provider, 'label' => self::PROVIDERS[$provider]['label'], 'default' => $default, 'models' => $models];
        }

        return $options;
    }

    /** @return list<ModelOption> */
    public function models(WorkspaceAiKey $key, bool $fresh = false): array
    {
        $provider = $key->provider;
        $cacheKey = "ai-models:{$key->workspace_id}:{$provider}:".substr(hash('sha256', $key->key.'|'.$key->base_url), 0, 16);
        $models = $fresh ? null : Cache::get($cacheKey);
        if (! is_array($models) || $models === []) {
            $models = $this->listModels($provider, $key->key, $key->base_url);
            if ($models !== []) {
                Cache::put($cacheKey, $models, now()->addHour());
            }
        }

        /** @var list<ModelOption> $models */
        return $models ?: array_map(fn (string $id, string $label) => ['id' => $id, 'label' => $label], array_keys(self::FALLBACK_MODELS[$provider] ?? []), self::FALLBACK_MODELS[$provider] ?? []);
    }

    /** @return list<ModelOption> */
    private function listModels(string $provider, string $key, ?string $url): array
    {
        try {
            $response = match ($provider) {
                'anthropic' => $this->anthropic($key, 8)->get('https://api.anthropic.com/v1/models', ['limit' => 100]),
                'custom' => $this->endpoint->request((string) $url, $key, 8)->get(CompatibleEndpoint::url((string) $url, 'models')),
                'openrouter' => $this->openRouter($key, 8)->get(self::OPENROUTER.'/models'),
                'ollama' => $this->endpoint->request((string) $url, $key, 8)->get(CompatibleEndpoint::url((string) $url, 'api/tags')),
                default => Http::withToken($key)->timeout(8)->get('https://api.openai.com/v1/models'),
            };
        } catch (ConnectionException|RuntimeException) {
            return [];
        }
        if (! $response->successful()) {
            return [];
        }

        return array_slice(self::readModels($provider, $response), 0, self::MAX_MODELS[$provider] ?? 30);
    }

    /**
     * Every model a provider listed, newest first.
     *
     * @return list<ModelOption>
     */
    private static function readModels(string $provider, Response $response): array
    {
        $models = [];
        $listed = $provider === 'ollama' ? (array) $response->json('models', []) : (array) $response->json('data', []);
        foreach ($listed as $model) {
            if (! is_array($model)) {
                continue;
            }
            $id = (string) ($provider === 'ollama' ? ($model['name'] ?? $model['model'] ?? '') : ($model['id'] ?? ''));
            if ($id === '' || preg_match(self::MODEL_PATTERN, $id) !== 1) {
                continue;
            }
            if ($provider === 'openai' && (preg_match('/^(gpt-|o\d|chatgpt-)/', $id) !== 1 || preg_match('/(embedding|audio|realtime|transcribe|tts|image|search|instruct|moderation|dall-e|whisper)/', $id) === 1)) {
                continue;
            }
            if ($provider === 'ollama' && preg_match('/embed/i', $id) === 1) {
                continue;
            }
            $label = match ($provider) {
                'ollama' => trim($id.(is_string($model['details']['parameter_size'] ?? null) ? ' · '.$model['details']['parameter_size'] : '')),
                'openrouter' => (string) ($model['name'] ?? $id),
                default => (string) ($model['display_name'] ?? $id),
            };
            $when = $model['created'] ?? $model['created_at'] ?? $model['modified_at'] ?? null;
            $models[] = ['id' => $id, 'label' => $label, 'created' => is_numeric($when) ? (int) $when : (is_string($when) ? (int) strtotime($when) : 0)];
        }
        usort($models, fn (array $a, array $b) => $b['created'] <=> $a['created']);

        return array_map(fn (array $m) => ['id' => $m['id'], 'label' => $m['label']], $models);
    }

    private function anthropic(string $key, int $timeout): PendingRequest
    {
        return Http::withHeaders(['x-api-key' => $key, 'anthropic-version' => self::ANTHROPIC_VERSION])->connectTimeout(10)->timeout($timeout);
    }

    private function openRouter(string $key, int $timeout): PendingRequest
    {
        return Http::withToken($key)->withHeaders(self::openRouterHeaders())->connectTimeout(10)->timeout($timeout);
    }

    /**
     * The request for an OpenAI-compatible provider: OpenRouter with its
     * app headers, anything else at its own address with that address pinned.
     *
     * @param  Ai  $ai
     *
     * @throws RuntimeException
     */
    public function compatible(array $ai, int $timeout): PendingRequest
    {
        return $ai['provider'] === 'openrouter'
            ? $this->openRouter($ai['key'], $timeout)
            : $this->endpoint->request((string) ($ai['url'] ?? ''), $ai['key'], $timeout);
    }

    /**
     * @param  Ai  $ai
     * @return array{0: array<string, mixed>, 1: string}
     *
     * @throws RuntimeException
     */
    public function json(array $ai, string $system, string $user, int $maxTokens = 1800): array
    {
        $response = $this->call($ai, $system, $user, $maxTokens, json: true);

        $text = match ($ai['provider']) {
            'openai' => $this->openaiJsonText($response),
            'anthropic' => self::anthropicText($response),
            default => (string) $response->json('choices.0.message.content'),
        };

        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        $answer = $start === false || $end === false ? null : json_decode($ai['provider'] === 'openai' ? $text : substr($text, $start, $end - $start + 1), true);
        if (! is_array($answer) || ($ai['provider'] === 'openai' && ! str_starts_with(ltrim($text), '{'))) {
            throw new RuntimeException('The model’s answer wasn’t in the shape it was asked for. Try again.');
        }

        return [$answer, (string) ($response->json('model') ?: $ai['model'])];
    }

    /**
     * A short plain answer, like the reply to a test prompt.
     *
     * @param  Ai  $ai
     * @return array{text: string, model: string, input: int, output: int}
     *
     * @throws RuntimeException
     */
    public function complete(array $ai, string $system, string $user, int $maxTokens = 200): array
    {
        $response = $this->call($ai, $system, $user, $maxTokens, json: false);
        $text = match ($ai['provider']) {
            'openai' => $this->openaiJsonText($response),
            'anthropic' => self::anthropicText($response),
            default => (string) $response->json('choices.0.message.content'),
        };
        [$input, $output] = AiUsageLog::tokens($ai['provider'], $response);

        return ['text' => trim($text), 'model' => (string) ($response->json('model') ?: $ai['model']), 'input' => $input, 'output' => $output];
    }

    /**
     * @param  Ai  $ai
     *
     * @throws RuntimeException
     */
    private function call(array $ai, string $system, string $user, int $maxTokens, bool $json): Response
    {
        if ($ai['key'] === '' && ! ($ai['own'] && $ai['provider'] === 'ollama')) {
            throw new RuntimeException('The agent isn’t set up on this ServerOS: there’s no model key.');
        }
        $who = self::who($ai, 'the model');

        try {
            $response = match ($ai['provider']) {
                'custom', 'openrouter', 'ollama' => $this->compatible($ai, 120)
                    ->post(CompatibleEndpoint::url((string) ($ai['url'] ?? self::chatBase($ai['provider'], null)), 'chat/completions'), [
                        'model' => $ai['model'],
                        'temperature' => 0.2,
                        'max_completion_tokens' => $maxTokens,
                        'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]],
                    ]),
                'anthropic' => $this->anthropic($ai['key'], 120)
                    ->post('https://api.anthropic.com/v1/messages', [
                        'model' => $ai['model'],
                        'max_tokens' => $maxTokens,
                        // No temperature: newer Claude models (Haiku 5.5 and up) refuse it.
                        'system' => $system,
                        'messages' => [['role' => 'user', 'content' => $user]],
                    ]),
                'openai' => Http::withToken($ai['key'])->connectTimeout(10)->timeout(120)
                    ->post('https://api.openai.com/v1/responses', array_filter([
                        'model' => $ai['model'],
                        'instructions' => $system,
                        'input' => [['role' => 'user', 'content' => $user]],
                        'max_output_tokens' => $maxTokens * 2,
                        'text' => $json ? ['format' => ['type' => 'json_object']] : null,
                        'store' => false,
                    ], fn ($v) => $v !== null)),
                default => Http::withToken($ai['key'])->connectTimeout(10)->timeout(90)
                    ->post('https://api.groq.com/openai/v1/chat/completions', array_filter([
                        'model' => $ai['model'],
                        'temperature' => 0.2,
                        'max_completion_tokens' => $maxTokens,
                        'reasoning_effort' => 'medium',
                        'include_reasoning' => false,
                        'response_format' => $json ? ['type' => 'json_object'] : null,
                        'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]],
                    ], fn ($v) => $v !== null)),
            };
        } catch (ConnectionException) {
            throw new RuntimeException("Couldn’t reach {$who}. Try again in a minute.");
        }

        if (! $response->successful()) {
            $why = (string) ($response->json('error.message') ?? '');
            throw new RuntimeException(match (true) {
                in_array($response->status(), [401, 403], true) => ucfirst($who).' was refused. Check it in Settings › AI and MCP.',
                $response->status() === 404 && $ai['own'] => "That model isn’t available to {$who}: ".$ai['model'].'.',
                $response->status() === 429 => ucfirst($who).' is out of requests for now. Try again in a minute.',
                default => ucfirst($who).' didn’t answer ('.$response->status().')'.($why !== '' ? ': '.rtrim(mb_substr($why, 0, 160), '.') : '').'. Try again in a minute.',
            });
        }
        AiUsageLog::record($ai, $response);

        return $response;
    }

    private static function anthropicText(Response $response): string
    {
        return implode('', array_map(fn ($c) => is_array($c) && ($c['type'] ?? null) === 'text' ? (string) ($c['text'] ?? '') : '', (array) $response->json('content', [])));
    }

    private function openaiJsonText(Response $response): string
    {
        if ($response->json('status') !== 'completed') {
            throw new RuntimeException('The model didn’t finish its answer. Try again.');
        }

        $text = '';
        foreach ((array) $response->json('output', []) as $item) {
            if (! is_array($item) || ($item['type'] ?? null) !== 'message') {
                continue;
            }
            if (($item['status'] ?? null) !== 'completed') {
                throw new RuntimeException('The model didn’t finish its answer. Try again.');
            }
            foreach ((array) ($item['content'] ?? []) as $part) {
                if (! is_array($part)) {
                    continue;
                }
                if (($part['type'] ?? null) === 'refusal') {
                    throw new RuntimeException('The model declined to answer. Try asking another way.');
                }
                if (($part['type'] ?? null) === 'output_text') {
                    $text .= (string) ($part['text'] ?? '');
                }
            }
        }

        return $text;
    }

    /** @throws RuntimeException */
    public function verify(string $provider, string $key, ?string $url = null): void
    {
        $this->account($provider, $key, $url);
    }

    /**
     * Checks a key with its provider and says what the provider told us
     * about the account: names and counts only, never the key. A refused
     * key throws with code 401; a wrong address with code 422.
     *
     * @return array{meta: array<string, string|int|float|bool|null>, models: list<ModelOption>}
     *
     * @throws RuntimeException
     */
    public function account(string $provider, string $key, ?string $url = null): array
    {
        $name = in_array($provider, ['custom', 'ollama'], true) ? CompatibleEndpoint::host((string) $url) : self::PROVIDERS[$provider]['label'];
        try {
            $response = match ($provider) {
                'anthropic' => $this->anthropic($key, 15)->get('https://api.anthropic.com/v1/models', ['limit' => 100]),
                'custom' => $this->endpoint->request((string) $url, $key, 15)->get(CompatibleEndpoint::url((string) $url, 'models')),
                'openrouter' => $this->openRouter($key, 15)->get(self::OPENROUTER.'/key'),
                'ollama' => $this->endpoint->request((string) $url, $key, 15)->get(CompatibleEndpoint::url((string) $url, 'api/tags')),
                default => Http::withToken($key)->timeout(15)->get('https://api.openai.com/v1/models'),
            };
        } catch (ConnectionException) {
            throw new RuntimeException('Couldn’t reach '.$name.' to check the key. Try again.', 422);
        } catch (RuntimeException $e) {
            throw new RuntimeException($e->getMessage(), 422);
        }
        if (in_array($response->status(), [401, 403], true)) {
            throw new RuntimeException($provider === 'ollama' ? $name.' didn’t accept that token.' : $name.' didn’t accept that key.', 401);
        }
        if (in_array($provider, ['custom', 'ollama'], true) && $response->redirect()) {
            throw new RuntimeException($name.' sent the request somewhere else. Use the address it ends up at.', 422);
        }
        $missing = $provider === 'ollama'
            ? 'There’s no Ollama at that address. Use the address Ollama listens on, like https://ollama.example.com.'
            : 'There’s no OpenAI-compatible API at that address. It usually ends in /v1.';
        if (in_array($provider, ['custom', 'ollama'], true) && $response->status() === 404) {
            throw new RuntimeException($missing, 422);
        }
        if (! $response->successful()) {
            throw new RuntimeException($name.' couldn’t check the key right now ('.$response->status().'). Try again.', 422);
        }
        // A site that serves a page for every path answers 200 too; an API lists its models.
        $list = $provider === 'ollama' ? 'models' : 'data';
        if (in_array($provider, ['custom', 'ollama'], true) && (! is_array($response->json()) || ! is_array($response->json($list)))) {
            throw new RuntimeException($missing, 422);
        }

        $listed = in_array($provider, ['openrouter', 'ollama'], true) ? [] : self::readModels($provider, $response);
        $found = match ($provider) {
            'anthropic' => ['meta' => array_filter([
                'organization' => self::header($response, 'anthropic-organization-id'),
                'workspace' => self::header($response, 'anthropic-workspace-id'),
                'models' => count($listed),
            ], fn ($v) => $v !== null), 'models' => $listed],
            'openai' => ['meta' => array_filter([
                'organization' => self::header($response, 'openai-organization'),
                'models' => count($listed),
            ], fn ($v) => $v !== null), 'models' => $listed],
            'openrouter' => $this->openRouterAccount($key, $response),
            'ollama' => $this->ollamaAccount($key, (string) $url, $response),
            default => ['meta' => ['models' => count($listed)], 'models' => $listed],
        };

        return ['meta' => $found['meta'], 'models' => array_slice($found['models'], 0, self::MAX_MODELS[$provider] ?? 30)];
    }

    /** @return array{meta: array<string, string|int|float|bool|null>, models: list<ModelOption>} */
    private function openRouterAccount(string $key, Response $response): array
    {
        $data = (array) $response->json('data', []);
        $label = is_string($data['label'] ?? null) && ! str_contains($data['label'], '...') ? mb_substr($data['label'], 0, 80) : null;
        $models = [];
        try {
            $listed = $this->openRouter($key, 15)->get(self::OPENROUTER.'/models');
            $models = $listed->successful() ? self::readModels('openrouter', $listed) : [];
        } catch (ConnectionException) {
        }

        return ['meta' => array_filter([
            'key_label' => $label,
            'limit' => is_numeric($data['limit'] ?? null) ? round((float) $data['limit'], 2) : null,
            'limit_remaining' => is_numeric($data['limit_remaining'] ?? null) ? round((float) $data['limit_remaining'], 2) : null,
            'limit_reset' => is_string($data['limit_reset'] ?? null) ? mb_substr($data['limit_reset'], 0, 20) : null,
            'usage_monthly' => is_numeric($data['usage_monthly'] ?? null) ? round((float) $data['usage_monthly'], 2) : null,
            'free_tier' => is_bool($data['is_free_tier'] ?? null) ? $data['is_free_tier'] : null,
            'models' => $models === [] ? null : count($models),
        ], fn ($v) => $v !== null), 'models' => $models];
    }

    /**
     * @return array{meta: array<string, string|int|float|bool|null>, models: list<ModelOption>}
     *
     * @throws RuntimeException
     */
    private function ollamaAccount(string $key, string $url, Response $tags): array
    {
        $models = self::readModels('ollama', $tags);
        if ($models === []) {
            throw new RuntimeException('That Ollama has no models yet. Pull one first, like ollama pull qwen3.', 422);
        }
        $version = null;
        try {
            $answer = $this->endpoint->request($url, $key, 10)->get(CompatibleEndpoint::url($url, 'api/version'));
            $version = $answer->successful() && is_string($answer->json('version')) ? mb_substr((string) $answer->json('version'), 0, 20) : null;
        } catch (ConnectionException|RuntimeException) {
        }

        return ['meta' => array_filter([
            'version' => $version,
            'host' => CompatibleEndpoint::host($url),
            'models' => count($models),
        ], fn ($v) => $v !== null), 'models' => $models];
    }

    private static function header(Response $response, string $name): ?string
    {
        $value = trim($response->header($name));

        return $value === '' ? null : mb_substr($value, 0, 80);
    }
}
