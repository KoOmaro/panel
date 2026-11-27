<?php

namespace App\Support\Integrations\Tailscale;

use App\Models\Integration;
use App\Models\Machine;
use App\Models\TailscaleChange;
use App\Models\TailscaleDevice;
use App\Support\Integrations\IntegrationFailed;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Throwable;

/**
 * A tailnet's devices as last seen, and which of them are the workspace's
 * machines: the device ServerOS put there, then one with the machine's own
 * Tailscale address, then one whose public endpoint is the machine's IP,
 * then one with the machine's hostname. A match has to be the only one.
 */
final class TailscaleDevices
{
    /**
     * Lists the devices, keeps them, drops vanished ones and links new ones.
     *
     * @return Collection<int, TailscaleDevice>
     *
     * @throws IntegrationFailed
     */
    public function sync(Integration $integration): Collection
    {
        $provider = $integration->provider();
        if (! $provider instanceof TailscaleProvider) {
            return collect();
        }
        $devices = $provider->client($integration)->devices();
        $kept = [];

        foreach ($devices as $device) {
            $id = self::text($device['nodeId'] ?? null) ?? self::text($device['id'] ?? null);
            if ($id === null) {
                continue;
            }
            $row = TailscaleDevice::query()->firstOrNew(['integration_id' => $integration->id, 'device_id' => mb_substr($id, 0, 64)]);
            $row->fill([
                'name' => mb_substr(rtrim((string) ($device['name'] ?? ''), '.'), 0, 255),
                'hostname' => mb_substr((string) ($device['hostname'] ?? ''), 0, 255),
                'addresses' => self::strings($device['addresses'] ?? []),
                'tags' => self::strings($device['tags'] ?? []),
                'os' => self::text($device['os'] ?? null, 40),
                'client_version' => self::text($device['clientVersion'] ?? null, 80),
                'authorized' => ($device['authorized'] ?? true) !== false,
                'external' => ($device['isExternal'] ?? false) === true,
                'connected' => is_bool($device['connectedToControl'] ?? null) ? $device['connectedToControl'] : null,
                'endpoints' => array_slice(self::strings($device['clientConnectivity']['endpoints'] ?? []), 0, 20),
                'created_at_tailnet' => self::time($device['created'] ?? null),
                'last_seen_at' => self::time($device['lastSeen'] ?? null),
                'synced_at' => now(),
            ]);
            $row->save();
            $kept[] = $row->id;
        }

        TailscaleDevice::query()->where('integration_id', $integration->id)->whereNotIn('id', $kept)->delete();
        $this->link($integration);
        $integration->forceFill(['meta' => [
            ...($integration->meta ?? []),
            'devices' => count($kept),
            'dns' => TailscaleProvider::dnsName($devices) ?? ($integration->meta['dns'] ?? null),
        ]])->save();

        return $this->rows($integration);
    }

    /** @return Collection<int, TailscaleDevice> */
    public function rows(Integration $integration): Collection
    {
        return TailscaleDevice::query()->where('integration_id', $integration->id)
            ->with('machine:id,workspace_id,name,provider,icon_path')
            ->orderByRaw('machine_id is null')->orderBy('hostname')->get();
    }

    public function link(Integration $integration): void
    {
        $rows = TailscaleDevice::query()->where('integration_id', $integration->id)->get();
        $machines = $integration->workspace->machines()->get(['id', 'workspace_id', 'name', 'ip_address', 'facts']);

        foreach ($rows as $row) {
            if ($row->machine_id !== null && ! $machines->contains('id', $row->machine_id)) {
                $row->forceFill(['machine_id' => null, 'linked_by' => null])->save();
            }
        }

        $taken = $rows->pluck('machine_id')->filter()->all();
        $free = $machines->reject(fn (Machine $machine) => in_array($machine->id, $taken, true))->values();

        foreach ($rows->whereNull('machine_id') as $row) {
            if ($row->external) {
                continue;
            }
            foreach ([TailscaleDevice::ADDRESS => [self::byAddress(...), self::byEndpoint(...)], TailscaleDevice::HOSTNAME => [self::byHostname(...)]] as $how => $tests) {
                $match = null;
                foreach ($tests as $test) {
                    $found = $free->filter(fn (Machine $machine) => $test($row, $machine))->values();
                    if ($found->count() === 1) {
                        $match = $found->first();
                        break;
                    }
                }
                if ($match instanceof Machine) {
                    $row->forceFill(['machine_id' => $match->id, 'linked_by' => $how])->save();
                    $free = $free->reject(fn (Machine $machine) => $machine->id === $match->id)->values();
                    break;
                }
            }
        }
    }

    /** The machine's device on any of the workspace's tailnets. */
    public static function of(Machine $machine): ?TailscaleDevice
    {
        return TailscaleDevice::query()->where('machine_id', $machine->id)
            ->whereHas('integration', fn ($query) => $query->where('workspace_id', $machine->workspace_id)->where('provider', 'tailscale'))
            ->with('integration')->latest('id')->first();
    }

    /** @return array<string, mixed> */
    public static function deviceProps(TailscaleDevice $device): array
    {
        return [
            'id' => $device->id,
            'deviceId' => $device->device_id,
            'name' => $device->name,
            'hostname' => $device->hostname,
            'address' => $device->address(),
            'addresses' => $device->addresses ?? [],
            'tags' => $device->tags ?? [],
            'os' => $device->os,
            'version' => $device->client_version,
            'online' => $device->online(),
            'authorized' => $device->authorized,
            'external' => $device->external,
            'lastSeen' => $device->last_seen_at?->toIso8601String(),
            'linkedBy' => $device->linked_by,
        ];
    }

    /**
     * What a machine's page shows: its device, the accounts it could join, and what's changing.
     *
     * @return array<string, mixed>|null
     */
    public static function machineProps(Machine $machine): ?array
    {
        $accounts = $machine->workspace->integrations()->where('provider', 'tailscale')->orderBy('created_at')->orderBy('id')->get();
        $device = self::of($machine);
        if ($accounts->isEmpty() && $device === null) {
            return null;
        }
        TailnetChanges::expireLost();
        $change = TailscaleChange::query()->where('machine_id', $machine->id)
            ->whereIn('integration_id', $accounts->pluck('id'))
            ->where(fn ($query) => $query->where('state', TailscaleChange::RUNNING)->orWhere('updated_at', '>', now()->subMinutes(30)))
            ->latest('id')->first();

        return [
            'accounts' => array_values($accounts->map(fn (Integration $integration) => [
                'id' => $integration->id,
                'label' => $integration->label,
                'tags' => TailscaleProvider::tags($integration),
                'failing' => $integration->status === Integration::FAILING,
            ])->all()),
            'device' => $device === null ? null : [
                ...self::deviceProps($device),
                'integration' => ['id' => $device->integration->id, 'label' => $device->integration->label],
            ],
            'change' => $change === null ? null : TailnetChanges::props($change),
            'blocked' => TailnetChanges::blocker($machine),
            'installed' => TailscaleInstall::container($machine) !== null ? 'serveros' : (TailscaleInstall::native($machine) ? 'native' : null),
            'hostname' => TailscaleInstall::hostname($machine),
        ];
    }

    /**
     * The account page's Devices tray: every device, which are machines, and
     * the machines that could still join.
     *
     * @return array<string, mixed>
     */
    public function accountProps(Integration $integration): array
    {
        $error = null;
        try {
            $rows = $this->sync($integration);
        } catch (IntegrationFailed $e) {
            $error = $e->getMessage();
            $rows = $this->rows($integration);
        } catch (Throwable $e) {
            report($e);
            $error = __('The device list couldn’t be loaded. Try again in a minute.');
            $rows = $this->rows($integration);
        }
        TailnetChanges::expireLost();
        $changes = TailscaleChange::query()->where('integration_id', $integration->id)
            ->where(fn ($query) => $query->where('state', TailscaleChange::RUNNING)->orWhere('updated_at', '>', now()->subMinutes(10)))
            ->with('machine:id,name')->latest('id')->get()->unique('machine_id');
        $on = [...$rows->pluck('machine_id')->filter()->all(), ...$changes->filter(fn (TailscaleChange $change) => $change->active())->pluck('machine_id')->all()];
        $machines = $integration->workspace->machines()->orderBy('name')->get();

        return [
            'tailnet' => (string) ($integration->meta['dns'] ?? $integration->meta['tailnet'] ?? ''),
            'tags' => TailscaleProvider::tags($integration),
            'devices' => array_values($rows->map(fn (TailscaleDevice $row) => [
                ...self::deviceProps($row),
                'machine' => $row->machine === null ? null : [
                    'id' => $row->machine->id,
                    'name' => $row->machine->name,
                    'href' => '/machines/'.$row->machine->id,
                ],
            ])->all()),
            'machines' => array_values($machines->reject(fn (Machine $machine) => in_array($machine->id, $on, true))->map(fn (Machine $machine) => [
                'id' => $machine->id,
                'name' => $machine->name,
                'provider' => $machine->provider,
                'blocked' => TailnetChanges::blocker($machine),
                'installed' => TailscaleInstall::native($machine) ? 'native' : null,
            ])->all()),
            'changes' => array_values($changes->map(fn (TailscaleChange $change) => [
                ...TailnetChanges::props($change),
                'machine' => ['id' => $change->machine->id, 'name' => $change->machine->name],
            ])->all()),
            'error' => $error,
        ];
    }

    private static function byAddress(TailscaleDevice $row, Machine $machine): bool
    {
        $own = [];
        foreach ($machine->facts['interfaces'] ?? [] as $interface) {
            foreach ((array) ($interface['addresses'] ?? []) as $address) {
                $own[] = strtolower(explode('/', (string) $address)[0]);
            }
        }

        return array_intersect(array_map('strtolower', $row->addresses ?? []), $own) !== [];
    }

    private static function byEndpoint(TailscaleDevice $row, Machine $machine): bool
    {
        if ($machine->ip_address === '') {
            return false;
        }
        foreach ($row->endpoints ?? [] as $endpoint) {
            $host = preg_match('/^\[(.+)\]:\d+$/', $endpoint, $m) === 1 ? $m[1] : (string) preg_replace('/:\d+$/', '', $endpoint);
            if (strcasecmp($host, $machine->ip_address) === 0) {
                return true;
            }
        }

        return false;
    }

    private static function byHostname(TailscaleDevice $row, Machine $machine): bool
    {
        $name = strtolower($row->hostname);
        if ($name === '') {
            return false;
        }
        $hostname = strtolower((string) ($machine->facts['hostname'] ?? ''));

        return $name === $hostname || $name === strtolower($machine->name) || $name === TailscaleInstall::hostname($machine);
    }

    private static function text(mixed $value, int $max = 255): ?string
    {
        return is_string($value) && $value !== '' ? mb_substr($value, 0, $max) : null;
    }

    /** @return list<string> */
    private static function strings(mixed $values): array
    {
        return is_array($values) ? array_values(array_filter($values, fn ($value) => is_string($value) && $value !== '')) : [];
    }

    private static function time(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '' || str_starts_with($value, '0001-')) {
            return null;
        }
        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
