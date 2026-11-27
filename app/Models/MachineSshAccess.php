<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $workspace_id
 * @property int $machine_id
 * @property int|null $user_id
 * @property string|null $email
 * @property string|null $name
 * @property bool $external
 * @property string $login
 * @property string|null $public_key
 * @property string|null $key_type
 * @property string|null $fingerprint
 * @property string|null $key_comment
 * @property string $state
 * @property string|null $note
 * @property int|null $decided_by_id
 * @property CarbonImmutable|null $requested_at
 * @property CarbonImmutable|null $decided_at
 * @property CarbonImmutable|null $added_at
 * @property CarbonImmutable|null $expires_at
 * @property int|null $removed_by_id
 * @property CarbonImmutable|null $removed_at
 * @property string|null $job_id
 * @property string|null $error
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Workspace $workspace
 * @property-read Machine $machine
 * @property-read User|null $user
 * @property-read User|null $decidedBy
 * @property-read User|null $removedBy
 */
class MachineSshAccess extends Model
{
    protected $table = 'machine_ssh_access';

    public const REQUESTED = 'requested';

    public const WAITING_KEY = 'waiting_key';

    public const ADDING = 'adding';

    public const ACTIVE = 'active';

    public const FAILED = 'failed';

    public const REMOVING = 'removing';

    public const DECLINED = 'declined';

    public const REMOVED = 'removed';

    /** The rows the Access tab lists: everything that has, or is on its way to having, a key on the machine. */
    public const LISTED = [self::REQUESTED, self::WAITING_KEY, self::ADDING, self::ACTIVE, self::FAILED, self::REMOVING];

    /**
     * The logins ServerOS may manage keys for. The daemon only lets the panel
     * change authorized_keys for users ServerOS manages, and "serveros" is the
     * one meant for people.
     */
    public const LOGINS = ['serveros'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'decided_at' => 'datetime',
            'added_at' => 'datetime',
            'expires_at' => 'datetime',
            'removed_at' => 'datetime',
            'external' => 'boolean',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
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

    /** @return BelongsTo<User, $this> */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_id');
    }

    /** @return BelongsTo<User, $this> */
    public function removedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'removed_by_id');
    }

    /** @param  Builder<self>  $query */
    public function scopeListed(Builder $query): void
    {
        $query->whereIn('state', self::LISTED);
    }

    public function isExternal(): bool
    {
        return $this->external;
    }

    public function personName(): string
    {
        return $this->user->name ?? $this->name ?? $this->email ?? 'Someone';
    }

    public function personEmail(): ?string
    {
        return $this->user->email ?? $this->email;
    }

    public function belongsToPerson(User $user): bool
    {
        return $this->user_id !== null && $this->user_id === $user->id;
    }
}
