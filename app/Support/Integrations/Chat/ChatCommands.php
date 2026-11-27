<?php

namespace App\Support\Integrations\Chat;

use App\Http\Controllers\MaintenanceController;
use App\Models\Activity;
use App\Models\ChatPost;
use App\Models\IntegrationServer;
use App\Models\Machine;
use App\Models\Monitor;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Audit\AuditContext;
use App\Support\DaemonJobQueue;
use App\Support\Deploys\AppReleases;
use App\Support\Deploys\ServiceLabels;
use App\Support\Health\WorkspaceHealth;
use App\Support\Monitors\MonitorAlerts;
use App\Support\Monitors\MonitorRunner;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;

/**
 * What the chat bots answer and do, the same in every chat: /status and
 * /machine read, and Check now, Restart and Mute act as the linked member,
 * only when their role lets them manage machines. Replies are plain text.
 */
final class ChatCommands
{
    public const string PERMISSION = 'manageMachines';

    private const int MAX_LINES = 12;

    public function __construct(private readonly DaemonJobQueue $queue) {}

    public static function help(): string
    {
        return implode("\n", [
            'ServerOS answers here:',
            '/status: machines, services and monitors that are down',
            '/machine <name>: how one machine is doing',
            '/checknow <monitor>: check a monitor now',
            '/mute <monitor> 1h: mute a monitor’s alerts (30m, 1h, 2h, 4h, 8h or 24h)',
            '/link <code>: link your chat account to your ServerOS account (the code is in your profile in ServerOS)',
            'The buttons on alerts and the commands that change something need a linked account whose role can manage machines.',
        ]);
    }

    public static function allowed(Workspace $workspace, ?User $user): bool
    {
        return $user !== null && Gate::forUser($user)->allows(self::PERMISSION, $workspace);
    }

    public static function refusal(Workspace $workspace, ?User $user): string
    {
        return $user === null
            ? "Link your account first: open your profile in ServerOS ({$workspace->name}), make a code under Chat accounts, then send /link with it."
            : "Your role in {$workspace->name} can’t do that. Ask an owner or admin.";
    }

    public function status(Workspace $workspace): string
    {
        $machines = $workspace->machines()->whereNotNull('uid')->orderBy('name')->get();
        $monitors = $workspace->monitors()->where('paused', false)->orderBy('name')->get();
        $problems = [];
        $servicesDown = 0;

        foreach ($machines as $machine) {
            if (! $machine->isOnline()) {
                $problems[] = $machine->last_seen_at === null
                    ? "{$machine->name} hasn’t reported yet"
                    : "{$machine->name} stopped reporting {$machine->last_seen_at->diffForHumans()}";

                continue;
            }
            foreach (self::downServices($machine) as $name) {
                $servicesDown++;
                $problems[] = "{$name} is down on {$machine->name}";
            }
        }
        $monitorsDown = $monitors->where('status', 'down');
        foreach ($monitorsDown as $monitor) {
            $problems[] = "{$monitor->name} is down".($monitor->last_error ? ": {$monitor->last_error}" : '');
        }

        $reporting = $machines->filter(fn (Machine $m) => $m->isOnline())->count();
        $head = match (true) {
            $machines->isEmpty() && $monitors->isEmpty() => "{$workspace->name} has no machines or monitors yet.",
            $problems === [] => "All good in {$workspace->name}: ".self::count($machines->count(), 'machine').' reporting, '.self::count($monitors->count(), 'monitor').' up.',
            default => "{$workspace->name}: {$reporting} of ".self::count($machines->count(), 'machine').' reporting, '
                .self::count($servicesDown, 'service').' down, '.$monitorsDown->count().' of '.self::count($monitors->count(), 'monitor').' down.',
        };

        return self::lines($head, $problems, $workspace->url('/machines'));
    }

    public function machine(Workspace $workspace, string $reference): string
    {
        $machine = self::find($workspace->machines(), $reference, 'machine', $workspace);
        if (is_string($machine)) {
            return $machine;
        }

        $state = match (true) {
            $machine->uid === null => 'hasn’t connected yet',
            $machine->isOnline() => 'is reporting',
            $machine->last_seen_at === null => 'hasn’t reported yet',
            default => "stopped reporting {$machine->last_seen_at->diffForHumans()}",
        };
        $lines = [];
        if ($machine->isOnline()) {
            $total = (int) ($machine->facts['memory_bytes'] ?? 0);
            $memory = $total > 0 && $machine->memory_used_mb !== null ? (int) floor($machine->memory_used_mb * 1048576 / $total * 100).'%' : null;
            $disk = $machine->volumes()[0] ?? null;
            $lines[] = implode(', ', array_filter([
                $machine->cpu_percent !== null ? "CPU {$machine->cpu_percent}%" : null,
                $memory !== null ? "memory {$memory}" : null,
                $machine->disk_percent !== null ? "disk {$machine->disk_percent}%".($disk ? ' ('.WorkspaceHealth::bytes($disk['total'] - $disk['used']).' free)' : '') : null,
            ])) ?: 'No readings yet.';
            $services = AppReleases::visible($machine);
            $down = self::downServices($machine);
            $lines[] = self::count(count($services), 'service').($down === [] ? ', none down' : ', down: '.implode(', ', $down));
        }
        $monitors = $workspace->monitors()->where('machine_id', $machine->id)->orderBy('name')->get();
        if ($monitors->isNotEmpty()) {
            $lines[] = 'Monitors: '.$monitors->map(fn (Monitor $m) => "{$m->name} ".($m->paused ? 'paused' : $m->status))->implode(', ');
        }
        if ($machine->inMaintenance()) {
            $lines[] = 'In maintenance until '.$machine->maintenance_until?->toDayDateTimeString().' UTC, so it isn’t alerted.';
        }

        return self::lines("{$machine->name} ({$machine->environment->value}) {$state}.", $lines, $workspace->url('/machines/'.$machine->id));
    }

    public function findMonitor(Workspace $workspace, string $reference): Monitor|string
    {
        return self::find($workspace->monitors()->with('machine'), $reference, 'monitor', $workspace);
    }

    /**
     * Runs the monitor's check after the reply has gone, then hands the result to $then.
     *
     * @param  (Closure(string): void)|null  $then
     */
    public function checkNow(Workspace $workspace, ?User $user, Monitor $monitor, ?Closure $then = null): string
    {
        if (! self::allowed($workspace, $user)) {
            return self::refusal($workspace, $user);
        }
        if ($monitor->paused) {
            return "{$monitor->name} is paused, so it isn’t checked. Resume it in ServerOS first.";
        }
        if (! RateLimiter::attempt("chat-check:{$workspace->id}", 20, fn () => true)) {
            return 'That’s a lot of checks in a minute. Try again shortly.';
        }

        defer(function () use ($monitor, $then): void {
            $last = (int) $monitor->checks()->max('id');
            app(MonitorRunner::class)->run(collect([$monitor->load('workspace')]));
            $fresh = $monitor->fresh();
            $check = $fresh?->checks()->where('id', '>', $last)->latest('id')->first();
            if ($then !== null && $fresh !== null) {
                $then(match (true) {
                    $check === null => "No result came back for {$fresh->name}.",
                    $check->ok => "{$fresh->name} is up".($check->response_ms !== null ? " ({$check->response_ms} ms)." : '.'),
                    $fresh->status === 'down' => "{$fresh->name} is still down: {$fresh->last_error}",
                    default => "The check of {$fresh->name} failed ({$fresh->last_error}). It counts as down after ".max(1, Monitor::CONFIRM_AFTER - $fresh->failures).' more in a row.',
                });
            }
        });

        return "Checking {$monitor->name} now.";
    }

    public function mute(Workspace $workspace, ?User $user, Monitor $monitor, int $minutes, string $chat): string
    {
        if (! self::allowed($workspace, $user)) {
            return self::refusal($workspace, $user);
        }
        if (! in_array($minutes, MaintenanceController::DURATIONS, true)) {
            return 'Mute for 30m, 1h, 2h, 4h, 8h or 24h.';
        }

        $monitor->forceFill(['muted_until' => now()->addMinutes($minutes)])->save();
        AuditContext::within(['via' => $chat], fn () => Activity::record($workspace, 'monitor.mute', $monitor->name, $monitor->machine, $user, ['minutes' => $minutes]));

        return "Muted {$monitor->name}’s alerts for ".MonitorAlerts::duration($minutes * 60).'. It’s still checked.';
    }

    /** When a machine that stopped reporting last did, and what its cloud server said at the last sync. */
    public function checkMachine(Workspace $workspace, ?User $user, Machine $machine): string
    {
        if (! self::allowed($workspace, $user)) {
            return self::refusal($workspace, $user);
        }
        $server = IntegrationServer::query()->where('machine_id', $machine->id)->with('integration:id,provider,label')->first();
        $status = is_array($server?->details) ? (string) ($server->details['status'] ?? '') : '';

        return implode(' ', array_filter([
            match (true) {
                $machine->isOnline() => "{$machine->name} is reporting again.",
                $machine->last_seen_at === null => "{$machine->name} hasn’t reported yet.",
                default => "{$machine->name} last reported {$machine->last_seen_at->diffForHumans()}.",
            },
            $server !== null && $status !== '' ? "Its server at {$server->integration->provider()->name()} was {$status} at the last sync".($server->synced_at ? ' ('.$server->synced_at->diffForHumans().').' : '.') : null,
        ]));
    }

    /** Maintenance for a while, so the machine isn't alerted. Not shown on status pages. */
    public function muteMachine(Workspace $workspace, ?User $user, Machine $machine, int $minutes, string $chat): string
    {
        if (! self::allowed($workspace, $user)) {
            return self::refusal($workspace, $user);
        }
        if (! in_array($minutes, MaintenanceController::DURATIONS, true)) {
            return 'Mute for 30m, 1h, 2h, 4h, 8h or 24h.';
        }

        $machine->forceFill(['maintenance_until' => now()->addMinutes($minutes), 'maintenance_reason' => null, 'maintenance_public' => false])->save();
        AuditContext::within(['via' => $chat], fn () => Activity::record($workspace, 'machine.maintenance', $machine->name, $machine, $user, ['minutes' => $minutes, 'reason' => null]));

        return "{$machine->name} is in maintenance for ".MonitorAlerts::duration($minutes * 60).', so it isn’t alerted. It isn’t shown on status pages.';
    }

    public function restart(Workspace $workspace, ?User $user, int $machineId, string $key, string $chat): string
    {
        if (! self::allowed($workspace, $user) || $user === null) {
            return self::refusal($workspace, $user);
        }
        $machine = $workspace->machines()->find($machineId);
        if (! $machine instanceof Machine) {
            return 'That machine isn’t in this workspace any more.';
        }
        $service = collect(AppReleases::visible($machine))->first(fn (array $s) => ($s['key'] ?? null) === $key);
        if ($service === null) {
            return "That service isn’t on {$machine->name} any more.";
        }
        $name = ServiceLabels::display($machine, $service) ?: $key;
        if (($service['details']['managed'] ?? null) !== 'true') {
            return "ServerOS doesn’t control {$name} yet. Adopt it on its page in ServerOS first.";
        }
        if (($reason = $this->queue->unavailable($machine)) !== null) {
            return $reason;
        }

        try {
            AuditContext::within(['via' => $chat], fn () => $this->queue->queue($machine, $user, 'service_action', ['type' => 'service_action', 'service' => $key, 'action' => 'restart'], $key));
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }

        return "Restarting {$name} on {$machine->name}.";
    }

    /**
     * A button on an alert.
     *
     * @param  (Closure(string): void)|null  $then  the result of a check, once it's in
     */
    public function press(ChatAction $action, ChatPost $post, ?User $user, string $chat, ?Closure $then = null): string
    {
        $workspace = $post->workspace;
        $monitor = $post->contextInt('monitor') === null ? null : $workspace->monitors()->with('machine')->find($post->contextInt('monitor'));
        $machine = $post->contextInt('machine') === null ? null : $workspace->machines()->find($post->contextInt('machine'));
        $gone = $post->contextInt('monitor') !== null ? 'That monitor isn’t in this workspace any more.' : 'That machine isn’t in this workspace any more.';

        return match ($action) {
            ChatAction::CheckNow => match (true) {
                $monitor instanceof Monitor => $this->checkNow($workspace, $user, $monitor, $then),
                $post->contextInt('monitor') === null && $machine instanceof Machine => $this->checkMachine($workspace, $user, $machine),
                default => $gone,
            },
            ChatAction::Mute => match (true) {
                $monitor instanceof Monitor => $this->mute($workspace, $user, $monitor, 60, $chat),
                $post->contextInt('monitor') === null && $machine instanceof Machine => $this->muteMachine($workspace, $user, $machine, 60, $chat),
                default => $gone,
            },
            ChatAction::Restart => $post->contextInt('machine') !== null && $post->contextString('service') !== null
                ? $this->restart($workspace, $user, (int) $post->contextInt('machine'), (string) $post->contextString('service'), $chat)
                : 'This alert doesn’t say which service.',
        };
    }

    /** "1h", "30m", "2h", "1d", "90" (minutes) as minutes, or null. */
    public static function minutes(?string $text): ?int
    {
        $text = strtolower(trim((string) $text));
        if ($text === '') {
            return 60;
        }
        if (preg_match('/^(\d{1,4})\s*(m|min|mins|minutes?|h|hr|hrs|hours?|d|days?)?$/', $text, $m) !== 1) {
            return null;
        }
        $unit = $m[2] ?? 'm';
        $minutes = (int) $m[1] * match ($unit[0]) {
            'h' => 60,
            'd' => 1440,
            default => 1,
        };

        return in_array($minutes, MaintenanceController::DURATIONS, true) ? $minutes : null;
    }

    /** @return list<string> the names of the services the watcher saw stop and not come back */
    private static function downServices(Machine $machine): array
    {
        $down = array_keys((array) ($machine->alert_state['services']['down'] ?? []));
        $services = $machine->inventory['services'] ?? null;
        $inventory = collect(is_array($services) ? $services : [])->filter(fn ($s) => is_array($s))->keyBy('key');

        return array_map(fn ($key) => is_array($service = $inventory->get($key)) ? (ServiceLabels::display($machine, $service) ?: (string) $key) : (string) $key, $down);
    }

    /**
     * @template T of Model
     *
     * @param  HasMany<T, Workspace>|Builder<T>  $query
     * @return T|string
     */
    private static function find(HasMany|Builder $query, string $reference, string $what, Workspace $workspace): Model|string
    {
        $reference = trim($reference);
        if ($reference === '') {
            return "Say which {$what}: /{$what} <name>.";
        }
        if (ctype_digit($reference) && ($found = (clone $query)->find((int) $reference)) !== null) {
            return $found;
        }
        $exact = (clone $query)->whereRaw('lower(name) = ?', [mb_strtolower($reference)])->limit(2)->get();
        if ($exact->count() === 1) {
            return $exact->first();
        }
        $close = (clone $query)->whereRaw('lower(name) like ?', ['%'.mb_strtolower(addcslashes($reference, '%_')).'%'])->orderBy('name')->limit(6)->get();
        if ($exact->isEmpty() && $close->count() === 1) {
            return $close->first();
        }

        return $close->isEmpty()
            ? "No {$what} called “{$reference}” in {$workspace->name}."
            : "Which {$what}? ".$close->pluck('name')->implode(', ');
    }

    private static function count(int $n, string $noun): string
    {
        return $n.' '.$noun.($n === 1 ? '' : 's');
    }

    /** @param  list<string>  $lines */
    private static function lines(string $head, array $lines, string $url): string
    {
        $more = count($lines) - self::MAX_LINES;
        $shown = array_map(fn (string $line) => '• '.$line, array_slice($lines, 0, self::MAX_LINES));
        if ($more > 0) {
            $shown[] = "and {$more} more";
        }

        return implode("\n", [$head, ...$shown, $url]);
    }
}
