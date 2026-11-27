<?php

namespace App\Support\Integrations\Tailscale;

use RuntimeException;

/**
 * Why a machine can't be put on a tailnet or taken off right now, in words
 * a person can act on; manual when running the commands by hand would work.
 */
final class TailnetRefused extends RuntimeException
{
    public function __construct(string $message, public readonly bool $manual = false)
    {
        parent::__construct($message);
    }
}
