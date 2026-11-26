<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Workspace;
use App\Support\Audit\AuditContext;
use App\Support\Audit\Audited;
use App\Support\Code\CodeGitHub;
use App\Support\Code\CodeRepository;
use App\Support\Code\CodeScreens;
use App\Support\Deploys\DeployOnPush;
use App\Support\GitHub\GitHubApp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

class WorkspaceCodeController extends Controller
{
    public function index(Request $request, Workspace $workspace): Response
    {
        return Inertia::render('workspace/code/WorkspaceCode', [
            'workspace' => $workspace->toPageProps(),
            ...CodeScreens::index($workspace, $request->user()),
        ]);
    }

    public function setup(Request $request, Workspace $workspace): Response
    {
        return Inertia::render('workspace/code/SetUpCode', [
            'workspace' => $workspace->toPageProps(),
            ...CodeScreens::setup($workspace, $request->string('project')->toString() ?: null),
        ]);
    }

    public function changes(Request $request, Workspace $workspace): Response
    {
        $number = $request->integer('pull');

        return Inertia::render('workspace/code/ReviewChanges', [
            'workspace' => $workspace->toPageProps(),
            ...CodeScreens::review($workspace, $request->user(), $number > 0 ? $number : null),
        ]);
    }

    /** The files the Code page shows, as a zip named after the workspace. */
    public function download(Workspace $workspace): BinaryFileResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'serveros-code-');
        $zip = new ZipArchive;
        abort_if($path === false || $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true, 500, 'Couldn’t make the zip.');
        foreach (CodeScreens::export($workspace)['files'] as $file) {
            $zip->addFromString($workspace->slug.'/'.$file['path'], implode("\n", $file['lines'])."\n");
        }
        $zip->close();

        return response()->download($path, $workspace->slug.'.zip', ['Content-Type' => 'application/zip'])->deleteFileAfterSend();
    }

    /** Where the workspace's code goes: a repository its GitHub App reaches, a branch and a folder. */
    #[Audited]
    public function repository(Request $request, Workspace $workspace, GitHubApp $github): RedirectResponse
    {
        Gate::authorize('manageMachines', $workspace);
        $request->merge(['folder' => CodeRepository::folder((string) $request->input('folder', ''))]);
        $data = $request->validate([
            'repo' => ['required', 'string', 'max:140', 'regex:'.CodeRepository::REPO_PATTERN],
            'branch' => ['nullable', 'string', 'max:200', 'regex:'.DeployOnPush::BRANCH_PATTERN],
            'folder' => ['nullable', 'string', 'max:200', 'regex:'.CodeRepository::FOLDER_PATTERN],
        ]);

        $found = collect($github->repositoriesFor($workspace))->first(fn (array $repository) => strcasecmp($repository['name'], $data['repo']) === 0);
        if ($found === null) {
            throw ValidationException::withMessages(['repo' => __('Pick one of the repositories ServerOS’s GitHub App can reach.')]);
        }
        $branch = ($data['branch'] ?? '') ?: CodeRepository::BRANCH;
        if ($found['branch'] !== '' && strcasecmp($found['branch'], $branch) === 0) {
            throw ValidationException::withMessages(['branch' => __(':branch is the default branch of :repo. Pick another branch, so the files go in through a pull request.', ['branch' => $branch, 'repo' => $found['name']])]);
        }
        $folder = (string) ($data['folder'] ?? '');

        $before = CodeRepository::of($workspace);
        $same = $before !== null && strcasecmp($before['repo'], $found['name']) === 0 && $before['branch'] === $branch;
        if ($same && $before['folder'] === $folder) {
            AuditContext::nothingChanged();

            return back();
        }

        $workspace->forceFill(['code_repository' => array_filter([
            'repo' => $found['name'],
            'branch' => $branch,
            'folder' => $folder,
            'private' => $found['private'],
            'pull' => $same ? ($workspace->code_repository['pull'] ?? null) : null,
        ], fn ($value) => $value !== null)])->save();
        Activity::record($workspace, 'github.code_repository', $found['name'], meta: ['branch' => $branch, 'folder' => $folder]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Your workspace’s code goes to :repo now.', ['repo' => $found['name']])]);

        return back();
    }

    /** Sends the files to the code repository's branch and opens a pull request, or updates the open one. */
    #[Audited]
    public function pullRequest(Workspace $workspace, GitHubApp $github): RedirectResponse
    {
        Gate::authorize('manageMachines', $workspace);
        $fail = function (string $message): RedirectResponse {
            AuditContext::nothingChanged();
            Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

            return back();
        };

        $repository = CodeRepository::of($workspace);
        if ($repository === null) {
            return $fail(__('Pick a repository for your workspace’s code first.'));
        }
        $token = $github->writeTokenFor($workspace, $repository['owner'], $repository['name']);
        if ($token === null) {
            return $fail(__('ServerOS’s GitHub App can’t write to :repo. Check what it can reach in Settings › GitHub.', ['repo' => $repository['repo']]));
        }

        $export = CodeScreens::export($workspace);
        $files = [];
        foreach ($export['files'] as $file) {
            $files[CodeRepository::path($repository['folder'], $file['path'])] = implode("\n", $file['lines'])."\n";
        }
        $where = $repository['folder'] === '' ? 'the root of the repository' : $repository['folder'].'/';
        try {
            $result = $github->syncPullRequest(
                $token,
                $repository['owner'],
                $repository['name'],
                $repository['branch'],
                $files,
                CodeRepository::prune($repository['folder']),
                "Write {$workspace->name} as code from ServerOS",
                "{$workspace->name} as code",
                implode("\n", [
                    "ServerOS wrote {$workspace->name} out as files from what it runs now: {$export['machines']} machines, {$export['services']} services and {$export['monitors']} monitors, in {$where}.",
                    '',
                    'Secrets are written as references, never their values. Merging this changes nothing on your servers.',
                ]),
            );
        } catch (RuntimeException $e) {
            return $fail($e->getMessage());
        }
        CodeGitHub::forget($workspace, $repository);

        if ($result['number'] === null || $result['url'] === null) {
            AuditContext::nothingChanged();
            Inertia::flash('toast', ['type' => 'info', 'message' => __(':repo already has these files on :branch, so there’s nothing to open.', ['repo' => $repository['repo'], 'branch' => $result['base']])]);

            return back();
        }

        $workspace->forceFill(['code_repository' => [...($workspace->code_repository ?? []), 'pull' => ['number' => $result['number'], 'url' => $result['url']]]])->save();
        if ($result['state'] === 'current') {
            AuditContext::nothingChanged();
            Inertia::flash('toast', ['type' => 'info', 'message' => __('Pull request #:number already has these files.', ['number' => $result['number']])]);

            return back();
        }

        Activity::record($workspace, 'github.code_pull_request', $repository['repo'], meta: ['number' => $result['number'], 'url' => $result['url'], 'updated' => $result['state'] === 'updated']);
        Inertia::flash('toast', ['type' => 'success', 'message' => $result['state'] === 'updated'
            ? __('Updated pull request #:number on :repo.', ['number' => $result['number'], 'repo' => $repository['repo']])
            : __('Opened pull request #:number on :repo.', ['number' => $result['number'], 'repo' => $repository['repo']])]);

        return back();
    }
}
