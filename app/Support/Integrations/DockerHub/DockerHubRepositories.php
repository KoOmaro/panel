<?php

namespace App\Support\Integrations\DockerHub;

use App\Models\Activity;
use App\Models\ContainerRegistry;
use App\Models\Integration;
use App\Models\Workspace;
use App\Support\Integrations\IntegrationFailed;
use Illuminate\Support\Facades\Cache;

/**
 * The workspace's Docker Hub account: its repositories and tags for the
 * deploy dialog (kept a few minutes), and the docker.io registry login it
 * keeps, so deploys pull its private images.
 */
final class DockerHubRepositories
{
    public const string PROVIDER = 'docker-hub';

    // A Docker ID or organization name, and a repository name in one.
    public const string NAMESPACE_PATTERN = '/^[a-z0-9]+(?:[._-][a-z0-9]+)*$/i';

    public const string REPOSITORY_PATTERN = '/^[a-z0-9]+(?:[._-][a-z0-9]+)*\/[a-z0-9]+(?:[._-][a-z0-9]+)*$/';

    public const string TAG_PATTERN = '/^[\w][\w.-]{0,127}$/';

    private const int CACHE_MINUTES = 5;

    public static function account(Workspace|int $workspace): ?Integration
    {
        return Integration::query()
            ->where('workspace_id', $workspace instanceof Workspace ? $workspace->id : $workspace)
            ->where('provider', self::PROVIDER)
            ->oldest('id')
            ->first();
    }

    public static function client(Integration $integration): DockerHubClient
    {
        return new DockerHubClient($integration->credential('username'), $integration->credential('token'));
    }

    /** @return list<string> */
    public static function organizations(mixed $text): array
    {
        $names = preg_split('/[\s,]+/', strtolower(trim((string) $text))) ?: [];

        return array_values(array_unique(array_filter($names, fn (string $name) => $name !== '')));
    }

    /** @return list<string> the account's own namespace first, then its organizations */
    public static function namespaces(Integration $integration): array
    {
        $username = strtolower($integration->credential('username'));
        $organizations = array_filter(self::organizations($integration->credential('organizations')), fn (string $name) => $name !== $username);

        return [$username, ...$organizations];
    }

    public static function forget(Integration $integration): void
    {
        Cache::forget(self::cacheKey($integration, 'repositories'));
    }

    /**
     * Every repository in the account's namespaces, most recently pushed
     * first, as the deploy dialog and the account page show them.
     *
     * @return array{repositories: list<array{namespace: string, name: string, repository: string, private: bool, description: string|null, pushedAt: string|null, pulls: int|null, url: string}>, namespaces: list<array{name: string, count: int}>, error: string|null}
     */
    public function list(Integration $integration): array
    {
        $key = self::cacheKey($integration, 'repositories');
        $cached = Cache::get($key);
        if (is_array($cached)) {
            /** @var array{repositories: list<array{namespace: string, name: string, repository: string, private: bool, description: string|null, pushedAt: string|null, pulls: int|null, url: string}>, namespaces: list<array{name: string, count: int}>, error: string|null} $cached */
            return $cached;
        }

        $client = self::client($integration);
        $repositories = [];
        $namespaces = [];
        $error = null;
        foreach (self::namespaces($integration) as $namespace) {
            try {
                $found = $client->repositories($namespace);
            } catch (IntegrationFailed $e) {
                $error ??= $e->getMessage();
                if ($e->refusedCredentials()) {
                    break;
                }

                continue;
            }
            $namespaces[] = ['name' => $namespace, 'count' => count($found)];
            foreach ($found as $row) {
                $name = is_string($row['name'] ?? null) ? $row['name'] : null;
                if ($name === null || (is_string($row['repository_type'] ?? null) && $row['repository_type'] !== 'image')) {
                    continue;
                }
                $repositories[] = [
                    'namespace' => $namespace,
                    'name' => $name,
                    'repository' => $namespace.'/'.$name,
                    'private' => (bool) ($row['is_private'] ?? false),
                    'description' => is_string($row['description'] ?? null) && $row['description'] !== '' ? $row['description'] : null,
                    'pushedAt' => is_string($row['last_updated'] ?? null) ? $row['last_updated'] : null,
                    'pulls' => is_int($row['pull_count'] ?? null) ? $row['pull_count'] : null,
                    'url' => 'https://hub.docker.com/r/'.$namespace.'/'.$name,
                ];
            }
        }
        usort($repositories, fn (array $a, array $b) => strcmp((string) $b['pushedAt'], (string) $a['pushedAt']));

        $list = ['repositories' => $repositories, 'namespaces' => $namespaces, 'error' => $error];
        if ($error === null) {
            Cache::put($key, $list, now()->addMinutes(self::CACHE_MINUTES));
        }

        return $list;
    }

    /**
     * A repository's tags, newest push first, with their size, platforms
     * and the port the image says it listens on, when it says.
     *
     * @return list<array{name: string, pushedAt: string|null, size: int|null, digest: string|null, platforms: list<string>, port: int|null}>
     *
     * @throws IntegrationFailed
     */
    public function tags(Integration $integration, string $repository): array
    {
        [$namespace, $name] = explode('/', strtolower($repository), 2);

        return Cache::remember(self::cacheKey($integration, 'tags:'.$repository), now()->addMinutes(2), function () use ($integration, $namespace, $name): array {
            return array_values(array_map(fn (array $tag) => self::tagRow($tag), array_filter(
                self::client($integration)->tags($namespace, $name),
                fn (array $tag) => is_string($tag['name'] ?? null) && preg_match(self::TAG_PATTERN, $tag['name']) === 1,
            )));
        });
    }

    /**
     * @return array{name: string, pushedAt: string|null, size: int|null, digest: string|null, platforms: list<string>, port: int|null}|null
     *
     * @throws IntegrationFailed
     */
    public function tag(Integration $integration, string $repository, string $tag): ?array
    {
        [$namespace, $name] = explode('/', strtolower($repository), 2);

        return Cache::remember(self::cacheKey($integration, 'tag:'.$repository.':'.$tag), now()->addMinutes(self::CACHE_MINUTES), function () use ($integration, $namespace, $name, $tag): ?array {
            try {
                return self::tagRow(self::client($integration)->tag($namespace, $name, $tag));
            } catch (IntegrationFailed $e) {
                if ($e->reason === IntegrationFailed::NOT_FOUND) {
                    return null;
                }

                throw $e;
            }
        });
    }

    /** Whether the repository is in one of the account's namespaces. */
    public static function owns(Integration $integration, string $repository): bool
    {
        $namespace = explode('/', strtolower($repository), 2)[0];

        return in_array($namespace, self::namespaces($integration), true);
    }

    /**
     * @param  array<string, mixed>  $tag
     * @return array{name: string, pushedAt: string|null, size: int|null, digest: string|null, platforms: list<string>, port: int|null}
     */
    private static function tagRow(array $tag): array
    {
        $images = array_values(array_filter((array) ($tag['images'] ?? []), 'is_array'));
        $platforms = [];
        $port = null;
        foreach ($images as $image) {
            $os = is_string($image['os'] ?? null) ? $image['os'] : null;
            $arch = is_string($image['architecture'] ?? null) ? $image['architecture'] : null;
            if ($os !== null && $arch !== null && $os !== 'unknown' && $arch !== 'unknown') {
                $platforms[] = $os.'/'.$arch.(is_string($image['variant'] ?? null) && $image['variant'] !== '' ? '/'.$image['variant'] : '');
            }
            foreach ((array) ($image['layers'] ?? []) as $layer) {
                if ($port === null && is_array($layer) && preg_match('/\bEXPOSE\s+(?:map\[)?(\d{1,5})/i', (string) ($layer['instruction'] ?? ''), $m) === 1 && (int) $m[1] >= 1 && (int) $m[1] <= 65535) {
                    $port = (int) $m[1];
                }
            }
        }
        $digest = $tag['digest'] ?? (count($images) === 1 ? ($images[0]['digest'] ?? null) : null);

        return [
            'name' => (string) ($tag['name'] ?? ''),
            'pushedAt' => is_string($tag['tag_last_pushed'] ?? null) ? $tag['tag_last_pushed'] : (is_string($tag['last_updated'] ?? null) ? $tag['last_updated'] : null),
            'size' => is_int($tag['full_size'] ?? null) ? $tag['full_size'] : null,
            'digest' => is_string($digest) && preg_match('/^sha256:[a-f0-9]{64}$/', $digest) === 1 ? $digest : null,
            'platforms' => array_values(array_unique($platforms)),
            'port' => $port,
        ];
    }

    /**
     * Keeps the workspace's docker.io registry login in step with the
     * account. A login for another Docker Hub user is left as it is unless
     * the person asks to use this account for pulls instead.
     *
     * @return string linked, or other when another login is kept
     */
    public function syncLogin(Integration $integration, bool $takeOver = false): string
    {
        $workspace = $integration->workspace;
        $username = $integration->credential('username');
        $login = $workspace->containerRegistries()->where('host', ContainerRegistry::DOCKER_HUB)->first();

        if ($login !== null && $login->integration_id !== $integration->id && strcasecmp($login->username, $username) !== 0 && ! $takeOver) {
            return 'other';
        }

        $login ??= $workspace->containerRegistries()->make(['host' => ContainerRegistry::DOCKER_HUB]);
        $changed = ! $login->exists || $login->username !== $username || $login->password !== $integration->credential('token') || $login->integration_id !== $integration->id;
        if ($changed) {
            $added = ! $login->exists;
            $login->fill(['username' => $username, 'password' => $integration->credential('token'), 'user_id' => $login->user_id ?? $integration->created_by])
                ->forceFill(['integration_id' => $integration->id])
                ->save();
            Activity::record($workspace, $added ? 'registry.add' : 'registry.update', $login->host, meta: ['username' => $username, 'via' => 'Docker Hub']);
        }

        return 'linked';
    }

    /** Removes the registry login that came from the account. */
    public function removeLogin(Integration $integration): bool
    {
        $login = $integration->workspace->containerRegistries()->where('integration_id', $integration->id)->first();
        if ($login === null) {
            return false;
        }
        $login->delete();
        Activity::record($integration->workspace, 'registry.remove', $login->host, meta: ['via' => 'Docker Hub']);

        return true;
    }

    /** @return array{state: string, username: string|null} linked, other or none */
    public function loginState(Integration $integration): array
    {
        $login = $integration->workspace->containerRegistries()->where('host', ContainerRegistry::DOCKER_HUB)->first();

        return match (true) {
            $login === null => ['state' => 'none', 'username' => null],
            $login->integration_id === $integration->id => ['state' => 'linked', 'username' => $login->username],
            default => ['state' => 'other', 'username' => $login->username],
        };
    }

    private static function cacheKey(Integration $integration, string $what): string
    {
        return 'integrations:docker-hub:'.$integration->id.':'.sha1($integration->fingerprint).':'.$what;
    }
}
