<?php

namespace App\Support\Integrations;

/**
 * What verify() learned about the account: a name to suggest for it, facts
 * kept as the integration's meta (never secrets), and connect fields it
 * settled that the person left empty (like which project), kept with the
 * credentials so checking it again finds the same thing.
 */
final readonly class ConnectedAccount
{
    /**
     * @param  array<string, string|int|float|bool|null>  $meta
     * @param  array<string, string>  $credentials
     */
    public function __construct(
        public string $label,
        public array $meta = [],
        public array $credentials = [],
    ) {}
}
