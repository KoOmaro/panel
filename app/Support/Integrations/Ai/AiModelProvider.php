<?php

namespace App\Support\Integrations\Ai;

use App\Models\Integration;
use App\Models\User;
use App\Models\WorkspaceAiKey;
use App\Support\Ai\AiFeatures;
use App\Support\Ai\AiProviders;
use App\Support\Integrations\Capability;
use App\Support\Integrations\ConnectedAccount;
use App\Support\Integrations\HasAccountPage;
use App\Support\Integrations\IntegrationFailed;
use App\Support\Integrations\KeepsCredentials;
use App\Support\Integrations\Provider;
use App\Support\Integrations\WorkspaceIntegrations;
use RuntimeException;

/**
 * An AI provider a workspace brings its own key for. The key lives in
 * workspace_ai_keys, where Settings › AI and MCP keeps it and every feature
 * reads it; the integration row is its account page. See AiAccounts.
 */
abstract class AiModelProvider extends Provider implements HasAccountPage, KeepsCredentials
{
    public function name(): string
    {
        return AiProviders::PROVIDERS[$this->key()]['label'];
    }

    public function multipleAccounts(): bool
    {
        return false;
    }

    public function ability(): string
    {
        return WorkspaceIntegrations::SETTINGS;
    }

    public function capabilities(): array
    {
        return [Capability::AskAi, Capability::ServerFixes, Capability::Readmes, Capability::CodingAgents, Capability::TokenUsage];
    }

    public function describe(Capability $capability): string
    {
        if ($capability !== Capability::CodingAgents) {
            return $capability->description();
        }

        return match ($this->key()) {
            'anthropic' => self::t('Claude Code in agents and workflow agent steps gets the key when it runs, so it bills to your account.'),
            'openai' => self::t('Codex in agents and workflow agent steps gets the key when it runs, so it bills to your account.'),
            'openrouter' => self::t('OpenCode in agents and workflow agent steps gets the key for openrouter/ models.'),
            default => self::t('OpenCode in agents and workflow agent steps runs ollama/ models on this Ollama.'),
        };
    }

    /**
     * The key or token the person typed, from the connect form.
     *
     * @param  array<string, mixed>  $credentials
     */
    protected function secret(array $credentials): string
    {
        return trim((string) ($credentials['key'] ?? ''));
    }

    /**
     * The provider's address, for the providers that have one.
     *
     * @param  array<string, mixed>  $credentials
     */
    protected function address(array $credentials): ?string
    {
        return null;
    }

    /**
     * @param  array<string, string>  $credentials
     *
     * @throws IntegrationFailed
     */
    public function verify(array $credentials): ConnectedAccount
    {
        try {
            $found = app(AiProviders::class)->account($this->key(), $this->secret($credentials), $this->address($credentials));
        } catch (RuntimeException $e) {
            $refused = $e->getCode() === 401;

            throw new IntegrationFailed($e->getMessage(), $refused ? IntegrationFailed::CREDENTIALS : IntegrationFailed::INVALID, $refused ? 401 : null, $this->fieldFor($refused));
        }
        $meta = $found['meta'];
        if ($this->key() === 'ollama' && isset($found['models'][0])) {
            $meta['first_model'] = $found['models'][0]['id'];
        }

        return new ConnectedAccount($this->name(), $meta, $this->tidy($credentials));
    }

    /** The connect field an error belongs on. */
    protected function fieldFor(bool $refused): ?string
    {
        return null;
    }

    /**
     * @param  array<string, string>  $credentials
     * @return array<string, string>
     */
    protected function tidy(array $credentials): array
    {
        return [];
    }

    public function keep(Integration $integration, array $credentials, User $by): void
    {
        $secret = $this->secret($credentials);
        $model = $this->key() === 'ollama' && is_string($integration->meta['first_model'] ?? null) ? $integration->meta['first_model'] : null;
        WorkspaceAiKey::query()->updateOrCreate(
            ['workspace_id' => $integration->workspace_id, 'provider' => $this->key()],
            [
                'key' => $secret,
                // A short key, like a local Ollama's, would be shown almost whole: keep no hint.
                'hint' => strlen($secret) >= 8 ? '…'.substr($secret, -4) : '',
                'model' => $model,
                'base_url' => $this->address($credentials),
                'user_id' => $by->id,
                'verified_at' => now(),
            ],
        );
    }

    public function credentials(Integration $integration): array
    {
        $key = $this->stored($integration);

        return $key === null ? [] : $this->credentialsOf($key);
    }

    /** @return array<string, string> */
    protected function credentialsOf(WorkspaceAiKey $key): array
    {
        return ['key' => $key->key];
    }

    protected function stored(Integration $integration): ?WorkspaceAiKey
    {
        return app(AiProviders::class)->key($integration->workspace_id, $this->key());
    }

    public function summary(Integration $integration): string
    {
        return AiAccounts::summary($integration->workspace, $this->key());
    }

    public function facts(Integration $integration): array
    {
        $meta = $integration->meta ?? [];
        $key = $this->stored($integration);
        $money = fn (mixed $value): string => '$'.number_format((float) $value, 2);

        return array_values(array_filter([
            isset($meta['organization']) ? ['label' => self::t('Organization'), 'value' => (string) $meta['organization']] : null,
            isset($meta['workspace']) ? ['label' => self::t(':provider workspace', ['provider' => $this->name()]), 'value' => (string) $meta['workspace']] : null,
            isset($meta['key_label']) ? ['label' => self::t('Key name'), 'value' => (string) $meta['key_label']] : null,
            isset($meta['host']) ? ['label' => self::t('Address'), 'value' => (string) $meta['host']] : null,
            isset($meta['version']) ? ['label' => self::t('Version'), 'value' => (string) $meta['version']] : null,
            isset($meta['limit']) ? ['label' => self::t('Credit limit'), 'value' => $money($meta['limit']).(isset($meta['limit_reset']) ? ' · '.(string) $meta['limit_reset'] : '')] : null,
            isset($meta['limit_remaining']) ? ['label' => self::t('Credit left'), 'value' => $money($meta['limit_remaining'])] : null,
            isset($meta['usage_monthly']) ? ['label' => self::t('Spent this month'), 'value' => $money($meta['usage_monthly'])] : null,
            isset($meta['free_tier']) ? ['label' => self::t('Free tier'), 'value' => $meta['free_tier'] ? self::t('Yes') : self::t('No')] : null,
            isset($meta['models']) ? ['label' => self::t('Models'), 'value' => (string) $meta['models']] : null,
            $key !== null && ($key->model ?: AiProviders::PROVIDERS[$this->key()]['model']) !== '' ? ['label' => self::t('Default model'), 'value' => (string) ($key->model ?: AiProviders::PROVIDERS[$this->key()]['model'])] : null,
            $key !== null && $key->hint !== '' ? ['label' => self::t('Key'), 'value' => $key->hint] : null,
        ]));
    }

    public function usage(Integration $integration): array
    {
        return AiAccounts::usageLines($integration->workspace, $this->key());
    }

    public function dependents(Integration $integration): array
    {
        return AiFeatures::fallbacks($integration->workspace, $this->key());
    }

    public function disconnect(Integration $integration): ?string
    {
        AiFeatures::forget($integration->workspace, $this->key());
        WorkspaceAiKey::query()->where('workspace_id', $integration->workspace_id)->where('provider', $this->key())->delete();

        return self::t('Disconnected :name. ServerOS forgot the key, and nothing changed at :provider.', ['name' => $integration->label, 'provider' => $this->name()]);
    }

    public function accountPage(Integration $integration): array
    {
        return AiAccounts::page($integration, $this);
    }
}
