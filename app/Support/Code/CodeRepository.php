<?php

namespace App\Support\Code;

use App\Models\Workspace;

/**
 * Where a workspace's code goes on GitHub: a repository its GitHub App can
 * reach, the branch ServerOS pushes to and the folder the files go in.
 *
 * @phpstan-type Repository array{repo: string, owner: string, name: string, branch: string, folder: string, private: bool, url: string, pullRequest: array{number: int, url: string}|null}
 */
final class CodeRepository
{
    public const string BRANCH = 'serveros/export';

    public const string FOLDER = 'serveros';

    public const string REPO_PATTERN = '#^([A-Za-z0-9-]{1,39})/([A-Za-z0-9._-]{1,100})$#';

    public const string FOLDER_PATTERN = '#^[A-Za-z0-9_-][A-Za-z0-9._-]*(/[A-Za-z0-9_-][A-Za-z0-9._-]*)*$#';

    public const array ROOT_FILES = ['backups.yml', 'monitors.yml', 'policies.yml', 'serveros.yml'];

    /** @return Repository|null */
    public static function of(Workspace $workspace): ?array
    {
        $setting = $workspace->code_repository ?? [];
        $repo = (string) ($setting['repo'] ?? '');
        if (preg_match(self::REPO_PATTERN, $repo, $match) !== 1) {
            return null;
        }
        $pull = $setting['pull'] ?? null;

        return [
            'repo' => $repo,
            'owner' => $match[1],
            'name' => $match[2],
            'branch' => (string) ($setting['branch'] ?? '') ?: self::BRANCH,
            'folder' => self::folder((string) ($setting['folder'] ?? '')),
            'private' => (bool) ($setting['private'] ?? false),
            'url' => 'https://github.com/'.$repo,
            'pullRequest' => is_array($pull) && is_int($pull['number'] ?? null) && is_string($pull['url'] ?? null)
                ? ['number' => $pull['number'], 'url' => $pull['url']]
                : null,
        ];
    }

    public static function folder(string $folder): string
    {
        return trim($folder, "/ \t");
    }

    public static function path(string $folder, string $file): string
    {
        return $folder === '' ? $file : $folder.'/'.$file;
    }

    /** A path in the repository as one of the files ServerOS writes, relative to the folder. */
    public static function relative(string $folder, string $path): ?string
    {
        $prefix = $folder === '' ? '' : $folder.'/';
        if (! str_starts_with($path, $prefix)) {
            return null;
        }
        $relative = substr($path, strlen($prefix));

        return preg_match('#^(machines|projects)/[^/]+\.ya?ml$#', $relative) === 1 || in_array($relative, self::ROOT_FILES, true)
            ? $relative
            : null;
    }

    /** The machine and project files on the branch that ServerOS may delete once it no longer writes them. */
    public static function prune(string $folder): string
    {
        return '#^'.preg_quote($folder === '' ? '' : $folder.'/', '#').'(machines|projects)/[^/]+\.ya?ml$#';
    }
}
