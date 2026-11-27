<?php

namespace App\Support\Integrations\Servers;

use App\Models\Integration;
use App\Support\Integrations\IntegrationFailed;

/**
 * A provider whose servers have no fixed types: cores, memory and disk are
 * picked by hand when one is made or resized (Proxmox VE). Its rules() say
 * so with ServerRules::SIZING_CUSTOM, and resizeTypes() is never asked.
 *
 * @throws IntegrationFailed
 */
interface SizesFreely
{
    public function sizeLimits(Integration $integration, Server $server): SizeLimits;

    /** Applies a new size. A change that needs a restart restarts the server only when $restart is true. */
    public function resizeTo(Integration $integration, string $id, ServerSize $size, bool $restart): SizeChange;
}
