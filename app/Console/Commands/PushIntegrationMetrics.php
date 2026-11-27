<?php

namespace App\Console\Commands;

use App\Jobs\PushGrafanaMetrics;
use App\Models\Integration;
use Illuminate\Console\Command;

class PushIntegrationMetrics extends Command
{
    protected $signature = 'integrations:metrics';

    protected $description = 'Send each workspace’s machine, service and monitor metrics to the Grafana Cloud (OTLP) endpoints it set up';

    public function handle(): int
    {
        $sent = 0;
        Integration::query()->where('provider', 'grafana')->whereNotNull('settings')->get()
            ->filter(fn (Integration $integration) => is_array($integration->setting('push')) && ! ($integration->setting('push')['failing'] ?? false))
            ->each(function (Integration $integration) use (&$sent): void {
                PushGrafanaMetrics::dispatch($integration->id);
                $sent++;
            });
        $this->info("Queued {$sent} metric pushes.");

        return self::SUCCESS;
    }
}
