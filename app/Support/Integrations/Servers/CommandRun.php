<?php

namespace App\Support\Integrations\Servers;

final readonly class CommandRun
{
    public function __construct(
        public int $exitCode,
        public string $output = '',
    ) {}

    public function ok(): bool
    {
        return $this->exitCode === 0;
    }
}
