<?php

namespace App\Support\Integrations\Servers;

/**
 * What a resize by hand did: the provider action to wait on (a restart
 * that applies it), and whether the new size is in effect or waits for
 * the server's next restart.
 */
final readonly class SizeChange
{
    public function __construct(
        public ?string $actionId = null,
        public bool $applied = true,
    ) {}
}
