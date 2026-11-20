<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\Workspace;
use App\Support\Audit\AuditContext;
use App\Support\Audit\Audited;
use App\Support\DaemonJobQueue;
use App\Support\Traffic\ExistingSites;
use App\Support\Traffic\SiteTraffic;
use App\Support\Traffic\TrafficSwitch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * Traffic counted from the access logs: the machine's switch, its list of
 * sites, each app's numbers for its Traffic tab, and the sites of the
 * machine's own web server, with response times for one on request.
 */
class MachineTrafficController extends Controller
{
    #[Audited]
    public function update(Request $request, Workspace $workspace, Machine $machine, TrafficSwitch $switch): JsonResponse
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);
        $enabled = (bool) $request->validate(['enabled' => ['required', 'boolean']])['enabled'];

        try {
            $record = $switch->set($machine, $request->user(), $enabled);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        }

        return response()->json(['jobId' => $record->id, 'traffic' => TrafficSwitch::props($machine->fresh() ?? $machine)], 202);
    }

    public function show(Workspace $workspace, Machine $machine, DaemonJob $job): JsonResponse
    {
        abort_unless($machine->workspace_id === $workspace->id && $job->machine_id === $machine->id && $job->kind === TrafficSwitch::KIND, 404);

        return response()->json([
            'state' => $job->isStale() ? 'stale' : $job->state,
            'error' => $job->error,
            'traffic' => TrafficSwitch::props($machine->fresh() ?? $machine),
        ]);
    }

    public function report(Request $request, Workspace $workspace, Machine $machine, string $service): JsonResponse
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);
        abort_if(AppVersionController::lastDeployOf($machine, $service) === null, 404);

        return response()->json([
            ...SiteTraffic::state($machine, $service, SiteTraffic::domains($machine, $service)),
            ...SiteTraffic::report($machine, $service, (string) $request->query('range', '24h')),
        ]);
    }

    /** Every site the machine counts, with its last 24 hours. */
    public function sites(Workspace $workspace, Machine $machine): JsonResponse
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);

        return response()->json([
            'collecting' => TrafficSwitch::on($machine),
            'blocked' => TrafficSwitch::blocked($machine),
            'existing' => ExistingSites::available($machine),
            'sites' => SiteTraffic::sites($machine),
        ]);
    }

    /** One site of the machine's own web server: the same report as an app's. */
    public function site(Request $request, Workspace $workspace, Machine $machine, string $site): JsonResponse
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);
        $found = ExistingSites::available($machine) ? ExistingSites::find($machine, $site) : null;
        abort_if($found === null, 404);

        return response()->json([
            ...ExistingSites::state($machine, $found),
            ...SiteTraffic::reportSite($machine, $found['site'], (string) $request->query('range', '24h')),
        ]);
    }

    /**
     * Response times for one site of the machine's own web server. Without
     * `apply` the machine only says which lines it would add, and where;
     * with it, it adds them.
     */
    #[Audited]
    public function timing(Request $request, Workspace $workspace, Machine $machine, string $site, DaemonJobQueue $queue): JsonResponse
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);
        $apply = (bool) $request->validate(['apply' => ['required', 'boolean']])['apply'];
        $found = ExistingSites::available($machine) ? ExistingSites::find($machine, $site) : null;
        abort_if($found === null, 404);
        if ($found['copy'] || $found['timed']) {
            return response()->json(['error' => __('This site’s log already has response times.')], 409);
        }

        try {
            $record = $queue->queue($machine, $request->user(), 'traffic_timing', ['type' => 'traffic_timing', 'site' => $found['site'], 'apply' => $apply], null, 180);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        }
        if ($apply) {
            Activity::record($machine->workspace_id, 'machine.traffic_timing', $machine->name, $machine, $request->user(), ['site' => ExistingSites::label($found)]);
        } else {
            // Only asks the machine what it would add.
            AuditContext::nothingChanged();
        }

        return response()->json(['jobId' => $record->id], 202);
    }

    public function timingJob(Workspace $workspace, Machine $machine, string $site, DaemonJob $job): JsonResponse
    {
        abort_unless($machine->workspace_id === $workspace->id && $job->machine_id === $machine->id && $job->kind === 'traffic_timing' && ($job->command['job']['site'] ?? null) === $site, 404);

        return response()->json([
            'state' => $job->isStale() ? 'stale' : $job->state,
            'result' => $job->result,
            'error' => $job->error,
        ]);
    }
}
