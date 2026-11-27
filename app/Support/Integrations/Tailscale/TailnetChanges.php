<?php

namespace App\Support\Integrations\Tailscale;

use App\Jobs\AdvanceTailnetChange;
use App\Models\DaemonJob;
use App\Models\Integration;
use App\Models\Machine;
use App\Models\TailscaleChange;
use App\Models\TailscaleDevice;
use App\Models\User;
use App\Support\DaemonJobQueue;
use App\Support\Integrations\IntegrationFailed;
use RuntimeException;

/**
 * Putting machines on a tailnet and taking them off. Joining makes a
 * one-off, pre-authorized key, deploys Tailscale with it (TailscaleInstall)
 * and waits for the device to turn up, then links it to the machine and
 * revokes the key. Leaving logs out on the machine, removes the container
 * and deletes the device. When the daemon can't do it, the commands to run
 * by hand are offered instead, and the device is still found and linked.
 */
final class TailnetChanges
{
    private const int WAIT = 5;

    private const int CONNECT_MINUTES = 5;

    private const int LOST_MINUTES = 45;

    private const int MAX_CHECKS = 400;

    /** @var array<string, list<string>> */
    public const array STEPS = [
        TailscaleChange::JOIN => ['install', 'connect'],
        TailscaleChange::LEAVE => ['logout', 'remove', 'delete'],
    ];

    public function __construct(
        private readonly DaemonJobQueue $queue,
        private readonly TailscaleDevices $devices,
    ) {}

    /** Why ServerOS can't install Tailscale on the machine itself right now, or null when it can. */
    public static function blocker(Machine $machine): ?string
    {
        if (TailscaleInstall::native($machine)) {
            return self::t('Tailscale already runs on :name outside ServerOS, so it isn’t installed again. Log it in to this tailnet with the commands instead.', ['name' => $machine->name]);
        }
        $reason = app(DaemonJobQueue::class)->unavailable($machine, 'deploy');

        return $reason === null ? null : self::t($reason);
    }

    public static function active(Machine $machine): ?TailscaleChange
    {
        self::expireLost();

        return TailscaleChange::query()->where('machine_id', $machine->id)->where('state', TailscaleChange::RUNNING)->latest('id')->first();
    }

    public static function expireLost(): void
    {
        TailscaleChange::query()->where('state', TailscaleChange::RUNNING)
            ->where('updated_at', '<', now()->subMinutes(self::LOST_MINUTES))
            ->update([
                'state' => 'failed',
                'error' => self::t('ServerOS lost track of this. Check the machine in the Tailscale admin console.'),
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * @throws TailnetRefused
     */
    public function join(Integration $integration, Machine $machine, User $user): TailscaleChange
    {
        $this->guard($integration, $machine);
        if (($reason = self::blocker($machine)) !== null) {
            throw new TailnetRefused($reason, manual: true);
        }

        $tags = TailscaleProvider::tags($integration);
        $hostname = TailscaleInstall::hostname($machine);
        $key = $this->key($integration, $machine, $tags);

        try {
            // A deploy pulls the image and starts it: a slow mirror can take a few minutes.
            $job = $this->queue->queue($machine, $user, 'deploy', TailscaleInstall::job($hostname, $tags), TailscaleInstall::SERVICE, 600, secretEnv: [TailscaleInstall::KEY_ENV => $key['key']]);
        } catch (RuntimeException $e) {
            $this->revoke($integration, $key['id']);

            throw new TailnetRefused($e->getMessage(), manual: true);
        }

        $change = TailscaleChange::query()->create([
            'integration_id' => $integration->id,
            'machine_id' => $machine->id,
            'user_id' => $user->id,
            'kind' => TailscaleChange::JOIN,
            'state' => TailscaleChange::RUNNING,
            'step' => 'install',
            'hostname' => $hostname,
            'tags' => $tags,
            'key_id' => $key['id'],
            'key_expires_at' => now()->addMinutes(TailscaleInstall::keyMinutes()),
            'job_id' => $job->id,
            'step_started_at' => now(),
        ]);
        AdvanceTailnetChange::dispatch($change->id)->delay(now()->addSeconds(self::WAIT));

        return $change;
    }

    /**
     * A one-off key and the commands to run it with, for when the daemon
     * can't do it. The key is handed back once and never kept; the device
     * is still looked for until the key runs out.
     *
     * @return array{change: TailscaleChange, commands: list<string>}
     *
     * @throws TailnetRefused
     */
    public function commands(Integration $integration, Machine $machine, User $user): array
    {
        $this->guard($integration, $machine);
        $tags = TailscaleProvider::tags($integration);
        $hostname = TailscaleInstall::hostname($machine);
        $key = $this->key($integration, $machine, $tags);

        $change = TailscaleChange::query()->create([
            'integration_id' => $integration->id,
            'machine_id' => $machine->id,
            'user_id' => $user->id,
            'kind' => TailscaleChange::JOIN,
            'state' => TailscaleChange::RUNNING,
            'step' => 'connect',
            'manual' => true,
            'hostname' => $hostname,
            'tags' => $tags,
            'key_id' => $key['id'],
            'key_expires_at' => now()->addMinutes(TailscaleInstall::keyMinutes()),
            'step_started_at' => now(),
        ]);
        AdvanceTailnetChange::dispatch($change->id)->delay(now()->addSeconds(self::WAIT * 2));

        return [
            'change' => $change,
            'commands' => TailscaleInstall::joinCommands($key['key'], $hostname, $tags, TailscaleInstall::native($machine) || TailscaleInstall::container($machine) !== null),
        ];
    }

    /**
     * Takes the machine off the tailnet: logs out and removes Tailscale on
     * the machine when ServerOS runs it there, then deletes the device.
     * When the daemon can't, the device is deleted now and the commands to
     * clean up the machine come back.
     *
     * @return array{change: TailscaleChange, commands: list<string>|null}
     *
     * @throws TailnetRefused
     */
    public function leave(TailscaleDevice $device, User $user): array
    {
        $machine = $device->machine ?? throw new TailnetRefused(self::t('This device isn’t one of your machines.'));
        $integration = $device->integration;
        if (self::active($machine) !== null) {
            throw new TailnetRefused(self::t(':name is already being changed. Wait for that to finish.', ['name' => $machine->name]));
        }
        $base = [
            'integration_id' => $integration->id,
            'machine_id' => $machine->id,
            'user_id' => $user->id,
            'kind' => TailscaleChange::LEAVE,
            'state' => TailscaleChange::RUNNING,
            'hostname' => mb_substr($device->hostname !== '' ? $device->hostname : TailscaleInstall::hostname($machine), 0, 63),
            'device_id' => $device->device_id,
            'step_started_at' => now(),
        ];

        $container = TailscaleInstall::container($machine);
        if ($container !== null && $this->queue->unavailable($machine, 'service_exec') === null) {
            try {
                $job = $this->queue->queue($machine, $user, 'service_exec', [
                    'type' => 'service_exec',
                    'service' => (string) $container['key'],
                    'command' => 'tailscale logout',
                    'timeout_secs' => 60,
                ], (string) $container['key'], 120);
                $change = TailscaleChange::query()->create([...$base, 'step' => 'logout', 'job_id' => $job->id]);
                AdvanceTailnetChange::dispatch($change->id)->delay(now()->addSeconds(self::WAIT));

                return ['change' => $change, 'commands' => null];
            } catch (RuntimeException) {
            }
        }

        $commands = TailscaleInstall::leaveCommands($machine);
        $change = TailscaleChange::query()->create([...$base, 'step' => 'delete', 'manual' => true]);
        try {
            $this->deleteDevice($change);
        } catch (IntegrationFailed $e) {
            $this->fail($change, $e->getMessage());

            throw new TailnetRefused($e->getMessage());
        }

        return ['change' => $change->refresh(), 'commands' => $commands];
    }

    /** Seconds until the next look, or null when there's nothing more to do. */
    public function advance(TailscaleChange $change): ?int
    {
        if (! $change->active()) {
            return null;
        }
        $integration = $change->integration;
        if (! $integration->provider() instanceof TailscaleProvider) {
            $this->fail($change, self::t('The Tailscale account this belonged to was disconnected.'));

            return null;
        }
        $change->checks++;
        if ($change->checks > self::MAX_CHECKS) {
            $this->fail($change, self::t('It took too long. Check the machine in the Tailscale admin console.'));

            return null;
        }

        try {
            $wait = $change->kind === TailscaleChange::JOIN ? $this->advanceJoin($change) : $this->advanceLeave($change);
        } catch (IntegrationFailed $e) {
            if (in_array($e->reason, [IntegrationFailed::UNAVAILABLE, IntegrationFailed::RATE_LIMITED], true)) {
                $change->save();

                return 30;
            }
            $this->fail($change, $e->getMessage());

            return null;
        }

        if ($change->active()) {
            $change->save();
        }

        return $change->active() ? $wait : null;
    }

    public function giveUp(TailscaleChange $change): void
    {
        $this->fail($change, self::t('Something went wrong on ServerOS’s side. Check the machine in the Tailscale admin console.'));
    }

    private function advanceJoin(TailscaleChange $change): ?int
    {
        if ($change->step === 'install') {
            $job = $change->job_id === null ? null : DaemonJob::query()->find($change->job_id);
            if ($job === null) {
                $this->revoke($change->integration, $change->key_id);
                $this->fail($change, self::t('The job on the machine is gone. Try again.'));

                return null;
            }
            if ($job->isActive() && ! $job->isStale()) {
                return self::WAIT;
            }
            if ($job->state !== 'succeeded') {
                $this->revoke($change->integration, $change->key_id);
                $this->fail($change, self::jobFailure($job, $change->machine), manual: true);

                return null;
            }
            $change->step = 'connect';
            $change->step_started_at = now();
        }

        $rows = $this->devices->sync($change->integration);
        $found = $rows->first(fn (TailscaleDevice $row) => $row->machine_id === $change->machine_id && $row->linked_by === TailscaleDevice::JOINED)
            ?? $rows->filter(fn (TailscaleDevice $row) => strcasecmp($row->hostname, $change->hostname) === 0
                && ($row->machine_id === null || $row->machine_id === $change->machine_id)
                && ($row->created_at_tailnet === null || $row->created_at_tailnet->gte($change->created_at?->subMinutes(2) ?? now()->subHour())))
                ->sortByDesc(fn (TailscaleDevice $row) => $row->created_at_tailnet?->getTimestamp() ?? 0)
                ->first();

        if ($found instanceof TailscaleDevice) {
            TailscaleDevice::query()->where('integration_id', $change->integration_id)->where('machine_id', $change->machine_id)->whereKeyNot($found->id)
                ->update(['machine_id' => null, 'linked_by' => null]);
            $found->forceFill(['machine_id' => $change->machine_id, 'linked_by' => TailscaleDevice::JOINED])->save();
            $this->revoke($change->integration, $change->key_id);
            $this->finish($change, ['name' => $found->name, 'address' => $found->address()], $found->device_id);

            return null;
        }

        $limit = $change->manual && $change->key_expires_at !== null
            ? $change->key_expires_at->addMinutes(2)
            : ($change->step_started_at ?? now())->addMinutes(self::CONNECT_MINUTES);
        if (now()->gt($limit)) {
            $this->revoke($change->integration, $change->key_id);
            $this->fail($change, $change->manual
                ? self::t(':name didn’t turn up on the tailnet before its key ran out. Make new commands and run them again.', ['name' => $change->machine->name])
                : self::t('Tailscale started on :name but it didn’t turn up on the tailnet. The tailscale service’s logs on its page say why.', ['name' => $change->machine->name]), manual: true);

            return null;
        }

        return $change->manual ? self::WAIT * 2 : self::WAIT;
    }

    private function advanceLeave(TailscaleChange $change): ?int
    {
        if (in_array($change->step, ['logout', 'remove'], true)) {
            $job = $change->job_id === null ? null : DaemonJob::query()->find($change->job_id);
            if ($job !== null && $job->isActive() && ! $job->isStale()) {
                return self::WAIT;
            }
            if ($change->step === 'logout') {
                $change->step = 'remove';
                $change->step_started_at = now();
                $change->job_id = null;
                $container = TailscaleInstall::container($change->machine);
                $user = $change->user;
                if ($container !== null && $user !== null) {
                    try {
                        $remove = $this->queue->queue($change->machine, $user, 'service_remove', ['type' => 'service_remove', 'service' => (string) $container['key'], 'delete_data' => true], (string) $container['key']);
                        $change->job_id = $remove->id;

                        return self::WAIT;
                    } catch (RuntimeException $e) {
                        $change->result = [...($change->result ?? []), 'cleanup' => $e->getMessage()];
                    }
                }
            } elseif ($job !== null && $job->state !== 'succeeded') {
                $change->result = [...($change->result ?? []), 'cleanup' => self::jobFailure($job, $change->machine)];
            }
            $change->step = 'delete';
            $change->step_started_at = now();
        }

        $this->deleteDevice($change);

        return null;
    }

    /** @throws IntegrationFailed */
    private function deleteDevice(TailscaleChange $change): void
    {
        $provider = $change->integration->provider();
        if ($change->device_id !== null && $provider instanceof TailscaleProvider) {
            $provider->client($change->integration)->deleteDevice($change->device_id);
            TailscaleDevice::query()->where('integration_id', $change->integration_id)->where('device_id', $change->device_id)->delete();
        }
        $this->finish($change, $change->result ?? []);
    }

    /**
     * @param  list<string>  $tags
     * @return array{id: string, key: string, expires: string|null}
     *
     * @throws TailnetRefused
     */
    private function key(Integration $integration, Machine $machine, array $tags): array
    {
        $provider = $integration->provider();
        if (! $provider instanceof TailscaleProvider) {
            throw new TailnetRefused(self::t('This account isn’t a Tailscale account.'));
        }
        if (($integration->meta['oauth'] ?? false) && $tags === []) {
            throw new TailnetRefused(self::t('Keys made by an OAuth client need a tag. Add one on the Tailscale account’s page.'));
        }

        try {
            return $provider->client($integration)->createKey($tags, TailscaleInstall::keyMinutes() * 60, 'ServerOS '.$machine->name);
        } catch (IntegrationFailed $e) {
            throw new TailnetRefused($e->getMessage());
        }
    }

    /** @throws TailnetRefused */
    private function guard(Integration $integration, Machine $machine): void
    {
        if ($integration->status === Integration::FAILING) {
            throw new TailnetRefused(self::t('Tailscale stopped taking this account’s token. Connect it again first.'));
        }
        $device = TailscaleDevice::query()->where('integration_id', $integration->id)->where('machine_id', $machine->id)->first();
        if ($device !== null) {
            throw new TailnetRefused(self::t(':name is already on this tailnet, as :device.', ['name' => $machine->name, 'device' => $device->name]));
        }
        if (self::active($machine) !== null) {
            throw new TailnetRefused(self::t(':name is already being changed. Wait for that to finish.', ['name' => $machine->name]));
        }
    }

    private function revoke(Integration $integration, ?string $keyId): void
    {
        $provider = $integration->provider();
        if ($keyId === null || ! $provider instanceof TailscaleProvider) {
            return;
        }
        try {
            $provider->client($integration)->deleteKey($keyId);
        } catch (IntegrationFailed) {
            // A one-off key is spent once used, and runs out in minutes anyway.
        }
    }

    public static function jobFailure(DaemonJob $job, Machine $machine): string
    {
        $message = (string) ($job->error['message'] ?? '');
        $lower = strtolower($message);

        return match (true) {
            $job->isStale() => self::t(':name stopped answering while it was working on this.', ['name' => $machine->name]),
            str_contains($lower, 'read-only') || ($job->error['phase'] ?? null) === 'policy' => self::t('The daemon on :name is in read-only mode, so it can’t change the machine.', ['name' => $machine->name]),
            str_contains($lower, 'docker') && (str_contains($lower, 'not found') || str_contains($lower, 'no such file') || str_contains($lower, 'cannot connect')) => self::t('Docker isn’t running on :name, and ServerOS runs Tailscale in a container.', ['name' => $machine->name]),
            $message !== '' => self::t('It didn’t work on :name: :reason', ['name' => $machine->name, 'reason' => rtrim($message, '.').'.']),
            default => self::t('It didn’t work on :name. Its job log says why.', ['name' => $machine->name]),
        };
    }

    /** @param  array<string, mixed>  $result */
    private function finish(TailscaleChange $change, array $result, ?string $deviceId = null): void
    {
        $change->forceFill([
            'state' => 'done',
            'result' => $result === [] ? null : $result,
            'device_id' => $deviceId ?? $change->device_id,
            'error' => null,
            'finished_at' => now(),
        ])->save();
    }

    private function fail(TailscaleChange $change, string $message, bool $manual = false): void
    {
        $change->forceFill([
            'state' => 'failed',
            'error' => mb_substr($message, 0, 1000),
            'result' => [...($change->result ?? []), 'manual' => $manual || $change->manual],
            'finished_at' => now(),
        ])->save();
    }

    /** @return array<string, mixed> */
    public static function props(TailscaleChange $change): array
    {
        $plan = $change->manual && $change->kind === TailscaleChange::JOIN ? ['connect'] : self::STEPS[$change->kind] ?? [];
        $at = array_search($change->step, $plan, true);
        $labels = [
            'install' => self::t('Install Tailscale'),
            'connect' => $change->manual ? self::t('Waiting for the machine to join') : self::t('Join the tailnet'),
            'logout' => self::t('Log out'),
            'remove' => self::t('Remove Tailscale'),
            'delete' => self::t('Delete the device'),
        ];
        $job = $change->active() && $change->job_id !== null ? DaemonJob::query()->find($change->job_id) : null;

        return [
            'id' => $change->id,
            'kind' => $change->kind,
            'state' => $change->state,
            'step' => $change->step,
            'manual' => $change->manual,
            'canRunCommands' => (bool) ($change->result['manual'] ?? false),
            'steps' => array_map(fn (string $step, int $index) => [
                'key' => $step,
                'label' => $labels[$step],
                'state' => match (true) {
                    $change->state === 'done' => 'done',
                    $at === false => 'todo',
                    $index < $at => 'done',
                    $index === $at => $change->state === 'failed' ? 'failed' : 'current',
                    default => 'todo',
                },
            ], $plan, array_keys($plan)),
            'phase' => $job?->phase,
            'jobId' => $change->job_id,
            'hostname' => $change->hostname,
            'error' => $change->error,
            'result' => array_filter([
                'name' => is_string($change->result['name'] ?? null) ? $change->result['name'] : null,
                'address' => is_string($change->result['address'] ?? null) ? $change->result['address'] : null,
                'cleanup' => is_string($change->result['cleanup'] ?? null) ? $change->result['cleanup'] : null,
            ]),
            'keyExpiresAt' => $change->key_expires_at?->toIso8601String(),
            'createdAt' => $change->created_at?->toIso8601String(),
            'finishedAt' => $change->finished_at?->toIso8601String(),
        ];
    }

    /** @param  array<string, string|int>  $replace */
    private static function t(string $text, array $replace = []): string
    {
        return is_string($line = __($text, $replace)) ? $line : $text;
    }
}
