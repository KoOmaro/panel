<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Workspace;
use App\Support\Admin\Impersonation;
use App\Support\Audit\NotAudited;
use App\Support\Integrations\IntegrationCatalogue;
use App\Support\Integrations\WorkspaceIntegrations;
use App\Support\Monitors\AlertKinds;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class IntegrationController extends Controller
{
    public function index(Request $request, Workspace $workspace, WorkspaceIntegrations $integrations): Response
    {
        $user = $request->user();
        if ($user->integrations_promo_seen_at === null && ! Impersonation::active($request)) {
            $user->forceFill(['integrations_promo_seen_at' => now()])->save();
        }
        $alerts = Gate::allows(WorkspaceIntegrations::MACHINES, $workspace);

        return Inertia::render('workspace/Integrations', [
            'workspace' => $workspace->toPageProps(),
            'categories' => IntegrationCatalogue::categories(),
            ...$integrations->props($workspace, $user),
            'alerts' => $alerts ? AlertKinds::props($workspace, true) : null,
        ]);
    }

    #[NotAudited('Personal: hides the integrations card on Home')]
    public function dismissPromo(Request $request, Workspace $workspace): RedirectResponse
    {
        if (! Impersonation::active($request)) {
            $request->user()->forceFill(['integrations_promo_seen_at' => now()])->save();
        }

        return back();
    }

    public static function showsPromo(?User $user, Workspace $workspace): bool
    {
        return $user !== null
            && $user->integrations_promo_seen_at === null
            && (Gate::forUser($user)->allows(WorkspaceIntegrations::MACHINES, $workspace) || Gate::forUser($user)->allows(WorkspaceIntegrations::SETTINGS, $workspace));
    }
}
