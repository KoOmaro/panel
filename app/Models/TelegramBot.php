<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The workspace's own Telegram bot, as ServerOS set it up to answer: the
 * webhook's address (by hook) and the secret Telegram sends back with every
 * update.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $hook
 * @property string $secret
 * @property string $token_hash a keyed hash of the token it was set up with, to tell when it changed
 * @property string|null $username the bot's @name, without the @
 * @property string $state on, failed or unreachable
 * @property string|null $error
 * @property int|null $last_update_id
 * @property CarbonImmutable|null $registered_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Workspace $workspace
 */
#[Hidden(['secret', 'token_hash'])]
class TelegramBot extends Model
{
    public const string ON = 'on';

    public const string FAILED = 'failed';

    public const string UNREACHABLE = 'unreachable';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'last_update_id' => 'integer',
            'registered_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function answers(): bool
    {
        return $this->state === self::ON;
    }
}
