<?php

namespace App\Jobs;

use App\Support\Integrations\N8n\N8nStep;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Calls an n8n webhook and waits for its answer, outside the workflow run's
 * lock. It runs once: the run's own timeout fails the step if it's lost.
 */
class RunN8nWorkflow implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = N8nStep::ANSWER_SECONDS + 15;

    public function __construct(public int $runId, public string $node, public string $url, public string $method, public string $name) {}

    public function handle(N8nStep $step): void
    {
        $step->answer($this->runId, $this->node, $this->url, $this->method, $this->name);
    }
}
