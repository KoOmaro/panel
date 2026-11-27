<?php

namespace App\Support\Ai;

use App\Models\AgentTask;
use App\Models\User;
use App\Models\Workflow;
use App\Models\Workspace;
use App\Models\WorkspaceAiModel;

/**
 * Every place the panel uses a model, and which provider and model each one
 * runs on. Nothing picked means the first key connected (Anthropic, OpenAI,
 * OpenRouter, Ollama, then an OpenAI-compatible API) or ServerOS's own model.
 * Agents and workflow agent steps run a coding agent on a machine instead:
 * the pick sets their agent and model when the task or step leaves it out.
 *
 * @phpstan-import-type ModelOption from AiProviders
 *
 * @phpstan-type FeatureRow array{key: string, label: string, about: string, choice: string|null, uses: string, own: bool, providers: list<string>}
 */
final class AiFeatures
{
    public const string ASSISTANT = 'assistant';

    public const string FIXES = 'fixes';

    public const string README = 'readme';

    public const string AGENTS = 'agents';

    public const string WORKFLOWS = 'workflows';

    public const string TEST = 'test';

    public const string OTHER = 'other';

    /** Each feature and the providers it can run on. */
    public const array ALL = [
        self::ASSISTANT => ['anthropic', 'openai', 'openrouter', 'ollama', 'custom'],
        self::FIXES => ['anthropic', 'openai', 'openrouter', 'ollama', 'custom'],
        self::README => ['anthropic', 'openai', 'openrouter', 'ollama', 'custom'],
        self::AGENTS => ['anthropic', 'openai', 'openrouter', 'ollama'],
        self::WORKFLOWS => ['anthropic', 'openai', 'openrouter', 'ollama'],
    ];

    /** The coding agent that runs on each provider's key. */
    public const array AGENT = ['anthropic' => 'claude', 'openai' => 'codex', 'openrouter' => 'opencode', 'ollama' => 'opencode'];

    public static function coding(string $feature): bool
    {
        return in_array($feature, [self::AGENTS, self::WORKFLOWS], true);
    }

    public static function label(string $feature): string
    {
        return self::t(match ($feature) {
            self::ASSISTANT => 'Ask AI',
            self::FIXES => 'Server fixes',
            self::README => 'Machine readmes',
            self::AGENTS => 'Agents',
            self::WORKFLOWS => 'Workflow agent steps',
            self::TEST => 'Tests',
            default => 'Other',
        });
    }

    public static function about(string $feature): string
    {
        return self::t(match ($feature) {
            self::ASSISTANT => 'The assistant in the panel. A chat can still pick another model.',
            self::FIXES => 'Looks into a problem on a machine and proposes the steps to fix it.',
            self::README => 'Writes the readme on each machine’s page.',
            self::AGENTS => 'Coding agents you start from the Agents page, when the task doesn’t pick a model.',
            self::WORKFLOWS => 'Agent steps in workflows, when the step doesn’t pick a model.',
            default => '',
        });
    }

    /**
     * The pick for a feature, if there is one.
     *
     * @return array{provider: string, model: string}|null
     */
    public static function choice(Workspace|int $workspace, string $feature): ?array
    {
        $row = WorkspaceAiModel::query()
            ->where('workspace_id', $workspace instanceof Workspace ? $workspace->id : $workspace)
            ->where('feature', $feature)
            ->first(['provider', 'model']);

        return $row === null ? null : ['provider' => $row->provider, 'model' => $row->model];
    }

    public static function choose(Workspace $workspace, string $feature, ?string $provider, ?string $model, ?User $by): void
    {
        if ($provider === null || $model === null) {
            WorkspaceAiModel::query()->where('workspace_id', $workspace->id)->where('feature', $feature)->delete();

            return;
        }
        WorkspaceAiModel::query()->updateOrCreate(
            ['workspace_id' => $workspace->id, 'feature' => $feature],
            ['provider' => $provider, 'model' => $model, 'user_id' => $by?->id],
        );
    }

    /** Takes back every pick of a provider whose key is going. */
    public static function forget(Workspace $workspace, string $provider): void
    {
        WorkspaceAiModel::query()->where('workspace_id', $workspace->id)->where('provider', $provider)->delete();
    }

    /** A coding agent's model name for a provider's model, like openrouter/qwen/qwen3-coder for OpenCode. */
    public static function agentModel(string $provider, string $model): string
    {
        return in_array($provider, ['openrouter', 'ollama'], true) ? "{$provider}/{$model}" : $model;
    }

    /**
     * The agent and model a coding feature starts with, when one was picked
     * and its key is still connected.
     *
     * @return array{agent: string, model: string, provider: string}|null
     */
    public static function agentDefault(Workspace $workspace, string $feature): ?array
    {
        $choice = self::choice($workspace, $feature);
        if ($choice === null || ! isset(self::AGENT[$choice['provider']]) || app(AiProviders::class)->key($workspace, $choice['provider']) === null) {
            return null;
        }

        return ['agent' => self::AGENT[$choice['provider']], 'model' => self::agentModel($choice['provider'], $choice['model']), 'provider' => $choice['provider']];
    }

    /**
     * Each feature with what it uses now, for the picker.
     *
     * @return list<FeatureRow>
     */
    public static function rows(Workspace $workspace): array
    {
        $ai = app(AiProviders::class);
        $rows = [];
        foreach (self::ALL as $feature => $providers) {
            $choice = self::choice($workspace, $feature);
            $live = $choice !== null && $ai->key($workspace, $choice['provider']) !== null ? $choice : null;
            if (self::coding($feature)) {
                $uses = $live === null
                    ? ($feature === self::AGENTS ? self::t('The agent and model each task picks') : self::t('The agent and model each step picks'))
                    : self::t(':agent with :model', ['agent' => self::agentName($live['provider']), 'model' => $live['model']]);
                $own = $live !== null;
            } else {
                $resolved = $ai->forFeature($workspace, $feature);
                $uses = self::describe($resolved);
                $own = $resolved['own'];
            }
            $rows[] = [
                'key' => $feature,
                'label' => self::label($feature),
                'about' => self::about($feature),
                'choice' => $live === null ? null : "{$live['provider']}:{$live['model']}",
                'uses' => $uses,
                'own' => $own,
                'providers' => $providers,
            ];
        }

        return $rows;
    }

    /**
     * The picker's rows and the models of every connected key, read live.
     *
     * @return array{features: list<FeatureRow>, options: list<array{provider: string, label: string, default: string, models: list<ModelOption>}>}
     */
    public static function props(Workspace $workspace): array
    {
        return ['features' => self::rows($workspace), 'options' => app(AiProviders::class)->modelOptions($workspace)];
    }

    /** @param  array{provider: string, model: string, own: bool}  $ai */
    public static function describe(array $ai): string
    {
        if (! $ai['own']) {
            return filled(config('services.groq.key')) ? self::t('ServerOS’s own model') : self::t('No model: connect a key');
        }

        return (AiProviders::PROVIDERS[$ai['provider']]['label'] ?? $ai['provider']).' · '.$ai['model'];
    }

    /**
     * What changes when a provider's key goes: which features move to
     * another key or to ServerOS's own model, and which agents lose it.
     *
     * @return list<string>
     */
    public static function fallbacks(Workspace $workspace, string $provider): array
    {
        $ai = app(AiProviders::class);
        if ($ai->key($workspace, $provider) === null) {
            return [];
        }
        $next = null;
        foreach (AiProviders::PREFERENCE as $other) {
            $key = $other === $provider ? null : $ai->key($workspace, $other);
            if ($key !== null) {
                $next = ['provider' => $other, 'model' => $key->model ?: AiProviders::PROVIDERS[$other]['model'], 'own' => true];
                break;
            }
        }
        $to = self::describe($next ?? ['provider' => 'groq', 'model' => '', 'own' => false]);

        $lines = [];
        foreach (array_keys(self::ALL) as $feature) {
            if (self::coding($feature)) {
                $choice = self::choice($workspace, $feature);
                if ($choice !== null && $choice['provider'] === $provider) {
                    $lines[] = self::t(':feature go back to the agent and model each one picks.', ['feature' => self::label($feature)]);
                }

                continue;
            }
            if ($ai->forFeature($workspace, $feature)['provider'] === $provider) {
                $lines[] = self::t(':feature moves to :to.', ['feature' => self::label($feature), 'to' => $to]);
            }
        }
        if (! self::runsAgents($workspace)) {
            return $lines;
        }
        $lines[] = match ($provider) {
            'anthropic' => self::t('Claude Code agents need ANTHROPIC_API_KEY set on their container.'),
            'openai' => self::t('Codex agents need OPENAI_API_KEY set on their container.'),
            'openrouter' => self::t('OpenCode agents on OpenRouter models need OPENROUTER_API_KEY set on their container.'),
            'ollama' => self::t('OpenCode agents on Ollama models go back to the Ollama on their own machine.'),
            default => '',
        };

        return array_values(array_filter($lines));
    }

    private static function runsAgents(Workspace $workspace): bool
    {
        if (AgentTask::query()->where('workspace_id', $workspace->id)->exists()) {
            return true;
        }

        return $workspace->workflows()->get(['graph'])
            ->contains(fn (Workflow $workflow) => collect($workflow->graph['nodes'] ?? [])->contains(fn ($node) => ($node['kind'] ?? null) === 'agent'));
    }

    public static function agentName(string $provider): string
    {
        return match (self::AGENT[$provider] ?? null) {
            'claude' => 'Claude Code',
            'codex' => 'Codex',
            default => 'OpenCode',
        };
    }

    /** @param  array<string, string|int>  $replace */
    private static function t(string $text, array $replace = []): string
    {
        return is_string($line = __($text, $replace)) ? $line : $text;
    }
}
