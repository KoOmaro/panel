<?php

namespace App\Support\Machines;

use App\Models\Machine;

/**
 * @phpstan-type Reading array{ts: int, cpu: float|null, mem: int|null, disk: int|null, load: array{0: float, 1: float, 2: float}|null, processes: int|null, rxBytes: int|null, txBytes: int|null}
 * @phpstan-type Counted array{ts: int, cpu: float|null, mem: int|null, disk: int|null, load: array{0: float, 1: float, 2: float}|null, processes: int|null, rx: int|null, tx: int|null}
 */
final class MachineVitals
{
    public const RATE_GAP = 300;

    /** @var array<string, int|float|null> */
    private array $changes = [];

    /** @param  array{rx: int|null, tx: int|null}  $counters */
    private function __construct(private ?int $at, private array $counters) {}

    public static function of(Machine $machine): self
    {
        return new self($machine->telemetry_ts, ['rx' => $machine->net_rx_bytes, 'tx' => $machine->net_tx_bytes]);
    }

    /**
     * @param  list<Reading>  $readings
     * @return list<Counted>
     */
    public function count(array $readings): array
    {
        usort($readings, fn (array $a, array $b) => $a['ts'] <=> $b['ts']);
        $from = $this->at;
        $moved = ['rx' => 0, 'tx' => 0];
        $newest = null;
        $counted = [];

        foreach ($readings as $reading) {
            $bytes = ['rx' => null, 'tx' => null];
            if ($this->at === null || $reading['ts'] >= $this->at) {
                foreach (['rx' => $reading['rxBytes'], 'tx' => $reading['txBytes']] as $way => $now) {
                    $before = $this->counters[$way];
                    $bytes[$way] = $before === null || $now === null || $now < $before ? null : $now - $before;
                    $moved[$way] = $bytes[$way] === null || $moved[$way] === null ? null : $moved[$way] + $bytes[$way];
                    $this->counters[$way] = $now;
                }
                $this->at = $reading['ts'];
                $newest = $reading;
            }

            $counted[] = [
                'ts' => $reading['ts'],
                'cpu' => $reading['cpu'],
                'mem' => $reading['mem'],
                'disk' => $reading['disk'],
                'load' => $reading['load'],
                'processes' => $reading['processes'],
                'rx' => $bytes['rx'],
                'tx' => $bytes['tx'],
            ];
        }

        if ($newest !== null) {
            $this->changes = [
                ...$this->changes,
                'load_1' => $newest['load'][0] ?? null,
                'load_5' => $newest['load'][1] ?? null,
                'load_15' => $newest['load'][2] ?? null,
                'processes' => $newest['processes'],
                'net_rx_bytes' => $this->counters['rx'],
                'net_tx_bytes' => $this->counters['tx'],
                'telemetry_ts' => $this->at,
            ];
            $gap = $from === null ? null : $newest['ts'] - $from;
            if ($gap !== 0) {
                $rate = fn (?int $bytes) => $bytes === null || $gap === null || $gap > self::RATE_GAP ? null : (int) round($bytes / $gap);
                $this->changes['net_rx_rate'] = $rate($moved['rx']);
                $this->changes['net_tx_rate'] = $rate($moved['tx']);
            }
        }

        return $counted;
    }

    /** @return array<string, int|float|null> */
    public function changes(): array
    {
        return $this->changes;
    }
}
