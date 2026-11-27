<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An alert ServerOS posted to the workspace's Telegram chat or a Discord
 * channel. Its buttons point at this row, and the alert that says it's
 * fixed edits the message in place.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int|null $integration_id the Discord server it went through; null for the workspace's own Telegram bot
 * @property string $chat telegram or discord
 * @property string $destination the chat or channel it went to
 * @property string|null $message_id the chat's own id for the message
 * @property string|null $thread what it's about, like monitor:12, so the recovery finds it
 * @property string $event
 * @property string $subject
 * @property string $state open, resolved, sent or failed
 * @property array<string, int|string>|null $context the ids the buttons act on
 * @property string|null $error
 * @property CarbonImmutable|null $resolved_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Workspace $workspace
 */
class ChatPost extends Model
{
    use MassPrunable;

    public const string OPEN = 'open';

    public const string RESOLVED = 'resolved';

    public const string SENT = 'sent';

    public const string FAILED = 'failed';

    public const int KEEP_DAYS = 30;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'resolved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(self::KEEP_DAYS));
    }

    public function contextInt(string $key): ?int
    {
        $value = $this->context[$key] ?? null;

        return is_int($value) ? $value : null;
    }

    public function contextString(string $key): ?string
    {
        $value = $this->context[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
