<?php

namespace App\Support\Code;

use App\Models\GitHubInstallation;
use App\Models\Machine;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Deploys\DeployOnPush;
use App\Support\GitHub\GitHubApp;
use App\Support\Mcp\OAuth;
use App\Support\Projects\AppSettings;
use App\Support\Projects\Previews;
use App\Support\Projects\ProjectFacts;
use App\Support\ServiceTemplates\ServiceTemplateCatalog;
use Illuminate\Support\Collection;

/**
 * Page props for the infrastructure-as-code screens, all from the workspace
 * as it runs and from GitHub: the files, what they can't describe, where
 * they go, and the open pull requests that change them. The panel can't
 * apply changes from code, so nothing here offers to.
 *
 * @phpstan-import-type Repository from CodeRepository
 * @phpstan-import-type Item from NeedsLook
 */
final class CodeScreens
{
    /**
     * The workspace written out as files, with what they can't describe
     * written in as comments.
     *
     * @return array{files: list<array{path: string, lines: list<string>}>, machines: int, services: int, monitors: int, projects: int, secrets: int, projectPaths: array<string, string>, needsLook: list<Item>, machineModels: Collection<int, Machine>, projectModels: Collection<int, Project>}
     */
    public static function export(Workspace $workspace): array
    {
        $export = WorkspaceExport::build($workspace);
        $items = NeedsLook::items($export['machineModels'], $export['machinePaths'], $export['projectModels'], $export['projectPaths']);

        return [
            'files' => WorkspaceExport::annotate($export['files'], NeedsLook::notes($items)),
            'machines' => $export['machines'],
            'services' => $export['services'],
            'monitors' => $export['monitors'],
            'projects' => $export['projects'],
            'secrets' => $export['secrets'],
            'projectPaths' => $export['projectPaths'],
            'needsLook' => $items,
            'machineModels' => $export['machineModels'],
            'projectModels' => $export['projectModels'],
        ];
    }

    /** @return array<string, mixed> */
    public static function index(Workspace $workspace, User $user): array
    {
        $export = self::export($workspace);
        $open = $export['projectModels']
            ->sortByDesc(fn (Project $project) => [$project->created_at?->getTimestamp() ?? 0, $project->id])
            ->first();

        return [
            'code' => [
                'files' => $export['files'],
                'machines' => $export['machines'],
                'services' => $export['services'],
                'monitors' => $export['monitors'],
                'projects' => $export['projects'],
                'secrets' => $export['secrets'],
                'open' => ($open === null ? null : $export['projectPaths'][$open->uid] ?? null) ?? ($export['files'][0]['path'] ?? null),
            ],
            'github' => self::github($workspace, $user),
            'repository' => self::repositoryProps(CodeRepository::of($workspace)),
            'defaults' => ['branch' => CodeRepository::BRANCH, 'folder' => CodeRepository::FOLDER],
            'canManage' => $user->can('manageMachines', $workspace),
            'needsLook' => array_map(fn (array $item) => [
                'title' => $item['title'],
                'body' => $item['body'],
                'icon' => $item['icon'],
                'path' => $item['path'],
            ], $export['needsLook']),
        ];
    }

    /** @return array<string, mixed> */
    public static function setup(Workspace $workspace, ?string $uid): array
    {
        $project = self::pick($workspace, $uid);
        if ($project === null) {
            return ['project' => null, 'projects' => [], 'source' => null, 'mcpUrl' => OAuth::resource($workspace)];
        }

        $monitors = $workspace->monitors()->orderBy('name')->get();

        return [
            'project' => [
                ...self::projectProps($project),
                'file' => WorkspaceExport::projectFile($workspace, $project, $monitors),
            ],
            'projects' => self::projectList($workspace),
            'mcpUrl' => OAuth::resource($workspace),
            'source' => self::source($workspace, $project),
        ];
    }

    /** @return array<string, mixed> */
    public static function review(Workspace $workspace, User $user, ?int $number): array
    {
        $repository = CodeRepository::of($workspace);
        $github = self::github($workspace, $user);
        $props = [
            'repository' => self::repositoryProps($repository),
            'github' => $github,
            'pullRequests' => [],
            'pullRequest' => null,
            'error' => null,
        ];
        if ($repository === null || ! $github['connected']) {
            return $props;
        }

        $reads = app(CodeGitHub::class);
        $list = $reads->pullRequests($workspace, $repository);
        $props['error'] = $list['error'];
        $props['pullRequests'] = array_map(fn (array $pull) => [
            'number' => $pull['number'],
            'title' => $pull['title'],
            'author' => $pull['author'],
            'draft' => $pull['draft'],
            'updated' => $pull['updated'],
        ], $list['pulls']);
        $pull = collect($list['pulls'])->firstWhere('number', $number) ?? $list['pulls'][0] ?? null;
        if ($pull === null) {
            return $props;
        }

        $details = $reads->details($workspace, $repository, $pull);
        $export = self::export($workspace);
        $live = [];
        foreach ($export['files'] as $file) {
            $live[$file['path']] = $file['lines'];
        }
        $proposed = [];
        $unreadable = [];
        foreach ($pull['files'] as $file) {
            if ($file['previous'] !== null && $file['previous'] !== $file['relative']) {
                $proposed[] = ['path' => $file['previous'], 'content' => null];
            }
            if ($file['status'] === 'removed') {
                $proposed[] = ['path' => $file['relative'], 'content' => null];

                continue;
            }
            $content = $details['contents'][$file['path']] ?? null;
            if ($content === null) {
                $unreadable[] = $file['relative'];

                continue;
            }
            $proposed[] = ['path' => $file['relative'], 'content' => $content];
        }
        $plan = CodePlan::make($live, $proposed, $export['machineModels']);
        $touched = collect($plan['changes'])->pluck('machine.id')->filter()->countBy();

        $props['pullRequest'] = [
            'number' => $pull['number'],
            'title' => $pull['title'],
            'url' => $pull['url'],
            'author' => $pull['author'],
            'draft' => $pull['draft'],
            'from' => $pull['from'],
            'to' => $pull['to'],
            'commit' => substr($pull['sha'], 0, 7),
            'commitUrl' => $repository['url'].'/commit/'.$pull['sha'],
            'updated' => $pull['updated'],
            'files' => array_map(fn (array $file) => [
                'path' => $file['path'],
                'status' => $file['status'],
                'additions' => $file['additions'],
                'deletions' => $file['deletions'],
                'lines' => $file['patch'] === null ? [] : Diffs::patch($file['patch']),
            ], $pull['files']),
            'reviews' => $details['reviews'],
            'checks' => $details['checks'],
            'changes' => $plan['changes'],
            'unreadable' => array_values(array_unique([...$unreadable, ...$plan['unreadable']])),
            'machines' => $export['machineModels']
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
        ];

        return $props;
    }

    /**
     * @param  Repository|null  $repository
     * @return array{repo: string, branch: string, folder: string, private: bool, url: string, pullRequest: array{number: int, url: string}|null}|null
     */
    public static function repositoryProps(?array $repository): ?array
    {
        return $repository === null ? null : [
            'repo' => $repository['repo'],
            'branch' => $repository['branch'],
            'folder' => $repository['folder'],
            'private' => $repository['private'],
            'url' => $repository['url'],
            'pullRequest' => $repository['pullRequest'],
        ];
    }

    /** @return array{enabled: bool, connected: bool, account: string|null, connectUrl: string|null} */
    private static function github(Workspace $workspace, User $user): array
    {
        $installation = GitHubInstallation::query()->where('workspace_id', $workspace->id)->oldest()->first();
        $canConnect = $user->can('manageMachines', $workspace);

        return [
            'enabled' => GitHubApp::enabled(),
            'connected' => $installation !== null,
            'account' => $installation?->account_login,
            'connectUrl' => ! $canConnect || $installation !== null ? null : (GitHubApp::enabled()
                ? route('workspace.github.connect', $workspace, false)
                : route('workspace.github.app', $workspace, false)),
        ];
    }

    /**
     * Where the project's app comes from, as its last deploy recorded it: the
     * GitHub repository, the branch pushes deploy from (or the repository's
     * default branch), the commit and the stack ServerOS recognised.
     *
     * @return array{app: string|null, repo: string|null, url: string|null, branch: string|null, commit: string|null, upload: bool, deployed: bool, stack: array{slug: string, name: string}|null}
     */
    private static function source(Workspace $workspace, Project $project): array
    {
        $chosen = null;
        foreach (WorkspaceExport::apps($project) as $app) {
            $deploy = AppSettings::lastDeploy($app->machine, $app->name);
            $job = (array) ($deploy?->command['job'] ?? []);
            $candidate = ['app' => $app, 'deploy' => $deploy, 'job' => $job, 'repo' => Previews::repoOf($job)];
            if ($candidate['repo'] !== null) {
                $chosen = $candidate;

                break;
            }
            $chosen ??= $candidate;
        }
        if ($chosen === null) {
            return ['app' => null, 'repo' => null, 'url' => null, 'branch' => null, 'commit' => null, 'upload' => false, 'deployed' => false, 'stack' => null];
        }

        $app = $chosen['app'];
        $repo = $chosen['repo'];
        $branch = null;
        if ($repo !== null) {
            $push = DeployOnPush::setting($app->machine, $app->name);
            $branch = $push['on'] ? $push['branch'] : null;
            if ($branch === null) {
                $listed = collect(app(GitHubApp::class)->repositoriesFor($workspace))->first(fn (array $found) => strcasecmp($found['name'], $repo) === 0);
                $branch = ($listed['branch'] ?? '') ?: null;
            }
        }
        $template = $app->template === null ? null : app(ServiceTemplateCatalog::class)->find($app->template);
        $commit = $chosen['job']['commit'] ?? null;

        return [
            'app' => $app->name,
            'repo' => $repo,
            'url' => $repo === null ? null : 'https://github.com/'.$repo,
            'branch' => $branch,
            'commit' => is_string($commit) && $commit !== '' ? substr($commit, 0, 7) : null,
            'upload' => str_contains((string) ($chosen['job']['repo'] ?? ''), '/api/deploy-sources/'),
            'deployed' => $chosen['deploy'] !== null,
            'stack' => $template !== null
                ? ['slug' => $template->slug, 'name' => $template->name]
                : app(ProjectFacts::class)->kindOf($chosen['deploy']),
        ];
    }

    private static function pick(Workspace $workspace, ?string $uid): ?Project
    {
        $projects = WorkspaceExport::projects($workspace);
        $chosen = $uid === null ? null : $projects->firstWhere('uid', $uid);

        return $chosen ?? $projects
            ->sortByDesc(fn (Project $project) => [$project->created_at?->getTimestamp() ?? 0, $project->id])
            ->first();
    }

    /** @return array<string, mixed> */
    private static function projectProps(Project $project): array
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
}
