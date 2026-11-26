<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
use App\Support\Code\CodeScreens;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

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
        return Inertia::render('workspace/code/ReviewChanges', [
            'workspace' => $workspace->toPageProps(),
            ...CodeScreens::review($workspace, $request->user(), $request->string('project')->toString() ?: null),
        ]);
    }
}
