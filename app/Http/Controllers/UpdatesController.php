<?php

namespace App\Http\Controllers;

use App\Http\Resources\MachineResource;
use App\Models\Activity;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\MachineGroup;
use App\Models\MachinePackageUpdate;
use App\Models\MachineVulnerability;
use App\Models\PackageRollout;
use App\Models\Vulnerability;
use App\Models\Workspace;
use App\Support\Audit\Audited;
use App\Support\DaemonJobQueue;
use App\Support\Images\ImageUpdates;
use App\Support\Machines\PackageHolds;
use App\Support\Machines\PackageRollouts;
use App\Support\Machines\PackageUpdates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class UpdatesController extends Controller
{
    /** Packages that only take effect after the machine restarts. */
    private const string RESTART_PATTERN = '/^(linux-(image|modules|headers|generic|virtual|kvm|aws|azure|gcp|oracle|lowlatency)|kernel(-core|-modules)?$|libc6$|glibc$|dbus$|dbus-broker$|intel-microcode$|amd64-microcode$|linux-firmware$)/';

    public function index(Request $request, Workspace $workspace): Response
    {
        $machines = $workspace->machines()->orderBy('name')->get();
        $ids = $machines->pluck('id');
        $rows = MachinePackageUpdate::query()->whereIn('machine_id', $ids)
            ->orderBy('name')->get(['machine_id', 'name', 'installed', 'candidate', 'security']);
        $findings = MachineVulnerability::query()->whereIn('machine_id', $ids)->whereNull('resolved_at')->with('vulnerability')->get();
        $checking = DaemonJob::query()
            ->whereIn('machine_id', $ids)->whereIn('kind', PackageUpdates::KINDS)
            ->whereIn('state', ['queued', 'running'])->where('updated_at', '>=', now()->subMinutes(DaemonJob::STALE_AFTER_MINUTES))
            ->pluck('machine_id')->unique()->values();
        $packages = self::packages($machines, $rows, $findings);

        return Inertia::render('workspace/Updates', [
            'workspace' => $workspace->toPageProps(),
            'summary' => self::summary($machines, $rows),
            'security' => self::security($rows, $findings, $machines),
            'machines' => $machines->map(fn (Machine $machine) => self::machine($machine))->values()->all(),
            'packages' => $packages,
            'checking' => $checking->all(),
            'rollouts' => PackageRollout::query()->where('workspace_id', $workspace->id)->with('creator:id,name')
                ->latest()->latest('id')->limit(30)->get()->map(fn (PackageRollout $r) => PackageRollouts::summary($r))->all(),
            'rollout' => self::current($workspace),
            'groups' => $workspace->machineGroups()->orderBy('name')->get()
                ->map(fn (MachineGroup $group) => ['id' => $group->id, 'name' => $group->name, 'machineIds' => $machines->where('machine_group_id', $group->id)->pluck('id')->values()->all()])
                ->filter(fn (array $group) => $group['machineIds'] !== [])->values()->all(),
            'containers' => ImageUpdates::present($machines),
            'commandsEnabled' => (bool) config('daemon.commands_enabled'),
            'view' => in_array($request->query('view'), ['packages', 'machines', 'containers', 'rollouts'], true) ? $request->query('view') : null,
        ]);
    }

    /**
     * The rollout the summary card follows: one still going, else the newest.
     *
     * @return array<string, mixed>|null
     */
    private static function current(Workspace $workspace): ?array
    {
        $rollout = PackageRollout::query()->where('workspace_id', $workspace->id)
            ->orderByRaw("case when state in ('running', 'pending', 'paused') then 0 else 1 end")
            ->latest('id')->first();

        return $rollout === null ? null : PackageRollouts::detail($rollout);
    }

    #[Audited]
    public function check(Workspace $workspace, Request $request): JsonResponse
    {
        Gate::authorize('manageMachines', $workspace);

        $queued = 0;
        $machines = $workspace->machines()->whereNotNull('uid')->get();
        foreach ($machines as $machine) {
            if ($machine->isOnline() && PackageUpdates::check($machine, $request->user()) !== null) {
                $queued++;
            }
        }
        if ($queued === 0) {
            $reason = $machines->isEmpty() ? 'No machines are connected yet.'
                : (config('daemon.commands_enabled') ? 'No machine could be checked: they’re offline, or busy with updates already.' : app(DaemonJobQueue::class)->unavailable($machines->first()));

            return response()->json(['error' => $reason], 409);
        }
        Activity::record($workspace, 'updates.check_all', null, null, $request->user(), ['machines' => $queued]);

        return response()->json(['queued' => $queued], 202);
    }

    #[Audited]
    public function storeRollout(Request $request, Workspace $workspace, PackageRollouts $rollouts): JsonResponse
    {
        Gate::authorize('manageMachines', $workspace);

        $rollout = $rollouts->create($workspace, $request->user(), $request->all());

        return response()->json(['rollout' => PackageRollouts::summary($rollout)], 201);
    }

    public function showRollout(Workspace $workspace, PackageRollout $rollout): JsonResponse
    {
        abort_unless($rollout->workspace_id === $workspace->id, 404);

        return response()->json(['rollout' => PackageRollouts::detail($rollout)]);
    }

    #[Audited]
    public function cancelRollout(Request $request, Workspace $workspace, PackageRollout $rollout, PackageRollouts $rollouts): JsonResponse
    {
        abort_unless($rollout->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);

        if (! in_array($rollout->state, PackageRollout::OPEN, true)) {
            return response()->json(['error' => 'That rollout has already finished.'], 409);
        }
        $rollouts->cancel($rollout, $request->user());

        return response()->json(['rollout' => PackageRollouts::detail($rollout->fresh() ?? $rollout)]);
    }

    #[Audited]
    public function hold(Request $request, Workspace $workspace, PackageHolds $holds): JsonResponse
    {
        Gate::authorize('manageMachines', $workspace);
        $data = $request->validate([
            'packages' => ['required', 'array', 'min:1', 'max:200'],
            'packages.*' => ['string', 'max:200', 'regex:'.PackageUpdates::NAME_PATTERN],
            'machines' => ['required', 'array', 'min:1', 'max:2000'],
            'machines.*' => ['integer'],
            'hold' => ['required', 'boolean'],
        ]);

        $machines = $workspace->machines()->whereNotNull('uid')->whereIn('id', $data['machines'])->orderBy('name')->get();
        abort_if($machines->isEmpty(), 404);

        $result = $holds->apply($workspace, $request->user(), $machines, array_values(array_unique($data['packages'])), (bool) $data['hold']);

        return response()->json($result, $result['queued'] === [] ? 409 : 202);
    }

    /**
     * @param  Collection<int, Machine>  $machines
     * @param  Collection<int, MachinePackageUpdate>  $rows
     * @return array<string, mixed>
     */
    private static function summary(Collection $machines, Collection $rows): array
    {
        $checked = $machines->whereNotNull('updates_checked_at');

        return [
            'updates' => $rows->count(),
            'packages' => $rows->pluck('name')->unique()->count(),
            'security' => $rows->where('security', true)->count(),
            'machines' => $rows->pluck('machine_id')->unique()->count(),
            'total' => $machines->count(),
            'unchecked' => $machines->count() - $checked->count(),
            'rebootRequired' => $machines->where('reboot_required', true)->count(),
            'held' => $machines->flatMap(fn (Machine $machine) => $machine->held_packages ?? [])->unique()->count(),
            'checkedAt' => $checked->min('updates_checked_at')?->toIso8601String(),
            'newestCheck' => $checked->max('updates_checked_at')?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private static function machine(Machine $machine): array
    {
        return [
            'id' => $machine->id,
            'name' => $machine->name,
            'provider' => $machine->provider,
            'ip' => $machine->ip_address,
            'os' => PackageUpdates::osName($machine),
            'release' => PackageUpdates::releaseKey($machine),
            'online' => $machine->isOnline(),
            'connection' => MachineResource::connection($machine),
            'groupId' => $machine->machine_group_id,
            'updates' => $machine->updates_count,
            'security' => $machine->security_updates_count,
            'rebootRequired' => $machine->reboot_required,
            'checkedAt' => $machine->updates_checked_at?->toIso8601String(),
            'complete' => $machine->updates_complete,
            'held' => $machine->held_packages ?? [],
            'canVersions' => DaemonJobQueue::canInstallVersions($machine),
            'canPick' => MachineUpdatesController::canPick($machine),
        ];
    }

    /**
     * @param  Collection<int, Machine>  $machines
     * @param  Collection<int, MachinePackageUpdate>  $rows
     * @param  Collection<int, MachineVulnerability>|null  $findings
     * @return list<array<string, mixed>>
     */
    public static function packages(Collection $machines, Collection $rows, ?Collection $findings = null): array
    {
        $byId = $machines->keyBy('id');
        $held = [];
        foreach ($machines as $machine) {
            foreach ($machine->held_packages ?? [] as $name) {
                $held[$name][] = $machine->id;
            }
        }
        $fixes = self::fixesByPackage($findings ?? collect());

        $packages = $rows->groupBy('name')->map(function (Collection $group, string $name) use ($byId, $held, $fixes) {
            $versions = $group->groupBy(fn (MachinePackageUpdate $row) => $row->candidate.'|'.PackageUpdates::releaseKey($byId[$row->machine_id]))
                ->map(fn (Collection $same) => [
                    'version' => $same->first()->candidate,
                    'release' => PackageUpdates::releaseKey($byId[$same->first()->machine_id]),
                    'os' => PackageUpdates::osName($byId[$same->first()->machine_id]),
                    'machineIds' => $same->pluck('machine_id')->unique()->values()->all(),
                ])
                ->sortByDesc(fn (array $v) => count($v['machineIds']))->values()->all();

            return [
                'name' => $name,
                'security' => $group->contains('security', true),
                'machineIds' => $group->pluck('machine_id')->unique()->values()->all(),
                'versions' => $versions,
                'installed' => $group->pluck('installed')->filter()->unique()->values()->all(),
                'heldOn' => $held[$name] ?? [],
                'on' => $group->unique('machine_id')->map(fn (MachinePackageUpdate $row) => [
                    'id' => $row->machine_id,
                    'installed' => $row->installed,
                    'candidate' => $row->candidate,
                ])->values()->all(),
                'restart' => preg_match(self::RESTART_PATTERN, $name) === 1,
                'fixes' => self::fixes(array_values($fixes[$name] ?? [])),
            ];
        });

        foreach ($held as $name => $ids) {
            if (! $packages->has($name)) {
                $packages->put($name, ['name' => $name, 'security' => false, 'machineIds' => [], 'versions' => [], 'installed' => [], 'heldOn' => $ids, 'on' => [], 'restart' => false, 'fixes' => null]);
            }
        }

        return array_values($packages->sortBy([
            fn ($a, $b) => $b['security'] <=> $a['security'],
            fn ($a, $b) => self::worst($a['fixes']) <=> self::worst($b['fixes']),
            fn ($a, $b) => count($b['machineIds']) <=> count($a['machineIds']),
            fn ($a, $b) => strcmp($a['name'], $b['name']),
        ])->all());
    }

    /**
     * Each known vulnerability under every package name it covers.
     *
     * @param  Collection<int, MachineVulnerability>  $findings
     * @return array<string, array<int, Vulnerability>>
     */
    private static function fixesByPackage(Collection $findings): array
    {
        $by = [];
        foreach ($findings as $finding) {
            foreach (array_unique([$finding->source, ...($finding->packages ?? [])]) as $name) {
                $by[$name][$finding->vulnerability_id] = $finding->vulnerability;
            }
        }

        return $by;
    }

    /**
     * @param  list<Vulnerability>  $vulnerabilities
     * @return array{severity: string, count: int, exploited: int, epss: float|null, cves: list<array<string, mixed>>}|null
     */
    private static function fixes(array $vulnerabilities): ?array
    {
        if ($vulnerabilities === []) {
            return null;
        }
        usort($vulnerabilities, fn (Vulnerability $a, Vulnerability $b) => [$a->rank(), -($a->cvss ?? 0), $a->cve] <=> [$b->rank(), -($b->cvss ?? 0), $b->cve]);
        $order = array_flip(Vulnerability::SEVERITIES);
        $worst = min(array_map(fn (Vulnerability $v) => $order[$v->severity] ?? 9, $vulnerabilities));
        $epss = array_filter(array_map(fn (Vulnerability $v) => $v->epss, $vulnerabilities), fn (?float $e) => $e !== null);

        return [
            'severity' => Vulnerability::SEVERITIES[$worst] ?? 'unknown',
            'count' => count($vulnerabilities),
            'exploited' => count(array_filter($vulnerabilities, fn (Vulnerability $v) => $v->exploited)),
            'epss' => $epss === [] ? null : max($epss),
            'cves' => array_map(fn (Vulnerability $v) => [
                'cve' => $v->cve,
                'title' => $v->title,
                'severity' => $v->severity,
                'cvss' => $v->cvss,
                'epss' => $v->epss,
                'exploited' => $v->exploited,
                'url' => $v->url(),
            ], array_slice($vulnerabilities, 0, 12)),
        ];
    }

    /** @param  array{severity: string}|null  $fixes */
    private static function worst(?array $fixes): int
    {
        return $fixes === null ? 9 : (int) (array_flip(Vulnerability::SEVERITIES)[$fixes['severity']] ?? 9);
    }

    /**
     * Known vulnerabilities the waiting updates fix, by severity.
     *
     * @param  Collection<int, MachinePackageUpdate>  $rows
     * @param  Collection<int, MachineVulnerability>  $findings
     * @param  Collection<int, Machine>  $machines
     * @return array<string, mixed>
     */
    private static function security(Collection $rows, Collection $findings, Collection $machines): array
    {
        $security = $rows->where('security', true);
        $vulnerabilities = $findings->pluck('vulnerability')->unique('id');
        $fixes = self::fixesByPackage($findings);
        $names = $rows->map(fn (MachinePackageUpdate $row) => $row->name)->unique()->values();
        $levels = [];
        foreach (['critical', 'high', 'medium', 'low'] as $severity) {
            $named = $names->filter(fn (string $name) => collect($fixes[$name] ?? [])
                ->contains(fn (Vulnerability $v) => $v->severity === $severity))->values()->all();
            $levels[$severity] = [
                'cves' => $vulnerabilities->where('severity', $severity)->count(),
                'exploited' => $vulnerabilities->where('severity', $severity)->where('exploited', true)->count(),
                'packages' => $named,
            ];
        }

        return [
            'updates' => $security->count(),
            'packages' => $security->pluck('name')->unique()->count(),
            'cves' => $vulnerabilities->count(),
            'exploited' => $vulnerabilities->where('exploited', true)->count(),
            'levels' => $levels,
            'scannedAt' => $machines->max('vulns_scanned_at')?->toIso8601String(),
        ];
    }
}
