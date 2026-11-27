<?php

namespace App\Jobs;

use App\Models\TailscaleChange;
use App\Support\Integrations\Tailscale\TailnetChanges;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

class AdvanceTailnetChange implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public int $changeId) {}

    public function handle(TailnetChanges $changes): void
    {
        $change = TailscaleChange::query()->find($this->changeId);
        if ($change === null || ! $change->active()) {
            return;
        }

        try {
            $wait = $changes->advance($change);
        } catch (Throwable $e) {
            report($e);
            $changes->giveUp($change);

            return;
        }

        if ($wait !== null) {
            self::dispatch($change->id)->delay(now()->addSeconds($wait));
        }
    }
}
