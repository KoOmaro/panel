<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Integration;
use App\Models\Workspace;
use App\Support\Audit\AuditContext;
use App\Support\Audit\Audited;
use App\Support\Integrations\IntegrationFailed;
use App\Support\Integrations\N8n\N8nStarter;
use App\Support\Integrations\N8n\N8nWorkflows;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use RuntimeException;

class N8nController extends Controller
{
    /** The account's workflows and their webhooks, for the workflow step's picker. */
    public function workflows(Request $request, Workspace $workspace, Integration $integration, N8nWorkflows $workflows): JsonResponse
    {
        $this->own($workspace, $integration);
        try {
            $list = $workflows->list($integration, fresh: $request->boolean('fresh'));
        } catch (IntegrationFailed $e) {
            return response()->json(['workflows' => [], 'error' => $e->getMessage()]);
        }

        return response()->json(['workflows' => array_map(fn (array $w) => [
            ...$w,
            'webhook' => $w['webhook'] === null ? null : [
                'node' => $w['webhook']['node'],
                'method' => $w['webhook']['method'],
                'answers' => $w['webhook']['answers'],
            ],
            'href' => N8nWorkflows::instance($integration).'/workflow/'.rawurlencode($w['id']),
        ], $list), 'error' => null]);
    }

    #[Audited]
    public function activate(Workspace $workspace, Integration $integration, string $workflow, N8nWorkflows $workflows): JsonResponse
    {
        $this->own($workspace, $integration);
        try {
            ['listed' => $listed] = $workflows->find($integration, $workflow);
            if ($listed['active']) {
                AuditContext::nothingChanged();

                return response()->json(['active' => true, 'name' => $listed['name']]);
            }
            $workflows->activate($integration, $workflow);
        } catch (IntegrationFailed $e) {
            return response()->json(['error' => $e->getMessage()], $e->reason === IntegrationFailed::NOT_FOUND ? 404 : 422);
        }
        Activity::record($workspace, 'integration.n8n_activate', $listed['name'], meta: ['account' => $integration->label]);

        return response()->json(['active' => true, 'name' => $listed['name']]);
    }

    #[Audited]
    public function starter(Request $request, Workspace $workspace, Integration $integration, N8nStarter $starter): RedirectResponse
    {
        $this->own($workspace, $integration);
        try {
            $made = $starter->create($integration, $request->user());
        } catch (IntegrationFailed|RuntimeException $e) {
            throw ValidationException::withMessages(['starter' => $e->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $made['credential']
            ? __('Made :name in n8n, with a read-only API key. Open it in n8n and run it.', ['name' => $made['name']])
            : __('Made :name in n8n. This n8n key can’t make credentials, so add a Header Auth credential with a ServerOS API key to its HTTP Request node.', ['name' => $made['name']])]);

        return back();
    }

    private function own(Workspace $workspace, Integration $integration): void
    {
        abort_unless($integration->workspace_id === $workspace->id && $integration->provider === 'n8n', 404);
        Gate::authorize('manageMachines', $workspace);
    }
}
