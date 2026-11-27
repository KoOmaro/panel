<?php

namespace App\Support\Integrations\Ai;

use App\Models\Integration;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceAiKey;
use App\Support\Ai\AiFeatures;
use App\Support\Ai\AiProviders;
use App\Support\Ai\AiUsageLog;
use App\Support\Integrations\IntegrationProviders;
use Illuminate\Support\Facades\Gate;

/**
 * Keeps one integration row beside each AI key, so a key connected in
 * Settings › AI and MCP has an account page and one connected on the
 * Integrations page shows in Settings. The key itself is only ever in
 * workspace_ai_keys; the row holds what the provider said about the account.
 */
final class AiAccounts
{
    public const array PROVIDERS = ['anthropic', 'openai', 'openrouter', 'ollama'];

    /** Adds the missing rows for keys connected before, and drops rows whose key is gone. */
    public static function sync(Workspace $workspace): void
    {
        $keys = WorkspaceAiKey::query()->where('workspace_id', $workspace->id)->whereIn('provider', self::PROVIDERS)->get()->keyBy('provider');
        $rows = $workspace->integrations()->whereIn('provider', self::PROVIDERS)->get()->keyBy('provider');

        foreach ($rows as $provider => $row) {
            if (! $keys->has($provider)) {
                $row->delete();
            }
        }
        foreach ($keys as $provider => $key) {
            if (! $rows->has($provider)) {
                self::linked($workspace, (string) $provider, [], $key->user_id, $key);
            }
        }
    }

    /**
     * The row for a key that was just connected or changed. With meta, it
     * was just checked; a key found without a row keeps its own dates.
     *
     * @param  array<string, string|int|float|bool|null>  $meta
     */
    public static function linked(Workspace $workspace, string $provider, array $meta, User|int|null $by = null, ?WorkspaceAiKey $found = null): Integration
    {
        $row = $workspace->integrations()->where('provider', $provider)->first()
            ?? $workspace->integrations()->make([
                'provider' => $provider,
                'label' => AiProviders::PROVIDERS[$provider]['label'],
                'credentials' => [],
                'fingerprint' => Integration::fingerprintOf($provider, ['workspace' => (string) $workspace->id]),
                'created_by' => $by instanceof User ? $by->id : $by,
            ]);
        $row->forceFill([
            'meta' => [...($row->meta ?? []), ...$meta],
            'status' => Integration::CONNECTED,
            'last_checked_at' => $meta !== [] ? now() : ($row->last_checked_at ?? $found?->verified_at),
            'last_error' => null,
        ]);
        if (! $row->exists && $found !== null) {
            $row->created_at = $found->created_at->toImmutable();
        }
        $row->save();

        return $row;
    }

    public static function unlinked(Workspace $workspace, string $provider): void
    {
        $workspace->integrations()->where('provider', $provider)->delete();
    }

    /**
     * The features that run on a provider's key now.
     *
     * @return list<string>
     */
    public static function usedBy(Workspace $workspace, string $provider): array
    {
        return array_values(array_map(
            fn (array $row) => $row['label'],
            array_filter(AiFeatures::rows($workspace), fn (array $row) => self::runsOn($row, $workspace, $provider)),
        ));
    }

    /** One line for the Connected list: which features run on the key. */
    public static function summary(Workspace $workspace, string $provider): string
    {
        $features = self::usedBy($workspace, $provider);

        return match (count($features)) {
            0 => self::t('Used when you pick its models'),
            1 => self::t('Used by :feature', ['feature' => $features[0]]),
            2 => self::t('Used by :first and :second', ['first' => $features[0], 'second' => $features[1]]),
            default => self::t('Used by :first and :count more', ['first' => $features[0], 'count' => count($features) - 1]),
        };
    }

    /** @param  array{key: string, choice: string|null}  $row */
    private static function runsOn(array $row, Workspace $workspace, string $provider): bool
    {
        if (AiFeatures::coding($row['key'])) {
            return $row['choice'] !== null && str_starts_with($row['choice'], $provider.':');
        }

        return app(AiProviders::class)->forFeature($workspace, $row['key'])['provider'] === $provider;
    }

    /**
     * What the key does in this workspace, for the account page.
     *
     * @return list<string>
     */
    public static function usageLines(Workspace $workspace, string $provider): array
    {
        $ai = app(AiProviders::class);
        $lines = [];
        foreach (AiFeatures::rows($workspace) as $row) {
            if (AiFeatures::coding($row['key'])) {
                if ($row['choice'] !== null && str_starts_with($row['choice'], $provider.':')) {
                    $lines[] = self::t(':feature run :agent with :model when they don’t pick one.', ['feature' => $row['label'], 'agent' => AiFeatures::agentName($provider), 'model' => substr($row['choice'], strlen($provider) + 1)]);
                }

                continue;
            }
            $uses = $ai->forFeature($workspace, $row['key']);
            if ($uses['provider'] === $provider) {
                $lines[] = self::t(':feature uses :model from this account.', ['feature' => $row['label'], 'model' => $uses['model']]);
            }
        }
        $lines[] = match ($provider) {
            'anthropic' => self::t('Claude Code agents get this key when they run.'),
            'openai' => self::t('Codex agents get this key when they run.'),
            'openrouter' => self::t('OpenCode agents on openrouter/ models get this key when they run.'),
            default => self::t('OpenCode agents on ollama/ models use this Ollama.'),
        };

        return $lines;
    }

    /**
     * The account page's AI part: the key's models, the model each feature
     * uses, and the last 30 days of tokens.
     *
     * @return array<string, mixed>
     */
    public static function page(Integration $integration, AiModelProvider $provider): array
    {
        $workspace = $integration->workspace;
        $key = app(AiProviders::class)->key($workspace, $provider->key());

        return [
            'kind' => 'ai',
            'provider' => $provider->key(),
            'models' => $key === null ? [] : app(AiProviders::class)->models($key),
            'defaultModel' => $key === null ? null : ($key->model ?: AiProviders::PROVIDERS[$provider->key()]['model']),
            ...AiFeatures::props($workspace),
            'usage' => AiUsageLog::summary($workspace, $provider->key()),
            'canManage' => Gate::allows('manageSettings', $workspace),
        ];
    }

    public static function provider(string $key): ?AiModelProvider
    {
        $provider = app(IntegrationProviders::class)->find($key);

        return $provider instanceof AiModelProvider ? $provider : null;
    }

    /** @param  array<string, string|int>  $replace */
    private static function t(string $text, array $replace = []): string
    {
        return is_string($line = __($text, $replace)) ? $line : $text;
    }
}
