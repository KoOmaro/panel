<?php

namespace App\Support\Integrations\N8n;

use App\Models\Integration;
use App\Support\Docs\DocsUrl;
use App\Support\Integrations\Capability;
use App\Support\Integrations\ConnectedAccount;
use App\Support\Integrations\ConnectField;
use App\Support\Integrations\HasAccountPage;
use App\Support\Integrations\IntegrationFailed;
use App\Support\Integrations\PinnedAddress;
use App\Support\Integrations\Provider;
use Carbon\CarbonImmutable;

/**
 * n8n, on n8n Cloud or self-hosted. ServerOS starts its workflows from a
 * workflow step (N8nStep) and makes a starter workflow there that calls the
 * ServerOS API (N8nStarter).
 */
final class N8nProvider extends Provider implements HasAccountPage
{
    public function key(): string
    {
        return 'n8n';
    }

    public function name(): string
    {
        return 'n8n';
    }

    public function fields(): array
    {
        return [
            new ConnectField(
                key: 'url',
                label: self::t('n8n address'),
                secret: false,
                help: self::t('The address you open n8n at, like https://acme.app.n8n.cloud or your own.'),
                placeholder: 'https://acme.app.n8n.cloud',
                rules: ['required', 'string', 'max:200'],
            ),
            new ConnectField(
                key: 'token',
                label: self::t('API key'),
                help: self::t('In n8n, open Settings › n8n API and make a key. If it has scopes, give it workflow:list, workflow:read, workflow:create, workflow:activate and credential:create.'),
                placeholder: self::t('Paste the key'),
                rules: ['required', 'string', 'min:20', 'max:2000', 'regex:/^\S+$/'],
            ),
        ];
    }

    public function capabilities(): array
    {
        return [Capability::Workflows, Capability::ServerOsApi];
    }

    public function verify(array $credentials): ConnectedAccount
    {
        $instance = N8nClient::normalise($credentials['url'] ?? null)
            ?? throw new IntegrationFailed(self::t('That isn’t an n8n address. Use the https:// address you open n8n at.'), field: 'url');
        $client = new N8nClient($instance, (string) ($credentials['token'] ?? ''));

        $workflows = $client->workflows();
        $instanceWorkflows = array_map(fn (array $w) => N8nWorkflows::describe($w, $instance), $workflows);
        $host = PinnedAddress::host($instance);

        return new ConnectedAccount(
            $host,
            [
                'instance' => $instance,
                'workflows' => count($workflows),
                'active' => count(array_filter($workflows, fn (array $w) => (bool) ($w['active'] ?? false))),
                'webhooks' => count(array_filter($instanceWorkflows, fn (array $w) => $w['webhook'] !== null && $w['problem'] === null)),
            ],
            ['url' => $instance],
        );
    }

    public function summary(Integration $integration): string
    {
        $count = (int) ($integration->meta['workflows'] ?? 0);

        return $count === 1
            ? self::t('1 workflow on :host', ['host' => PinnedAddress::host(N8nWorkflows::instance($integration))])
            : self::t(':count workflows on :host', ['count' => $count, 'host' => PinnedAddress::host(N8nWorkflows::instance($integration))]);
    }

    public function facts(Integration $integration): array
    {
        $meta = $integration->meta ?? [];

        return [
            ['label' => self::t('Address'), 'value' => PinnedAddress::host(N8nWorkflows::instance($integration))],
            ['label' => self::t('Workflows'), 'value' => self::t(':count, :active on', ['count' => (int) ($meta['workflows'] ?? 0), 'active' => (int) ($meta['active'] ?? 0)])],
            ['label' => self::t('With a webhook'), 'value' => (string) (int) ($meta['webhooks'] ?? 0)],
        ];
    }

    public function usage(Integration $integration): array
    {
        $users = N8nWorkflows::users($integration->workspace, $integration);
        $out = [match (count($users)) {
            0 => self::t('No ServerOS workflow starts one of its workflows yet. Add a Run an n8n workflow step to one.'),
            1 => self::t(':name starts an n8n workflow here.', ['name' => $users[0]['name']]),
            default => self::t(':count ServerOS workflows start n8n workflows here.', ['count' => count($users)]),
        }];
        $starter = N8nStarter::of($integration);
        if ($starter !== null) {
            $out[] = self::t('ServerOS made the starter workflow :name here :when, with a read-only API key.', [
                'name' => $starter['name'],
                'when' => CarbonImmutable::parse($starter['at'])->diffForHumans(),
            ]);
        }

        return $out;
    }

    public function dependents(Integration $integration): array
    {
        $users = N8nWorkflows::users($integration->workspace, $integration);
        $out = [];
        if ($users !== []) {
            $out[] = count($users) === 1
                ? self::t(':name can’t start its n8n step any more.', ['name' => $users[0]['name']])
                : self::t(':count workflows can’t start their n8n steps any more.', ['count' => count($users)]);
        }
        if (N8nStarter::key($integration) !== null) {
            $out[] = self::t('The API key ServerOS made for the starter workflow is revoked, so n8n can’t call ServerOS with it.');
        }

        return $out;
    }

    public function disconnect(Integration $integration): ?string
    {
        N8nWorkflows::forget($integration);
        $key = N8nStarter::key($integration);
        if ($key === null) {
            return null;
        }
        $key->revoke();

        return self::t('Disconnected :name and revoked the API key ServerOS made for n8n.', ['name' => $integration->label]);
    }

    public function accountPage(Integration $integration): array
    {
        $instance = N8nWorkflows::instance($integration);
        $users = N8nWorkflows::users($integration->workspace, $integration);
        $byWorkflow = [];
        foreach ($users as $user) {
            foreach ($user['n8n'] as $id) {
                $byWorkflow[$id][] = ['id' => $user['id'], 'name' => $user['name'], 'enabled' => $user['enabled'], 'href' => '/workflows/'.$user['id']];
            }
        }

        $error = null;
        $workflows = [];
        try {
            $workflows = app(N8nWorkflows::class)->list($integration, fresh: true);
            $integration->forceFill(['meta' => [
                ...($integration->meta ?? []),
                'workflows' => count($workflows),
                'active' => count(array_filter($workflows, fn (array $w) => $w['active'])),
                'webhooks' => count(array_filter($workflows, fn (array $w) => $w['webhook'] !== null && $w['problem'] === null)),
            ]])->save();
        } catch (IntegrationFailed $e) {
            $error = $e->getMessage();
        }

        $starter = N8nStarter::of($integration);
        $key = N8nStarter::key($integration);

        return [
            'instance' => $instance,
            'error' => $error,
            'workflows' => array_map(fn (array $w) => [
                'id' => $w['id'],
                'name' => $w['name'],
                'active' => $w['active'],
                'href' => $instance.'/workflow/'.rawurlencode($w['id']),
                'webhook' => $w['webhook'] === null ? null : [
                    'node' => $w['webhook']['node'],
                    'method' => $w['webhook']['method'],
                    'answers' => $w['webhook']['answers'],
                ],
                'problem' => $w['problem'],
                'usedBy' => $byWorkflow[$w['id']] ?? [],
            ], $workflows),
            'api' => [
                'baseUrl' => N8nStarter::apiBase(),
                'keysHref' => '/settings/api',
                'docsUrl' => DocsUrl::page('guides', 'api'),
                'examples' => N8nStarter::examples($integration->workspace),
            ],
            'starter' => $starter === null ? null : [
                'name' => $starter['name'],
                'href' => $instance.'/workflow/'.rawurlencode($starter['workflow']),
                'at' => $starter['at'],
                'key' => $key === null ? null : ['name' => $key->name, 'prefix' => $key->token_prefix, 'lastUsedAt' => $key->last_used_at?->toIso8601String()],
                'credential' => $starter['credential'] !== null,
            ],
        ];
    }
}
