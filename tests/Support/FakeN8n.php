<?php

namespace Tests\Support;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * A small stand-in for n8n: its public API (/api/v1, X-N8N-API-KEY) and
 * the production URLs of its Webhook triggers, so tests never call a real
 * n8n. It keeps workflows and credentials, records every call and every
 * webhook delivery, answers webhooks as told, and can fail the next call
 * to a path.
 */
final class FakeN8n
{
    public const string KEY = 'fake-n8n-api-key-for-tests';

    /** @var array<string, array<string, mixed>> by id */
    public array $workflows = [];

    /** @var array<string, array<string, mixed>> by id */
    public array $credentials = [];

    /** @var list<array{method: string, host: string, path: string, query: array<string, mixed>, body: array<string, mixed>, key: string|null}> */
    public array $calls = [];

    /** @var list<array{method: string, path: string, body: array<string, mixed>}> */
    public array $deliveries = [];

    /** @var array<string, array{status: int, body: mixed}> by webhook path */
    public array $answers = [];

    public bool $canMakeCredentials = true;

    public bool $legacyActivate = true;

    /** @var array<string, list<array{status: int, body: array<string, mixed>}>> */
    private array $failures = [];

    private int $next = 1;

    /** @param  list<string>  $hosts */
    public static function install(array $hosts = ['acme.app.n8n.cloud', 'n8n.example.com', '10.0.0.5:5678'], bool $strict = true): self
    {
        $fake = new self;
        if ($strict) {
            Http::preventStrayRequests();
        }
        $routes = [];
        foreach ($hosts as $host) {
            $routes["{$host}/api/v1/*"] = fn (Request $request) => $fake->api($request);
            $routes["{$host}/webhook/*"] = fn (Request $request) => $fake->webhook($request);
        }
        Http::fake($routes);

        return $fake;
    }

    /**
     * A workflow, with a Webhook trigger unless $webhook is null.
     *
     * @param  array<string, mixed>|null  $webhook  the Webhook node's parameters
     * @param  array<string, mixed>  $node  more of the Webhook node
     */
    public function workflow(string $name, ?array $webhook = ['httpMethod' => 'POST', 'path' => 'deploy-hook'], bool $active = true, array $node = []): string
    {
        $id = 'wf'.str_pad((string) $this->next++, 6, '0', STR_PAD_LEFT);
        $nodes = [[
            'id' => 'n-set-'.$id,
            'name' => 'Edit Fields',
            'type' => 'n8n-nodes-base.set',
            'typeVersion' => 3.4,
            'position' => [220, 0],
            'parameters' => [],
        ]];
        if ($webhook !== null) {
            array_unshift($nodes, [
                'id' => 'n-hook-'.$id,
                'name' => 'Webhook',
                'type' => 'n8n-nodes-base.webhook',
                'typeVersion' => 2,
                'position' => [0, 0],
                'webhookId' => 'b2c3d4e5-0000-4000-8000-'.str_pad((string) $this->next, 12, '0', STR_PAD_LEFT),
                'parameters' => $webhook,
                ...$node,
            ]);
        }
        $this->workflows[$id] = [
            'id' => $id,
            'name' => $name,
            'active' => $active,
            'createdAt' => '2026-11-20T10:00:00.000Z',
            'updatedAt' => '2026-11-25T10:00:00.000Z',
            'nodes' => $nodes,
            'connections' => (object) [],
            'settings' => ['executionOrder' => 'v1'],
        ];

        return $id;
    }

    /**
     * How a webhook answers.
     */
    public function answer(string $path, int $status, mixed $body): self
    {
        $this->answers[$path] = ['status' => $status, 'body' => $body];

        return $this;
    }

    /** @param  array<string, mixed>  $body */
    public function failNext(string $method, string $path, int $status, array $body = []): self
    {
        $this->failures[strtoupper($method).' '.$path][] = ['status' => $status, 'body' => $body];

        return $this;
    }

    /** @return list<string> */
    public function called(?string $method = null): array
    {
        return array_values(array_map(
            fn (array $call) => $call['method'].' '.$call['path'],
            array_filter($this->calls, fn (array $call) => $method === null || $call['method'] === $method),
        ));
    }

    /** @return array<string, mixed>|null */
    public function bodyOf(string $method, string $path): ?array
    {
        foreach (array_reverse($this->calls) as $call) {
            if ($call['method'] === $method && $call['path'] === $path) {
                return $call['body'];
            }
        }

        return null;
    }

    public function api(Request $request): PromiseInterface
    {
        $url = parse_url($request->url());
        $path = substr((string) ($url['path'] ?? ''), strlen('/api/v1/'));
        parse_str((string) ($url['query'] ?? ''), $query);
        $method = strtoupper($request->method());
        $body = $request->data();
        $key = $request->header('X-N8N-API-KEY')[0] ?? null;
        $this->calls[] = ['method' => $method, 'host' => (string) ($url['host'] ?? ''), 'path' => $path, 'query' => $query, 'body' => is_array($body) ? $body : [], 'key' => $key];

        if (($this->failures["{$method} {$path}"] ?? []) !== [] && ($failure = array_shift($this->failures["{$method} {$path}"])) !== null) {
            return Http::response($failure['body'], $failure['status']);
        }
        if ($key !== self::KEY) {
            return Http::response(['message' => 'unauthorized'], 401);
        }

        $segments = explode('/', $path);

        return match (true) {
            $method === 'GET' && $path === 'workflows' => $this->list($query),
            $method === 'GET' && $segments[0] === 'workflows' && count($segments) === 2 => isset($this->workflows[$segments[1]])
                ? Http::response($this->workflows[$segments[1]])
                : Http::response(['message' => 'Not Found'], 404),
            $method === 'POST' && $segments[0] === 'workflows' && count($segments) === 3 && in_array($segments[2], ['activate', 'publish'], true) => $this->activate($segments[1], $segments[2]),
            $method === 'POST' && $path === 'workflows' => $this->createWorkflow($body),
            $method === 'POST' && $path === 'credentials' => $this->createCredential($body),
            $method === 'DELETE' && $segments[0] === 'credentials' && count($segments) === 2 => $this->deleteCredential($segments[1]),
            default => Http::response(['message' => 'not found'], 404),
        };
    }

    public function webhook(Request $request): PromiseInterface
    {
        $url = parse_url($request->url());
        $path = rawurldecode(substr((string) ($url['path'] ?? ''), strlen('/webhook/')));
        $method = strtoupper($request->method());
        $body = json_decode($request->body(), true);
        $this->deliveries[] = ['method' => $method, 'path' => $path, 'body' => is_array($body) ? $body : []];

        foreach ($this->workflows as $workflow) {
            foreach ($workflow['nodes'] as $node) {
                if ($node['type'] !== 'n8n-nodes-base.webhook' || ($node['disabled'] ?? false)) {
                    continue;
                }
                $nodePath = ltrim((string) ($node['parameters']['path'] ?? ''), '/') ?: $node['webhookId'];
                $methods = (array) ($node['parameters']['httpMethod'] ?? 'GET');
                if ($nodePath === $path && in_array($method, $methods, true) && $workflow['active']) {
                    $answer = $this->answers[$path] ?? ['status' => 200, 'body' => ['message' => 'Workflow was started']];

                    return Http::response($answer['body'], $answer['status']);
                }
            }
        }

        return Http::response(['code' => 404, 'message' => "The requested webhook \"{$method} {$path}\" is not registered.", 'hint' => 'The workflow must be active for a production URL to run successfully.'], 404);
    }

    /** @param  array<string, mixed>  $query */
    private function list(array $query): PromiseInterface
    {
        $limit = (int) ($query['limit'] ?? 100);
        $all = array_values($this->workflows);
        $start = isset($query['cursor']) ? (int) base64_decode((string) $query['cursor']) : 0;
        $page = array_slice($all, $start, $limit);
        $next = $start + $limit < count($all) ? base64_encode((string) ($start + $limit)) : null;

        return Http::response(['data' => $page, 'nextCursor' => $next]);
    }

    private function activate(string $id, string $how): PromiseInterface
    {
        if ($how === 'activate' && ! $this->legacyActivate) {
            return Http::response(['message' => 'not found'], 404);
        }
        if (! isset($this->workflows[$id])) {
            return Http::response(['message' => 'Not Found'], 404);
        }
        $this->workflows[$id]['active'] = true;

        return Http::response($this->workflows[$id]);
    }

    /** @param  array<string, mixed>  $body */
    private function createWorkflow(array $body): PromiseInterface
    {
        foreach (['name', 'nodes', 'connections', 'settings'] as $required) {
            if (! array_key_exists($required, $body)) {
                return Http::response(['message' => "request/body must have required property '{$required}'"], 400);
            }
        }
        if (array_key_exists('active', $body)) {
            return Http::response(['message' => 'request/body/active is read-only'], 400);
        }
        $id = 'wf'.str_pad((string) $this->next++, 6, '0', STR_PAD_LEFT);
        $this->workflows[$id] = [...$body, 'id' => $id, 'active' => false, 'createdAt' => now()->toIso8601ZuluString(), 'updatedAt' => now()->toIso8601ZuluString()];

        return Http::response($this->workflows[$id]);
    }

    /** @param  array<string, mixed>  $body */
    private function createCredential(array $body): PromiseInterface
    {
        if (! $this->canMakeCredentials) {
            return Http::response(['message' => 'Forbidden'], 403);
        }
        $id = 'cr'.$this->next++;
        $this->credentials[$id] = ['id' => $id, 'name' => $body['name'] ?? '', 'type' => $body['type'] ?? '', 'data' => $body['data'] ?? []];

        return Http::response(['id' => $id, 'name' => $body['name'] ?? '', 'type' => $body['type'] ?? '', 'createdAt' => now()->toIso8601ZuluString(), 'updatedAt' => now()->toIso8601ZuluString()]);
    }

    private function deleteCredential(string $id): PromiseInterface
    {
        unset($this->credentials[$id]);

        return Http::response(['id' => $id]);
    }
}
