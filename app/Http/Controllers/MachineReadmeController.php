<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Machine;
use App\Models\Workspace;
use App\Support\Audit\AuditContext;
use App\Support\Audit\Audited;
use App\Support\Code\CodeGitHub;
use App\Support\Code\CodeRepository;
use App\Support\Code\WorkspaceExport;
use App\Support\GitHub\GitHubApp;
use App\Support\Machines\Readme\MachineReadmes;
use App\Support\Machines\Readme\ReadmeFacts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;
use RuntimeException;

class MachineReadmeController extends Controller
{
    #[Audited]
    public function write(Request $request, Workspace $workspace, Machine $machine): RedirectResponse
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);
        if ($machine->inventory === null) {
            return $this->fail(ReadmeFacts::t('ServerOS hasn’t looked around :machine yet. The readme is written after its first scan.', ['machine' => $machine->name]));
        }
        $key = MachineReadmes::writeKey($machine);
        if (RateLimiter::tooManyAttempts($key, MachineReadmes::WRITES_PER_HOUR)) {
            $minutes = max(1, (int) ceil(RateLimiter::availableIn($key) / 60));

            return $this->fail((string) trans_choice('The readme was written again a few times this hour. You can ask again in :count minute.|The readme was written again a few times this hour. You can ask again in :count minutes.', $minutes, ['count' => $minutes]));
        }
        RateLimiter::hit($key, 3600);

        MachineReadmes::queue($machine, force: true);
        Activity::record($workspace, 'machine.readme_write', $machine->name, $machine, $request->user());

        return back();
    }

    #[Audited]
    public function commit(Request $request, Workspace $workspace, Machine $machine, GitHubApp $github, MachineReadmes $readmes): RedirectResponse
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);

        $repository = CodeRepository::of($workspace);
        if ($repository === null) {
            return $this->fail(ReadmeFacts::t('Pick a repository for your workspace’s code first.'));
        }
        $token = $github->writeTokenFor($workspace, $repository['owner'], $repository['name']);
        if ($token === null) {
            return $this->fail(ReadmeFacts::t('ServerOS’s GitHub App can’t write to :repo. Check what it can reach in Settings › GitHub.', ['repo' => $repository['repo']]));
        }
        $page = $readmes->page($machine, $request->user());
        if (! is_string($page['markdown'])) {
            return $this->fail(ReadmeFacts::t('The readme is still being written. Try again in a moment.'));
        }

        $file = WorkspaceExport::fileName($machine->name).'.md';
        $path = CodeRepository::path($repository['folder'], 'machines/'.$file);
        try {
            $result = $github->syncPullRequest(
                $token,
                $repository['owner'],
                $repository['name'],
                $repository['branch'],
                [$path => $page['markdown']],
                null,
                "Add {$machine->name}’s readme from ServerOS",
                "{$workspace->name} as code",
                implode("\n", [
                    "ServerOS wrote {$workspace->name} out as files from what it runs now. This adds {$path}, the readme of {$machine->name}.",
                    '',
                    'Merging this changes nothing on your servers.',
                ]),
            );
        } catch (RuntimeException $e) {
            return $this->fail($e->getMessage());
        }
        CodeGitHub::forget($workspace, $repository);

        if ($result['number'] === null || $result['url'] === null) {
            AuditContext::nothingChanged();
            Inertia::flash('toast', ['type' => 'info', 'message' => ReadmeFacts::t(':repo already has :file on :branch.', ['repo' => $repository['repo'], 'file' => $file, 'branch' => $result['base']])]);

            return back();
        }
        $workspace->forceFill(['code_repository' => [...($workspace->code_repository ?? []), 'pull' => ['number' => $result['number'], 'url' => $result['url']]]])->save();
        if ($result['state'] === 'current') {
            AuditContext::nothingChanged();
            Inertia::flash('toast', ['type' => 'info', 'message' => ReadmeFacts::t('Pull request #:number already has :file.', ['number' => $result['number'], 'file' => $file])]);

            return back();
        }

        Activity::record($workspace, 'github.machine_readme', $repository['repo'], $machine, $request->user(), ['number' => $result['number'], 'url' => $result['url'], 'file' => $path]);
        Inertia::flash('toast', ['type' => 'success', 'message' => $result['state'] === 'updated'
            ? ReadmeFacts::t('Added :file to pull request #:number on :repo.', ['file' => $file, 'number' => $result['number'], 'repo' => $repository['repo']])
            : ReadmeFacts::t('Opened pull request #:number on :repo with :file.', ['number' => $result['number'], 'repo' => $repository['repo'], 'file' => $file])]);

        return back();
    }

    private function fail(string $message): RedirectResponse
    {
        AuditContext::nothingChanged();
        Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

        return back();
    }
}
