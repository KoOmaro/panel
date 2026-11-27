<?php

namespace App\Support\Machines;

use App\Enums\WorkspacePermission;
use App\Enums\WorkspaceRole;
use App\Models\Activity;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\MachineSshAccess;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\SshAccessNotice;
use App\Support\DaemonJobQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use RuntimeException;

final class SshAccess
{
    public const KIND = 'ssh_key';

    public const TIMEOUT_SECS = 60;

    /** @var list<int> */
    public const EXPIRY_DAYS = [1, 7, 30, 90];

    /** @var list<string> */
    public const KEY_TYPES = ['ssh-ed25519', 'ssh-rsa', 'ecdsa-sha2-nistp256', 'ecdsa-sha2-nistp384', 'sk-ssh-ed25519@openssh.com'];

    private const HISTORY_DAYS = 7;

    public function __construct(private readonly DaemonJobQueue $queue) {}

    /**
     * A public key as the daemon takes it (the same types and characters it
     * accepts), with the SHA256 fingerprint ssh-keygen -l shows.
     *
     * @return array{type: string, body: string, comment: string|null, fingerprint: string}|null
     */
    public static function parseKey(string $key): ?array
    {
        $parts = preg_split('/\s+/', trim($key), 3) ?: [];
        $type = $parts[0] ?? '';
        $body = $parts[1] ?? '';
        if (! in_array($type, self::KEY_TYPES, true) || strlen($body) <= 20 || preg_match('#^[A-Za-z0-9+/]+=*$#', $body) !== 1) {
            return null;
        }
        $blob = base64_decode($body, true);
        if ($blob === false || strlen($blob) < 8) {
            return null;
        }
        $length = unpack('N', substr($blob, 0, 4));
        if (! is_array($length) || substr($blob, 4, (int) $length[1]) !== $type) {
            return null;
        }
        $comment = trim(preg_replace('/[^\x20-\x7E]/', '', $parts[2] ?? '') ?? '');

        return [
            'type' => $type,
            'body' => $body,
            'comment' => $comment === '' ? null : mb_substr($comment, 0, 120),
            'fingerprint' => 'SHA256:'.rtrim(base64_encode(hash('sha256', $blob, true)), '='),
        ];
    }

    public static function typeLabel(?string $type): ?string
    {
        return match ($type) {
            'ssh-ed25519' => 'ED25519',
            'sk-ssh-ed25519@openssh.com' => 'ED25519-SK',
            'ssh-rsa' => 'RSA',
            'ecdsa-sha2-nistp256', 'ecdsa-sha2-nistp384' => 'ECDSA',
            default => null,
        };
    }

    public static function canApprove(Workspace $workspace, User $user): bool
    {
        return $workspace->allows($user, WorkspacePermission::ManageMembers);
    }

    /** Whether this key already has, or is on its way to having, access to the machine. */
    public static function taken(Machine $machine, string $fingerprint, string $login): bool
    {
        return MachineSshAccess::query()
            ->where('machine_id', $machine->id)
            ->where('login', $login)
            ->where('fingerprint', $fingerprint)
            ->listed()
            ->exists();
    }

    /**
     * Ask the machine to add the key. Throws, with why, when it can't take work.
     *
     * @throws RuntimeException
     */
    public function add(MachineSshAccess $access, User $actor): void
    {
        $this->send($access, $actor, 'add');
        $access->forceFill(['state' => MachineSshAccess::ADDING, 'error' => null])->save();
    }

    /** @throws RuntimeException */
    public function remove(MachineSshAccess $access, User $actor): void
    {
        $this->send($access, $actor, 'remove');
        $access->forceFill(['state' => MachineSshAccess::REMOVING, 'error' => null])->save();
    }

    /** @throws RuntimeException */
    private function send(MachineSshAccess $access, User $actor, string $action): void
    {
        $machine = $access->machine;
        if (($reason = $this->queue->unavailable($machine, self::KIND)) !== null) {
            throw new RuntimeException($reason);
        }
        if ($access->public_key === null) {
            throw new RuntimeException('There’s no key to send yet.');
        }

        $job = $this->queue->queue($machine, $actor, self::KIND, [
            'type' => self::KIND,
            'user' => $access->login,
            'action' => $action,
            'public_key' => $access->public_key,
        ], null, self::TIMEOUT_SECS);

        $access->forceFill(['job_id' => $job->id])->save();
    }

    /** What the machine said about an add or remove ServerOS sent. */
    public static function finished(DaemonJob $job): void
    {
        $access = MachineSshAccess::query()->where('job_id', $job->id)->first();
        if ($access === null || ! in_array($access->state, [MachineSshAccess::ADDING, MachineSshAccess::REMOVING], true)) {
            return;
        }
        $adding = $access->state === MachineSshAccess::ADDING;

        if ($job->state === 'succeeded') {
            $access->forceFill($adding
                ? ['state' => MachineSshAccess::ACTIVE, 'added_at' => now(), 'error' => null]
                : ['state' => MachineSshAccess::REMOVED, 'removed_at' => $access->removed_at ?? now(), 'error' => null])->save();

            return;
        }

        $why = self::failure($job, $access->machine, $access->login);
        if ($adding) {
            $access->forceFill(['state' => MachineSshAccess::FAILED, 'error' => $why])->save();
            Activity::system($access->workspace_id, 'machine.ssh_access.failed', $access->personName(), $access->machine, self::meta($access, ['error' => $why]));

            return;
        }
        $access->forceFill(['state' => MachineSshAccess::ACTIVE, 'error' => 'Couldn’t take the key off: '.$why])->save();
    }

    private static function failure(DaemonJob $job, Machine $machine, string $login): string
    {
        $message = is_string($job->error['message'] ?? null) ? $job->error['message'] : null;

        return match (true) {
            $message !== null && str_contains($message, 'does not exist') => "There’s no {$login} user on {$machine->name} yet. Create it there with: sudo useradd -m -s /bin/bash {$login}",
            $message !== null && str_contains($message, 'read-only') => "{$machine->name}’s daemon is in read-only mode, so it can’t change keys.",
            $message !== null => mb_substr($message, 0, 400),
            $job->state === 'timed_out' => "{$machine->name} didn’t finish in time.",
            default => "{$machine->name} couldn’t change the key ({$job->state}).",
        };
    }

    /**
     * Take keys off when their time is up or their person has left the
     * workspace, and stop waiting on machines that never answered.
     *
     * @return array{ended: int, stale: int}
     */
    public function sweep(): array
    {
        $ended = 0;
        $stale = 0;

        $left = fn ($q) => $q->where('external', false)->where(fn ($q) => $q->whereNull('user_id')->orWhereNotExists(fn ($member) => $member
            ->from('user_workspace')
            ->whereColumn('user_workspace.user_id', 'machine_ssh_access.user_id')
            ->whereColumn('user_workspace.workspace_id', 'machine_ssh_access.workspace_id')));

        MachineSshAccess::query()
            ->listed()
            ->where(fn ($q) => $q->where('expires_at', '<=', now())->orWhere($left))
            ->with(['machine', 'workspace.owner', 'user', 'decidedBy'])
            ->orderBy('id')
            ->get()
            ->each(function (MachineSshAccess $access) use (&$ended) {
                $reason = $access->expires_at !== null && $access->expires_at->lte(now()) ? 'expired' : 'left';
                if ($this->end($access, $reason)) {
                    $ended++;
                }
            });

        MachineSshAccess::query()
            ->whereIn('state', [MachineSshAccess::ADDING, MachineSshAccess::REMOVING])
            ->where('updated_at', '<', now()->subMinutes(DaemonJob::STALE_AFTER_MINUTES))
            ->with('machine')
            ->get()
            ->each(function (MachineSshAccess $access) use (&$stale) {
                $job = $access->job_id === null ? null : DaemonJob::query()->find($access->job_id);
                if ($job !== null && ! $job->isStale() && $job->isActive()) {
                    return;
                }
                $why = "{$access->machine->name} didn’t answer, so ServerOS stopped waiting.";
                $access->forceFill($access->state === MachineSshAccess::ADDING
                    ? ['state' => MachineSshAccess::FAILED, 'error' => $why]
                    : ['state' => MachineSshAccess::ACTIVE, 'error' => 'Couldn’t take the key off: '.$why])->save();
                $stale++;
            });

        return ['ended' => $ended, 'stale' => $stale];
    }

    private function end(MachineSshAccess $access, string $reason): bool
    {
        if (in_array($access->state, [MachineSshAccess::ADDING, MachineSshAccess::REMOVING], true)) {
            return false;
        }

        if ($access->state === MachineSshAccess::ACTIVE) {
            $actor = $access->decidedBy ?? $access->workspace->owner;
            try {
                $this->remove($access, $actor);
            } catch (RuntimeException $e) {
                $why = 'Waiting to take the key off: '.$e->getMessage();
                if ($access->error !== $why) {
                    $access->forceFill(['error' => $why])->save();
                }

                return false;
            }
        } else {
            $access->forceFill(['state' => MachineSshAccess::REMOVED, 'removed_at' => now()])->save();
        }

        Activity::system($access->workspace_id, 'machine.ssh_access.end', $access->personName(), $access->machine, self::meta($access, ['reason' => $reason]));

        return true;
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public static function meta(MachineSshAccess $access, array $extra = []): array
    {
        return array_filter([
            'access_id' => $access->id,
            'login' => $access->login,
            'fingerprint' => $access->fingerprint,
            'external' => $access->isExternal() ?: null,
            ...$extra,
        ], fn ($value) => $value !== null);
    }

    /**
     * @param  iterable<User>|User  $to
     * @param  'requested'|'approved'|'declined'|'given'|'waiting_key'  $kind
     */
    public static function notify(iterable|User $to, MachineSshAccess $access, string $kind, ?User $by = null): void
    {
        $people = collect($to instanceof User ? [$to] : $to)->filter(fn (User $user) => $user->id !== $by?->id)->unique('id')->values();
        if ($people->isNotEmpty()) {
            Notification::send($people, new SshAccessNotice($access, $kind, $by));
        }
    }

    /** @return Collection<int, User> */
    public static function approvers(Workspace $workspace): Collection
    {
        return $workspace->members()->get()->filter(fn (User $member) => self::canApprove($workspace, $member))->values();
    }

    public static function tabUrl(Machine $machine): string
    {
        return $machine->workspace->url("/machines/{$machine->id}/access");
    }

    public static function reviewUrl(MachineSshAccess $access): string
    {
        return $access->workspace->url("/machines/{$access->machine_id}/access/{$access->id}");
    }

    /** @return array<string, mixed> */
    public static function view(Workspace $workspace, Machine $machine, ?User $viewer): array
    {
        $canApprove = $viewer !== null && self::canApprove($workspace, $viewer);
        $members = $workspace->members()->orderBy('name')->get();
        $roles = $members->mapWithKeys(fn (User $member) => [$member->id => WorkspaceRole::tryFrom((string) $member->getRelationValue('pivot')?->getAttribute('role'))?->label()]);

        $rows = MachineSshAccess::query()
            ->where('machine_id', $machine->id)
            ->listed()
            ->with('user')
            ->orderByRaw('case when state = ? then 0 else 1 end', [MachineSshAccess::REQUESTED])
            ->orderBy('id')
            ->get();

        $security = is_array($machine->facts['security'] ?? null) ? $machine->facts['security'] : [];
        $ssh = is_array($security['ssh'] ?? null) ? $security['ssh'] : null;
        $logins = is_array($security['logins'] ?? null) ? $security['logins'] : null;
        $ports = $ssh === null ? [] : array_values(array_filter((array) ($ssh['ports'] ?? []), 'is_int'));

        $activity = Activity::query()
            ->where('machine_id', $machine->id)
            ->where('action', 'like', 'machine.ssh_access.%')
            ->with('user')
            ->latest('id');
        $last = (clone $activity)->first();
        $recent = (clone $activity)->where('created_at', '>=', now()->subDays(self::HISTORY_DAYS))->limit(6)->get();
        $people = MachineSshAccess::query()
            ->whereIn('id', $recent->map(fn (Activity $a) => $a->meta['access_id'] ?? null)->filter()->unique()->values())
            ->with('user')
            ->get()
            ->keyBy('id');

        return [
            'canApprove' => $canApprove,
            'login' => MachineSshAccess::LOGINS[0],
            'logins' => MachineSshAccess::LOGINS,
            'address' => filled($machine->ip_address) ? $machine->ip_address : null,
            'hostname' => is_string($machine->facts['hostname'] ?? null) ? $machine->facts['hostname'] : $machine->name,
            'port' => $ports === [] || in_array(22, $ports, true) ? null : $ports[0],
            'ssh' => $ssh === null ? null : [
                'passwords' => is_bool($ssh['password_authentication'] ?? null) ? $ssh['password_authentication'] : null,
                'keyboardInteractive' => ($ssh['kbd_interactive_authentication'] ?? null) === true && ($ssh['use_pam'] ?? null) === true,
                'rootLogin' => is_string($ssh['permit_root_login'] ?? null) ? strtolower($ssh['permit_root_login']) : null,
                'ports' => $ports,
            ],
            'signIns' => $logins === null ? null : [
                'failed' => (int) ($logins['failed'] ?? 0),
                'sources' => (int) ($logins['sources'] ?? 0),
                'root' => (int) ($logins['root'] ?? 0),
                'password' => (int) ($logins['accepted_password'] ?? 0),
                'hours' => max(1, intdiv((int) ($logins['window_secs'] ?? 3600), 3600)),
            ],
            'people' => $rows->map(fn (MachineSshAccess $access) => self::row($access, $roles, $viewer))->values()->all(),
            'history' => $recent->map(fn (Activity $a) => self::event($a, $people->get($a->meta['access_id'] ?? 0)))->values()->all(),
            'lastChange' => $last === null ? null : ['at' => $last->created_at->toIso8601String(), 'by' => $last->actor_name],
            'members' => $canApprove ? $members->map(fn (User $member) => [
                'id' => $member->id,
                'name' => $member->name,
                'email' => $member->email,
                'avatar' => $member->avatar,
                'role' => $roles[$member->id] ?? null,
            ])->values()->all() : [],
            'expiryDays' => self::EXPIRY_DAYS,
        ];
    }

    /**
     * @param  Collection<int, string|null>  $roles
     * @return array<string, mixed>
     */
    private static function row(MachineSshAccess $access, Collection $roles, ?User $viewer): array
    {
        return [
            'id' => $access->id,
            'state' => $access->state,
            'name' => $access->personName(),
            'email' => $access->personEmail(),
            'avatar' => $access->user?->avatar,
            'external' => $access->isExternal(),
            'role' => $access->user_id === null ? null : ($roles[$access->user_id] ?? null),
            'mine' => $viewer !== null && $access->belongsToPerson($viewer),
            'login' => $access->login,
            'keyType' => self::typeLabel($access->key_type),
            'fingerprint' => $access->fingerprint,
            'comment' => $access->key_comment,
            'note' => $access->note,
            'error' => $access->error,
            'requestedAt' => $access->requested_at?->toIso8601String(),
            'addedAt' => $access->added_at?->toIso8601String(),
            'expiresAt' => $access->expires_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private static function event(Activity $activity, ?MachineSshAccess $access): array
    {
        $kind = substr($activity->action, strlen('machine.ssh_access.'));

        return [
            'id' => $activity->id,
            'kind' => $kind,
            'reason' => $activity->meta['reason'] ?? null,
            'at' => $activity->created_at->toIso8601String(),
            'person' => $access === null ? ['name' => $activity->subject, 'avatar' => null, 'external' => (bool) ($activity->meta['external'] ?? false)] : [
                'name' => $access->personName(),
                'avatar' => $access->user?->avatar,
                'external' => $access->isExternal(),
            ],
            'login' => $activity->meta['login'] ?? null,
            'by' => $activity->actor_name,
            'fingerprint' => $activity->meta['fingerprint'] ?? null,
            'error' => $activity->meta['error'] ?? null,
            'count' => $activity->meta['count'] ?? null,
        ];
    }
}
