<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $machine_id
 * @property string $state writing or ready
 * @property string|null $facts_hash
 * @property array{intro?: string, wiring?: string, services?: array<string, string>}|null $sections
 * @property string|null $source ai or panel
 * @property string|null $model
 * @property string|null $reason why the panel wrote it: no_model, quota, error or rejected
 * @property CarbonImmutable|null $requested_at
 * @property CarbonImmutable|null $generated_at
 * @property array<string, string>|null $seen_by when each person first saw it, by user id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Machine $machine
 */
class MachineReadme extends Model
{
    public const string WRITING = 'writing';

    public const string READY = 'ready';

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sections' => 'array',
            'seen_by' => 'array',
            'requested_at' => 'immutable_datetime',
            'generated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Machine, $this> */
    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    public function seenBy(User $user): bool
    {
        return array_key_exists((string) $user->id, $this->seen_by ?? []);
    }
}
