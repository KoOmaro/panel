<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Machine;
use App\Models\MachineSshAccess;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Audit\Audited;
use App\Support\DaemonJobQueue;
use App\Support\Machines\SshAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class MachineSshAccessController extends Controller
{
    public function __construct(private readonly SshAccess $ssh) {}

    #[Audited]
    public function store(Request $request, Workspace $workspace, Machine $machine, DaemonJobQueue $queue): RedirectResponse
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMembers', $workspace);
        $data = $request->validate([
            'people' => ['required', 'array', 'min:1', 'max:20'],
            'people.*.userId' => ['nullable', 'integer'],
            'people.*.email' => ['nullable', 'email:rfc', 'max:255'],
            'people.*.name' => ['nullable', 'string', 'max:120'],
            'people.*.key' => ['nullable', 'string', 'max:16000'],
            'login' => ['required', Rule::in(MachineSshAccess::LOGINS)],
            'expiresInDays' => ['nullable', 'integer', Rule::in(SshAccess::EXPIRY_DAYS)],
        ]);
        $giver = $this->user($request);
        $login = $data['login'];

        $members = $workspace->members()->get();
        $byId = $members->keyBy('id');
        $byEmail = $members->keyBy(fn (User $member) => mb_strtolower($member->email));

        $chosen = [];
        $errors = [];
        $seen = [];
        foreach ($data['people'] as $i => $person) {
            $user = isset($person['userId'])
                ? $byId->get((int) $person['userId'])
                : (isset($person['email']) ? $byEmail->get(mb_strtolower($person['email'])) : null);
            if (isset($person['userId']) && $user === null) {
                $errors["people.{$i}.userId"] = 'They aren’t in this workspace any more.';

                continue;
            }
            if ($user === null && blank($person['email'] ?? null)) {
                $errors["people.{$i}.email"] = 'Add their email.';

                continue;
            }

            $key = null;
            if (filled($person['key'] ?? null)) {
                $key = SshAccess::parseKey((string) $person['key']);
                if ($key === null) {
                    $errors["people.{$i}.key"] = 'That isn’t a public key ServerOS can add. Paste one line from a .pub file, like ~/.ssh/id_ed25519.pub.';

                    continue;
                }
                if (isset($seen[$key['fingerprint']]) || SshAccess::taken($machine, $key['fingerprint'], $login)) {
                    $errors["people.{$i}.key"] = "That key already has access to {$machine->name}.";

                    continue;
                }
                $seen[$key['fingerprint']] = true;
            } elseif ($user === null) {
                $errors["people.{$i}.key"] = 'Paste their public key. People outside the workspace can’t add their own.';

                continue;
            }
            $chosen[] = ['user' => $user, 'email' => $user->email ?? mb_strtolower((string) $person['email']), 'name' => $user->name ?? ($person['name'] ?? null), 'key' => $key];
        }

        $external = collect($chosen)->contains(fn (array $person) => $person['user'] === null);
        if ($external && ! isset($data['expiresInDays'])) {
            $errors['expiresInDays'] = 'Pick when their access ends.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        if (collect($chosen)->contains(fn (array $person) => $person['key'] !== null) && ($reason = $queue->unavailable($machine, SshAccess::KIND)) !== null) {
            throw ValidationException::withMessages(['access' => $reason]);
        }

        $added = 0;
        foreach ($chosen as $person) {
            $user = $person['user'];
            if ($person['key'] === null && $user !== null && MachineSshAccess::query()->where('machine_id', $machine->id)->where('user_id', $user->id)->where('state', MachineSshAccess::WAITING_KEY)->exists()) {
                continue;
            }

            $access = $this->create($workspace, $machine, $user, $person['email'], $person['name'], $login, $person['key'], [
                'state' => $person['key'] === null ? MachineSshAccess::WAITING_KEY : MachineSshAccess::ADDING,
                'decided_by_id' => $giver->id,
                'decided_at' => now(),
                'expires_at' => $user === null ? now()->addDays((int) $data['expiresInDays']) : null,
            ]);
            if ($person['key'] !== null) {
                $this->sendOrFail($access, $giver);
            }
            Activity::record($workspace, 'machine.ssh_access.give', $access->personName(), $machine, meta: SshAccess::meta($access, [
                'expires' => $access->expires_at?->toDateString(),
                'waiting_key' => $person['key'] === null ?: null,
            ]));
            if ($user !== null) {
                SshAccess::notify($user, $access, $person['key'] === null ? 'waiting_key' : 'given', $giver);
            }
            $added++;
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $added === 1 ? "Gave 1 person access to {$machine->name}." : "Gave {$added} people access to {$machine->name}."]);

        return back();
    }

    /** Ask for access with your own key, or add your key to access someone gave you. */
    #[Audited]
    public function request(Request $request, Workspace $workspace, Machine $machine, DaemonJobQueue $queue): RedirectResponse
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);
        $data = $request->validate([
            'key' => ['required', 'string', 'max:16000'],
            'note' => ['nullable', 'string', 'max:300'],
            'login' => ['sometimes', Rule::in(MachineSshAccess::LOGINS)],
        ]);
        $user = $this->user($request);
        $login = $data['login'] ?? MachineSshAccess::LOGINS[0];

        $key = SshAccess::parseKey($data['key']);
        if ($key === null) {
            throw ValidationException::withMessages(['key' => 'That isn’t a public key ServerOS can add. Paste one line from a .pub file, like ~/.ssh/id_ed25519.pub.']);
        }
        if (SshAccess::taken($machine, $key['fingerprint'], $login)) {
            throw ValidationException::withMessages(['key' => "That key already has access to {$machine->name}."]);
        }

        $waiting = MachineSshAccess::query()
            ->where('machine_id', $machine->id)
            ->where('user_id', $user->id)
            ->where('state', MachineSshAccess::WAITING_KEY)
            ->first();
        $canApprove = SshAccess::canApprove($workspace, $user);

        if ($waiting !== null || $canApprove) {
            if (($reason = $queue->unavailable($machine, SshAccess::KIND)) !== null) {
                throw ValidationException::withMessages(['key' => $reason]);
            }
            $access = $waiting ?? $this->create($workspace, $machine, $user, $user->email, $user->name, $login, null, [
                'state' => MachineSshAccess::ADDING,
                'decided_by_id' => $user->id,
                'decided_at' => now(),
            ]);
            $access->forceFill(self::keyColumns($key, $user->email, $login))->save();
            $this->sendOrFail($access, $user);
            Activity::record($workspace, $waiting !== null ? 'machine.ssh_access.key' : 'machine.ssh_access.give', $waiting !== null ? $machine->name : $access->personName(), $machine, meta: SshAccess::meta($access));
            Inertia::flash('toast', ['type' => 'success', 'message' => "Adding your key to {$machine->name}."]);

            return back();
        }

        if (MachineSshAccess::query()->where('machine_id', $machine->id)->where('user_id', $user->id)->where('state', MachineSshAccess::REQUESTED)->exists()) {
            throw ValidationException::withMessages(['key' => 'You already asked for access here. An owner or admin will look at it.']);
        }

        $access = $this->create($workspace, $machine, $user, $user->email, $user->name, $login, $key, [
            'state' => MachineSshAccess::REQUESTED,
            'requested_at' => now(),
            'note' => filled($data['note'] ?? null) ? trim((string) $data['note']) : null,
        ]);
        Activity::record($workspace, 'machine.ssh_access.request', $machine->name, $machine, meta: SshAccess::meta($access));
        SshAccess::notify(SshAccess::approvers($workspace), $access, 'requested', $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Asked. Owners and admins get an email to approve it.']);

        return back();
    }

    public function show(Request $request, Workspace $workspace, Machine $machine, MachineSshAccess $access): Response
    {
        $this->guard($workspace, $machine, $access);
        $user = $this->user($request);
        $canDecide = SshAccess::canApprove($workspace, $user);
        abort_unless($canDecide || $access->belongsToPerson($user), 403);
        $access->loadMissing(['user', 'decidedBy']);

        return Inertia::render('workspace/SshAccessApproval', [
            'workspace' => ['name' => $workspace->name, 'slug' => $workspace->slug],
            'machine' => ['id' => $machine->id, 'name' => $machine->name, 'provider' => $machine->provider, 'iconUrl' => $machine->iconUrl()],
            'access' => [
                'id' => $access->id,
                'state' => $access->state,
                'name' => $access->personName(),
                'email' => $access->personEmail(),
                'avatar' => $access->user?->avatar,
                'external' => $access->isExternal(),
                'mine' => $access->belongsToPerson($user),
                'login' => $access->login,
                'keyType' => SshAccess::typeLabel($access->key_type),
                'fingerprint' => $access->fingerprint,
                'comment' => $access->key_comment,
                'note' => $access->note,
                'requestedAt' => $access->requested_at?->toIso8601String(),
                'expiresAt' => $access->expires_at?->toIso8601String(),
                'decidedBy' => $access->decidedBy?->name,
                'decidedAt' => $access->decided_at?->toIso8601String(),
                'error' => $access->error,
            ],
            'viewer' => ['email' => $user->email],
            'canDecide' => $canDecide,
            'accessUrl' => SshAccess::tabUrl($machine),
        ]);
    }

    #[Audited]
    public function approve(Request $request, Workspace $workspace, Machine $machine, MachineSshAccess $access): RedirectResponse
    {
        $this->guard($workspace, $machine, $access);
        Gate::authorize('manageMembers', $workspace);
        $user = $this->user($request);
        if ($access->state !== MachineSshAccess::REQUESTED) {
            throw ValidationException::withMessages(['access' => 'This request has already been answered.']);
        }

        try {
            $this->ssh->add($access, $user);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['access' => $e->getMessage()]);
        }
        $access->forceFill(['decided_by_id' => $user->id, 'decided_at' => now()])->save();
        Activity::record($workspace, 'machine.ssh_access.approve', $access->personName(), $machine, meta: SshAccess::meta($access));
        if ($access->user !== null) {
            SshAccess::notify($access->user, $access, 'approved', $user);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => "Approved. Adding {$access->personName()}’s key to {$machine->name}."]);

        return back();
    }

    #[Audited]
    public function decline(Request $request, Workspace $workspace, Machine $machine, MachineSshAccess $access): RedirectResponse
    {
        $this->guard($workspace, $machine, $access);
        Gate::authorize('manageMembers', $workspace);
        $user = $this->user($request);
        if ($access->state !== MachineSshAccess::REQUESTED) {
            throw ValidationException::withMessages(['access' => 'This request has already been answered.']);
        }

        $access->forceFill(['state' => MachineSshAccess::DECLINED, 'decided_by_id' => $user->id, 'decided_at' => now()])->save();
        Activity::record($workspace, 'machine.ssh_access.decline', $access->personName(), $machine, meta: SshAccess::meta($access));
        if ($access->user !== null) {
            SshAccess::notify($access->user, $access, 'declined', $user);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Turned down. Nothing changed on '.$machine->name.'.']);

        return back();
    }

    #[Audited]
    public function retry(Request $request, Workspace $workspace, Machine $machine, MachineSshAccess $access): RedirectResponse
    {
        $this->guard($workspace, $machine, $access);
        $user = $this->user($request);
        abort_unless(SshAccess::canApprove($workspace, $user) || $access->belongsToPerson($user), 403);
        if ($access->state !== MachineSshAccess::FAILED) {
            throw ValidationException::withMessages(['access' => 'Only a key that couldn’t be added can be tried again.']);
        }

        try {
            $this->ssh->add($access, $user);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['access' => $e->getMessage()]);
        }
        Activity::record($workspace, 'machine.ssh_access.retry', $access->personName(), $machine, meta: SshAccess::meta($access));
        Inertia::flash('toast', ['type' => 'success', 'message' => "Trying again on {$machine->name}."]);

        return back();
    }

    /** Take someone's key off, or withdraw a request that hasn't been answered. */
    #[Audited]
    public function destroy(Request $request, Workspace $workspace, Machine $machine, MachineSshAccess $access): RedirectResponse
    {
        $this->guard($workspace, $machine, $access);
        $user = $this->user($request);
        abort_unless(SshAccess::canApprove($workspace, $user) || $access->belongsToPerson($user), 403);
        if (! in_array($access->state, [MachineSshAccess::ACTIVE, MachineSshAccess::REQUESTED, MachineSshAccess::WAITING_KEY, MachineSshAccess::FAILED], true)) {
            throw ValidationException::withMessages(['access' => "{$machine->name} is still working on this key. Try again in a moment."]);
        }

        $withdrawn = $access->state === MachineSshAccess::REQUESTED && $access->belongsToPerson($user);
        $this->takeOff($access, $user);
        Activity::record($workspace, $withdrawn ? 'machine.ssh_access.withdraw' : 'machine.ssh_access.revoke', $withdrawn ? $machine->name : $access->personName(), $machine, meta: SshAccess::meta($access));
        Inertia::flash('toast', ['type' => 'success', 'message' => match (true) {
            $withdrawn => 'Withdrew your request.',
            $access->state === MachineSshAccess::REMOVING => "Taking {$access->personName()}’s key off {$machine->name}.",
            default => "Removed {$access->personName()}.",
        }]);

        return back();
    }

    #[Audited]
    public function destroyAll(Request $request, Workspace $workspace, Machine $machine): RedirectResponse
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMembers', $workspace);
        $user = $this->user($request);

        $rows = MachineSshAccess::query()
            ->where('machine_id', $machine->id)
            ->whereIn('state', [MachineSshAccess::ACTIVE, MachineSshAccess::REQUESTED, MachineSshAccess::WAITING_KEY, MachineSshAccess::FAILED])
            ->with(['machine', 'user'])
            ->get();
        if ($rows->isEmpty()) {
            throw ValidationException::withMessages(['access' => "Nobody has access to {$machine->name} through ServerOS."]);
        }

        foreach ($rows as $access) {
            $this->takeOff($access, $user);
        }
        Activity::record($workspace, 'machine.ssh_access.revoke_all', $machine->name, $machine, meta: ['count' => $rows->count()]);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Taking everyone’s access to {$machine->name} away."]);

        return back();
    }

    /** @throws ValidationException */
    private function takeOff(MachineSshAccess $access, User $user): void
    {
        if ($access->state !== MachineSshAccess::ACTIVE) {
            $access->forceFill(['state' => MachineSshAccess::REMOVED, 'removed_by_id' => $user->id, 'removed_at' => now()])->save();

            return;
        }

        try {
            $this->ssh->remove($access, $user);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['access' => $e->getMessage()]);
        }
        $access->forceFill(['removed_by_id' => $user->id])->save();
    }

    private function sendOrFail(MachineSshAccess $access, User $actor): void
    {
        try {
            $this->ssh->add($access, $actor);
        } catch (RuntimeException $e) {
            $access->forceFill(['state' => MachineSshAccess::FAILED, 'error' => $e->getMessage()])->save();
        }
    }

    /**
     * @param  array{type: string, body: string, comment: string|null, fingerprint: string}|null  $key
     * @param  array<string, mixed>  $columns
     */
    private function create(Workspace $workspace, Machine $machine, ?User $user, string $email, ?string $name, string $login, ?array $key, array $columns): MachineSshAccess
    {
        $access = new MachineSshAccess;
        $access->forceFill([
            'workspace_id' => $workspace->id,
            'machine_id' => $machine->id,
            'user_id' => $user?->id,
            'email' => $email,
            'name' => $name !== null && trim($name) !== '' ? trim($name) : null,
            'external' => $user === null,
            'login' => $login,
            ...($key === null ? [] : self::keyColumns($key, $email, $login)),
            ...$columns,
        ])->save();
        $access->setRelation('machine', $machine);
        $access->setRelation('workspace', $workspace);
        $access->setRelation('user', $user);

        return $access;
    }

    /**
     * The key as it goes into authorized_keys: its own comment, or the
     * person's email so it can be told apart on the machine.
     *
     * @param  array{type: string, body: string, comment: string|null, fingerprint: string}  $key
     * @return array<string, string|null>
     */
    private static function keyColumns(array $key, string $email, string $login): array
    {
        return [
            'public_key' => "{$key['type']} {$key['body']} ".($key['comment'] ?? $email),
            'key_type' => $key['type'],
            'fingerprint' => $key['fingerprint'],
            'key_comment' => $key['comment'],
            'login' => $login,
        ];
    }

    private function guard(Workspace $workspace, Machine $machine, MachineSshAccess $access): void
    {
        abort_unless($machine->workspace_id === $workspace->id && $access->machine_id === $machine->id && $access->workspace_id === $workspace->id, 404);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
