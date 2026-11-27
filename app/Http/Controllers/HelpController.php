<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
use App\Support\Extensions\Extensions;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HelpController extends Controller
{
    public function __invoke(Request $request, Workspace $workspace): Response
    {
        return Inertia::render('workspace/Help', [
            'workspace' => $workspace->toPageProps(),
            ...app(Extensions::class)->pagePropsFor('workspace/Help', $request, $workspace),
        ]);
    }
}
