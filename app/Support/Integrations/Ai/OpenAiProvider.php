<?php

namespace App\Support\Integrations\Ai;

use App\Support\Integrations\ConnectField;

final class OpenAiProvider extends AiModelProvider
{
    public function key(): string
    {
        return 'openai';
    }

    public function fields(): array
    {
        return [
            new ConnectField(
                key: 'key',
                label: self::t('API key'),
                help: self::t('Make a project key on OpenAI’s API keys page, with access to models. ServerOS lists the key’s models to check it.'),
                link: 'https://platform.openai.com/api-keys',
                linkLabel: self::t('Open API keys'),
                placeholder: 'sk-proj-…',
                rules: ['required', 'string', 'min:20', 'max:300', 'regex:/^\S+$/'],
            ),
        ];
    }
}
