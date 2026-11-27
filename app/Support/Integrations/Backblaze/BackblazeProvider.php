<?php

namespace App\Support\Integrations\Backblaze;

use App\Models\Integration;
use App\Models\IntegrationBucket;
use App\Support\Integrations\Capability;
use App\Support\Integrations\ConnectedAccount;
use App\Support\Integrations\ConnectField;
use App\Support\Integrations\HasAccountPage;
use App\Support\Integrations\IntegrationFailed;
use App\Support\Integrations\Provider;
use App\Support\Integrations\Storage\BackupStorage;
use App\Support\Integrations\Storage\BucketKey;
use App\Support\Integrations\Storage\MadeBucket;
use App\Support\Integrations\Storage\NewBucket;
use App\Support\Integrations\Storage\StoresBackups;

final class BackblazeProvider extends Provider implements HasAccountPage, StoresBackups
{
    /** What the account key needs: to find a free name, make the bucket and make its key. */
    public const array REQUIRED = ['listBuckets', 'writeBuckets', 'writeKeys'];

    /** What the account key should have: to delete the keys ServerOS made, and to read how much is stored. */
    public const array OPTIONAL = ['deleteKeys', 'listFiles'];

    /** The daemon only uploads (S3 PutObject), so the bucket's key can only write files. */
    public const array KEY_CAPABILITIES = ['writeFiles'];

    private const int USAGE_PAGES = 20;

    public function key(): string
    {
        return 'backblaze';
    }

    public function name(): string
    {
        return 'Backblaze B2';
    }

    public function fields(): array
    {
        return [
            new ConnectField(
                key: 'key_id',
                label: self::t('Key ID'),
                secret: false,
                help: null,
                link: 'https://secure.backblaze.com/app_keys.htm',
                linkLabel: self::t('Open Backblaze application keys'),
                placeholder: self::t('Paste the key ID'),
                rules: ['required', 'string', 'min:12', 'max:64', 'regex:/^[A-Za-z0-9]+$/'],
            ),
            new ConnectField(
                key: 'application_key',
                label: self::t('Application key'),
                help: self::t('In Backblaze, open Application Keys and use the master key, or add a key with access to all buckets and Read and Write. ServerOS makes a separate key for each bucket it sets up, which can only write to that bucket.'),
                placeholder: self::t('Paste the application key'),
                rules: ['required', 'string', 'min:20', 'max:100', 'regex:/^\S+$/'],
            ),
        ];
    }

    public function capabilities(): array
    {
        return [Capability::BackupStorage];
    }

    public function describe(Capability $capability): string
    {
        return $capability === Capability::BackupStorage
            ? self::t('Makes a private bucket for your backups with a key that can only write there, keeps only the latest version of each file, and shows how much is stored with an estimate of the cost.')
            : parent::describe($capability);
    }

    public function verify(array $credentials): ConnectedAccount
    {
        $client = new B2Client($credentials['key_id'] ?? '', $credentials['application_key'] ?? '');
        $auth = $client->authorize();

        if ($auth['buckets'] !== [] || $auth['namePrefix'] !== null) {
            throw new IntegrationFailed(self::t('This key only reaches :buckets. Use the master key or a key for all buckets, so ServerOS can make a bucket for backups.', ['buckets' => implode(', ', $auth['buckets']) ?: self::t('part of a bucket')]), IntegrationFailed::PERMISSION, field: 'application_key');
        }
        $missing = array_values(array_diff(self::REQUIRED, $auth['capabilities']));
        if ($missing !== []) {
            throw new IntegrationFailed(self::t('This key can’t :what. Use the master key, or a key with Read and Write for all buckets.', ['what' => implode(', ', $missing)]), IntegrationFailed::PERMISSION, field: 'application_key');
        }

        return new ConnectedAccount('Backblaze B2', [
            'account_id' => $auth['accountId'],
            'region' => $client->region(),
            'missing' => implode(',', array_diff(self::OPTIONAL, $auth['capabilities'])),
        ]);
    }

    public function multipleAccounts(): bool
    {
        return true;
    }

    public function summary(Integration $integration): string
    {
        return BackupStorage::summary($integration);
    }

    public function facts(Integration $integration): array
    {
        return array_values(array_filter([
            is_string($integration->meta['region'] ?? null) && $integration->meta['region'] !== '' ? ['label' => self::t('Region'), 'value' => $integration->meta['region']] : null,
            ['label' => self::t('Buckets ServerOS made'), 'value' => (string) IntegrationBucket::query()->where('integration_id', $integration->id)->count()],
        ]));
    }

    public function usage(Integration $integration): array
    {
        return BackupStorage::usageLines($integration);
    }

    public function dependents(Integration $integration): array
    {
        return BackupStorage::dependents($integration);
    }

    public function accountPage(Integration $integration): array
    {
        return app(BackupStorage::class)->props($integration, $this);
    }

    public function disconnectChoices(Integration $integration): array
    {
        return BackupStorage::disconnectChoices($integration, $this);
    }

    public function disconnectWith(Integration $integration, array $chosen): ?string
    {
        return app(BackupStorage::class)->disconnect($integration, $this, $chosen);
    }

    public function bucketNames(Integration $integration, NewBucket $bucket): array
    {
        $client = $this->client($integration);
        $names = [];
        foreach ((array) ($client->read('b2_list_buckets', ['accountId' => $client->accountId()])['buckets'] ?? []) as $found) {
            if (is_array($found) && is_string($found['bucketName'] ?? null)) {
                $names[] = $found['bucketName'];
            }
        }

        return $names;
    }

    public function createBucket(Integration $integration, NewBucket $bucket): MadeBucket
    {
        $client = $this->client($integration);
        $made = $client->call('b2_create_bucket', [
            'accountId' => $client->accountId(),
            'bucketName' => $bucket->name,
            'bucketType' => 'allPrivate',
            'bucketInfo' => ['managed-by' => 'ServerOS'],
            'lifecycleRules' => self::lifecycle($bucket->keepDays),
        ]);
        $region = $client->region();

        return new MadeBucket(
            name: (string) ($made['bucketName'] ?? $bucket->name),
            endpoint: $client->s3Host(),
            region: $region,
            id: is_scalar($made['bucketId'] ?? null) ? (string) $made['bucketId'] : null,
            location: $region,
        );
    }

    public function prepare(Integration $integration, IntegrationBucket $bucket): void {}

    public function createKey(Integration $integration, IntegrationBucket $bucket): BucketKey
    {
        $client = $this->client($integration);
        $key = $client->call('b2_create_key', [
            'accountId' => $client->accountId(),
            'capabilities' => self::KEY_CAPABILITIES,
            'keyName' => substr('serveros-'.$bucket->bucket, 0, 100),
            'bucketIds' => [(string) $bucket->bucket_id],
        ]);
        if (! is_string($key['applicationKeyId'] ?? null) || ! is_string($key['applicationKey'] ?? null)) {
            throw new IntegrationFailed(self::t('Backblaze made the key but didn’t send it back. Try again.'), IntegrationFailed::UNAVAILABLE);
        }

        return new BucketKey($key['applicationKeyId'], $key['applicationKeyId'], $key['applicationKey'], 'b2');
    }

    public function deleteKey(Integration $integration, IntegrationBucket $bucket): void
    {
        try {
            $this->client($integration)->call('b2_delete_key', ['applicationKeyId' => (string) $bucket->key_id]);
        } catch (IntegrationFailed $e) {
            if (! in_array($e->status, [400, 404], true) || $e->reason === IntegrationFailed::PERMISSION) {
                throw $e;
            }
        }
    }

    public function setRetention(Integration $integration, IntegrationBucket $bucket): void
    {
        $client = $this->client($integration);
        $client->call('b2_update_bucket', [
            'accountId' => $client->accountId(),
            'bucketId' => (string) $bucket->bucket_id,
            'lifecycleRules' => self::lifecycle($bucket->keep_days),
        ]);
    }

    public function bucketUsage(Integration $integration, IntegrationBucket $bucket): array
    {
        $client = $this->client($integration);
        $bytes = 0;
        $files = 0;
        $start = null;

        for ($page = 0; $page < self::USAGE_PAGES; $page++) {
            $body = $client->read('b2_list_file_versions', array_filter([
                'bucketId' => (string) $bucket->bucket_id,
                'maxFileCount' => 1000,
                'startFileName' => $start['name'] ?? null,
                'startFileId' => $start['id'] ?? null,
            ], fn ($v) => $v !== null));
            foreach ((array) ($body['files'] ?? []) as $file) {
                if (is_array($file) && ($file['action'] ?? null) === 'upload') {
                    $bytes += (int) ($file['contentLength'] ?? 0);
                    $files++;
                }
            }
            $start = is_string($body['nextFileName'] ?? null) ? ['name' => $body['nextFileName'], 'id' => is_string($body['nextFileId'] ?? null) ? $body['nextFileId'] : null] : null;
            if ($start === null) {
                return ['bytes' => $bytes, 'files' => $files, 'more' => false];
            }
        }

        return ['bytes' => $bytes, 'files' => $files, 'more' => true];
    }

    public function price(): array
    {
        $perTb = (float) config('services.backblaze.price_per_tb_month', 6.95);

        return ['perGbMonth' => $perTb / 1000, 'label' => self::t('$:price per TB a month', ['price' => number_format($perTb, 2)])];
    }

    public function bucketOptions(): array
    {
        return [];
    }

    public function keysHelp(Integration $integration, IntegrationBucket $bucket): ?string
    {
        return null;
    }

    public function pathStyle(): bool
    {
        return false;
    }

    public function keepNote(): string
    {
        return self::t('Backblaze keeps only the latest version of each file: an older one is deleted a day after a newer one replaces it.');
    }

    public function notes(Integration $integration): array
    {
        $missing = array_filter(explode(',', (string) ($integration->meta['missing'] ?? '')));

        return array_values(array_filter([
            in_array('listFiles', $missing, true) ? self::t('This key can’t list files, so ServerOS can’t show how much is stored. Give it listFiles to see it.') : null,
            in_array('deleteKeys', $missing, true) ? self::t('This key can’t delete keys, so the keys ServerOS makes stay at Backblaze when a destination goes. Give it deleteKeys to have them deleted.') : null,
        ]));
    }

    public function client(Integration $integration): B2Client
    {
        return new B2Client($integration->credential('key_id'), $integration->credential('application_key'));
    }

    /**
     * Keeps only the latest version of each file (an older one is hidden and
     * deleted a day later), and with $days hides each file that many days
     * after it was uploaded, so it's gone a day after that.
     *
     * @return list<array{fileNamePrefix: string, daysFromUploadingToHiding: int|null, daysFromHidingToDeleting: int}>
     */
    public static function lifecycle(?int $days): array
    {
        return [['fileNamePrefix' => '', 'daysFromUploadingToHiding' => $days, 'daysFromHidingToDeleting' => 1]];
    }
}
