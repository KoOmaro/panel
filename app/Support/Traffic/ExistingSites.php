<?php

namespace App\Support\Traffic;

use App\Models\Machine;
use App\Support\DaemonJobQueue;

/**
 * The sites a machine's own nginx and Apache configs serve (ones ServerOS
 * didn't write), whose traffic the daemon counts read-only from the access
 * logs those configs already write. The daemon reports the whole list with
 * telemetry while traffic is on; it's kept on the machine as {at, sites}.
 *
 * A site's key always has a dot (`nginx.shop.example.com`), which the keys
 * of the apps ServerOS deploys never have, so the two can't collide in
 * traffic_rollups. A log several sites write without saying which is one
 * site, `shared`, keyed by its file.
 *
 * @phpstan-type Site array{site: string, server: string, names: list<string>, logs: list<string>, timed: bool, shared: bool, copy: bool, configs: list<string>, ports: list<int>}
 */
final class ExistingSites
{
    /** @var array<string, string> */
    public const SERVERS = ['nginx' => 'nginx', 'apache' => 'Apache'];

    private const MAX_SITES = 200;

    private const MAX_LIST = 50;

    /** Whether the machine's daemon counts these sites at all. */
    public static function available(Machine $machine): bool
    {
        return DaemonJobQueue::canCountExistingSites($machine);
    }

    /**
     * A reported list, cleaned up for keeping on the machine.
     *
     * @param  array<mixed>  $reported
     * @return array{at: int, sites: list<Site>}
     */
    public static function stored(array $reported): array
    {
        $sites = [];
        foreach (array_slice(array_values($reported), 0, self::MAX_SITES) as $site) {
            if (($clean = self::clean($site)) !== null) {
                $sites[$clean['site']] = $clean;
            }
        }

        return ['at' => now()->getTimestamp(), 'sites' => array_values($sites)];
    }

    /** @return Site|null */
    private static function clean(mixed $site): ?array
    {
        if (! is_array($site) || ! is_string($site['site'] ?? null) || ! is_string($site['server'] ?? null)) {
            return null;
        }
        $key = $site['site'];
        $server = $site['server'];
        if (! array_key_exists($server, self::SERVERS) || strlen($key) > 120 || ! preg_match('/^(nginx|apache)\.[A-Za-z0-9_.-]+$/', $key)) {
            return null;
        }
        /** @return list<string> */
        $strings = fn (mixed $list, int $length) => is_array($list)
            ? array_values(array_map(fn (string $s) => mb_strcut($s, 0, $length), array_filter(array_slice(array_values($list), 0, self::MAX_LIST), fn ($s) => is_string($s) && $s !== '')))
            : [];

        return [
            'site' => $key,
            'server' => $server,
            'names' => $strings($site['names'] ?? null, 253),
            'logs' => $strings($site['logs'] ?? null, 500),
            'timed' => ($site['timed'] ?? false) === true,
            'shared' => ($site['shared'] ?? false) === true,
            'copy' => ($site['copy'] ?? false) === true,
            'configs' => $strings($site['configs'] ?? null, 500),
            'ports' => is_array($site['upstream_ports'] ?? null)
                ? array_values(array_filter(array_slice($site['upstream_ports'], 0, self::MAX_LIST), fn ($p) => is_int($p) && $p > 0 && $p < 65536))
                : [],
        ];
    }

    /**
     * The machine's sites, as last reported.
     *
     * @return list<Site>
     */
    public static function all(Machine $machine): array
    {
        $sites = $machine->traffic_sites['sites'] ?? null;

        // Kept as stored() wrote them; read through clean() all the same.
        return is_array($sites) ? array_values(array_filter(array_map(fn (array $s) => self::clean([...$s, 'upstream_ports' => $s['ports'] ?? []]), $sites))) : [];
    }

    /** @return Site|null */
    public static function find(Machine $machine, string $key): ?array
    {
        foreach (self::all($machine) as $site) {
            if ($site['site'] === $key) {
                return $site;
            }
        }

        return null;
    }

    /**
     * Which web server a service is, when it's nginx or Apache.
     *
     * @param  array<string, mixed>  $service
     */
    public static function serverOf(array $service): ?string
    {
        $text = strtolower(implode(' ', array_filter([$service['key'] ?? null, $service['name'] ?? null, $service['found'] ?? null], 'is_string')));

        return match (true) {
            str_contains($text, 'nginx') => 'nginx',
            str_contains($text, 'apache') || str_contains($text, 'httpd') => 'apache',
            default => null,
        };
    }

    /**
     * The sites a service's page shows: every site of a web server, or for
     * an app it adopted, the sites that pass requests on to its ports.
     *
     * @param  array<string, mixed>  $service
     * @return list<Site>
     */
    public static function forService(Machine $machine, array $service): array
    {
        if (! self::available($machine) || is_string($service['app'] ?? null)) {
            return [];
        }
        $server = self::serverOf($service);
        $ports = array_values(array_filter((array) ($service['ports'] ?? []), 'is_int'));

        return array_values(array_filter(self::all($machine), fn (array $site) => $server !== null
            ? $site['server'] === $server
            : $ports !== [] && array_intersect($site['ports'], $ports) !== []));
    }

    /**
     * What a page needs to show one site's traffic, without the numbers.
     *
     * @param  Site  $site
     * @return array{site: string, domain: string|null, collecting: bool, blocked: string|null, existing: array{server: string, names: list<string>, logs: list<string>, configs: list<string>, timed: bool, shared: bool, copy: bool}}
     */
    public static function state(Machine $machine, array $site): array
    {
        return [
            'site' => $site['site'],
            'domain' => $site['names'][0] ?? null,
            'collecting' => TrafficSwitch::on($machine),
            'blocked' => TrafficSwitch::blocked($machine),
            'existing' => [
                'server' => self::SERVERS[$site['server']],
                'names' => $site['names'],
                'logs' => $site['logs'],
                'configs' => $site['configs'],
                'timed' => $site['timed'],
                'shared' => $site['shared'],
                'copy' => $site['copy'],
            ],
        ];
    }

    /**
     * The key of the machine's service for a web server, for links to its
     * page.
     */
    public static function serviceKey(Machine $machine, string $server): ?string
    {
        $found = null;
        foreach ((array) ($machine->inventory['services'] ?? []) as $service) {
            if (! is_array($service) || ! is_string($service['key'] ?? null) || self::serverOf($service) !== $server) {
                continue;
            }
            if (($service['kind'] ?? null) === 'web_server' || $found === null) {
                $found = $service['key'];
            }
        }

        return $found;
    }

    /**
     * How a site is named in lists: its first name, or what it is.
     *
     * @param  Site  $site
     */
    public static function label(array $site): string
    {
        if ($site['shared']) {
            $file = basename($site['logs'][0] ?? 'access.log');
            $said = __(':file (:count sites)', ['file' => $file, 'count' => (string) count($site['names'])]);

            return is_string($said) ? $said : $file;
        }

        return $site['names'][0] ?? (is_string($said = __('Default site')) ? $said : 'Default site');
    }
}
