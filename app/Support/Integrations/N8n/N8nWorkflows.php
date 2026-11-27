<?php

namespace App\Support\Integrations\N8n;

use App\Models\Integration;
use App\Models\Workflow;
use App\Models\Workspace;
use App\Support\Integrations\IntegrationFailed;
use Illuminate\Support\Facades\Cache;

/**
 * The n8n workflows of an account, and how each one can be started from
 * ServerOS: through the production URL of its Webhook trigger, read from
 * the workflow's nodes.
 *
 * @phpstan-type Webhook array{node: string, path: string, url: string|null, method: string|null, answers: bool, auth: string, problem: string|null}
 * @phpstan-type Listed array{id: string, name: string, active: bool, updatedAt: string|null, webhook: Webhook|null, problem: string|null}
 */
final class N8nWorkflows
{
    public const string NODE_TYPE = 'n8n-nodes-base.webhook';

    /** The methods that carry a JSON body, in the order ServerOS prefers them. */
    private const array BODY_METHODS = ['POST', 'PUT', 'PATCH'];

    private const int CACHE_SECONDS = 60;

    public static function client(Integration $integration): N8nClient
    {
        return new N8nClient(self::instance($integration), $integration->credential('token'));
    }

    public static function instance(Integration $integration): string
    {
        return N8nClient::normalise($integration->credential('url')) ?? '';
    }

    /**
     * Every workflow with its webhook, kept for a minute.
     *
     * @return list<Listed>
     *
     * @throws IntegrationFailed
     */
    public function list(Integration $integration, bool $fresh = false): array
    {
        $key = "n8n:workflows:{$integration->id}";
        if ($fresh) {
            Cache::forget($key);
        }

        /** @var list<Listed> */
        return Cache::remember($key, self::CACHE_SECONDS, function () use ($integration): array {
            $instance = self::instance($integration);
            $list = array_map(fn (array $workflow) => self::describe($workflow, $instance), self::client($integration)->workflows());
            usort($list, fn (array $a, array $b) => [$b['webhook'] !== null, mb_strtolower($a['name'])] <=> [$a['webhook'] !== null, mb_strtolower($b['name'])]);

            return $list;
        });
    }

    public static function forget(Integration $integration): void
    {
        Cache::forget("n8n:workflows:{$integration->id}");
    }

    /**
     * One workflow, read now.
     *
     * @return array{workflow: array<string, mixed>, listed: Listed}
     *
     * @throws IntegrationFailed
     */
    public function find(Integration $integration, string $id, ?string $node = null): array
    {
        $workflow = self::client($integration)->get('workflows/'.rawurlencode($id), ['excludePinnedData' => 'true']);

        return ['workflow' => $workflow, 'listed' => self::describe($workflow, self::instance($integration), $node)];
    }

    /**
     * Turns a workflow on, so its webhook's production URL answers.
     *
     * @throws IntegrationFailed
     */
    public function activate(Integration $integration, string $id): void
    {
        $client = self::client($integration);
        try {
            $client->post('workflows/'.rawurlencode($id).'/activate');
        } catch (IntegrationFailed $e) {
            // Newer n8n calls it publishing; /activate is kept for older ones.
            if (! in_array($e->status, [404, 405], true)) {
                throw $e;
            }
            $client->post('workflows/'.rawurlencode($id).'/publish');
        }
        self::forget($integration);
    }

    /**
     * What a workflow is, from its JSON, and the webhook ServerOS would call.
     *
     * @param  array<string, mixed>  $workflow
     * @return Listed
     */
    public static function describe(array $workflow, string $instance, ?string $node = null): array
    {
        $hooks = array_values(array_filter((array) ($workflow['nodes'] ?? []), fn ($n) => is_array($n) && ($n['type'] ?? null) === self::NODE_TYPE));
        $enabled = array_values(array_filter($hooks, fn (array $n) => ($n['disabled'] ?? false) !== true));
        $picked = $node === null || $node === ''
            ? ($enabled[0] ?? null)
            : collect($enabled)->first(fn (array $n) => ($n['name'] ?? null) === $node);

        $id = (string) $workflow['id'];
        $webhook = is_array($picked) ? self::webhook($picked, $id, $instance) : null;
        $problem = match (true) {
            $hooks === [] => self::t('It doesn’t start with a Webhook trigger. Add one in n8n to start it from ServerOS.'),
            $enabled === [] => self::t('Its Webhook trigger is turned off in n8n.'),
            $webhook === null => self::t('Its Webhook node :name is gone.', ['name' => (string) $node]),
            default => $webhook['problem'],
        };

        return [
            'id' => $id,
            'name' => trim((string) ($workflow['name'] ?? '')) ?: $id,
            'active' => (bool) ($workflow['active'] ?? false),
            'updatedAt' => is_string($workflow['updatedAt'] ?? null) ? $workflow['updatedAt'] : null,
            'webhook' => $webhook,
            'problem' => $problem,
        ];
    }

    /**
     * A Webhook node's production URL, the way n8n builds it: the path as
     * typed (the node's webhookId when it's empty), after /webhook/. A path
     * with route parameters needs values ServerOS doesn't have, so it isn't used.
     *
     * @param  array<string, mixed>  $node
     * @return Webhook
     */
    public static function webhook(array $node, string $workflowId, string $instance): array
    {
        $parameters = (array) ($node['parameters'] ?? []);
        $raw = trim((string) ($parameters['path'] ?? ''));
        $webhookId = is_string($node['webhookId'] ?? null) ? $node['webhookId'] : null;
        $dynamic = str_starts_with($raw, ':') || str_contains($raw, '/:');
        $path = ltrim($raw, '/');
        $path = match (true) {
            $webhookId === null => $workflowId.'/'.mb_strtolower((string) ($node['name'] ?? '')).'/'.$path,
            default => $path !== '' ? $path : $webhookId,
        };

        $methods = $parameters['httpMethod'] ?? 'GET';
        $methods = array_map(fn ($m) => strtoupper((string) $m), is_array($methods) ? $methods : [$methods]);
        $method = collect(self::BODY_METHODS)->first(fn (string $m) => in_array($m, $methods, true));
        $auth = (string) ($parameters['authentication'] ?? 'none');
        $respond = (string) ($parameters['responseMode'] ?? 'onReceived');

        $problem = match (true) {
            $dynamic => self::t('Its webhook path has a route parameter in it. Use a fixed path to start it from ServerOS.'),
            $method === null => self::t('Its webhook only takes :methods. Set its HTTP Method to POST.', ['methods' => implode(', ', $methods)]),
            $auth !== 'none' => self::t('Its webhook asks for a login, which ServerOS can’t send. Set its Authentication to None and keep the long random path n8n made.'),
            default => null,
        };

        return [
            'node' => (string) ($node['name'] ?? 'Webhook'),
            'path' => $path,
            'url' => $dynamic ? null : $instance.'/webhook/'.implode('/', array_map('rawurlencode', explode('/', $path))),
            'method' => $method,
            'answers' => in_array($respond, ['lastNode', 'responseNode', 'streaming'], true),
            'auth' => $auth,
            'problem' => $problem,
        ];
    }

    /**
     * The ServerOS workflows with a step that starts one of this account's n8n workflows.
     *
     * @return list<array{id: int, name: string, enabled: bool, n8n: list<string>}>
     */
    public static function users(Workspace $workspace, Integration $integration): array
    {
        $out = [];
        foreach ($workspace->workflows()->orderBy('name')->get() as $workflow) {
            /** @var Workflow $workflow */
            $steps = collect($workflow->graph['nodes'] ?? [])
                ->filter(fn (array $n) => ($n['kind'] ?? null) === 'n8n' && (int) (((array) ($n['rules'] ?? []))['integration'] ?? 0) === $integration->id);
            if ($steps->isEmpty()) {
                continue;
            }
            $out[] = [
                'id' => $workflow->id,
                'name' => $workflow->name,
                'enabled' => $workflow->enabled,
                'n8n' => array_values($steps->map(fn (array $n) => (string) (((array) $n['rules'])['workflow'] ?? ''))->filter()->unique()->all()),
            ];
        }

        return $out;
    }

    /** @param  array<string, string|int>  $replace */
    private static function t(string $text, array $replace = []): string
    {
        return is_string($line = __($text, $replace)) ? $line : $text;
    }
}
