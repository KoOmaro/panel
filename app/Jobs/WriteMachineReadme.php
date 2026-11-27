<?php

namespace App\Jobs;

use App\Models\Machine;
use App\Models\MachineReadme;
use App\Support\Machines\Readme\ReadmeFacts;
use App\Support\Machines\Readme\ReadmeWords;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class WriteMachineReadme implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 150;

    public int $uniqueFor = 300;

    public function __construct(public int $machineId, public bool $force = false)
    {
        $this->onQueue((string) config('services.groq.agent_queue', 'default'));
    }

    public function uniqueId(): string
    {
        return (string) $this->machineId;
    }

    public function handle(ReadmeFacts $facts, ReadmeWords $words): void
    {
        $machine = Machine::query()->with('workspace')->find($this->machineId);
        if ($machine === null || $machine->inventory === null) {
            return;
        }
        $readme = MachineReadme::query()->firstOrNew(['machine_id' => $machine->id]);

        try {
            $found = $facts->build($machine);
            $hash = ReadmeWords::hash($found);
            if (! $this->force && $readme->exists && $readme->state === MachineReadme::READY && $readme->facts_hash === $hash) {
                return;
            }
            $written = $words->write($machine->workspace, $found);
        } catch (Throwable $e) {
            report($e);
            if ($readme->exists && $readme->state === MachineReadme::WRITING) {
                $readme->forceFill(['state' => MachineReadme::READY])->save();
            }

            return;
        }

        $readme->forceFill([
            'state' => MachineReadme::READY,
            'facts_hash' => $hash,
            'sections' => $written['sections'],
            'source' => $written['source'],
            'model' => $written['model'],
            'reason' => $written['reason'],
            'generated_at' => now(),
        ])->save();
    }
}
