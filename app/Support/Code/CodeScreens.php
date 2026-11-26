<?php

namespace App\Support\Code;

use App\Models\GitHubInstallation;
use App\Models\Machine;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Support\GitHub\GitHubApp;
use App\Support\Mcp\OAuth;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Page props for the infrastructure-as-code screens. The panel has no
 * config file, plan or drift check yet, so these screens are a proposal:
 * the files are written from the workspace's real machines, services,
 * projects and monitors, and everything with no source yet (pull requests,
 * plans, reviewers, hand-made changes) is sample data under `sample`.
 */
final class CodeScreens
{
    public const array STACKS = ['next', 'laravel', 'django', 'node', 'bun', 'astro'];

    /** @return array<string, mixed> */
    public static function index(Workspace $workspace, User $user): array
    {
        $machines = $workspace->countedMachines()->orderBy('name')->get();
        $needsLook = self::needsLook($machines);
        $notes = [];
        foreach ($needsLook as $item) {
            $notes[$item['path']] = $item['comment'];
        }

        $export = WorkspaceExport::build($workspace, $notes);
        $open = self::latestProject($workspace);
        $projectPaths = $export['projectPaths'];
        unset($export['projectPaths']);

        return [
            'code' => [
                ...$export,
                'open' => ($open === null ? null : $projectPaths[$open->uid] ?? null) ?? ($export['files'][0]['path'] ?? null),
            ],
            'github' => self::github($workspace, $user),
            'sample' => [
                'repo' => self::githubOwner($workspace).'/infra',
                'branch' => 'serveros/export',
                'needsLook' => array_map(fn (array $item) => [
                    'title' => $item['title'],
                    'body' => $item['body'],
                    'icon' => $item['icon'],
                    'path' => $item['path'],
                ], $needsLook),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function setup(Workspace $workspace, ?string $uid): array
    {
        $project = self::pick($workspace, $uid);
        if ($project === null) {
            return ['project' => null, 'projects' => [], 'sample' => null, 'mcpUrl' => OAuth::resource($workspace)];
        }

        $monitors = $workspace->monitors()->orderBy('name')->get();
        $repo = WorkspaceExport::repoOf($project);
        $stack = self::stackOf($project);

        return [
            'project' => [
                ...self::projectProps($workspace, $project),
                'file' => WorkspaceExport::projectFile($workspace, $project, $monitors),
            ],
            'projects' => self::projectList($workspace),
            'mcpUrl' => OAuth::resource($workspace),
            'sample' => [
                'repo' => $repo ?? self::githubOwner($workspace).'/'.WorkspaceExport::slug($project->name),
                'stack' => $stack,
                'branch' => 'add-serveros',
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function review(Workspace $workspace, User $user, ?string $uid): array
    {
        $project = self::pick($workspace, $uid);
        $machines = $workspace->countedMachines()->orderBy('name')->get();
        if ($project === null || $machines->isEmpty()) {
            return ['project' => null, 'projects' => [], 'sample' => null];
        }

        $monitors = $workspace->monitors()->orderBy('name')->get();
        $slug = WorkspaceExport::slug($project->name);
        $app = WorkspaceExport::apps($project)->first();
        $appName = $app->name ?? 'web';
        $running = $machines->mapWithKeys(fn (Machine $machine) => [$machine->id => WorkspaceExport::services($machine)]);
        $runs = fn (callable $test) => $machines->first(fn (Machine $machine) => collect($running[$machine->id])->contains($test));

        $web = $app->machine
            ?? $runs(fn (array $service) => str_contains(strtolower((string) $service['name']), $slug))
            ?? $machines->first();
        $db = $runs(fn (array $service) => ($service['kind'] ?? null) === 'database') ?? $machines->first();
        $search = $runs(fn (array $service) => str_contains(strtolower((string) $service['name']), 'meili')) ?? $web;
        $domain = (string) (($app === null ? null : (WorkspaceExport::spec($app)['domains'][0] ?? null))
            ?: parse_url(WorkspaceExport::monitorsOf($project, $monitors)[0] ?? '', PHP_URL_HOST)
            ?: $slug.'.'.$workspace->slug.'.dev');
        $environment = WorkspaceExport::environmentOf($project);
        $stack = self::stackOf($project);

        $changes = [
            [
                'id' => 'cache',
                'op' => 'add',
                'logo' => 'redis',
                'title' => __('Add Redis on :machine', ['machine' => $db->name]),
                'short' => __('Add Redis on :machine', ['machine' => $db->name]),
                'path' => 'services.cache',
                'machine' => self::machineRef($db),
                'risk' => null,
                'diff' => [
                    ['no' => 11, 'sign' => '+', 'text' => '  cache:'],
                    ['no' => 12, 'sign' => '+', 'text' => '    template: redis'],
                    ['no' => 13, 'sign' => '+', 'text' => '    version: "7"'],
                    ['no' => 14, 'sign' => '+', 'text' => '    machine: '.$db->name],
                ],
                'note' => __('Redis starts on :machine and only listens on the private network.', ['machine' => $db->name]),
            ],
            [
                'id' => 'web',
                'op' => 'change',
                'logo' => $stack,
                'title' => __('Change :app from 2 to 3 copies, and set REDIS_URL', ['app' => $appName]),
                'short' => __('Change :app', ['app' => $appName]),
                'path' => 'services.'.$appName,
                'machine' => self::machineRef($web),
                'risk' => ['tone' => 'amber', 'label' => __('Restarts :app', ['app' => $appName])],
                'diff' => [
                    ['no' => 4, 'sign' => ' ', 'text' => '  '.$appName.':'],
                    ['no' => 5, 'sign' => ' ', 'text' => '    from: repo'],
                    ['no' => 6, 'sign' => ' ', 'text' => '    machine: '.$web->name],
                    ['no' => 7, 'sign' => '-', 'text' => '    copies: 2'],
                    ['no' => 7, 'sign' => '+', 'text' => '    copies: 3'],
                    ['no' => 8, 'sign' => ' ', 'text' => '    domain: '.$domain],
                    ['no' => 9, 'sign' => '+', 'text' => '    env:'],
                    ['no' => 10, 'sign' => '+', 'text' => '      REDIS_URL: ${{ services.cache.url }}'],
                ],
                'note' => __(':app restarts on :machine to pick up the extra copy and REDIS_URL.', ['app' => $appName, 'machine' => $web->name]),
            ],
            [
                'id' => 'monitor',
                'op' => 'add',
                'logo' => null,
                'title' => __('Add a monitor for :address', ['address' => $domain.'/health']),
                'short' => __('Add the health monitor'),
                'path' => 'monitors[1]',
                'machine' => null,
                'risk' => null,
                'diff' => [
                    ['no' => 26, 'sign' => ' ', 'text' => 'monitors:'],
                    ['no' => 27, 'sign' => ' ', 'text' => '  - https://'.$domain],
                    ['no' => 28, 'sign' => '+', 'text' => '  - https://'.$domain.'/health'],
                ],
                'note' => __('ServerOS checks it every minute and tells you when it fails.'),
            ],
            [
                'id' => 'search',
                'op' => 'remove',
                'logo' => 'meili',
                'title' => __('Remove search'),
                'short' => __('Remove search'),
                'path' => 'services.search',
                'machine' => self::machineRef($search),
                'risk' => ['tone' => 'red', 'label' => __('Deletes meili-data')],
                'diff' => [
                    ['no' => 16, 'sign' => '-', 'text' => '  search:'],
                    ['no' => 17, 'sign' => '-', 'text' => '    template: meilisearch'],
                    ['no' => 18, 'sign' => '-', 'text' => '    machine: '.$search->name],
                    ['no' => 19, 'sign' => '-', 'text' => '    volume: meili-data'],
                ],
                'note' => __('search stops on :machine and its meili-data volume is deleted.', ['machine' => $search->name]),
            ],
        ];

        $touched = collect($changes)->pluck('machine.id')->filter()->countBy();

        return [
            'project' => [
                ...self::projectProps($workspace, $project),
                'file' => WorkspaceExport::projectFile($workspace, $project, $monitors),
            ],
            'projects' => self::projectList($workspace),
            'sample' => [
                'title' => __('Add Redis and scale :app', ['app' => $appName]),
                'pullRequest' => 142,
                'author' => Str::slug($user->name) ?: 'you',
                'from' => 'add-redis',
                'to' => 'main',
                'commit' => 'a3f9c1e',
                'planned' => __(':count min ago', ['count' => 2]),
                'stack' => $stack,
                'changes' => $changes,
                'checks' => [
                    ['title' => __('Secrets are set'), 'detail' => __(':names are in :environment', ['names' => 'STRIPE_KEY and APP_KEY', 'environment' => $environment])],
                    ['title' => __('No public database ports'), 'detail' => __('Redis only listens on the private network')],
                    ['title' => __('Databases are backed up'), 'detail' => __(':machine backs up daily to :destination', ['machine' => $db->name, 'destination' => 'Cloudflare R2'])],
                    ['title' => __(':environment needs a review', ['environment' => $environment]), 'detail' => __('Approved by :name :time', ['name' => 'Maya', 'time' => __(':count min ago', ['count' => 4])])],
                ],
                'machines' => $machines
                    ->filter(fn (Machine $machine) => $touched->has($machine->id))
                    ->sortByDesc(fn (Machine $machine) => $touched[$machine->id])
                    ->map(fn (Machine $machine) => [
                        'id' => $machine->id,
                        'name' => $machine->name,
                        'provider' => $machine->provider,
                        'changes' => $touched[$machine->id],
                    ])
                    ->values()
                    ->all(),
            ],
        ];
    }

    /** @return array{id: int, name: string, provider: string|null} */
    private static function machineRef(Machine $machine): array
    {
        return ['id' => $machine->id, 'name' => $machine->name, 'provider' => $machine->provider];
    }

    /** @return array{connected: bool, account: string|null, connectUrl: string|null} */
    private static function github(Workspace $workspace, User $user): array
    {
        $installation = GitHubInstallation::query()->where('workspace_id', $workspace->id)->oldest()->first();
        $canConnect = $user->can('manageMachines', $workspace);

        return [
            'connected' => $installation !== null,
            'account' => $installation?->account_login,
            'connectUrl' => ! $canConnect || $installation !== null ? null : (GitHubApp::enabled()
                ? route('workspace.github.connect', $workspace, false)
                : route('workspace.github.app', $workspace, false)),
        ];
    }

    private static function githubOwner(Workspace $workspace): string
    {
        return strtolower((string) (GitHubInstallation::query()->where('workspace_id', $workspace->id)->oldest()->value('account_login') ?? $workspace->slug));
    }

    private static function pick(Workspace $workspace, ?string $uid): ?Project
    {
        $chosen = $uid === null ? null : WorkspaceExport::projects($workspace)->firstWhere('uid', $uid);

        return $chosen ?? self::latestProject($workspace);
    }

    private static function latestProject(Workspace $workspace): ?Project
    {
        return WorkspaceExport::projects($workspace)
            ->sortByDesc(fn (Project $project) => [$project->created_at?->getTimestamp() ?? 0, $project->id])
            ->first();
    }

    /** @return array<string, mixed> */
    private static function projectProps(Workspace $workspace, Project $project): array
    {
        return [
            'uid' => $project->uid,
            'name' => $project->name,
            'environment' => WorkspaceExport::environmentOf($project),
            'slug' => WorkspaceExport::slug($project->name),
        ];
    }

    /** @return list<array{uid: string, name: string}> */
    private static function projectList(Workspace $workspace): array
    {
        return array_values(WorkspaceExport::projects($workspace)
            ->map(fn (Project $project) => ['uid' => $project->uid, 'name' => $project->name])
            ->all());
    }

    /**
     * The framework an app was deployed with, read from what its last deploy
     * shipped. Next.js until there's a deploy to read.
     */
    private static function stackOf(Project $project): string
    {
        foreach (WorkspaceExport::apps($project) as $app) {
            $spec = WorkspaceExport::spec($app);
            $env = implode(' ', array_map('strval', array_keys((array) ($spec['env'] ?? []))));
            $files = strtolower(implode("\n", array_map('strval', (array) ($spec['files'] ?? []))));
            $stack = match (true) {
                str_contains($env, 'NEXT_') => 'next',
                str_contains($env, 'ASTRO') || str_contains($files, 'astro') => 'astro',
                str_contains($env, 'DJANGO') || str_contains($files, 'manage.py') => 'django',
                str_contains($env, 'APP_KEY') && str_contains($env, 'DB_CONNECTION') => 'laravel',
                str_contains($files, 'oven/bun') || str_contains($files, 'bun install') => 'bun',
                str_contains($files, 'from node:') => 'node',
                default => null,
            };
            if ($stack !== null) {
                return $stack;
            }
        }

        return 'next';
    }

    /**
     * Things set up by hand that the files can't describe cleanly. The panel
     * can't tell these yet, so they're sample items placed on real machines.
     *
     * @param  Collection<int, Machine>  $machines
     * @return list<array{title: string, body: string, icon: string, path: string, comment: list<string>}>
     */
    private static function needsLook(Collection $machines): array
    {
        if ($machines->isEmpty()) {
            return [];
        }
        $running = fn (Machine $machine) => collect(WorkspaceExport::services($machine))->pluck('name')->map(fn ($name) => strtolower((string) $name));
        $cron = $machines->first(fn (Machine $machine) => $running($machine)->contains(fn (string $name) => (bool) preg_match('/cron|schedul/', $name)))
            ?? $machines->get(1) ?? $machines->first();
        $proxy = $machines->first(fn (Machine $machine) => $running($machine)->contains('nginx'));
        $proxyName = 'nginx';
        if ($proxy === null) {
            $proxy = $machines->first(fn (Machine $machine) => $running($machine)->contains('caddy')) ?? $machines->first();
            $proxyName = $running($proxy)->contains('caddy') ? 'caddy' : 'nginx';
        }

        $items = [[
            'title' => __('A crontab on :machine', ['machine' => $cron->name]),
            'body' => __('Set up by hand, so we wrote it as a comment in :path.', ['path' => 'machines/'.WorkspaceExport::fileName($cron->name).'.yml']),
            'icon' => 'terminal-fill',
            'path' => 'machines/'.WorkspaceExport::fileName($cron->name).'.yml',
            'comment' => ['# Needs a look: a crontab was set up by hand here.', '# */15 * * * * /usr/local/bin/sync-stock'],
        ]];
        $proxyPath = 'machines/'.WorkspaceExport::fileName($proxy->name).'.yml';
        $item = [
            'title' => __(':service on :machine was edited by hand', ['service' => $proxyName, 'machine' => $proxy->name]),
            'body' => __('We copied the config as is. Look it over before you merge.'),
            'icon' => 'file-fill',
            'path' => $proxyPath,
            'comment' => ['# Needs a look: '.$proxyName.' was edited by hand here.', '# Its config is copied as is.'],
        ];
        if ($proxyPath === $items[0]['path']) {
            $item['comment'] = [...$items[0]['comment'], ...$item['comment']];
            $items[0]['comment'] = [];
        }
        $items[] = $item;

        return $items;
    }
}
