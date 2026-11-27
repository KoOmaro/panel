<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a Slack alert or deploy notice said (its ChatPost keeps where it went
 * and whether it's open), so it can be drawn again when it's fixed or when
 * someone acts on it: the notes say who did what from its buttons.
 *
 * @property int $id
 * @property int $chat_post_id
 * @property int $integration_id
 * @property string $channel
 * @property string $ts
 * @property string $kind alert or deploy
 * @property array<string, mixed> $payload what it says and what its buttons act on, never secrets
 * @property list<array{text: string, at: string}>|null $notes
 * @property string|null $job_id the job started from it that it waits on
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read ChatPost $post
 * @property-read Integration $integration
 */
class SlackMessage extends Model
{
    public const string ALERT = 'alert';

    public const string DEPLOY = 'deploy';

    public const int NOTES = 5;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'notes' => 'array',
        ];
    }

    public function input(string $key): ?string
    {
        $value = $this->payload[$key] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }

    public function note(string $text): void
    {
        $notes = [...($this->notes ?? []), ['text' => mb_substr($text, 0, 300), 'at' => now()->toIso8601String()]];
        $this->notes = array_slice($notes, -self::NOTES);
    }

    /** @return BelongsTo<ChatPost, $this> */
    public function post(): BelongsTo
    {
        return $this->belongsTo(ChatPost::class, 'chat_post_id');
    }

    /** @return BelongsTo<Integration, $this> */
    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }
}
