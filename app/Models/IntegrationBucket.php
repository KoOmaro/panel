<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A bucket ServerOS made in a connected storage account for backups, with
 * the key it made that can only write there. The bucket and its files stay
 * at the provider whatever happens here; only the key ServerOS made is ever
 * deleted, and only when its destination goes or someone asks.
 *
 * @property int $id
 * @property int $integration_id
 * @property int|null $backup_destination_id
 * @property string $bucket
 * @property string|null $bucket_id the provider's id for it (Backblaze B2)
 * @property string $endpoint the S3 endpoint host
 * @property string|null $region the S3 signing region
 * @property string|null $location where the provider keeps it, as it says
 * @property string|null $jurisdiction R2's default or eu
 * @property string|null $key_id the key ServerOS made, never a key someone pasted
 * @property string|null $key_kind how to delete it: b2, account or user
 * @property int|null $keep_days how long the provider keeps each backup, or null for always
 * @property string $state ready, needs_keys or failed
 * @property string|null $error
 * @property array{machines?: list<int>, schedule?: string, name?: string}|null $pending what to do once it's ready
 * @property array{bytes?: int, files?: int, more?: bool}|null $usage
 * @property string|null $usage_error
 * @property CarbonImmutable|null $usage_checked_at
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Integration $integration
 * @property-read BackupDestination|null $destination
 */
class IntegrationBucket extends Model
{
    public const string READY = 'ready';

    public const string NEEDS_KEYS = 'needs_keys';

    public const string FAILED = 'failed';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'keep_days' => 'integer',
            'pending' => 'array',
            'usage' => 'array',
            'usage_checked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Integration, $this> */
    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    /** @return BelongsTo<BackupDestination, $this> */
    public function destination(): BelongsTo
    {
        return $this->belongsTo(BackupDestination::class, 'backup_destination_id');
    }
}
