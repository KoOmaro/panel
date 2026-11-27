<?php

namespace App\Support\Integrations\N8n;

use App\Jobs\RunN8nWorkflow;
use App\Models\Integration;
use App\Models\Machine;
use App\Models\Monitor;
use App\Models\WorkflowRun;
use App\Support\Integrations\IntegrationFailed;
use App\Support\Integrations\PinnedAddress;
use App\Support\Workflows\WorkflowEngine;
use App\Support\Workflows\WorkflowText;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The "Run an n8n workflow" step. It reads the n8n workflow now, finds its
 * Webhook trigger and POSTs what happened (the trigger, its machine,
 * service and monitor) to the production URL. Then it either goes on as
 * soon as n8n takes it, waits for n8n's answer (a queued call, up to a
 * minute), or waits for the n8n workflow to POST to a one-off callback URL.
 * Whatever n8n sends back is kept on the step, for {{n8n.field}} in later
 * steps and the "A field n8n sent back" check.
 *
 * @phpstan-import-type StepResult from WorkflowEngine
 */
final class N8nStep
{
    public const array WAITS = ['no', 'answer', 'callback'];

    public const int CALL_SECONDS = 15;

    public const int ANSWER_SECONDS = 60;

    public const int MAX_CALLBACK_MINUTES = 1440;

    public const int MAX_RESPONSE_BYTES = 16_000;

    public function __construct(private readonly N8nWorkflows $workflows) {}

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $rules
     * @return StepResult
     */
    public function start(WorkflowRun $run, array $node, array $rules): array
    {
        $integration = self::account($run, $rules);
        if ($integration === null) {
            return ['state' => 'failed', 'message' => 'The n8n account is gone. Choose another in the step.'];
        }
        $id = trim((string) ($rules['workflow'] ?? ''));
        try {
            ['listed' => $listed] = $this->workflows->find($integration, $id, filled($rules['node'] ?? null) ? (string) $rules['node'] : null);
        } catch (IntegrationFailed $e) {
            return ['state' => 'failed', 'message' => $e->reason === IntegrationFailed::NOT_FOUND ? 'That n8n workflow is gone. Choose another in the step.' : $e->getMessage()];
        }
        $name = $listed['name'];
        $webhook = $listed['webhook'];
        if ($listed['problem'] !== null || $webhook === null || $webhook['url'] === null || $webhook['method'] === null) {
            return ['state' => 'failed', 'message' => "{$name}: ".($listed['problem'] ?? 'it has no webhook ServerOS can call.')];
        }
        if (! $listed['active']) {
            return ['state' => 'failed', 'message' => "{$name} is off in n8n, so its webhook doesn’t answer. Turn it on from the step, or in n8n."];
        }

        $wait = in_array($rules['wait'] ?? 'no', self::WAITS, true) ? (string) ($rules['wait'] ?? 'no') : 'no';
        if ($wait === 'answer') {
            RunN8nWorkflow::dispatch($run->id, (string) $node['id'], $webhook['url'], $webhook['method'], $name)->afterCommit();

            return [
                'state' => 'running',
                'phase' => 'answer',
                'timeout' => 3,
                'expired' => "{$name} didn’t answer within ".self::ANSWER_SECONDS.' seconds.',
                'message' => "Started {$name} in n8n, waiting for its answer",
            ];
        }

        $callback = $wait === 'callback' ? self::callbackUrl($run, (string) $node['id'], (int) ($run->steps[$node['id']]['startedAt'] ?? 0)) : null;
        $called = $this->call($webhook['url'], $webhook['method'], self::payload($run, $node, $rules, $callback), self::CALL_SECONDS, $name);
        if ($called['state'] === 'failed' || $wait === 'no') {
            return $called;
        }
        $minutes = self::minutes($rules['minutes'] ?? null);

        return [
            'state' => 'running',
            'phase' => 'callback',
            'timeout' => $minutes,
            'expired' => "{$name} didn’t call back within {$minutes} ".($minutes === 1 ? 'minute' : 'minutes').'.',
            'message' => "Started {$name} in n8n, waiting up to {$minutes} min for it to call back",
        ];
    }

    /** The queued half of "wait for its answer": the call itself, outside the run's lock. */
    public function answer(int $runId, string $nodeId, string $url, string $method, string $name): void
    {
        $run = WorkflowRun::query()->find($runId);
        $node = $run === null ? null : collect($run->graph['nodes'] ?? [])->firstWhere('id', $nodeId);
        $step = $run?->steps[$nodeId] ?? null;
        if ($run === null || ! is_array($node) || $run->finished() || ($step['state'] ?? null) !== 'running' || ($step['phase'] ?? null) !== 'answer') {
            return;
        }

        $result = $this->call($url, $method, self::payload($run, $node, (array) ($node['rules'] ?? []), null), self::ANSWER_SECONDS, $name);
        app(WorkflowEngine::class)->finishStep($runId, $nodeId, 'answer', $result);
    }

    /**
     * What the n8n workflow sent to its callback URL. {"error": "…"} fails the step.
     *
     * @return StepResult
     */
    public static function callbackResult(mixed $body): array
    {
        $body = self::kept($body);
        $error = is_array($body) && is_string($body['error'] ?? null) && trim($body['error']) !== '' ? trim($body['error']) : null;

        return $error === null
            ? ['state' => 'succeeded', 'message' => 'n8n called back', 'response' => $body]
            : ['state' => 'failed', 'message' => 'n8n called back with an error: '.mb_substr($error, 0, 300), 'response' => $body];
    }

    public static function callbackToken(WorkflowRun $run, string $nodeId, int $startedAt): string
    {
        return hash_hmac('sha256', "n8n-callback:{$run->id}:{$nodeId}:{$startedAt}", (string) config('app.key'));
    }

    public static function callbackUrl(WorkflowRun $run, string $nodeId, int $startedAt): string
    {
        return rtrim((string) config('app.url'), '/').route('n8n.callback', ['run' => $run->id, 'node' => $nodeId, 'token' => self::callbackToken($run, $nodeId, $startedAt)], false);
    }

    public static function minutes(mixed $value): int
    {
        return max(1, min(self::MAX_CALLBACK_MINUTES, (int) ($value ?: 30)));
    }

    /**
     * What the step sends: what started the run, and the machine, service
     * and monitor it was about.
     *
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    public static function payload(WorkflowRun $run, array $node, array $rules, ?string $callback): array
    {
        $workflow = $run->workflow;
        $workspace = $workflow->workspace;
        $data = (array) ($run->trigger['data'] ?? []);

        $machine = isset($data['machine_id']) ? Machine::query()->where('workspace_id', $workspace->id)->find((int) $data['machine_id']) : null;
        $service = null;
        if (is_string($data['service_key'] ?? null) && $machine !== null) {
            $found = collect((array) ($machine->inventory['services'] ?? []))->first(fn ($s) => is_array($s) && ($s['key'] ?? null) === $data['service_key']);
            $service = is_array($found) ? array_filter([
                'key' => $found['key'],
                'name' => (string) ($found['name'] ?? $found['key']),
                'status' => is_string($found['status'] ?? null) ? $found['status'] : null,
                'kind' => is_string($found['kind'] ?? null) ? $found['kind'] : null,
                'managed' => ($found['details']['managed'] ?? null) === 'true',
            ], fn ($v) => $v !== null) : null;
        }
        if ($service === null && is_string($data['service'] ?? null)) {
            $service = ['name' => $data['service']];
        }
        $monitor = isset($data['monitor_id']) ? Monitor::query()->where('workspace_id', $workspace->id)->find((int) $data['monitor_id']) : null;

        $input = trim((string) ($rules['body'] ?? ''));
        $rendered = $input === '' ? null : WorkflowText::render($input, $run);
        $decoded = $rendered === null ? null : json_decode($rendered, true);

        return array_filter([
            'event' => (string) ($run->trigger['kind'] ?? 'manual'),
            'summary' => (string) ($run->trigger['summary'] ?? ''),
            'test' => (bool) ($data['test'] ?? false),
            'workspace' => ['name' => $workspace->name, 'slug' => $workspace->slug, 'url' => $workspace->url()],
            'workflow' => [
                'id' => $workflow->id,
                'name' => $workflow->name,
                'run' => $run->id,
                'run_url' => $workspace->url("/workflows/{$workflow->id}?run={$run->id}"),
                'step' => (string) $node['id'],
            ],
            'trigger' => (object) $data,
            'machine' => $machine === null ? null : [
                'id' => $machine->id,
                'name' => $machine->name,
                'ip' => $machine->ip_address,
                'online' => $machine->isOnline(),
                'cpu_percent' => $machine->cpu_percent,
                'memory_used_mb' => $machine->memory_used_mb,
                'disk_percent' => $machine->fullestDisk(),
                'load_1' => $machine->load_1,
                'last_seen_at' => $machine->last_seen_at?->toIso8601String(),
                'url' => $workspace->url("/machines/{$machine->id}"),
            ],
            'service' => $service,
            'monitor' => $monitor === null ? null : [
                'id' => $monitor->id,
                'name' => $monitor->name,
                'type' => $monitor->type,
                'target' => $monitor->target(),
                'status' => $monitor->status,
                'last_error' => $monitor->last_error,
                'response_ms' => $monitor->last_response_ms,
                'last_checked_at' => $monitor->last_checked_at?->toIso8601String(),
                'url' => $workspace->url("/monitors/{$monitor->id}"),
            ],
            'input' => $rendered === null ? null : (is_array($decoded) ? $decoded : $rendered),
            'callback_url' => $callback,
        ], fn ($v) => $v !== null);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return StepResult
     */
    private function call(string $url, string $method, array $payload, int $seconds, string $name): array
    {
        try {
            $options = PinnedAddress::options($url, null);
        } catch (IntegrationFailed $e) {
            return ['state' => 'failed', 'message' => $e->getMessage()];
        }

        $start = hrtime(true);
        try {
            $response = Http::timeout($seconds)->connectTimeout(5)
                ->withHeaders(['User-Agent' => 'ServerOS-Workflows/1.0'])
                ->withOptions($options)
                ->acceptJson()
                ->send($method, $url, ['json' => $payload]);
        } catch (ConnectionException $e) {
            return ['state' => 'failed', 'message' => str_contains(strtolower($e->getMessage()), 'timed out')
                ? "{$name} didn’t answer within {$seconds} seconds."
                : 'n8n couldn’t be reached. Check it’s running and its address hasn’t changed.'];
        }
        $ms = (int) round((hrtime(true) - $start) / 1e6);

        if ($response->status() === 404) {
            return ['state' => 'failed', 'message' => "n8n says {$name}’s webhook isn’t registered. Check it’s on in n8n and its path hasn’t changed."];
        }
        if (! $response->successful()) {
            $said = self::said($response);

            return ['state' => 'failed', 'status' => $response->status(), 'message' => "{$name} answered {$response->status()}".($said === null ? '.' : ": {$said}")];
        }

        return [
            'state' => 'succeeded',
            'status' => $response->status(),
            'response' => self::kept($response->json() ?? $response->body()),
            'message' => "{$name} answered {$response->status()} in {$ms} ms",
        ];
    }

    /** What's kept of an answer: JSON as it came, text up to 2,000 characters, nothing bigger than 16 KB. */
    private static function kept(mixed $body): mixed
    {
        if (is_string($body)) {
            $body = trim($body);

            return $body === '' ? null : mb_substr($body, 0, 2000);
        }
        if (! is_array($body)) {
            return is_scalar($body) ? $body : null;
        }

        return strlen((string) json_encode($body)) > self::MAX_RESPONSE_BYTES ? ['_truncated' => true] : $body;
    }

    private static function said(Response $response): ?string
    {
        $message = $response->json('message');

        return is_string($message) && trim($message) !== '' ? mb_substr(trim($message), 0, 300) : null;
    }

    /** @param  array<string, mixed>  $rules */
    public static function account(WorkflowRun $run, array $rules): ?Integration
    {
        return Integration::query()
            ->where('workspace_id', $run->workflow->workspace_id)
            ->where('provider', 'n8n')
            ->find((int) ($rules['integration'] ?? 0));
    }
}
