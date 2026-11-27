<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Workspace;
use App\Models\WorkspaceAiKey;
use App\Support\Ai\AiFeatures;
use App\Support\Ai\AiProviders;
use App\Support\Ai\CompatibleEndpoint;
use App\Support\Assistant\AssistantAllowance;
use App\Support\Audit\AuditContext;
use App\Support\Audit\Audited;
use App\Support\Integrations\Ai\AiAccounts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use RuntimeException;

class WorkspaceAiController extends Controller
{
    /** @return array{providers: list<array<string, mixed>>, agentUses: string, canManage: bool} */
    public static function props(Workspace $workspace, bool $canManage): array
    {
        AiAccounts::sync($workspace);
        $keys = WorkspaceAiKey::query()->where('workspace_id', $workspace->id)->with('user:id,name')->get()->keyBy('provider');
        $accounts = $workspace->integrations()->whereIn('provider', AiAccounts::PROVIDERS)->pluck('id', 'provider');
        $uses = app(AiProviders::class)->forFeature($workspace, AiFeatures::FIXES);

        return [
            'providers' => array_values(collect(AiProviders::PROVIDERS)->map(fn (array $p, string $provider) => [
                'provider' => $provider,
                'label' => $p['label'],
                'defaultModel' => $p['model'],
                'connected' => $keys->has($provider),
                'hint' => $keys->get($provider)?->hint,
                'model' => $keys->get($provider)?->model,
                'baseUrl' => $keys->get($provider)?->base_url,
                'by' => $keys->get($provider)?->user?->name,
                'connectedAt' => $keys->get($provider)?->created_at?->toIso8601String(),
                'accountId' => $accounts->get($provider),
                'fallbacks' => $canManage && $keys->has($provider) ? AiFeatures::fallbacks($workspace, $provider) : [],
            ])->all()),
            'agentUses' => $uses['own'] ? AiProviders::PROVIDERS[$uses['provider']]['label'].' · '.$uses['model'] : 'ServerOS',
            'canManage' => $canManage,
        ];
    }

    #[Audited]
    public function update(Request $request, Workspace $workspace, string $provider, AiProviders $ai): RedirectResponse
    {
        Gate::authorize('manageSettings', $workspace);
        abort_unless(isset(AiProviders::PROVIDERS[$provider]), 404);
        $existing = $ai->key($workspace, $provider);
        $custom = $provider === 'custom';
        $ollama = $provider === 'ollama';
        $addressed = $custom || $ollama;
        $data = $request->validate([
            'key' => [$existing || $ollama ? 'nullable' : 'required', 'string', $addressed ? 'min:1' : 'min:20', 'max:300', 'regex:/^\S+$/'],
            'model' => [$custom ? 'required' : 'nullable', 'string', 'max:80', 'regex:/^[A-Za-z0-9._:\/\[\]-]+$/'],
            'url' => $addressed ? ['required', 'string', 'max:255'] : ['prohibited'],
        ], [
            'key.regex' => 'Paste the key on its own, with no spaces.',
            'model.regex' => 'That isn’t a model name.',
            'model.required' => 'Name the model to use, like deepseek-chat.',
            'url.required' => $ollama ? 'Add the address Ollama listens on, like https://ollama.example.com.' : 'Add the API address, like https://openrouter.ai/api/v1.',
        ]);

        $key = trim((string) ($data['key'] ?? ''));
        $url = null;
        if ($addressed) {
            $url = $ollama ? AiProviders::ollamaBase((string) $data['url']) : CompatibleEndpoint::normalise((string) $data['url']);
            if ($url === null) {
                throw ValidationException::withMessages(['url' => 'That isn’t a web address. It starts with https://.']);
            }
        }
        $moved = $addressed && $existing !== null && $url !== $existing->base_url;
        $checks = $key !== '' || $moved || $existing === null;
        $found = null;
        if ($checks) {
            try {
                $found = $ai->account($provider, $key !== '' ? $key : (string) $existing?->key, $url);
            } catch (RuntimeException $e) {
                throw ValidationException::withMessages([$addressed && $e->getCode() !== 401 ? 'url' : 'key' => $e->getMessage()]);
            }
        }

        $model = trim((string) ($data['model'] ?? '')) ?: null;
        if ($ollama && $model === null) {
            $model = $existing->model ?? ($found['models'][0]['id'] ?? null);
        }
        $fresh = $key !== '' || $existing === null;
        WorkspaceAiKey::query()->updateOrCreate(['workspace_id' => $workspace->id, 'provider' => $provider], [
            ...($fresh ? [
                'key' => $key,
                // A short key, like a local Ollama's, would be shown almost whole: keep no hint.
                'hint' => strlen($key) >= 8 ? '…'.substr($key, -4) : '',
                'user_id' => $request->user()->id,
            ] : []),
            ...($url !== null ? ['base_url' => $url] : []),
            ...($checks ? ['verified_at' => now()] : []),
            'model' => $model,
        ]);
        if (in_array($provider, AiAccounts::PROVIDERS, true)) {
            AiAccounts::linked($workspace, $provider, $found['meta'] ?? [], $request->user());
        }
        Activity::record($workspace, $existing ? 'ai.update' : 'ai.connect', AiProviders::PROVIDERS[$provider]['label'], null, $request->user());

        return back();
    }

    #[Audited]
    public function destroy(Request $request, Workspace $workspace, string $provider): RedirectResponse
    {
        Gate::authorize('manageSettings', $workspace);
        $request->merge(['provider' => $provider])->validate(['provider' => [Rule::in(array_keys(AiProviders::PROVIDERS))]]);
        AiFeatures::forget($workspace, $provider);
        $deleted = WorkspaceAiKey::query()->where('workspace_id', $workspace->id)->where('provider', $provider)->delete();
        AiAccounts::unlinked($workspace, $provider);
        if ($deleted > 0) {
            Activity::record($workspace, 'ai.disconnect', AiProviders::PROVIDERS[$provider]['label'], null, $request->user());
            Inertia::flash('toast', ['type' => 'success', 'message' => 'Removed the '.AiProviders::PROVIDERS[$provider]['label'].' key.']);
        } else {
            AuditContext::nothingChanged();
        }

        return back();
    }

    /** Picks the provider and model one feature runs on, or puts it back to the first key. */
    #[Audited]
    public function models(Request $request, Workspace $workspace, AiProviders $ai): RedirectResponse
    {
        Gate::authorize('manageSettings', $workspace);
        $data = $request->validate([
            'feature' => ['required', 'string', Rule::in(array_keys(AiFeatures::ALL))],
            'choice' => ['nullable', 'string', 'max:110'],
        ]);
        $feature = (string) $data['feature'];
        $choice = trim((string) ($data['choice'] ?? ''));
        $provider = $model = null;
        if ($choice !== '') {
            [$provider, $model] = array_pad(explode(':', $choice, 2), 2, '');
            if (! in_array($provider, AiFeatures::ALL[$feature], true) || $ai->key($workspace, $provider) === null) {
                throw ValidationException::withMessages(['choice' => __('Connect that provider first.')]);
            }
            if (preg_match(AiProviders::MODEL_PATTERN, $model) !== 1) {
                throw ValidationException::withMessages(['choice' => __('That isn’t a model name.')]);
            }
        }

        $before = AiFeatures::choice($workspace, $feature);
        if ($before === ($provider === null ? null : ['provider' => $provider, 'model' => $model])) {
            AuditContext::nothingChanged();

            return back();
        }
        AiFeatures::choose($workspace, $feature, $provider, $model, $request->user());
        Activity::record($workspace, 'ai.models', AiFeatures::label($feature), null, $request->user(), [
            'model' => $provider === null ? null : AiProviders::PROVIDERS[$provider]['label'].' · '.$model,
        ]);

        return back();
    }

    /** Sends a tiny prompt on the key and says what came back and how long it took. */
    #[Audited]
    public function test(Request $request, Workspace $workspace, string $provider, AiProviders $ai): JsonResponse
    {
        Gate::authorize('manageSettings', $workspace);
        abort_unless(isset(AiProviders::PROVIDERS[$provider]), 404);
        $key = $ai->key($workspace, $provider) ?? abort(404);
        $data = $request->validate(['model' => ['nullable', 'string', 'max:80', 'regex:'.AiProviders::MODEL_PATTERN]]);
        $target = [...AiProviders::using($key, trim((string) ($data['model'] ?? '')) ?: null), 'feature' => AiFeatures::TEST];
        if ($target['model'] === '') {
            AuditContext::nothingChanged();

            return response()->json(['error' => __('Pick a model to test first.')], 422);
        }
        if (($refused = AssistantAllowance::take($workspace)) !== null) {
            AuditContext::nothingChanged();

            return response()->json(['error' => $refused], 429);
        }
        $started = hrtime(true);
        try {
            $answer = $ai->complete($target, 'You check that an API key works. Answer in one short sentence.', 'Say hello to ServerOS and name the model you are.', 120);
        } catch (RuntimeException $e) {
            Activity::record($workspace, 'ai.test', AiProviders::PROVIDERS[$provider]['label'], null, $request->user(), ['ok' => false, 'model' => $target['model']]);

            return response()->json(['error' => $e->getMessage(), 'ms' => (int) round((hrtime(true) - $started) / 1e6)], 422);
        }
        $ms = (int) round((hrtime(true) - $started) / 1e6);
        Activity::record($workspace, 'ai.test', AiProviders::PROVIDERS[$provider]['label'], null, $request->user(), ['ok' => true, 'model' => $answer['model']]);

        return response()->json([
            'reply' => mb_substr($answer['text'], 0, 600),
            'model' => $answer['model'],
            'ms' => $ms,
            'input' => $answer['input'],
            'output' => $answer['output'],
        ]);
    }
}
