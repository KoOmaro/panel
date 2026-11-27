<?php

namespace App\Support\Integrations;

use App\Models\Integration;
use App\Models\User;

/**
 * A provider whose credentials already live where the rest of the panel
 * reads them, like AI keys in workspace_ai_keys. Its integration row keeps
 * none: connecting hands them over here, and Check again reads them back.
 */
interface KeepsCredentials
{
    /** @param  array<string, string>  $credentials */
    public function keep(Integration $integration, array $credentials, User $by): void;

    /** @return array<string, string> */
    public function credentials(Integration $integration): array;
}
