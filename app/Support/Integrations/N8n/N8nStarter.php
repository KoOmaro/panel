<?php

namespace App\Support\Integrations\N8n;

use App\Http\Controllers\WorkspaceApiController;
use App\Mcp\Scopes;
use App\Models\Activity;
use App\Models\Integration;
use App\Models\McpToken;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Integrations\IntegrationFailed;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Calling ServerOS from n8n: the API's address, two HTTP Request nodes to
 * paste into n8n, and a starter workflow ServerOS makes there through the
 * n8n API. The starter gets its own read-only API key, kept in n8n as a
 * Header Auth credential (never shown here), and stays inactive until
 * someone runs it.
 *
 * @phpstan-type Starter array{workflow: string, name: string, credential: string|null, key: int, at: string}
 */
final class N8nStarter
{
    public const string HTTP_NODE = 'n8n-nodes-base.httpRequest';

    public const float HTTP_VERSION = 4.2;

    public static function apiBase(): string
    {
        return rtrim((string) config('app.url'), '/').'/api/v1';
    }

    /** @return Starter|null */
    public static function of(Integration $integration): ?array
    {
        $starter = $integration->setting('starter');

        return is_array($starter) && is_string($starter['workflow'] ?? null) ? [
            'workflow' => $starter['workflow'],
            'name' => (string) ($starter['name'] ?? ''),
            'credential' => is_string($starter['credential'] ?? null) ? $starter['credential'] : null,
            'key' => (int) ($starter['key'] ?? 0),
            'at' => (string) ($starter['at'] ?? now()->toIso8601String()),
        ] : null;
    }

    /** The API key ServerOS made for the starter workflow, while it's still good. */
    public static function key(Integration $integration): ?McpToken
    {
        $id = (int) ($integration->setting('starter')['key'] ?? 0);

        return $id === 0 ? null : McpToken::query()->whereKey($id)->where('workspace_id', $integration->workspace_id)->where('audience', McpToken::API)->active()->first();
    }

    /**
     * Two HTTP Request nodes, as n8n copies them, to paste into a workflow.
     *
     * @return list<array{key: string, title: string, description: string, access: string, json: string}>
     */
    public static function examples(Workspace $workspace): array
    {
        $base = self::apiBase();
        $workflow = $workspace->workflows()->orderBy('name')->first();
        $auth = ['authentication' => 'genericCredentialType', 'genericAuthType' => 'httpHeaderAuth'];
        $node = fn (string $name, array $parameters) => (string) json_encode([
            'nodes' => [[
                'parameters' => [...$parameters, ...$auth, 'options' => (object) []],
                'name' => $name,
                'type' => self::HTTP_NODE,
                'typeVersion' => self::HTTP_VERSION,
                'position' => [0, 0],
            ]],
            'connections' => (object) [],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return [
            [
                'key' => 'machines',
                'title' => self::t('List your machines'),
                'description' => self::t('GET /machines answers with every machine, whether it’s online, and its CPU, memory and disk.'),
                'access' => 'read',
                'json' => $node('List ServerOS machines', ['url' => "{$base}/machines"]),
            ],
            [
                'key' => 'run',
                'title' => $workflow === null ? self::t('Run a ServerOS workflow') : self::t('Run :name', ['name' => $workflow->name]),
                'description' => self::t('POST /workflows/{id}/runs runs a ServerOS workflow now, like its Run button. It needs a key that can make changes.'),
                'access' => 'write',
                'json' => $node('Run a ServerOS workflow', [
                    'method' => 'POST',
                    'url' => $base.'/workflows/'.($workflow === null ? '{workflow}' : $workflow->id).'/runs',
                    'sendBody' => true,
                    'specifyBody' => 'json',
                    'jsonBody' => '{"wait": 0}',
                ]),
            ],
        ];
    }

    /**
     * Makes the starter workflow in n8n: a read-only API key, a Header Auth
     * credential holding it, and an inactive workflow that lists the
     * workspace's machines with it. Without the right to make credentials,
     * the workflow is made without one and the key is revoked again.
     *
     * @return array{name: string, href: string, credential: bool}
     *
     * @throws IntegrationFailed|RuntimeException
     */
    public function create(Integration $integration, User $user): array
    {
        $workspace = $integration->workspace;
        $instance = N8nWorkflows::instance($integration);
        $client = N8nWorkflows::client($integration);
        $name = self::t('ServerOS: list machines');
        $credentialName = self::t('ServerOS API (:workspace)', ['workspace' => $workspace->name]);

        $old = self::key($integration);
        $keys = McpToken::query()->where('workspace_id', $workspace->id)->where('user_id', $user->id)->where('audience', McpToken::API)->active()->count();
        if ($keys >= WorkspaceApiController::MAX_KEYS && $old?->user_id !== $user->id) {
            throw new RuntimeException(self::t('You have :count API keys here. Revoke one you no longer use in Settings › API first.', ['count' => WorkspaceApiController::MAX_KEYS]));
        }
        [$key, $secret] = McpToken::issueKey($workspace, $user, self::t('n8n starter workflow'), [Scopes::READ], McpToken::API);

        $credential = null;
        try {
            $made = $client->post('credentials', [
                'name' => $credentialName,
                'type' => 'httpHeaderAuth',
                'data' => ['name' => 'Authorization', 'value' => 'Bearer '.$secret],
            ]);
            $credential = is_scalar($made['id'] ?? null) ? (string) $made['id'] : null;
        } catch (IntegrationFailed $e) {
            if ($e->reason !== IntegrationFailed::PERMISSION) {
                $key->revoke();

                throw $e;
            }
        }
        if ($credential === null) {
            $key->revoke();
        }

        try {
            $workflow = $client->post('workflows', self::workflow($name, $credential === null ? null : ['id' => $credential, 'name' => $credentialName]));
        } catch (IntegrationFailed $e) {
            $key->revoke();
            if ($credential !== null) {
                try {
                    $client->delete('credentials/'.rawurlencode($credential));
                } catch (IntegrationFailed) {
                }
            }

            throw $e;
        }
        $id = is_scalar($workflow['id'] ?? null) ? (string) $workflow['id'] : throw new RuntimeException(self::t('n8n made the workflow but didn’t say where it is. Look for :name in n8n.', ['name' => $name]));

        $old?->revoke();
        $integration->forceFill(['settings' => [...($integration->settings ?? []), 'starter' => [
            'workflow' => $id,
            'name' => $name,
            'credential' => $credential,
            'key' => $credential === null ? 0 : $key->id,
            'at' => now()->toIso8601String(),
        ]]])->save();
        N8nWorkflows::forget($integration);

        if ($credential !== null) {
            Activity::record($workspace, 'api.key_create', $key->name, meta: ['scopes' => $key->scopes]);
        }
        Activity::record($workspace, 'integration.n8n_starter', $name, meta: ['account' => $integration->label, 'key' => $credential !== null]);

        return ['name' => $name, 'href' => $instance.'/workflow/'.rawurlencode($id), 'credential' => $credential !== null];
    }

    /**
     * @param  array{id: string, name: string}|null  $credential
     * @return array<string, mixed>
     */
    public static function workflow(string $name, ?array $credential): array
    {
        $base = self::apiBase();
        $http = [
            'id' => (string) Str::uuid(),
            'name' => 'List ServerOS machines',
            'type' => self::HTTP_NODE,
            'typeVersion' => self::HTTP_VERSION,
            'position' => [260, 0],
            'parameters' => [
                'url' => "{$base}/machines",
                'authentication' => 'genericCredentialType',
                'genericAuthType' => 'httpHeaderAuth',
                'options' => (object) [],
            ],
        ];
        if ($credential !== null) {
            $http['credentials'] = ['httpHeaderAuth' => $credential];
        }

        return [
            'name' => $name,
            'nodes' => [
                [
                    'id' => (string) Str::uuid(),
                    'name' => 'Start',
                    'type' => 'n8n-nodes-base.manualTrigger',
                    'typeVersion' => 1,
                    'position' => [0, 0],
                    'parameters' => (object) [],
                ],
                $http,
                [
                    'id' => (string) Str::uuid(),
                    'name' => 'About this workflow',
                    'type' => 'n8n-nodes-base.stickyNote',
                    'typeVersion' => 1,
                    'position' => [0, -260],
                    'parameters' => [
                        'width' => 520,
                        'height' => 200,
                        'content' => self::t("## Made by ServerOS\nIt lists your machines from the ServerOS API (:base). The key is read-only: make one that can make changes in Settings › API to run workflows or restart services.", ['base' => $base]),
                    ],
                ],
            ],
            'connections' => [
                'Start' => ['main' => [[['node' => 'List ServerOS machines', 'type' => 'main', 'index' => 0]]]],
            ],
            'settings' => ['executionOrder' => 'v1'],
        ];
    }

    /** @param  array<string, string|int>  $replace */
    private static function t(string $text, array $replace = []): string
    {
        return is_string($line = __($text, $replace)) ? $line : $text;
    }
}
