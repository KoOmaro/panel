<?php

namespace App\Support\Integrations\Servers;

use App\Models\Integration;
use App\Support\Integrations\IntegrationFailed;

/**
 * A provider that makes a server in two goes: create() starts it (Proxmox
 * clones a template), and once that action is done prepare() gives it its
 * size and settings and starts it. ServerOperations shows the first part as
 * "Make the server".
 *
 * @throws IntegrationFailed
 */
interface PreparesServers
{
    /** Returns the action to wait on while it starts, or null when there's none to follow. */
    public function prepare(Integration $integration, string $id, NewServer $server): ?string;
}
