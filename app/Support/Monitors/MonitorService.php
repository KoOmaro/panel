<?php

namespace App\Support\Monitors;

use App\Models\Monitor;
use App\Support\Deploys\AppReleases;
use App\Support\Deploys\ServiceLabels;
use App\Support\Traffic\SiteTraffic;

/**
 * The service behind a monitor, when the panel can tell: an app on the
 * monitor's machine whose domain (from its last deploy) is the monitor's
 * host, then a service listening on a TCP monitor's port, then one named
 * like the host's first label (shop.example.com and the app shop).
 */
final class MonitorService
{
    /** @return array{machine: int, machineName: string, key: string, name: string, managed: bool}|null */
    public static function of(Monitor $monitor): ?array
    {
        $machine = $monitor->machine;
        if ($machine === null) {
            return null;
        }
        $host = strtolower(trim((string) ($monitor->type === 'tcp' ? $monitor->host : parse_url((string) $monitor->url, PHP_URL_HOST)), '.'));
        $services = array_values(array_filter(AppReleases::visible($machine), fn (array $s) => is_string($s['key'] ?? null)));
        $found = null;

        if ($host !== '') {
            foreach (array_keys(AppReleases::live($machine)) as $app) {
                foreach (SiteTraffic::domains($machine, (string) $app) as $domain) {
                    if (self::covers(strtolower($domain), $host) && ($service = AppReleases::find($machine, (string) $app)) !== null && is_string($service['key'] ?? null)) {
                        $found = $service;
                        break 2;
                    }
                }
            }
        }
        if ($found === null && $monitor->type === 'tcp' && $monitor->port !== null) {
            $found = collect($services)->first(fn (array $s) => in_array($monitor->port, array_map('intval', (array) ($s['ports'] ?? [])), true));
        }
        $label = explode('.', $host)[0];
        if ($found === null && $label !== '') {
            $found = collect($services)->first(fn (array $s) => strtolower((string) ($s['app'] ?? AppReleases::parse($s['name'] ?? null)['app'] ?? ltrim((string) ($s['name'] ?? ''), '/'))) === $label);
        }
        if (! is_array($found)) {
            return null;
        }

        return [
            'machine' => $machine->id,
            'machineName' => $machine->name,
            'key' => (string) $found['key'],
            'name' => ServiceLabels::display($machine, $found) ?: (string) $found['key'],
            'managed' => ($found['details']['managed'] ?? null) === 'true',
        ];
    }

    private static function covers(string $domain, string $host): bool
    {
        return $domain === $host || (str_starts_with($domain, '*.') && str_ends_with($host, substr($domain, 1)) && substr_count($host, '.') === substr_count($domain, '.'));
    }
}
