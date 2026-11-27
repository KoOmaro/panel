<?php

namespace App\Support\Integrations\Servers;

use App\Models\Integration;
use App\Models\IntegrationServer;
use App\Support\Integrations\IntegrationFailed;

/**
 * The account page's Regions tray for providers that choose regions: every
 * region the account can use, which ones ServerOS looks in, and what it
 * found in each.
 */
final class CloudRegions
{
    /** @return array{list: list<array{name: string, city: string, country: string, zone: string|null, chosen: bool, default: bool, servers: int, linked: list<string>}>, error: string|null} */
    public static function props(Integration $integration, ChoosesRegions $provider): array
    {
        $chosen = $provider->chosenRegions($integration);
        $default = $provider->defaultRegion($integration);
        $error = null;
        try {
            $all = $provider->regions($integration);
        } catch (IntegrationFailed $e) {
            $error = $e->getMessage();
            $all = array_map(fn (string $name) => new ServerLocation($name, $name, ''), $chosen);
        }

        $found = [];
        foreach ($integration->servers()->with('machine:id,name')->get() as $row) {
            /** @var IntegrationServer $row */
            $region = (string) ($row->details['location'] ?? '');
            $found[$region]['servers'] = ($found[$region]['servers'] ?? 0) + 1;
            if ($row->machine !== null) {
                $found[$region]['linked'][] = $row->machine->name;
            }
        }

        return [
            'list' => array_map(fn (ServerLocation $region) => [
                ...$region->toArray(),
                'chosen' => in_array($region->name, $chosen, true),
                'default' => $region->name === $default,
                'servers' => (int) ($found[$region->name]['servers'] ?? 0),
                'linked' => $found[$region->name]['linked'] ?? [],
            ], $all),
            'error' => $error,
        ];
    }

    /**
     * Machines linked to servers in the given regions, by region.
     *
     * @param  list<string>  $regions
     * @return array<string, list<string>>
     */
    public static function linkedIn(Integration $integration, array $regions): array
    {
        $out = [];
        foreach ($integration->servers()->whereNotNull('machine_id')->with('machine:id,name')->get() as $row) {
            /** @var IntegrationServer $row */
            $region = (string) ($row->details['location'] ?? '');
            if (in_array($region, $regions, true) && $row->machine !== null) {
                $out[$region][] = $row->machine->name;
            }
        }

        return $out;
    }
}
