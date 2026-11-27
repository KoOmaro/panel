<?php

namespace App\Support\Integrations\Ai;

use App\Models\WorkspaceAiKey;
use App\Support\Ai\AiProviders;
use App\Support\Edition;
use App\Support\Integrations\ConnectField;

/**
 * An Ollama server the workspace runs itself. Its address is resolved and
 * pinned for every request (CompatibleEndpoint), so private addresses work
 * only on a self-hosted panel with MONITORS_ALLOW_PRIVATE on.
 */
final class OllamaProvider extends AiModelProvider
{
    public function key(): string
    {
        return 'ollama';
    }

    public function fields(): array
    {
        return [
            new ConnectField(
                key: 'url',
                label: self::t('Address'),
                secret: false,
                help: Edition::selfHosted()
                    ? self::t('Where Ollama listens, like http://10.0.0.5:11434. A private address needs MONITORS_ALLOW_PRIVATE=true on this ServerOS.')
                    : self::t('Where Ollama listens, over https, like https://ollama.example.com. Put it behind a proxy that asks for a token.'),
                placeholder: Edition::selfHosted() ? 'http://10.0.0.5:11434' : 'https://ollama.example.com',
                rules: ['required', 'string', 'max:255'],
            ),
            new ConnectField(
                key: 'token',
                label: self::t('Token'),
                help: self::t('Only when the proxy in front of Ollama asks for one. It’s sent as a bearer token.'),
                placeholder: self::t('Optional'),
                rules: ['nullable', 'string', 'max:300', 'regex:/^\S*$/'],
            ),
        ];
    }

    /** @param  array<string, mixed>  $credentials */
    protected function secret(array $credentials): string
    {
        return trim((string) ($credentials['token'] ?? ''));
    }

    /** @param  array<string, mixed>  $credentials */
    protected function address(array $credentials): string
    {
        return AiProviders::ollamaBase((string) ($credentials['url'] ?? '')) ?? trim((string) ($credentials['url'] ?? ''));
    }

    protected function fieldFor(bool $refused): string
    {
        return $refused ? 'token' : 'url';
    }

    protected function tidy(array $credentials): array
    {
        return ['url' => $this->address($credentials)];
    }

    protected function credentialsOf(WorkspaceAiKey $key): array
    {
        return ['url' => (string) $key->base_url, 'token' => $key->key];
    }
}
