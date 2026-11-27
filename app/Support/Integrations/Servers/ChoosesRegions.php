<?php

namespace App\Support\Integrations\Servers;

use App\Models\Integration;
use App\Support\Integrations\IntegrationFailed;

/**
 * A provider whose servers are listed one region at a time (AWS). The
 * account page lets people pick the regions ServerOS looks in, kept in the
 * account's settings as "regions"; the default region is always one.
 *
 * @throws IntegrationFailed
 */
interface ChoosesRegions
{
    /** @return list<ServerLocation> every region the account can use */
    public function regions(Integration $integration): array;

    /** @return list<string> the regions servers are listed in and made in, the default first */
    public function chosenRegions(Integration $integration): array;

    public function defaultRegion(Integration $integration): string;
}
