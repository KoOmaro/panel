<?php

namespace App\Support\Integrations\Storage;

use App\Models\Integration;
use App\Models\IntegrationBucket;
use App\Support\Integrations\IntegrationFailed;

/**
 * A storage provider ServerOS sets backups up in: it makes a private bucket,
 * a key that can only write there and the lifecycle rules, and reads back
 * how much is stored. BackupStorage does the rest. See docs/integrations.md.
 */
interface StoresBackups
{
    /**
     * @return list<string> the buckets the account already has
     *
     * @throws IntegrationFailed
     */
    public function bucketNames(Integration $integration, NewBucket $bucket): array;

    /**
     * Makes a private bucket with the lifecycle rules for $bucket->keepDays.
     * A name someone else has throws IntegrationFailed with reason CONFLICT.
     *
     * @throws IntegrationFailed
     */
    public function createBucket(Integration $integration, NewBucket $bucket): MadeBucket;

    /**
     * Anything the bucket needs once it exists (R2's lifecycle rules). Safe to run again.
     *
     * @throws IntegrationFailed
     */
    public function prepare(Integration $integration, IntegrationBucket $bucket): void;

    /**
     * A key that can only write to the bucket, or null when this account
     * can't make one (then someone pastes one, and keysHelp() says how).
     *
     * @throws IntegrationFailed
     */
    public function createKey(Integration $integration, IntegrationBucket $bucket): ?BucketKey;

    /** @throws IntegrationFailed */
    public function deleteKey(Integration $integration, IntegrationBucket $bucket): void;

    /** @throws IntegrationFailed */
    public function setRetention(Integration $integration, IntegrationBucket $bucket): void;

    /**
     * @return array{bytes: int, files: int, more: bool}
     *
     * @throws IntegrationFailed
     */
    public function bucketUsage(Integration $integration, IntegrationBucket $bucket): array;

    /** @return array{perGbMonth: float, label: string} the provider's published storage price, in US dollars */
    public function price(): array;

    /** @return list<array{key: string, label: string, help: string|null, options: list<array{value: string, label: string}>}> choices for a new bucket beyond the shared ones */
    public function bucketOptions(): array;

    /** What to do when the account can't make a key itself, or null when it always can. */
    public function keysHelp(Integration $integration, IntegrationBucket $bucket): ?string;

    public function pathStyle(): bool;

    /** What the provider's own rules add to how long backups stay, for the bucket dialog. */
    public function keepNote(): ?string;

    /** @return list<string> what this account can't do for its buckets, for the Buckets tray */
    public function notes(Integration $integration): array;
}
