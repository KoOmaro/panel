<?php

namespace App\Http\Controllers;

use App\Models\GitHubInstallation;
use App\Models\User;
use App\Models\Workspace;
use App\Support\GitHub\GitHubApp;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class GitHubRepositoryController extends Controller
{
    /** The connected GitHub accounts' repositories, to pick one to deploy. */
    public function index(Workspace $workspace, GitHubApp $github): JsonResponse
    {
        Gate::authorize('manageMachines', $workspace);

        $installations = GitHubApp::enabled() ? $workspace->githubInstallations()->get() : collect();
        $people = User::query()->whereIn('id', $installations->pluck('connected_by')->filter())->pluck('name', 'id');

        return response()->json([
            'enabled' => GitHubApp::enabled(),
            'connected' => $installations->isNotEmpty(),
            'accounts' => $installations->map(fn (GitHubInstallation $installation) => [
                'login' => $installation->account_login,
                'type' => $installation->account_type,
                'connectedBy' => $people[$installation->connected_by] ?? null,
            ])->values()->all(),
            'repositories' => $installations->isNotEmpty()
                ? $github->repositoriesFor($workspace)
                : [],
        ]);
    }

    public function branches(Workspace $workspace, string $owner, string $repo, GitHubApp $github): JsonResponse
    {
        Gate::authorize('manageMachines', $workspace);
        abort_if($repo === '.' || $repo === '..', 404);

        return response()->json(['branches' => $github->branchesFor($workspace, $owner, $repo) ?? []]);
    }
}
