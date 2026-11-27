<?php

namespace App\Http\Controllers;

use App\Support\Metrics\MetricsTokens;
use App\Support\Metrics\WorkspaceMetrics;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * A workspace's metrics in the Prometheus text format, for a Prometheus
 * that scrapes ServerOS. It takes a metrics token as a bearer token (made
 * on a Grafana account's page) and nothing else, and answers each token
 * up to PER_MINUTE times a minute.
 */
class MetricsController extends Controller
{
    public const int PER_MINUTE = 20;

    public const string CONTENT_TYPE = 'text/plain; version=0.0.4; charset=utf-8';

    public function __invoke(Request $request, WorkspaceMetrics $metrics): Response
    {
        $token = MetricsTokens::find($request->bearerToken());
        if ($token === null) {
            return $this->text(401, "# Send a metrics token as a bearer token. Make one on a Grafana account's page in ServerOS.\n")
                ->header('WWW-Authenticate', 'Bearer');
        }
        $workspace = $token->workspace;
        if ($workspace->roleOf($token->user) === null || $token->user->suspended_at !== null) {
            return $this->text(403, "# The token's owner is no longer a member of {$workspace->name}.\n");
        }
        if (! $workspace->isActive()) {
            return $this->text(402, "# {$workspace->name} has no plan, so its metrics are off.\n");
        }

        $key = 'metrics:'.$token->id;
        if (RateLimiter::tooManyAttempts($key, self::PER_MINUTE)) {
            return $this->text(429, '# Too many scrapes: a token may scrape '.self::PER_MINUTE." times a minute.\n")
                ->header('Retry-After', (string) RateLimiter::availableIn($key));
        }
        RateLimiter::hit($key, 60);
        if ($token->last_used_at === null || $token->last_used_at->lt(now()->subMinute())) {
            $token->forceFill(['last_used_at' => now(), 'last_used_ip' => $request->ip()])->saveQuietly();
        }

        $body = Cache::remember("metrics:workspace:{$workspace->id}", 10, fn () => WorkspaceMetrics::prometheus($metrics->collect($workspace)));

        return $this->text(200, $body);
    }

    private function text(int $status, string $body): Response
    {
        return response($body, $status)->header('Content-Type', self::CONTENT_TYPE)->header('Cache-Control', 'no-store');
    }
}
