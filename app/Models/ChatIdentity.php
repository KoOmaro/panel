<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Telegram or Discord account that a workspace member linked to
 * themselves, so what they press in the chat runs as them.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $user_id
 * @property string $chat telegram or discord
 * @property string $external_id the chat's id for the person
 * @property string|null $external_name their name there, as it was when they linked
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Workspace $workspace
 * @property-read User $user
 */
class ChatIdentity extends Model
{
    protected $guarded = [];

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
}
