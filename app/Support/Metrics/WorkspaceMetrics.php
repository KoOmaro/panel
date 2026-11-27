<?php

namespace App\Support\Metrics;

use App\Models\Machine;
use App\Models\Monitor;
use App\Models\Workspace;
use App\Support\Deploys\AppReleases;
use App\Support\Deploys\ServiceLabels;

/**
 * A workspace's machines, services and monitors as metrics, from what the
 * panel already keeps (each machine's latest reading, its services as last
 * reported, each monitor's last check). The same series go out as the
 * Prometheus text format (MetricsController) and as OTLP (GrafanaPush), with
 * names that come out the same in Prometheus either way.
 *
 * A machine that stopped reporting only has serveros_machine_up 0: its
 * other values would be stale.
 *
 * @phpstan-type Point array{labels: array<string, string>, value: float|int}
 * @phpstan-type Series array{name: string, help: string, unit: string, points: list<Point>}
 */
final class WorkspaceMetrics
{
    private const array SERIES = [
        'serveros_machine_up' => ['1 when the machine is reporting to ServerOS, 0 when it stopped.', ''],
        'serveros_machine_cpu_percent' => ['CPU in use, as a percentage of all cores.', '%'],
        'serveros_machine_cpu_cores' => ['CPU cores.', ''],
        'serveros_machine_memory_used_bytes' => ['Memory in use.', 'By'],
        'serveros_machine_memory_total_bytes' => ['Memory installed.', 'By'],
        'serveros_machine_disk_used_percent' => ['How full the fullest disk is.', '%'],
        'serveros_machine_disk_used_bytes' => ['Space used on each disk.', 'By'],
        'serveros_machine_disk_total_bytes' => ['Size of each disk.', 'By'],
        'serveros_machine_load1' => ['Load average over 1 minute.', ''],
        'serveros_machine_load5' => ['Load average over 5 minutes.', ''],
        'serveros_machine_load15' => ['Load average over 15 minutes.', ''],
        'serveros_machine_network_receive_bytes_per_second' => ['Bytes received per second over the last minute.', 'By/s'],
        'serveros_machine_network_transmit_bytes_per_second' => ['Bytes sent per second over the last minute.', 'By/s'],
        'serveros_machine_processes' => ['Processes running.', ''],
        'serveros_machine_uptime_seconds' => ['Seconds since the machine booted.', 's'],
        'serveros_service_up' => ['1 when the service is running, 0 when it isn’t.', ''],
        'serveros_monitor_up' => ['1 when the monitor’s last check passed, 0 when it failed.', ''],
        'serveros_monitor_response_seconds' => ['How long the monitor’s last check took.', 's'],
    ];

    /** @return list<Series> */
    public function collect(Workspace $workspace): array
    {
        $points = array_fill_keys(array_keys(self::SERIES), []);
        $add = function (string $name, array $labels, int|float|null $value) use (&$points): void {
            if ($value !== null) {
                $points[$name][] = ['labels' => $labels, 'value' => $value];
            }
        };
        $base = ['workspace' => $workspace->slug];

        $machines = $workspace->machines()->whereNotNull('uid')->orderBy('name')->get();
        foreach ($machines as $machine) {
            /** @var Machine $machine */
            $labels = [...$base, 'machine' => $machine->name, 'machine_id' => (string) $machine->id];
            $online = $machine->isOnline();
            $add('serveros_machine_up', $labels, $online ? 1 : 0);
            if (! $online) {
                continue;
            }
            $facts = is_array($machine->facts) ? $machine->facts : [];
            $add('serveros_machine_cpu_percent', $labels, $machine->cpu_percent);
            $add('serveros_machine_cpu_cores', $labels, is_numeric($facts['cpu_cores'] ?? null) ? (int) $facts['cpu_cores'] : null);
            $add('serveros_machine_memory_used_bytes', $labels, $machine->memory_used_mb === null ? null : $machine->memory_used_mb * 1024 * 1024);
            $add('serveros_machine_memory_total_bytes', $labels, is_numeric($facts['memory_bytes'] ?? null) ? (int) $facts['memory_bytes'] : null);
            $add('serveros_machine_disk_used_percent', $labels, $machine->fullestDisk());
            foreach ($machine->volumes() as $disk) {
                $add('serveros_machine_disk_used_bytes', [...$labels, 'mount' => $disk['mount']], $disk['used']);
                $add('serveros_machine_disk_total_bytes', [...$labels, 'mount' => $disk['mount']], $disk['total']);
            }
            $add('serveros_machine_load1', $labels, $machine->load_1);
            $add('serveros_machine_load5', $labels, $machine->load_5);
            $add('serveros_machine_load15', $labels, $machine->load_15);
            $add('serveros_machine_network_receive_bytes_per_second', $labels, $machine->net_rx_rate);
            $add('serveros_machine_network_transmit_bytes_per_second', $labels, $machine->net_tx_rate);
            $add('serveros_machine_processes', $labels, $machine->processes);
            $add('serveros_machine_uptime_seconds', $labels, is_numeric($facts['boot_ts'] ?? null) ? max(0, now()->getTimestamp() - (int) $facts['boot_ts']) : null);

            $seen = [];
            foreach ((array) ($machine->inventory['services'] ?? []) as $service) {
                if (! is_array($service) || ! is_string($service['key'] ?? null) || preg_match('/^(docker|systemd):/', $service['key']) !== 1 || ServiceLabels::isHidden($machine, $service)) {
                    continue;
                }
                $identity = ServiceLabels::identity($service);
                if (isset($seen[$identity])) {
                    continue;
                }
                $seen[$identity] = true;
                $add('serveros_service_up', [
                    ...$labels,
                    'service' => ServiceLabels::for($machine, $service) ?? AppReleases::parse($service['name'] ?? null)['app'] ?? ltrim((string) ($service['name'] ?? $service['key']), '/'),
                    'service_id' => $identity,
                    'kind' => str_starts_with($service['key'], 'docker:') ? 'container' : 'systemd',
                ], ($service['status'] ?? null) === 'running' ? 1 : 0);
            }
        }

        foreach ($workspace->monitors()->where('paused', false)->orderBy('name')->get() as $monitor) {
            /** @var Monitor $monitor */
            if (! in_array($monitor->status, ['up', 'down'], true)) {
                continue;
            }
            $labels = [...$base, 'monitor' => $monitor->name, 'monitor_id' => (string) $monitor->id, 'type' => $monitor->type, 'target' => $monitor->target()];
            $add('serveros_monitor_up', $labels, $monitor->status === 'up' ? 1 : 0);
            $add('serveros_monitor_response_seconds', $labels, $monitor->last_response_ms === null ? null : round($monitor->last_response_ms / 1000, 3));
        }

        $series = [];
        foreach (self::SERIES as $name => [$help, $unit]) {
            if ($points[$name] !== []) {
                $series[] = ['name' => $name, 'help' => $help, 'unit' => $unit, 'points' => $points[$name]];
            }
        }

        return $series;
    }

    /** @return list<string> every series name, for docs and the dashboard */
    public static function names(): array
    {
        return array_keys(self::SERIES);
    }

    /**
     * The Prometheus text exposition format, version 0.0.4.
     *
     * @param  list<Series>  $series
     */
    public static function prometheus(array $series): string
    {
        $out = '';
        foreach ($series as $one) {
            $out .= '# HELP '.$one['name'].' '.str_replace(['\\', "\n"], ['\\\\', '\n'], $one['help'])."\n";
            $out .= '# TYPE '.$one['name']." gauge\n";
            foreach ($one['points'] as $point) {
                $labels = [];
                foreach ($point['labels'] as $key => $value) {
                    $labels[] = $key.'="'.str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\n'], $value).'"';
                }
                $out .= $one['name'].'{'.implode(',', $labels).'} '.self::number($point['value'])."\n";
            }
        }

        return $out;
    }

    /**
     * An OTLP/HTTP JSON ExportMetricsServiceRequest: one resource (ServerOS,
     * namespaced by the workspace, so job is "<slug>/serveros"), every series
     * a gauge, its labels as data point attributes.
     *
     * @param  list<Series>  $series
     * @return array<string, mixed>
     */
    public static function otlp(Workspace $workspace, array $series, int $at): array
    {
        $attribute = fn (string $key, string $value) => ['key' => $key, 'value' => ['stringValue' => $value]];
        $time = (string) $at.'000000000';

        return ['resourceMetrics' => [[
            'resource' => ['attributes' => [
                $attribute('service.name', 'serveros'),
                $attribute('service.namespace', $workspace->slug),
                $attribute('service.instance.id', (string) parse_url((string) config('app.url'), PHP_URL_HOST)),
            ]],
            'scopeMetrics' => [[
                'scope' => ['name' => 'serveros', 'version' => '1'],
                'metrics' => array_map(fn (array $one) => [
                    'name' => $one['name'],
                    'description' => $one['help'],
                    'unit' => $one['unit'],
                    'gauge' => ['dataPoints' => array_map(fn (array $point) => [
                        'attributes' => array_map(fn (string $key) => $attribute($key, $point['labels'][$key]), array_keys($point['labels'])),
                        'timeUnixNano' => $time,
                        ...(is_int($point['value']) ? ['asInt' => (string) $point['value']] : ['asDouble' => $point['value']]),
                    ], $one['points'])],
                ], $series),
            ]],
        ]]];
    }

    /** @param  list<Series>  $series */
    public static function count(array $series): int
    {
        return array_sum(array_map(fn (array $one) => count($one['points']), $series));
    }

    private static function number(int|float $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        return rtrim(rtrim(sprintf('%.6F', $value), '0'), '.') ?: '0';
    }
}
