<?php

namespace App\Support\Integrations;

/**
 * One field of a provider's connect form. Secret fields are stored encrypted
 * with the account's credentials and are never sent back to the browser.
 * A field with options is a choice, and each option can bring its own link
 * (where to make the token for that choice). A snippet is text to copy
 * next to the field, like the IAM policy an access key needs.
 */
final readonly class ConnectField
{
    /**
     * @param  list<string>  $rules
     * @param  list<array{value: string, label: string, link?: string|null}>  $options
     */
    public function __construct(
        public string $key,
        public string $label,
        public bool $secret = true,
        public ?string $help = null,
        public ?string $link = null,
        public ?string $linkLabel = null,
        public ?string $placeholder = null,
        public array $rules = ['required', 'string', 'max:500'],
        public ?string $value = null,
        public array $options = [],
        public ?string $snippet = null,
        public ?string $snippetLabel = null,
    ) {}

    /** @return array{key: string, label: string, secret: bool, help: string|null, link: string|null, linkLabel: string|null, placeholder: string|null, value: string|null, required: bool, options: list<array{value: string, label: string, link?: string|null}>, snippet: string|null, snippetLabel: string|null} */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'secret' => $this->secret,
            'help' => $this->help,
            'link' => $this->link,
            'linkLabel' => $this->linkLabel,
            'placeholder' => $this->placeholder,
            'value' => $this->secret ? null : $this->value,
            'required' => in_array('required', $this->rules, true),
            'options' => $this->options,
            'snippet' => $this->snippet,
            'snippetLabel' => $this->snippetLabel,
        ];
    }
}
