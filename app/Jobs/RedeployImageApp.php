<?php

namespace App\Jobs;

use App\Models\DockerHubApp;
use App\Support\Integrations\DockerHub\DockerHubApps;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Docker Hub said a new image was pushed to an app's tag: asks the
 * registry which image the tag points at now and redeploys the app onto
 * it, outside the webhook's request.
 */
class RedeployImageApp implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public int $appId, public string $tag) {}

    public function handle(DockerHubApps $apps): void
    {
        $row = DockerHubApp::query()->with('machine.workspace')->find($this->appId);
        if ($row === null || ! $row->redeploy_on_push || $row->tag !== $this->tag) {
            return;
        }

        $apps->pushed($row);
    }
}
