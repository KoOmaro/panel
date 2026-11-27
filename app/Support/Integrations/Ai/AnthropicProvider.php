<?php

namespace App\Support\Integrations\Ai;

use App\Support\Integrations\ConnectField;

final class AnthropicProvider extends AiModelProvider
{
    public function key(): string
    {
        return 'anthropic';
    }

    public function fields(): array
    {
        return [
            new ConnectField(
                key: 'key',
                label: self::t('API key'),
                help: self::t('Make one in the Claude Console under API keys. ServerOS lists the key’s models to check it.'),
                link: 'https://platform.claude.com/settings/keys',
                linkLabel: self::t('Open the Claude Console'),
                placeholder: 'sk-ant-api03-…',
                rules: ['required', 'string', 'min:20', 'max:300', 'regex:/^\S+$/'],
            ),
        ];
    }
}
