<?php

namespace App\Support\Integrations\R2;

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

/**
 * Cloudflare R2 through the Cloudflare API, with a token that has Account ›
 * Workers R2 Storage › Edit. A connected Cloudflare account's token can be
 * used instead of pasting one. S3 credentials for a bucket are an API token
 * scoped to it: its id is the access key and the SHA-256 of its value the
 * secret (developers.cloudflare.com/r2/api/tokens).
 */
final class R2Provider extends Provider implements HasAccountPage, StoresBackups
{
    /** "Object Read & Write" in the R2 dashboard: read, write and list objects in the buckets it names. */
    public const string KEY_PERMISSION = 'Workers R2 Storage Bucket Item Write';

    public const array LOCATIONS = ['wnam', 'enam', 'weur', 'eeur', 'apac', 'oc'];

    public const array JURISDICTIONS = ['default', 'eu'];

    private const int ABORT_UPLOADS_DAYS = 7;

    private const int ACCOUNTS_TRIED = 10;

    public function key(): string
    {
        return 'r2';
    }

    public function name(): string
    {
        return 'Cloudflare R2';
    }

    public function fields(): array
    {
        return [
            new ConnectField(
                key: 'token',
                label: self::t('API token'),
                help: self::t('In Cloudflare, create a custom token with Account › Workers R2 Storage › Edit. Add Account › Account API Tokens › Edit so ServerOS can make a key for just the backup bucket, and Account › Account Analytics › Read to see how much is stored.'),
                link: 'https://dash.cloudflare.com/profile/api-tokens',
                linkLabel: self::t('Open Cloudflare API tokens'),
                placeholder: self::t('Paste the token'),
                rules: ['required', 'string', 'min:20', 'max:200', 'regex:/^\S+$/'],
            ),
            new ConnectField(
                key: 'account_id',
                label: self::t('Account ID'),
                secret: false,
                help: self::t('Optional when the token reaches one account. It’s on the R2 overview page.'),
                placeholder: self::t('Optional'),
                rules: ['nullable', 'string', 'regex:/^[a-f0-9]{32}$/'],
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
            ? self::t('Makes a private R2 bucket for your backups with a key for just that bucket, deletes old backups when you choose, and shows how much is stored with an estimate of the cost.')
            : parent::describe($capability);
    }

    public function reusesTokenOf(): string
    {
        return 'cloudflare';
    }

    public function reusedCredentials(Integration $other): array
    {
        return ['token' => $other->credential('token')];
    }

    public function verify(array $credentials): ConnectedAccount
    {
        $client = new R2Client($credentials['token'] ?? '');
        $wanted = strtolower(trim($credentials['account_id'] ?? ''));
        $accounts = [];
        try {
            $listed = $client->all('accounts');
        } catch (IntegrationFailed $e) {
            throw $e->reason === IntegrationFailed::PERMISSION
                ? new IntegrationFailed(self::t('Cloudflare turned the token down. Check it’s copied in full and hasn’t been deleted.'), IntegrationFailed::CREDENTIALS, $e->status)
                : $e;
        }
        foreach ($listed as $account) {
            if (is_string($account['id'] ?? null)) {
                $accounts[$account['id']] = is_string($account['name'] ?? null) ? $account['name'] : $account['id'];
            }
        }

        if ($wanted !== '') {
            if ($accounts !== [] && ! isset($accounts[$wanted])) {
                throw new IntegrationFailed(self::t('This token can’t reach that account. Check the account ID.'), IntegrationFailed::NOT_FOUND, field: 'account_id');
            }
            $client->get("accounts/{$wanted}/r2/buckets", ['per_page' => 1]);
            $id = $wanted;
        } else {
            $id = $this->pickAccount($client, $accounts);
        }

        $settled = $wanted === '' ? ['account_id' => $id] : [];

        return new ConnectedAccount($accounts[$id] ?? 'Cloudflare R2', ['account' => $accounts[$id] ?? null, 'account_id' => $id], $settled);
    }

    /** @param  array<string, string>  $accounts */
    private function pickAccount(R2Client $client, array $accounts): string
    {
        if ($accounts === []) {
            throw new IntegrationFailed(self::t('Fill in the account ID. This token can’t list its accounts.'), IntegrationFailed::INVALID, field: 'account_id');
        }
        $usable = [];
        $last = null;
        foreach (array_slice(array_keys($accounts), 0, self::ACCOUNTS_TRIED) as $id) {
            try {
                $client->get("accounts/{$id}/r2/buckets", ['per_page' => 1]);
                $usable[] = $id;
            } catch (IntegrationFailed $e) {
                if ($e->refusedCredentials()) {
                    throw $e;
                }
                $last = $e;
            }
        }

        return match (true) {
            count($usable) === 1 => $usable[0],
            $usable === [] => throw ($last ?? new IntegrationFailed(self::t('Cloudflare says this token isn’t allowed to do that. Give it Account › Workers R2 Storage › Edit.'), IntegrationFailed::PERMISSION)),
            default => throw new IntegrationFailed(self::t('This token reaches R2 in :accounts. Fill in the account ID to pick one.', ['accounts' => implode(', ', array_map(fn (string $id) => $accounts[$id], $usable))]), IntegrationFailed::INVALID, field: 'account_id'),
        };
    }

    public function summary(Integration $integration): string
    {
        return BackupStorage::summary($integration);
    }

    public function facts(Integration $integration): array
    {
        return array_values(array_filter([
            is_string($integration->meta['account'] ?? null) ? ['label' => self::t('Account'), 'value' => $integration->meta['account']] : null,
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
        $names = [];
        $account = self::account($integration);
        $body = $this->client($integration)->inJurisdiction('GET', "accounts/{$account}/r2/buckets", $bucket->jurisdiction, query: ['name_contains' => $bucket->name, 'per_page' => 1000]);
        foreach ((array) ($body['result']['buckets'] ?? []) as $found) {
            if (is_array($found) && is_string($found['name'] ?? null)) {
                $names[] = $found['name'];
            }
        }

        return $names;
    }

    public function createBucket(Integration $integration, NewBucket $bucket): MadeBucket
    {
        $account = self::account($integration);
        $made = (array) ($this->client($integration)->inJurisdiction('POST', "accounts/{$account}/r2/buckets", $bucket->jurisdiction, array_filter([
            'name' => $bucket->name,
            'locationHint' => $bucket->location,
            'storageClass' => 'Standard',
        ]))['result'] ?? []);
        $jurisdiction = is_string($made['jurisdiction'] ?? null) ? $made['jurisdiction'] : $bucket->jurisdiction;

        return new MadeBucket(
            name: (string) ($made['name'] ?? $bucket->name),
            endpoint: self::endpoint($account, $jurisdiction),
            region: 'auto',
            location: is_string($made['location'] ?? null) ? $made['location'] : $bucket->location,
            jurisdiction: $jurisdiction,
        );
    }

    public function prepare(Integration $integration, IntegrationBucket $bucket): void
    {
        $this->setRetention($integration, $bucket);
    }

    public function createKey(Integration $integration, IntegrationBucket $bucket): ?BucketKey
    {
        $client = $this->client($integration);
        $account = self::account($integration);
        $resource = 'com.cloudflare.edge.r2.bucket.'.$account.'_'.($bucket->jurisdiction ?? 'default').'_'.$bucket->bucket;
        $name = mb_substr('ServerOS backups '.$bucket->bucket, 0, 120);

        foreach (['account' => "accounts/{$account}/tokens", 'user' => 'user/tokens'] as $kind => $path) {
            try {
                $group = $this->permissionGroup($client, $path);
                if ($group === null) {
                    continue;
                }
                $token = (array) ($client->post($path, [
                    'name' => $name,
                    'policies' => [['effect' => 'allow', 'resources' => [$resource => '*'], 'permission_groups' => [['id' => $group]]]],
                ])['result'] ?? []);
            } catch (IntegrationFailed $e) {
                if ($e->reason === IntegrationFailed::PERMISSION) {
                    continue;
                }
                throw $e;
            }
            if (! is_string($token['id'] ?? null) || ! is_string($token['value'] ?? null)) {
                throw new IntegrationFailed(self::t('Cloudflare made the token but didn’t send it back. Try again.'), IntegrationFailed::UNAVAILABLE);
            }

            return new BucketKey($token['id'], $token['id'], hash('sha256', $token['value']), $kind);
        }

        return null;
    }

    private function permissionGroup(R2Client $client, string $tokens): ?string
    {
        $groups = (array) ($client->get($tokens.'/permission_groups', ['name' => self::KEY_PERMISSION])['result'] ?? []);
        foreach ($groups as $group) {
            if (is_array($group) && ($group['name'] ?? null) === self::KEY_PERMISSION && is_string($group['id'] ?? null)) {
                return $group['id'];
            }
        }

        return null;
    }

    public function deleteKey(Integration $integration, IntegrationBucket $bucket): void
    {
        $path = $bucket->key_kind === 'user'
            ? 'user/tokens/'.$bucket->key_id
            : 'accounts/'.self::account($integration).'/tokens/'.$bucket->key_id;
        try {
            $this->client($integration)->delete($path);
        } catch (IntegrationFailed $e) {
            if ($e->reason !== IntegrationFailed::NOT_FOUND) {
                throw $e;
            }
        }
    }

    public function setRetention(Integration $integration, IntegrationBucket $bucket): void
    {
        $rules = [[
            'id' => 'serveros-abort-uploads',
            'enabled' => true,
            'conditions' => ['prefix' => ''],
            'abortMultipartUploadsTransition' => ['condition' => ['type' => 'Age', 'maxAge' => self::ABORT_UPLOADS_DAYS * 86400]],
        ]];
        if ($bucket->keep_days !== null) {
            $rules[] = [
                'id' => 'serveros-keep',
                'enabled' => true,
                'conditions' => ['prefix' => ''],
                'deleteObjectsTransition' => ['condition' => ['type' => 'Age', 'maxAge' => $bucket->keep_days * 86400]],
            ];
        }

        $account = self::account($integration);
        $this->client($integration)->inJurisdiction('PUT', "accounts/{$account}/r2/buckets/{$bucket->bucket}/lifecycle", $bucket->jurisdiction, ['rules' => $rules]);
    }

    public function bucketUsage(Integration $integration, IntegrationBucket $bucket): array
    {
        $data = $this->client($integration)->graphql(<<<'GRAPHQL'
            query R2Storage($accountTag: string!, $bucketName: string!, $start: Time!, $end: Time!) {
              viewer {
                accounts(filter: { accountTag: $accountTag }) {
                  r2StorageAdaptiveGroups(limit: 1, filter: { bucketName: $bucketName, datetime_geq: $start, datetime_leq: $end }, orderBy: [datetime_DESC]) {
                    max { objectCount payloadSize metadataSize }
                  }
                }
              }
            }
            GRAPHQL, [
            'accountTag' => self::account($integration),
            'bucketName' => in_array($bucket->jurisdiction, [null, 'default'], true) ? $bucket->bucket : $bucket->jurisdiction.'_'.$bucket->bucket,
            'start' => now()->subDays(2)->toIso8601ZuluString(),
            'end' => now()->toIso8601ZuluString(),
        ]);
        $max = (array) ($data['viewer']['accounts'][0]['r2StorageAdaptiveGroups'][0]['max'] ?? []);

        return [
            'bytes' => (int) ($max['payloadSize'] ?? 0) + (int) ($max['metadataSize'] ?? 0),
            'files' => (int) ($max['objectCount'] ?? 0),
            'more' => false,
        ];
    }

    public function price(): array
    {
        $perGb = (float) config('services.r2.price_per_gb_month', 0.015);

        return ['perGbMonth' => $perGb, 'label' => self::t('$:price per GB a month', ['price' => rtrim(rtrim(number_format($perGb, 4), '0'), '.')])];
    }

    public function bucketOptions(): array
    {
        return [
            [
                'key' => 'location',
                'label' => self::t('Location'),
                'help' => self::t('Where R2 keeps the bucket. Automatic picks a place near the first upload.'),
                'options' => [
                    ['value' => '', 'label' => self::t('Automatic')],
                    ['value' => 'weur', 'label' => self::t('Western Europe')],
                    ['value' => 'eeur', 'label' => self::t('Eastern Europe')],
                    ['value' => 'wnam', 'label' => self::t('Western North America')],
                    ['value' => 'enam', 'label' => self::t('Eastern North America')],
                    ['value' => 'apac', 'label' => self::t('Asia-Pacific')],
                    ['value' => 'oc', 'label' => self::t('Oceania')],
                ],
            ],
            [
                'key' => 'jurisdiction',
                'label' => self::t('Jurisdiction'),
                'help' => self::t('An EU bucket keeps its data in the European Union.'),
                'options' => [
                    ['value' => 'default', 'label' => self::t('Anywhere')],
                    ['value' => 'eu', 'label' => self::t('European Union')],
                ],
            ],
        ];
    }

    public function keysHelp(Integration $integration, IntegrationBucket $bucket): string
    {
        return self::t('This token can’t make API tokens, so ServerOS can’t make a key for just :bucket. In Cloudflare, open R2 › Manage API tokens, create an Account API token with Object Read & Write for :bucket, and paste its Access Key ID and Secret Access Key here. Or give this token Account › Account API Tokens › Edit and try again.', ['bucket' => $bucket->bucket]);
    }

    public function pathStyle(): bool
    {
        return true;
    }

    public function keepNote(): ?string
    {
        return null;
    }

    public function notes(Integration $integration): array
    {
        return [];
    }

    public function client(Integration $integration): R2Client
    {
        return new R2Client($integration->credential('token'));
    }

    public static function account(Integration $integration): string
    {
        return $integration->credential('account_id');
    }

    public static function endpoint(string $account, ?string $jurisdiction): string
    {
        return in_array($jurisdiction, [null, '', 'default'], true)
            ? "{$account}.r2.cloudflarestorage.com"
            : "{$account}.{$jurisdiction}.r2.cloudflarestorage.com";
    }
}
