<?php

namespace App\Http\Controllers;

use App\Http\Resources\MachineResource;
use App\Models\Activity;
use App\Models\Backup;
use App\Models\BackupDestination;
use App\Models\Machine;
use App\Models\Workspace;
use App\Support\Audit\AuditContext;
use App\Support\Audit\Audited;
use App\Support\Backups\BackupRunner;
use App\Support\Deploys\ServiceLabels;
use App\Support\Integrations\Storage\BackupStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class BackupController extends Controller
{
    public const array SCHEDULES = ['off', 'daily', 'weekly'];

    public function index(Workspace $workspace, BackupRunner $runner): Response
    {
        $machines = $workspace->machines()->with('backupDestination')->orderBy('name')->get();
        $ids = $machines->modelKeys();
        $recent = Backup::query()->whereIn('machine_id', $ids)->where('created_at', '>=', now()->subDays(30))
            ->with(['machine', 'destination'])->latest('id')->get();
        $latest = $recent->groupBy(fn (Backup $b) => $b->machine_id.'|'.$b->service)->map(fn (Collection $list) => $list->first());
        $cannot = $runner->cannotRestoreAll($latest->values());
        $labels = self::labels($machines);
        $stored = Backup::query()->whereIn('machine_id', $ids)->where('state', 'succeeded')->whereNotNull('backup_destination_id')
            ->selectRaw('backup_destination_id, sum(bytes) as bytes')->groupBy('backup_destination_id')->pluck('bytes', 'backup_destination_id');

        $destinations = $workspace->backupDestinations()->with('machine')->orderBy('name')->get();
        $madeBy = BackupStorage::madeBy(array_values($destinations->map(fn (BackupDestination $d) => $d->id)->all()));

        return Inertia::render('workspace/Backups', [
            'workspace' => $workspace->toPageProps(),
            'machines' => $machines->map(fn (Machine $machine) => [
                'id' => $machine->id,
                'name' => $machine->name,
                'ip' => $machine->ip_address,
                'provider' => $machine->provider,
                'connection' => MachineResource::connection($machine),
                'destinationId' => $machine->backup_destination_id,
                'schedule' => $machine->backup_schedule,
                'lastBackup' => $machine->last_backup_at?->toIso8601String(),
                'services' => count($machine->backupableServices()),
                'serviceList' => array_map(function (array $service) use ($machine, $latest, $cannot) {
                    $backup = $latest->get($machine->id.'|'.$service['key']);

                    return [
                        'key' => $service['key'],
                        'name' => self::label($machine, $service),
                        'kind' => $service['kind'] ?? null,
                        'image' => $service['details']['image'] ?? $service['image'] ?? null,
                        'iconUrl' => ServiceLabels::iconUrl($machine, $service),
                        'last' => $backup === null ? null : [
                            ...self::brief($backup),
                            'cannotRestore' => $cannot[$backup->id] ?? null,
                        ],
                    ];
                }, $machine->backupableServices()),
                'lastRun' => self::lastRun($recent->where('machine_id', $machine->id)),
                'unavailable' => $runner->unavailable($machine),
            ]),
            'destinations' => $destinations->map(fn (BackupDestination $d) => [
                ...$d->toPageProps(),
                'machines' => $machines->where('backup_destination_id', $d->id)->count(),
                'provider' => $d->type === 'machine' ? $d->machine?->provider : null,
                'stored' => (int) ($stored[$d->id] ?? 0),
                'madeBy' => $madeBy[$d->id] ?? null,
            ]),
            'storage' => Gate::allows('manageMachines', $workspace) ? BackupStorage::shortcuts($workspace) : [],
            'summary' => self::summary($machines, $recent, (int) $stored->sum(), $labels),
            'history' => function () use ($machines, $runner, $labels) {
                $backups = Backup::query()
                    ->whereIn('machine_id', $machines->modelKeys())
                    ->with(['machine', 'destination'])
                    ->latest('id')
                    ->limit(100)
                    ->get();
                $cannot = $runner->cannotRestoreAll($backups);

                return $backups->map(fn (Backup $backup) => [
                    ...$backup->toPageProps(),
                    'serviceLabel' => $labels[$backup->machine_id.'|'.$backup->service] ?? $backup->service_name,
                    'cannotRestore' => $cannot[$backup->id] ?? null,
                ]);
            },
        ]);
    }

    /**
     * What each service is called on the page, by machine and key.
     *
     * @param  Collection<int, Machine>  $machines
     * @return array<string, string>
     */
    private static function labels(Collection $machines): array
    {
        $labels = [];
        foreach ($machines as $machine) {
            foreach ($machine->inventory['services'] ?? [] as $service) {
                if (is_array($service) && isset($service['key'])) {
                    $labels[$machine->id.'|'.$service['key']] = self::label($machine, $service);
                }
            }
        }

        return $labels;
    }

    /**
     * The service's name, with a stack's containers called app · service
     * rather than by their full container name.
     *
     * @param  array<string, mixed>  $service
     */
    private static function label(Machine $machine, array $service): string
    {
        $display = ServiceLabels::display($machine, $service);
        $project = $service['details']['compose_project'] ?? null;
        if (! is_string($project) || $display !== ($service['name'] ?? null) || ! str_starts_with($display, $project.'-')) {
            return $display;
        }
        $app = (string) preg_replace('/^serveros-/', '', $project);
        $inner = (string) preg_replace('/-\d+$/', '', substr($display, strlen($project) + 1));

        return $inner === '' || $inner === $app ? $app : "{$app} · {$inner}";
    }

    /** @return array<string, mixed> */
    private static function brief(Backup $backup): array
    {
        return [
            'id' => $backup->id,
            'serviceName' => $backup->service_name,
            'state' => $backup->state,
            'trigger' => $backup->trigger,
            'bytes' => $backup->bytes,
            'error' => $backup->error,
            'createdAt' => $backup->created_at?->toIso8601String(),
            'finishedAt' => $backup->finished_at?->toIso8601String(),
        ];
    }

    /**
     * The machine's newest run: its services' backups started together.
     *
     * @param  Collection<int, Backup>  $backups  newest first
     * @return array{state: string, at: string|null, bytes: int|null, failed: list<string>, error: string|null}|null
     */
    private static function lastRun(Collection $backups): ?array
    {
        $newest = $backups->first();
        if ($newest === null) {
            return null;
        }
        $run = $backups->filter(fn (Backup $b) => $b->trigger === $newest->trigger
            && $b->created_at !== null && $newest->created_at !== null && $b->created_at->diffInMinutes($newest->created_at, true) < 30)->values();
        $states = $run->pluck('state');
        $failed = $run->where('state', 'failed');

        return [
            'state' => match (true) {
                $states->contains(fn (string $s) => in_array($s, ['queued', 'running'], true)) => 'running',
                $failed->isNotEmpty() => 'failed',
                $states->contains('succeeded') => 'succeeded',
                default => 'skipped',
            },
            'at' => $run->last()?->created_at?->toIso8601String(),
            'bytes' => $run->where('state', 'succeeded')->isEmpty() ? null : (int) $run->where('state', 'succeeded')->sum('bytes'),
            'failed' => array_values($failed->map(fn (Backup $b) => $b->service_name)->all()),
            'error' => $failed->first()?->error,
        ];
    }

    /**
     * @param  Collection<int, Machine>  $machines
     * @param  Collection<int, Backup>  $recent  the last 30 days, newest first
     * @param  array<string, string>  $labels
     * @return array<string, mixed>
     */
    private static function summary(Collection $machines, Collection $recent, int $stored, array $labels): array
    {
        $day = $recent->filter(fn (Backup $b) => $b->created_at !== null && $b->created_at->gte(now()->subDay()));
        $finished = $recent->whereIn('state', ['succeeded', 'failed']);
        $shown = $day->whereIn('state', ['queued', 'running'])
            ->concat($day->where('state', 'failed'))
            ->concat($day->where('state', 'succeeded'))
            ->take(5)
            ->map(fn (Backup $b) => [
                'id' => $b->id,
                'service' => $labels[$b->machine_id.'|'.$b->service] ?? $b->service_name,
                'machine' => $b->machine->name,
                'state' => $b->state,
                'bytes' => $b->bytes,
                'at' => $b->created_at?->toIso8601String(),
            ])->values();

        return [
            'machines' => $machines->count(),
            'covered' => $machines->filter(fn (Machine $m) => $m->backup_destination_id !== null && $m->backup_schedule !== 'off')->count(),
            'services' => $machines->sum(fn (Machine $m) => count($m->backupableServices())),
            'stored' => $stored,
            'lastRunAt' => $recent->whereNotIn('trigger', ['pre-restore', 'pre-deploy'])->first()?->created_at?->toIso8601String(),
            'succeededRate' => $finished->isEmpty() ? null : round(100 * $finished->where('state', 'succeeded')->count() / $finished->count(), 1),
            'restores' => $recent->whereNotNull('restore_state')->count(),
            'day' => [
                'done' => $day->where('state', 'succeeded')->count(),
                'failed' => $day->where('state', 'failed')->count(),
                'running' => $day->whereIn('state', ['queued', 'running'])->count(),
                'total' => $day->count(),
                'shown' => $shown->all(),
            ],
        ];
    }

    #[Audited]
    public function restore(Request $request, Workspace $workspace, Backup $backup, BackupRunner $runner): JsonResponse
    {
        abort_unless($backup->machine->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);

        $confirm = (string) $request->validate(['confirm' => ['required', 'string', 'max:255']])['confirm'];
        if ($confirm !== $backup->service_name) {
            return response()->json(['errors' => ['confirm' => ['Type the service’s name to confirm.']]], 422);
        }

        try {
            $job = $runner->restore($backup, $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        }
        Activity::record($workspace, 'backup.restore', $backup->service_name, $backup->machine, meta: [
            'from' => $backup->created_at?->format('j M Y, H:i'),
            'undo' => $backup->trigger === 'pre-restore',
        ]);

        return response()->json(['jobId' => $job->id, 'state' => 'queued'], 202);
    }

    #[Audited]
    public function update(Request $request, Workspace $workspace, Machine $machine): RedirectResponse
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);

        $data = $request->validate([
            'destination_id' => ['nullable', 'integer', Rule::exists('backup_destinations', 'id')->where('workspace_id', $workspace->id)],
            'schedule' => ['required', Rule::in(self::SCHEDULES)],
        ]);

        $destination = isset($data['destination_id']) ? BackupDestination::query()->whereKey($data['destination_id'])->first() : null;
        if ($destination?->machine_id === $machine->id) {
            throw ValidationException::withMessages(['destination_id' => 'A machine can\'t keep its own backups; pick somewhere else.']);
        }
        if ($destination === null && $data['schedule'] !== 'off') {
            throw ValidationException::withMessages(['schedule' => 'Choose where its backups go first.']);
        }

        $machine->forceFill([
            'backup_destination_id' => $destination?->id,
            'backup_schedule' => $data['schedule'],
        ]);
        if ($machine->isDirty(['backup_destination_id', 'backup_schedule'])) {
            Activity::record($workspace, 'backups.schedule', $machine->name, $machine, meta: ['schedule' => $data['schedule'], 'destination' => $destination?->name]);
        } else {
            AuditContext::nothingChanged();
        }
        $machine->save();

        return back();
    }

    #[Audited]
    public function run(Request $request, Workspace $workspace, Machine $machine, BackupRunner $runner): JsonResponse
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);

        try {
            $started = $runner->run($machine, $request->user(), 'manual');
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        }
        Activity::record($workspace, 'backup.run', $machine->name, $machine, meta: ['services' => $started]);

        return response()->json(['started' => $started], 202);
    }
}
