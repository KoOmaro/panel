<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Machine;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\MonitorIncident;
use App\Models\StatusIncident;
use App\Models\Workspace;
use App\Models\WorkspaceStatusPage;
use App\Support\Activity\ActivityLine;
use App\Support\Audit\Audited;
use App\Support\Audit\NotAudited;
use App\Support\Deploys\AppReleases;
use App\Support\Deploys\ServiceLabels;
use App\Support\Monitors\AlertKinds;
use App\Support\Monitors\HostResolver;
use App\Support\Monitors\MonitorAlerts;
use App\Support\Monitors\MonitorRunner;
use App\Support\Monitors\MonitorSettings;
use App\Support\Monitors\TelegramAlerts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

use function Illuminate\Support\defer;

class MonitorController extends Controller
{
    public const BUCKETS = 144;

    public const RANGES = ['24h' => 24, '7d' => 168];

    public function index(Workspace $workspace): Response
    {
        $monitors = $workspace->monitors()->with('machine')->orderBy('name')->get();
        $pausedBy = $this->pausedBy($workspace, array_values($monitors->where('paused', true)->map(fn (Monitor $m) => $m->name)->all()));
        $page = $workspace->fleetStatusPage;

        return Inertia::render('workspace/Monitors', [
            'workspace' => $workspace->toPageProps(),
            'monitors' => $monitors->map(fn (Monitor $m) => [
                ...$m->toPageProps(),
                'machine' => $m->machine ? ['id' => $m->machine->id, 'name' => $m->machine->name, 'provider' => $m->machine->provider] : null,
                'service' => $this->serviceFor($m),
                'pausedBy' => $m->paused ? ($pausedBy[$m->name] ?? null) : null,
            ])->values(),
            'uptime' => $this->overallUptime($monitors),
            'machines' => $this->machines($workspace),
            'limit' => $workspace->monitorLimit(),
            'minInterval' => $workspace->minMonitorInterval(),
            'alerts' => $this->alertSettings($workspace),
            'incidents' => array_values($workspace->statusIncidents()->shown()->with(['updates', 'machine'])
                ->orderByRaw('resolved_at is null desc')->latest('started_at')->limit(30)->get()
                ->map(fn (StatusIncident $incident) => [...$incident->toReport(withMachine: true), 'machineId' => $incident->machine_id])
                ->all()),
            'statusPage' => [
                'available' => $workspace->hasFleetStatusPage(),
                'set' => $page !== null,
                'live' => (bool) $page?->live(),
                'url' => $page ? rtrim((string) config('app.url'), '/').'/status/'.$page->slug : null,
                'shown' => $page ? $this->shownOnStatusPage($page, $monitors) : 0,
            ],
        ]);
    }

    /** @param  Collection<int, Monitor>  $monitors */
    private function shownOnStatusPage(WorkspaceStatusPage $page, Collection $monitors): int
    {
        $covered = $page->coveredMachines()->modelKeys();
        $picked = $page->monitors ?? [];

        return $monitors->filter(fn (Monitor $m) => ! $m->paused
            && (in_array($m->id, $picked, true) || ($m->machine_id !== null && in_array($m->machine_id, $covered, true))))->count();
    }

    /** @param  Collection<int, Monitor>  $monitors */
    private function overallUptime(Collection $monitors): ?float
    {
        $cutoff = now()->subDays(29)->toDateString();
        $checks = 0;
        $ok = 0;
        foreach ($monitors->where('paused', false) as $monitor) {
            foreach ($monitor->daily ?? [] as $day => [$c, $o]) {
                if ($day >= $cutoff) {
                    $checks += $c;
                    $ok += $o;
                }
            }
        }

        return $checks === 0 ? null : round($ok / $checks * 100, 3);
    }

    /**
     * @param  list<string>  $names
     * @return array<string, array{name: string|null, at: string}>
     */
    private function pausedBy(Workspace $workspace, array $names): array
    {
        if ($names === []) {
            return [];
        }

        return Activity::query()
            ->where('workspace_id', $workspace->id)
            ->where('action', 'monitor.pause')
            ->whereIn('subject', $names)
            ->with('user:id,name')
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn (Activity $a) => [(string) $a->subject => [
                'name' => ActivityLine::actor($a)['name'] ?? null,
                'at' => $a->created_at->toIso8601String(),
            ]])
            ->all();
    }

    /** @return array{name: string, image: string|null, icon: string|null}|null */
    private function serviceFor(Monitor $monitor): ?array
    {
        $machine = $monitor->machine;
        $services = $machine?->inventory['services'] ?? null;
        if ($machine === null || ! is_array($services)) {
            return null;
        }

        $host = strtolower((string) ($monitor->type === 'tcp' ? $monitor->host : parse_url((string) $monitor->url, PHP_URL_HOST)));
        $label = explode('.', $host)[0];
        $name = fn (array $s) => strtolower(AppReleases::parse($s['name'] ?? null)['app'] ?? (string) ($s['name'] ?? ''));
        $match = collect($services)->first(fn ($s) => is_array($s) && (
            ($monitor->type === 'tcp' && in_array($monitor->port, (array) ($s['ports'] ?? []), true))
            || ($label !== '' && $name($s) === $label)
        ));
        if (! is_array($match)) {
            return null;
        }
        $image = $match['image'] ?? $match['details']['image'] ?? null;

        return [
            'name' => ServiceLabels::for($machine, $match) ?? $name($match),
            'image' => is_string($image) ? $image : null,
            'icon' => ServiceLabels::iconUrl($machine, $match),
        ];
    }

    public function show(Request $request, Workspace $workspace, Monitor $monitor): Response
    {
        $this->own($workspace, $monitor);
        $range = $request->validate(['range' => ['nullable', Rule::in(array_keys(self::RANGES))]])['range'] ?? '24h';
        $hours = self::RANGES[$range];

        return Inertia::render('workspace/MonitorShow', [
            'workspace' => $workspace->toPageProps(),
            'monitor' => $monitor->toPageProps(),
            'machines' => $this->machines($workspace),
            'minInterval' => $workspace->minMonitorInterval(),
            'range' => $range,
            'chart' => $this->chart($monitor, $hours),
            'uptime' => [
                'day' => $this->uptimeSince($monitor, now()->subDay()),
                'week' => $this->uptimeSince($monitor, now()->subWeek()),
                'month' => $monitor->uptime(30),
                'quarter' => $monitor->uptime(Monitor::DAYS),
            ],
            'days' => $monitor->days(Monitor::DAYS),
            'incidents' => $monitor->incidents()->latest('started_at')->limit(20)->get()
                ->map(fn (MonitorIncident $i) => $i->toPageProps())->values(),
            'checks' => $monitor->checks()->latest('checked_at')->limit(20)->get()
                ->map(fn (MonitorCheck $c) => [
                    'id' => $c->id,
                    'at' => $c->checked_at->toIso8601String(),
                    'ok' => $c->ok,
                    'ms' => $c->response_ms,
                    'code' => $c->status_code,
                    'error' => $c->error,
                ])->values(),
        ]);
    }

    #[Audited]
    public function store(Request $request, Workspace $workspace, MonitorRunner $runner): RedirectResponse
    {
        Gate::authorize('manageMachines', $workspace);

        if (($full = MonitorSettings::limitReached($workspace)) !== null) {
            throw ValidationException::withMessages(['name' => $full]);
        }

        $monitor = $workspace->monitors()->create([
            ...$this->validated($request, $workspace),
            'status' => 'pending',
            'next_check_at' => now(),
        ]);

        Activity::record($workspace, 'monitor.add', $monitor->name, $monitor->machine, meta: ['target' => $monitor->target()]);

        defer(fn () => $runner->run(collect([$monitor->fresh(['workspace'])])));

        return to_route('workspace.monitors.show', ['workspace' => $workspace->slug, 'monitor' => $monitor->id]);
    }

    #[Audited]
    public function update(Request $request, Workspace $workspace, Monitor $monitor): RedirectResponse
    {
        $this->own($workspace, $monitor);
        Gate::authorize('manageMachines', $workspace);

        MonitorSettings::update($monitor, $this->validated($request, $workspace));
        Activity::record($workspace, 'monitor.update', $monitor->name, $monitor->machine);

        return back();
    }

    #[Audited]
    public function pause(Request $request, Workspace $workspace, Monitor $monitor): RedirectResponse
    {
        $this->own($workspace, $monitor);
        Gate::authorize('manageMachines', $workspace);
        $paused = $request->validate(['paused' => ['required', 'boolean']])['paused'];

        MonitorSettings::pause($monitor, (bool) $paused);
        Activity::record($workspace, $paused ? 'monitor.pause' : 'monitor.resume', $monitor->name, $monitor->machine);

        return back();
    }

    #[NotAudited('Runs the check now; what it finds is recorded as usual')]
    public function check(Workspace $workspace, Monitor $monitor, MonitorRunner $runner): RedirectResponse
    {
        $this->own($workspace, $monitor);
        Gate::authorize('manageMachines', $workspace);

        $runner->run(collect([$monitor->load('workspace')]));

        return back();
    }

    #[Audited]
    public function destroy(Workspace $workspace, Monitor $monitor): RedirectResponse
    {
        $this->own($workspace, $monitor);
        Gate::authorize('manageMachines', $workspace);

        Activity::record($workspace, 'monitor.remove', $monitor->name);
        $monitor->delete();

        return to_route('workspace.monitors.index', ['workspace' => $workspace->slug]);
    }

    #[Audited]
    public function updateAlerts(Request $request, Workspace $workspace, HostResolver $resolver): RedirectResponse
    {
        Gate::authorize('manageMachines', $workspace);

        $data = $request->validate([
            'email' => ['required', 'boolean'],
            'webhook' => ['nullable', 'string', 'max:2000', 'url:https'],
            ...TelegramAlerts::rules(),
            'muted' => ['sometimes', 'array'],
            'muted.*' => ['string', Rule::in(AlertKinds::keys())],
        ], TelegramAlerts::messages());
        $webhook = isset($data['webhook']) && trim($data['webhook']) !== '' ? trim($data['webhook']) : null;

        if ($webhook !== null && $resolver->resolve((string) parse_url($webhook, PHP_URL_HOST)) === []) {
            throw ValidationException::withMessages(['webhook' => 'That address can’t be reached from the panel.']);
        }
        $telegram = TelegramAlerts::merge($workspace, $data);

        $muted = array_key_exists('muted', $data)
            ? array_values(array_intersect(AlertKinds::keys(), $data['muted']))
            : AlertKinds::muted($workspace);

        $workspace->forceFill([
            'alert_email' => $data['email'],
            'alert_webhook_url' => $webhook,
            'alert_telegram_chat' => $telegram['chat'],
            'alert_telegram_token' => $telegram['token'],
            'alert_muted' => $muted,
        ])->save();
        Activity::record($workspace, 'alerts.update', null, meta: ['email' => (bool) $data['email'], 'webhook' => $webhook !== null, 'telegram' => $telegram['chat'] !== null, 'muted' => $muted]);

        return back();
    }

    #[NotAudited('Sends a test alert; nothing changes')]
    public function testAlert(Request $request, Workspace $workspace, MonitorAlerts $alerts, TelegramAlerts $telegrams): JsonResponse
    {
        Gate::authorize('manageMachines', $workspace);

        if ($request->input('channel') === 'telegram') {
            $data = $request->validate([...TelegramAlerts::rules(), 'telegram_chat' => ['required', ...TelegramAlerts::rules()['telegram_chat']]], TelegramAlerts::messages());
            $telegram = TelegramAlerts::merge($workspace, $data);
            $error = $telegram['chat'] === null || $telegram['token'] === null ? 'Add the chat id first.' : $telegrams->test($workspace, $telegram['token'], $telegram['chat']);

            return response()->json(['ok' => $error === null, 'error' => $error]);
        }

        $url = $request->validate(['webhook' => ['required', 'string', 'max:2000', 'url:https']])['webhook'];
        $error = $alerts->test($workspace, $url);

        return response()->json(['ok' => $error === null, 'error' => $error]);
    }

    private function own(Workspace $workspace, Monitor $monitor): void
    {
        abort_unless($monitor->workspace_id === $workspace->id, 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, Workspace $workspace): array
    {
        return app(MonitorSettings::class)->validate($request->all(), $workspace);
    }

    private function uptimeSince(Monitor $monitor, \DateTimeInterface $since): ?float
    {
        $row = $monitor->checks()->where('checked_at', '>=', $since)
            ->selectRaw('count(*) as total, sum(case when ok then 1 else 0 end) as passed')
            ->toBase()->first();

        return $row === null || (int) $row->total === 0 ? null : round((int) $row->passed / (int) $row->total * 100, 3);
    }

    /** @return array{timestamps: list<int>, response: list<int|null>, failed: list<int>} */
    private function chart(Monitor $monitor, int $hours): array
    {
        $end = now()->getTimestamp();
        $start = $end - $hours * 3600;
        $size = intdiv($hours * 3600, self::BUCKETS);
        $totalMs = $timed = $failed = array_fill(0, self::BUCKETS, 0);

        $checks = $monitor->checks()->where('checked_at', '>=', now()->subHours($hours))
            ->orderBy('checked_at')
            ->get(['checked_at', 'ok', 'response_ms']);
        foreach ($checks as $check) {
            $i = min(self::BUCKETS - 1, max(0, intdiv($check->checked_at->getTimestamp() - $start, $size)));
            if ($check->ok && $check->response_ms !== null) {
                $totalMs[$i] += $check->response_ms;
                $timed[$i]++;
            }
            if (! $check->ok) {
                $failed[$i]++;
            }
        }

        return [
            'timestamps' => array_map(fn (int $i) => $start + $i * $size, range(0, self::BUCKETS - 1)),
            'response' => array_map(fn (int $ms, int $n) => $n > 0 ? (int) round($ms / $n) : null, $totalMs, $timed),
            'failed' => array_values($failed),
        ];
    }

    /**
     * @return list<array{id: int, name: string, ip: string}>
     */
    private function machines(Workspace $workspace): array
    {
        return array_values($workspace->machines()->orderBy('name')->get(['id', 'name', 'ip_address'])
            ->map(fn (Machine $m) => ['id' => $m->id, 'name' => $m->name, 'ip' => $m->ip_address])->all());
    }

    /** @return array{email: bool, webhook: string|null, webhookSet: bool, telegram: array{chat: string|null, set: bool}, muted: list<string>} */
    private function alertSettings(Workspace $workspace): array
    {
        return AlertKinds::props($workspace, Gate::allows('manageMachines', $workspace));
    }
}
