<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Putting a machine on a tailnet, or taking it off: a one-off key, a job on
 * the machine, then the device found (or deleted) through the API.
 * AdvanceTailnetChange moves it on a step at a time. The key itself is never
 * kept; only its id, so it can be revoked.
 *
 * @property int $id
 * @property int $integration_id
 * @property int $machine_id
 * @property int|null $user_id
 * @property string $kind join or leave
 * @property string $state running, done or failed
 * @property string $step install, connect, logout, remove or delete
 * @property bool $manual the commands were shown to run by hand instead
 * @property string $hostname
 * @property list<string>|null $tags
 * @property string|null $key_id
 * @property CarbonImmutable|null $key_expires_at
 * @property string|null $job_id the daemon job being waited on
 * @property string|null $device_id
 * @property array<string, mixed>|null $result
 * @property string|null $error
 * @property int $checks
 * @property CarbonImmutable|null $step_started_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Integration $integration
 * @property-read Machine $machine
 * @property-read User|null $user
 */
class TailscaleChange extends Model
{
    public const string JOIN = 'join';

    public const string LEAVE = 'leave';

    public const string RUNNING = 'running';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'manual' => 'boolean',
            'tags' => 'array',
            'result' => 'array',
            'key_expires_at' => 'datetime',
            'step_started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function active(): bool
    {
        return $this->state === self::RUNNING;
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

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
