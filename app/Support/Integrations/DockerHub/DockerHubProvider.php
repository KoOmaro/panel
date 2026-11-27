<?php

namespace App\Support\Integrations\DockerHub;

use App\Models\DockerHubApp;
use App\Models\Integration;
use App\Support\Integrations\Capability;
use App\Support\Integrations\ConnectedAccount;
use App\Support\Integrations\ConnectField;
use App\Support\Integrations\IntegrationFailed;
use App\Support\Integrations\Provider;

/**
 * A Docker Hub account, with a username and a personal access token. It
 * lists the repositories and tags of the account and the organizations
 * named for it when you deploy, keeps the workspace's docker.io registry
 * login so private images pull, redeploys apps when a new image is pushed,
 * and says when an app's tag has a newer image.
 */
final class DockerHubProvider extends Provider
{
    public const int MAX_ORGANIZATIONS = 10;

    public function key(): string
    {
        return DockerHubRepositories::PROVIDER;
    }

    public function name(): string
    {
        return 'Docker Hub';
    }

    public function multipleAccounts(): bool
    {
        return false;
    }

    public function fields(): array
    {
        return [
            new ConnectField(
                key: 'username',
                label: self::t('Username'),
                secret: false,
                help: self::t('Your Docker ID, the name you sign in to Docker Hub with.'),
                placeholder: self::t('your-docker-id'),
                rules: ['required', 'string', 'max:255', 'regex:'.DockerHubRepositories::NAMESPACE_PATTERN],
            ),
            new ConnectField(
                key: 'token',
                label: self::t('Personal access token'),
                help: self::t('Make one with Read-only access in Account settings › Personal access tokens. That lets ServerOS list your repositories and tags and pull private images. It never pushes or changes anything at Docker Hub.'),
                link: 'https://app.docker.com/settings/personal-access-tokens',
                linkLabel: self::t('Make one on Docker Hub'),
                placeholder: self::t('Paste the token'),
                rules: ['required', 'string', 'min:8', 'max:255', 'regex:/^\S+$/'],
            ),
            new ConnectField(
                key: 'organizations',
                label: self::t('Organizations'),
                secret: false,
                help: self::t('Optional. The organizations whose repositories you want to deploy too, separated by commas. Docker Hub’s API can’t list them for a token.'),
                placeholder: self::t('acme, acme-labs'),
                rules: ['nullable', 'string', 'max:500'],
            ),
        ];
    }

    public function capabilities(): array
    {
        return [Capability::Images, Capability::PushDeploys];
    }

    public function describe(Capability $capability): string
    {
        return $capability === Capability::PushDeploys
            ? self::t('Redeploys an app with the new image when one is pushed to its tag, through a webhook you add in Docker Hub. Docker Hub’s API can’t add webhooks, so ServerOS gives you the address to paste.')
            : $capability->description();
    }

    public function verify(array $credentials): ConnectedAccount
    {
        $username = strtolower(trim((string) ($credentials['username'] ?? '')));
        $organizations = DockerHubRepositories::organizations($credentials['organizations'] ?? null);
        if (count($organizations) > self::MAX_ORGANIZATIONS) {
            throw new IntegrationFailed(self::t('Name up to :count organizations.', ['count' => self::MAX_ORGANIZATIONS]), field: 'organizations');
        }

        $client = new DockerHubClient($username, (string) ($credentials['token'] ?? ''));
        $client->accessToken();
        $repositories = $client->count($username);
        foreach ($organizations as $organization) {
            try {
                $repositories += $client->count($organization);
            } catch (IntegrationFailed $e) {
                if ($e->refusedCredentials() || $e->reason === IntegrationFailed::UNAVAILABLE || $e->reason === IntegrationFailed::RATE_LIMITED) {
                    throw $e;
                }

                throw new IntegrationFailed(self::t('Docker Hub can’t find the organization :name, or this account can’t see it.', ['name' => $organization]), $e->reason, $e->status, 'organizations');
            }
        }

        return new ConnectedAccount($username, [
            'username' => $username,
            'organizations' => $organizations === [] ? null : implode(', ', $organizations),
            'repositories' => $repositories,
        ], ['username' => $username, 'organizations' => implode(', ', $organizations)]);
    }

    public function connected(Integration $integration): void
    {
        DockerHubRepositories::forget($integration);
        app(DockerHubRepositories::class)->syncLogin($integration);
        DockerHubApp::query()->whereNull('integration_id')
            ->whereIn('machine_id', $integration->workspace->machines()->select('id'))
            ->update(['integration_id' => $integration->id]);
    }

    public function summary(Integration $integration): string
    {
        $count = (int) ($integration->meta['repositories'] ?? 0);

        return $count === 1
            ? self::t('@:user · 1 repository', ['user' => (string) ($integration->meta['username'] ?? $integration->label)])
            : self::t('@:user · :count repositories', ['user' => (string) ($integration->meta['username'] ?? $integration->label), 'count' => $count]);
    }

    public function facts(Integration $integration): array
    {
        $meta = $integration->meta ?? [];
        $facts = [
            ['label' => self::t('Signed in as'), 'value' => '@'.($meta['username'] ?? $integration->credential('username'))],
            ['label' => self::t('Repositories'), 'value' => (string) ($meta['repositories'] ?? 0)],
        ];
        if (is_string($meta['organizations'] ?? null) && $meta['organizations'] !== '') {
            $facts[] = ['label' => self::t('Organizations'), 'value' => $meta['organizations']];
        }

        return $facts;
    }

    public function usage(Integration $integration): array
    {
        $login = app(DockerHubRepositories::class)->loginState($integration);
        $apps = DockerHubApp::query()->where('integration_id', $integration->id)->get();
        $watching = $apps->where('redeploy_on_push', true)->count();

        $out = [match ($login['state']) {
            'linked' => self::t('Deploys pull private images from Docker Hub with this account.'),
            'other' => self::t('Deploys pull from Docker Hub with the login for :name saved in Settings, not this account.', ['name' => (string) $login['username']]),
            default => self::t('Deploys don’t sign in to Docker Hub.'),
        }];
        $out[] = match ($apps->count()) {
            0 => self::t('Its repositories are listed when you deploy an image.'),
            1 => self::t('1 app is deployed from an image here.'),
            default => self::t(':count apps are deployed from images here.', ['count' => $apps->count()]),
        };
        if ($watching > 0) {
            $out[] = $watching === 1
                ? self::t('1 app redeploys when a new image is pushed.')
                : self::t(':count apps redeploy when a new image is pushed.', ['count' => $watching]);
        }

        return $out;
    }

    public function dependents(Integration $integration): array
    {
        $out = [];
        if (app(DockerHubRepositories::class)->loginState($integration)['state'] === 'linked') {
            $out[] = self::t('Deploys stop pulling private images from Docker Hub: the registry login goes with the account.');
        }
        $watching = DockerHubApp::query()->where('integration_id', $integration->id)->where('redeploy_on_push', true)->count();
        if ($watching > 0) {
            $out[] = $watching === 1
                ? self::t('1 app stops redeploying when a new image is pushed, and its webhook address stops working.')
                : self::t(':count apps stop redeploying when a new image is pushed, and their webhook addresses stop working.', ['count' => $watching]);
        }

        return $out;
    }

    public function disconnect(Integration $integration): ?string
    {
        DockerHubRepositories::forget($integration);
        $removed = app(DockerHubRepositories::class)->removeLogin($integration);
        DockerHubApp::query()->where('integration_id', $integration->id)->update([
            'redeploy_on_push' => false, 'hook_key' => null, 'hook_token' => null,
        ]);

        return $removed
            ? self::t('Disconnected :name and removed its Docker Hub registry login. Nothing changed at Docker Hub.', ['name' => $integration->label])
            : null;
    }
}
