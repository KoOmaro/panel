<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $machine_id
 * @property int $ts
 * @property int $samples
 * @property float|null $cpu
 * @property float|null $cpu_max
 * @property int|null $mem_used
 * @property int|null $mem_max
 * @property int|null $disk_percent
 * @property float|null $load_1
 * @property float|null $load_5
 * @property float|null $load_15
 * @property int|null $processes
 * @property int|null $net_rx bytes received in the minute, from the interface counters
 * @property int|null $net_tx bytes sent in the minute
 */
class MachineSample extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public const KEEP_DAYS = 30;

    public const MAX_POINTS = 120;

    /** @param  list<array{ts: int, cpu: float|null, mem: int|null, disk: int|null, load?: array{0: float, 1: float, 2: float}|null, processes?: int|null, rx?: int|null, tx?: int|null}>  $readings */
    public static function record(Machine $machine, array $readings): void
    {
        $oldest = now()->subDays(self::KEEP_DAYS)->getTimestamp();

        foreach ($readings as $reading) {
            if ($reading['ts'] < $oldest) {
                continue;
            }

            $sample = self::query()->firstOrNew([
                'machine_id' => $machine->id,
                'ts' => intdiv($reading['ts'], 60) * 60,
            ]);
            $count = $sample->samples;
            $mean = fn (int|float|null $current, int|float|null $next) => match (true) {
                $next === null => $current,
                $current === null => $next,
                default => ($current * $count + $next) / ($count + 1),
            };
            $peak = fn (int|float|null $current, int|float|null $next) => $current === null || $next === null
                ? $current ?? $next
                : max($current, $next);
            $total = fn (?int $current, ?int $next) => $next === null ? $current : ($current ?? 0) + $next;
            $load = $reading['load'] ?? null;

            $sample->fill([
                'samples' => min(65535, $count + 1),
                'cpu' => $mean($sample->cpu, $reading['cpu']),
                'cpu_max' => $peak($sample->cpu_max, $reading['cpu']),
                'mem_used' => ($mem = $mean($sample->mem_used, $reading['mem'])) === null ? null : (int) round($mem),
                'mem_max' => $peak($sample->mem_max, $reading['mem']),
                'disk_percent' => $peak($sample->disk_percent, $reading['disk']),
                'load_1' => $mean($sample->load_1, $load[0] ?? null),
                'load_5' => $mean($sample->load_5, $load[1] ?? null),
                'load_15' => $mean($sample->load_15, $load[2] ?? null),
                'processes' => $reading['processes'] ?? $sample->processes,
                'net_rx' => $total($sample->net_rx, $reading['rx'] ?? null),
                'net_tx' => $total($sample->net_tx, $reading['tx'] ?? null),
            ])->save();
        }
    }

    /** @return array{received: int, sent: int, since: string|null}|null */
    public static function monthTraffic(Machine $machine): ?array
    {
        $start = now()->startOfMonth()->getTimestamp();
        $counted = fn () => self::query()->where('machine_id', $machine->id)->whereNotNull('net_rx');
        $month = $counted()
            ->where('ts', '>=', $start)
            ->selectRaw('sum(net_rx) as received, sum(net_tx) as sent, min(ts) as first_ts')
            ->first();
        $first = $month?->getAttribute('first_ts');
        if ($month === null || $first === null) {
            return null;
        }

        return [
            'received' => (int) $month->getAttribute('received'),
            'sent' => (int) $month->getAttribute('sent'),
            'since' => $counted()->where('ts', '<', $start)->exists()
                ? null
                : CarbonImmutable::createFromTimestamp((int) $first)->toIso8601String(),
        ];
    }

    /** @return list<array{ts: int, cpu: float|null, cpuMax: float|null, mem: int|null, memMax: int|null, disk: int|null}> */
    public static function history(Machine $machine, int $window): array
    {
        $bucket = max(60, (int) ceil($window / self::MAX_POINTS / 60) * 60);
        $now = now()->getTimestamp();
        $first = intdiv($now - $window, $bucket) * $bucket + $bucket;

        $rows = self::query()
            ->where('machine_id', $machine->id)
            ->where('ts', '>=', $first)
            ->selectRaw('ts - (ts % ?) as bucket', [$bucket])
            ->selectRaw('avg(cpu) as cpu, max(cpu_max) as cpu_max, avg(mem_used) as mem_used, max(mem_max) as mem_max, max(disk_percent) as disk_percent')
            ->groupBy('bucket')
            ->get()
            ->keyBy(fn (self $row) => (int) $row->getAttribute('bucket'));

        $number = fn (mixed $value, int $precision) => $value === null ? null : round((float) $value, $precision);

        $points = [];
        for ($ts = $first; $ts <= $now; $ts += $bucket) {
            $row = $rows->get($ts);
            $points[] = [
                'ts' => $ts,
                'cpu' => $number($row?->cpu, 2),
                'cpuMax' => $number($row?->cpu_max, 2),
                'mem' => $row?->mem_used === null ? null : (int) $row->mem_used,
                'memMax' => $row?->mem_max === null ? null : (int) $row->mem_max,
                'disk' => $row?->disk_percent === null ? null : (int) $row->disk_percent,
            ];
        }

        return $points;
    }
}
