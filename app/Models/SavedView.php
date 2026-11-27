<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $workspace_id
 * @property int $user_id
 * @property string $page
 * @property string $name
 * @property array<string, mixed> $filters
 * @property int $position
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Workspace $workspace
 * @property-read User $user
 */
class SavedView extends Model
{
    public const int MAX_PER_PAGE = 20;

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'position' => 'integer',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @param  Builder<SavedView>  $query */
    public function scopeOwnedBy(Builder $query, Workspace $workspace, User $user, string $page): void
    {
        $query->where('workspace_id', $workspace->id)
            ->where('user_id', $user->id)
            ->where('page', $page);
    }
}
