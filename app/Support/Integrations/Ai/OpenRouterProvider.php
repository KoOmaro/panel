<?php

namespace App\Support\Integrations\Ai;

use App\Support\Integrations\ConnectField;

final class OpenRouterProvider extends AiModelProvider
{
    public function key(): string
    {
        return 'openrouter';
    }

    public function fields(): array
    {
        return [
            new ConnectField(
                key: 'key',
                label: self::t('API key'),
                help: self::t('Make a key on OpenRouter’s keys page. A credit limit on it caps what ServerOS can spend.'),
                link: 'https://openrouter.ai/settings/keys',
                linkLabel: self::t('Open OpenRouter keys'),
                placeholder: 'sk-or-v1-…',
                rules: ['required', 'string', 'min:20', 'max:300', 'regex:/^\S+$/'],
            ),
        ];
    }
}
