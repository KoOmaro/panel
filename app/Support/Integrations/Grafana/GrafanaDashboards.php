<?php

namespace App\Support\Integrations\Grafana;

use App\Models\Activity;
use App\Models\Integration;
use App\Models\Workspace;
use App\Support\Integrations\IntegrationFailed;

/**
 * The workspace's dashboard in Grafana, made and updated through
 * POST /api/dashboards/db with a uid of its own, so updating it replaces
 * the same dashboard. Its panels read a "datasource" variable of type
 * prometheus, so it works with any Prometheus data source that has the
 * ServerOS series (Grafana Cloud's, or your own that scrapes ServerOS);
 * the one picked here is its default.
 *
 * @phpstan-type DataSource array{uid: string, name: string, isDefault: bool, cloud: bool}
 */
final class GrafanaDashboards
{
    public static function client(Integration $integration): GrafanaClient
    {
        return new GrafanaClient(self::address($integration), $integration->credential('token'));
    }

    public static function address(Integration $integration): string
    {
        return GrafanaClient::normalise($integration->credential('url')) ?? '';
    }

    /**
     * The Prometheus data sources the token can see.
     *
     * @return list<DataSource>
     *
     * @throws IntegrationFailed
     */
    public function dataSources(Integration $integration): array
    {
        $sources = [];
        foreach (self::client($integration)->get('datasources') as $source) {
            if (! is_array($source) || ($source['type'] ?? null) !== 'prometheus' || ! is_string($source['uid'] ?? null)) {
                continue;
            }
            $sources[] = [
                'uid' => $source['uid'],
                'name' => (string) ($source['name'] ?? $source['uid']),
                'isDefault' => (bool) ($source['isDefault'] ?? false),
                'cloud' => str_starts_with($source['uid'], 'grafanacloud-') || str_starts_with((string) ($source['name'] ?? ''), 'grafanacloud-'),
            ];
        }
        usort($sources, fn (array $a, array $b) => [! $a['cloud'], ! $a['isDefault'], $a['name']] <=> [! $b['cloud'], ! $b['isDefault'], $b['name']]);

        return $sources;
    }

    public static function uid(Workspace $workspace): string
    {
        return 'serveros-'.substr(hash('sha256', config('app.url').'|'.$workspace->id), 0, 24);
    }

    /**
     * Makes the dashboard, or replaces it with the current one.
     *
     * @param  array{uid: string, name: string}  $source
     * @return array{uid: string, url: string, version: int}
     *
     * @throws IntegrationFailed
     */
    public function save(Integration $integration, array $source): array
    {
        $workspace = $integration->workspace;
        $had = $integration->setting('dashboard');
        $saved = self::client($integration)->post('dashboards/db', [
            'dashboard' => [...self::build($workspace, $source), 'id' => null],
            'overwrite' => true,
            'message' => 'Updated by ServerOS',
        ]);
        $uid = is_string($saved['uid'] ?? null) ? $saved['uid'] : self::uid($workspace);
        $url = is_string($saved['url'] ?? null) ? $saved['url'] : '/d/'.$uid;
        $dashboard = ['uid' => $uid, 'url' => $url, 'version' => (int) ($saved['version'] ?? 1)];

        $integration->forceFill(['settings' => [...($integration->settings ?? []), 'dashboard' => [
            ...$dashboard,
            'datasource' => ['uid' => $source['uid'], 'name' => $source['name']],
            'at' => now()->toIso8601String(),
        ]]])->save();
        Activity::record($workspace, is_array($had) ? 'integration.grafana_dashboard_update' : 'integration.grafana_dashboard', $integration->label, meta: ['datasource' => $source['name']]);

        return $dashboard;
    }

    /**
     * @param  array{uid: string, name: string}  $source
     * @return array<string, mixed>
     */
    public static function build(Workspace $workspace, array $source): array
    {
        $ds = ['type' => 'prometheus', 'uid' => '${datasource}'];
        $all = 'workspace="'.$workspace->slug.'"';
        $sel = '{'.$all.',machine=~"$machine"}';
        $id = 0;
        $target = fn (string $expr, string $legend, int $i = 0, array $extra = []) => ['datasource' => $ds, 'refId' => chr(65 + $i), 'expr' => $expr, 'legendFormat' => $legend, ...$extra];
        $grid = fn (int $x, int $y, int $w, int $h) => ['x' => $x, 'y' => $y, 'w' => $w, 'h' => $h];
        $series = function (string $title, array $targets, string $unit, array $pos, array $defaults = []) use ($ds, &$id) {
            return [
                'id' => ++$id,
                'type' => 'timeseries',
                'title' => $title,
                'datasource' => $ds,
                'gridPos' => $pos,
                'fieldConfig' => ['defaults' => ['unit' => $unit, 'min' => 0, 'custom' => ['lineWidth' => 1, 'fillOpacity' => 8, 'showPoints' => 'never', 'spanNulls' => false], ...$defaults], 'overrides' => []],
                'options' => ['legend' => ['displayMode' => 'list', 'placement' => 'bottom', 'showLegend' => true], 'tooltip' => ['mode' => 'multi', 'sort' => 'desc']],
                'targets' => $targets,
            ];
        };
        $stat = function (string $title, string $expr, array $pos, array $steps, string $legend = '', string $unit = 'none') use ($ds, $target, &$id) {
            return [
                'id' => ++$id,
                'type' => 'stat',
                'title' => $title,
                'datasource' => $ds,
                'gridPos' => $pos,
                'fieldConfig' => ['defaults' => ['unit' => $unit, 'thresholds' => ['mode' => 'absolute', 'steps' => $steps]], 'overrides' => []],
                'options' => ['reduceOptions' => ['calcs' => ['lastNotNull'], 'fields' => '', 'values' => false], 'colorMode' => 'value', 'graphMode' => 'none', 'textMode' => 'auto', 'justifyMode' => 'auto'],
                'targets' => [$target($expr, $legend, 0, ['instant' => true])],
            ];
        };
        $good = [['color' => 'green', 'value' => null]];
        $badAbove = [['color' => 'green', 'value' => null], ['color' => 'red', 'value' => 1]];
        $upDown = ['mappings' => [['type' => 'value', 'options' => ['0' => ['text' => 'Down', 'color' => 'red', 'index' => 0], '1' => ['text' => 'Up', 'color' => 'green', 'index' => 1]]]], 'max' => 1];

        $panels = [
            $stat('Machines reporting', "sum(serveros_machine_up{{$all}}) or vector(0)", $grid(0, 0, 6, 4), $good),
            $stat('Machines not reporting', "count(serveros_machine_up{{$all}} == 0) or vector(0)", $grid(6, 0, 6, 4), $badAbove),
            $stat('Services not running', "count(serveros_service_up{{$all}} == 0) or vector(0)", $grid(12, 0, 6, 4), $badAbove),
            $stat('Monitors down', "count(serveros_monitor_up{{$all}} == 0) or vector(0)", $grid(18, 0, 6, 4), $badAbove),
            $series('CPU', [$target("serveros_machine_cpu_percent{$sel}", '{{machine}}')], 'percent', $grid(0, 4, 12, 8), ['max' => 100]),
            $series('Memory', [$target("100 * serveros_machine_memory_used_bytes{$sel} / serveros_machine_memory_total_bytes{$sel}", '{{machine}}')], 'percent', $grid(12, 4, 12, 8), ['max' => 100]),
            $series('Fullest disk', [$target("serveros_machine_disk_used_percent{$sel}", '{{machine}}')], 'percent', $grid(0, 12, 12, 8), ['max' => 100]),
            $series('Load (1 minute)', [$target("serveros_machine_load1{$sel}", '{{machine}}')], 'none', $grid(12, 12, 12, 8)),
            $series('Network', [
                $target("serveros_machine_network_receive_bytes_per_second{$sel}", '{{machine}} in'),
                $target("serveros_machine_network_transmit_bytes_per_second{$sel}", '{{machine}} out', 1),
            ], 'Bps', $grid(0, 20, 12, 8)),
            $series('Processes', [$target("serveros_machine_processes{$sel}", '{{machine}}')], 'none', $grid(12, 20, 12, 8)),
            $stat('Uptime', "serveros_machine_uptime_seconds{$sel}", $grid(0, 28, 12, 6), $good, '{{machine}}', 's'),
            $series('Monitor response time', [$target("serveros_monitor_response_seconds{{$all}}", '{{monitor}}')], 's', $grid(12, 28, 12, 6)),
            [
                'id' => ++$id,
                'type' => 'state-timeline',
                'title' => 'Monitors',
                'datasource' => $ds,
                'gridPos' => $grid(0, 34, 12, 8),
                'fieldConfig' => ['defaults' => [...$upDown, 'thresholds' => ['mode' => 'absolute', 'steps' => [['color' => 'red', 'value' => null], ['color' => 'green', 'value' => 1]]]], 'overrides' => []],
                'options' => ['showValue' => 'never', 'mergeValues' => true, 'alignValue' => 'left', 'rowHeight' => 0.9, 'legend' => ['showLegend' => false, 'displayMode' => 'list', 'placement' => 'bottom']],
                'targets' => [$target("serveros_monitor_up{{$all}}", '{{monitor}}')],
            ],
            [
                'id' => ++$id,
                'type' => 'table',
                'title' => 'Services not running',
                'datasource' => $ds,
                'gridPos' => $grid(12, 34, 12, 8),
                'fieldConfig' => ['defaults' => [], 'overrides' => []],
                'options' => ['showHeader' => true, 'cellHeight' => 'sm'],
                'targets' => [$target("serveros_service_up{$sel} == 0", '', 0, ['instant' => true, 'format' => 'table'])],
                'transformations' => [['id' => 'organize', 'options' => [
                    'excludeByName' => ['Time' => true, 'Value' => true, '__name__' => true, 'job' => true, 'instance' => true, 'workspace' => true, 'machine_id' => true, 'service_id' => true],
                    'indexByName' => ['machine' => 0, 'service' => 1, 'kind' => 2],
                    'renameByName' => ['machine' => 'Machine', 'service' => 'Service', 'kind' => 'Kind'],
                ]]],
            ],
        ];

        $machines = 'label_values(serveros_machine_up{'.$all.'}, machine)';

        return [
            'uid' => self::uid($workspace),
            'title' => self::t('ServerOS: :workspace', ['workspace' => $workspace->name]),
            'description' => self::t('Machines, services and monitors in :workspace. Made by ServerOS: changes made here are replaced when it’s updated from ServerOS.', ['workspace' => $workspace->name]),
            'tags' => ['serveros'],
            'timezone' => 'browser',
            'editable' => true,
            'refresh' => '1m',
            'schemaVersion' => 39,
            'time' => ['from' => 'now-6h', 'to' => 'now'],
            'links' => [['title' => self::t('Open in ServerOS'), 'type' => 'link', 'url' => $workspace->url('/machines'), 'targetBlank' => true, 'icon' => 'external link', 'tooltip' => '', 'tags' => [], 'asDropdown' => false, 'includeVars' => false, 'keepTime' => false]],
            'templating' => ['list' => [
                [
                    'name' => 'datasource',
                    'label' => self::t('Data source'),
                    'type' => 'datasource',
                    'query' => 'prometheus',
                    'current' => ['text' => $source['name'], 'value' => $source['uid']],
                    'hide' => 0,
                    'refresh' => 1,
                    'regex' => '',
                    'options' => [],
                ],
                [
                    'name' => 'machine',
                    'label' => self::t('Machine'),
                    'type' => 'query',
                    'datasource' => $ds,
                    'definition' => $machines,
                    'query' => ['query' => $machines, 'refId' => 'Machines', 'qryType' => 1],
                    'multi' => true,
                    'includeAll' => true,
                    'current' => ['text' => 'All', 'value' => '$__all'],
                    'refresh' => 2,
                    'sort' => 1,
                    'hide' => 0,
                    'options' => [],
                ],
            ]],
            'panels' => $panels,
        ];
    }

    /** @param  array<string, string|int>  $replace */
    private static function t(string $text, array $replace = []): string
    {
        return is_string($line = __($text, $replace)) ? $line : $text;
    }
}
