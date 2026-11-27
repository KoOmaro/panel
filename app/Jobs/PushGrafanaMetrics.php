<?php

namespace App\Jobs;

use App\Models\Integration;
use App\Support\Integrations\Grafana\GrafanaPush;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PushGrafanaMetrics implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = GrafanaPush::TIMEOUT + 15;

    public function __construct(public int $integrationId) {}

    public function handle(GrafanaPush $push): void
    {
        $integration = Integration::query()->with('workspace')->find($this->integrationId);
        if ($integration !== null && $integration->provider === 'grafana') {
            $push->push($integration);
        }
    }
}
