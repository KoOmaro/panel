<?php

namespace App\Support\Integrations\Servers;

use App\Support\Integrations\Capability;

/**
 * A provider whose account holds servers that ServerOS can do less with
 * than the account allows, like OVHcloud's dedicated servers next to its
 * Public Cloud instances.
 */
interface LimitsServers
{
    /** @return list<Capability> what ServerOS can do with this one server */
    public function capabilitiesOf(Server $server): array;
}
