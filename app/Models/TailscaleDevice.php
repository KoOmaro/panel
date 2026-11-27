<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A device on a connected tailnet as last seen, and the machine it is.
 *
 * @property int $id
 * @property int $integration_id
 * @property int|null $machine_id
 * @property string $device_id the device's nodeId (its legacy id when there's none)
 * @property string $name the MagicDNS name
 * @property string $hostname
 * @property list<string>|null $addresses
 * @property list<string>|null $tags
 * @property string|null $os
 * @property string|null $client_version
 * @property bool $authorized
 * @property bool $external
 * @property bool|null $connected whether Tailscale says it's connected to its control server
 * @property list<string>|null $endpoints
 * @property string|null $linked_by joined, address or hostname
 * @property CarbonImmutable|null $created_at_tailnet
 * @property CarbonImmutable|null $last_seen_at
 * @property CarbonImmutable|null $synced_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Integration $integration
 * @property-read Machine|null $machine
 */
class TailscaleDevice extends Model
{
    public const string JOINED = 'joined';

    public const string ADDRESS = 'address';

    public const string HOSTNAME = 'hostname';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'addresses' => 'array',
            'tags' => 'array',
            'endpoints' => 'array',
            'authorized' => 'boolean',
            'external' => 'boolean',
            'connected' => 'boolean',
            'created_at_tailnet' => 'datetime',
            'last_seen_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    /** The device's Tailscale IPv4 address, or its first address. */
    public function address(): ?string
    {
        $addresses = $this->addresses ?? [];
        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                return $address;
            }
        }

        return $addresses[0] ?? null;
    }

    public function online(): bool
    {
        return $this->connected ?? ($this->last_seen_at !== null && $this->last_seen_at->gt(now()->subMinutes(5)));
    }

    /** @return BelongsTo<Integration, $this> */
    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    /** @return BelongsTo<Machine, $this> */
    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }
}
