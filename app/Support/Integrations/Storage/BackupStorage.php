<?php

namespace App\Support\Integrations\Storage;

use App\Models\Activity;
use App\Models\BackupDestination;
use App\Models\Integration;
use App\Models\IntegrationBucket;
use App\Models\Machine;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Backups\S3Probe;
use App\Support\Integrations\IntegrationCatalogue;
use App\Support\Integrations\IntegrationFailed;
use App\Support\Integrations\IntegrationProviders;
use App\Support\Integrations\Provider;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Setting backups up in a connected storage account: a private bucket
 * named after the workspace, a key that can only reach it, the lifecycle
 * rules, the backup destination with that key, and the machines that back
 * up there. Buckets and the files in them are never deleted from here.
 *
 * @phpstan-type Choices array{name?: string|null, keep_days?: int|null, location?: string|null, jurisdiction?: string|null, machines?: list<int>, schedule?: string|null}
 */
final class BackupStorage
{
    public const array KEEP_DAYS = [30, 90, 180, 365];

    public const array SCHEDULES = ['daily', 'weekly'];

    private const int USAGE_MINUTES = 60;

    private const int NAME_TRIES = 4;

    public function __construct(private readonly S3Probe $probe) {}

    public static function suggestedName(Workspace $workspace): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($workspace->slug)), '-');
        $slug = trim(substr($slug, 0, 63 - strlen('serveros--backups')), '-');

        return $slug === '' ? 'serveros-backups' : "serveros-{$slug}-backups";
    }

    /**
     * Makes the bucket, then its key and destination, and sends the chosen
     * machines' backups there. A bucket that was made stays recorded even
     * when a later step fails, so trying again carries on from it.
     *
     * @param  Choices  $choices
     * @return array{bucket: IntegrationBucket, warning: string|null}
     *
     * @throws IntegrationFailed
     */
    public function setUp(Integration $integration, Provider&StoresBackups $provider, User $user, array $choices): array
    {
        $plan = new NewBucket(
            name: self::suggestedName($integration->workspace),
            keepDays: $choices['keep_days'] ?? null,
            location: ($choices['location'] ?? '') !== '' ? $choices['location'] : null,
            jurisdiction: ($choices['jurisdiction'] ?? '') !== '' ? (string) $choices['jurisdiction'] : 'default',
            workspace: $integration->workspace->slug,
        );
        $name = self::free($plan->name, $provider->bucketNames($integration, $plan));
        $made = null;

        for ($try = 1; $made === null; $try++) {
            try {
                $made = $provider->createBucket($integration, $plan->named($name));
            } catch (IntegrationFailed $e) {
                if ($e->reason !== IntegrationFailed::CONFLICT || $try >= self::NAME_TRIES) {
                    throw $e;
                }
                $name = substr($plan->name, 0, 57).'-'.Str::lower(Str::random(5));
            }
        }

        $bucket = IntegrationBucket::query()->create([
            'integration_id' => $integration->id,
            'bucket' => $made->name,
            'bucket_id' => $made->id,
            'endpoint' => $made->endpoint,
            'region' => $made->region,
            'location' => $made->location,
            'jurisdiction' => $made->jurisdiction,
            'keep_days' => $plan->keepDays,
            'state' => IntegrationBucket::FAILED,
            'pending' => [
                'machines' => array_map('intval', $choices['machines'] ?? []),
                'schedule' => in_array($choices['schedule'] ?? null, self::SCHEDULES, true) ? $choices['schedule'] : 'daily',
                'name' => trim((string) ($choices['name'] ?? '')),
            ],
            'created_by' => $user->id,
        ]);

        return ['bucket' => $bucket, 'warning' => $this->finish($bucket, $provider, $user)];
    }

    /**
     * Applies what the bucket needs, makes its key and its destination.
     * Returns a warning when the test upload didn't go through yet.
     *
     * @throws IntegrationFailed
     */
    public function finish(IntegrationBucket $bucket, Provider&StoresBackups $provider, User $user): ?string
    {
        $integration = $bucket->integration;

        try {
            $provider->prepare($integration, $bucket);
            if ($bucket->backup_destination_id !== null) {
                $bucket->forceFill(['state' => IntegrationBucket::READY, 'error' => null])->save();

                return null;
            }
            if ($bucket->key_id !== null) {
                $provider->deleteKey($integration, $bucket);
                $bucket->forceFill(['key_id' => null, 'key_kind' => null])->save();
            }
            $key = $provider->createKey($integration, $bucket);
        } catch (IntegrationFailed $e) {
            $bucket->forceFill(['state' => IntegrationBucket::FAILED, 'error' => $e->getMessage()])->save();

            throw $e;
        }

        if ($key === null) {
            $bucket->forceFill(['state' => IntegrationBucket::NEEDS_KEYS, 'error' => null])->save();

            return null;
        }
        $bucket->forceFill(['key_id' => $key->id, 'key_kind' => $key->kind])->save();

        return $this->connect($bucket, $provider, $key->accessKey, $key->secretKey, $user);
    }

    /**
     * Keys someone made by hand, for an account that can't make them. They're
     * checked with a test upload first, and never deleted by ServerOS.
     *
     * @return string|null why they don't work, or null when they're in
     */
    public function addKeys(IntegrationBucket $bucket, Provider&StoresBackups $provider, string $access, string $secret, User $user): ?string
    {
        $problem = $this->probe->check($this->settings($bucket, $provider, $access, $secret));
        if ($problem !== null) {
            return $problem;
        }
        $this->connect($bucket, $provider, $access, $secret, $user, checked: true);

        return null;
    }

    /** @throws IntegrationFailed */
    public function retention(IntegrationBucket $bucket, Provider&StoresBackups $provider, ?int $days): bool
    {
        if ($bucket->keep_days === $days) {
            return false;
        }
        $before = $bucket->keep_days;
        $bucket->keep_days = $days;
        try {
            $provider->setRetention($bucket->integration, $bucket);
        } catch (IntegrationFailed $e) {
            $bucket->keep_days = $before;

            throw $e;
        }
        $bucket->save();

        return true;
    }

    /**
     * Sends exactly these machines' backups to the bucket: each keeps its
     * schedule, or takes $schedule when it had none, and ones left out stop
     * backing up. With $onlyAdd, machines already there are left alone.
     *
     * @param  list<int>  $ids
     * @return int how many machines changed
     */
    public function choose(IntegrationBucket $bucket, array $ids, string $schedule, User $user, bool $onlyAdd = false): int
    {
        $destination = $bucket->destination;
        if ($destination === null) {
            return 0;
        }
        $workspace = $bucket->integration->workspace;
        $changed = 0;
        $machines = $workspace->machines()
            ->where(fn ($q) => $q->whereIn('id', $ids)->when(! $onlyAdd, fn ($q) => $q->orWhere('backup_destination_id', $destination->id)))
            ->orderBy('name')
            ->get();

        foreach ($machines as $machine) {
            $wanted = in_array($machine->id, $ids, true);
            $machine->forceFill($wanted
                ? ['backup_destination_id' => $destination->id, 'backup_schedule' => $machine->backup_schedule !== 'off' ? $machine->backup_schedule : $schedule]
                : ['backup_destination_id' => null, 'backup_schedule' => 'off']);
            if (! $machine->isDirty()) {
                continue;
            }
            $machine->save();
            $changed++;
            Activity::record($workspace, 'backups.schedule', $machine->name, $machine, $user, [
                'schedule' => $machine->backup_schedule,
                'destination' => $wanted ? $destination->name : null,
            ]);
        }

        return $changed;
    }

    /** Takes back the key ServerOS made when its destination is removed on the Backups page; the bucket stays. */
    public function destinationRemoved(BackupDestination $destination): void
    {
        $bucket = IntegrationBucket::query()->where('backup_destination_id', $destination->id)->with('integration')->first();
        if ($bucket === null) {
            return;
        }
        $provider = $bucket->integration->provider();
        if ($bucket->key_id === null || ! $provider instanceof StoresBackups) {
            $bucket->delete();

            return;
        }

        try {
            $provider->deleteKey($bucket->integration, $bucket);
            $bucket->delete();
        } catch (IntegrationFailed $e) {
            $bucket->forceFill(['state' => IntegrationBucket::FAILED, 'error' => self::t('ServerOS couldn’t delete the key it made for this bucket: :reason Delete it at :provider.', [
                'reason' => rtrim($e->getMessage(), '.').'.',
                'provider' => $provider->name(),
            ])])->save();
        }
    }

    /**
     * What disconnecting does with the buckets: with "keys" ticked, deletes
     * each key ServerOS made and the destination that used it.
     *
     * @param  list<string>  $chosen
     */
    public function disconnect(Integration $integration, Provider&StoresBackups $provider, array $chosen): ?string
    {
        $buckets = IntegrationBucket::query()->where('integration_id', $integration->id)->with('destination')->get();
        if ($buckets->isEmpty()) {
            return null;
        }
        if (! in_array('keys', $chosen, true)) {
            return self::t('Disconnected :name. The buckets, backups and keys stay at :provider, so backups keep going there.', ['name' => $integration->label, 'provider' => $provider->name()]);
        }

        $deleted = 0;
        $failed = [];
        foreach ($buckets->whereNotNull('key_id') as $bucket) {
            try {
                $provider->deleteKey($integration, $bucket);
            } catch (IntegrationFailed $e) {
                $failed[] = $bucket->bucket;

                continue;
            }
            $deleted++;
            $destination = $bucket->destination;
            if ($destination !== null) {
                $destination->machines()->update(['backup_schedule' => 'off']);
                $destination->delete();
                Activity::record($integration->workspace, 'backup_destination.remove', $destination->name);
            }
        }

        $done = trans_choice('Disconnected :name and deleted :count key ServerOS made. The buckets and backups stay at :provider.|Disconnected :name and deleted :count keys ServerOS made. The buckets and backups stay at :provider.', $deleted, [
            'name' => $integration->label, 'count' => $deleted, 'provider' => $provider->name(),
        ]);

        return $failed === [] ? (string) $done : $done.' '.self::t('The key for :buckets couldn’t be deleted, so backups still go there. Delete it at :provider.', ['buckets' => implode(', ', $failed), 'provider' => $provider->name()]);
    }

    /**
     * The Buckets tray on the account page.
     *
     * @return array<string, mixed>
     */
    public function props(Integration $integration, Provider&StoresBackups $provider): array
    {
        $buckets = IntegrationBucket::query()->where('integration_id', $integration->id)->with('destination')->orderBy('id')->get();
        $workspace = $integration->workspace;
        $machines = $workspace->machines()->with('backupDestination:id,name')->orderBy('name')->get(['id', 'name', 'backup_destination_id', 'backup_schedule']);

        foreach ($buckets as $bucket) {
            if ($bucket->state === IntegrationBucket::READY && ($bucket->usage_checked_at === null || $bucket->usage_checked_at->lt(now()->subMinutes(self::USAGE_MINUTES)))) {
                $this->refreshUsage($integration, $provider, $bucket);
            }
        }
        $price = $provider->price();

        return [
            'buckets' => array_values($buckets->map(fn (IntegrationBucket $bucket) => $this->bucketProps($integration, $provider, $bucket, $machines, $price))->all()),
            'suggestedName' => self::suggestedName($workspace),
            'machines' => array_values($machines->map(fn (Machine $machine) => [
                'id' => $machine->id,
                'name' => $machine->name,
                'destinationId' => $machine->backup_destination_id,
                'destination' => $machine->backupDestination?->name,
                'schedule' => $machine->backup_schedule,
            ])->all()),
            'keepOptions' => self::KEEP_DAYS,
            'options' => $provider->bucketOptions(),
            'price' => $price['label'],
            'notes' => $provider->notes($integration),
            'keepNote' => $provider->keepNote(),
        ];
    }

    /**
     * The storage providers ServerOS can set backups up in, for the Backups
     * page: each connected account, or where to connect one.
     *
     * @return list<array{key: string, name: string, logo: string, accounts: list<array{id: int, label: string, href: string}>, connect: string}>
     */
    public static function shortcuts(Workspace $workspace): array
    {
        $catalogue = collect(IntegrationCatalogue::all())->keyBy('key');
        $out = [];
        foreach (app(IntegrationProviders::class)->all() as $key => $provider) {
            if (! $provider instanceof StoresBackups) {
                continue;
            }
            $out[] = [
                'key' => $key,
                'name' => $provider->name(),
                'logo' => (string) ($catalogue->get($key)['logo'] ?? $key),
                'accounts' => array_values($workspace->integrations()->where('provider', $key)->where('status', Integration::CONNECTED)->orderBy('label')->get(['id', 'label'])
                    ->map(fn (Integration $integration) => ['id' => $integration->id, 'label' => $integration->label, 'href' => '/integrations/'.$integration->id.'?setup'])->all()),
                'connect' => '/integrations?connect='.$key,
            ];
        }

        return $out;
    }

    /**
     * Which account made each destination, for the Backups page.
     *
     * @param  list<int>  $destinations
     * @return array<int, array{label: string, provider: string, href: string}>
     */
    public static function madeBy(array $destinations): array
    {
        if ($destinations === []) {
            return [];
        }

        return IntegrationBucket::query()->whereIn('backup_destination_id', $destinations)->with('integration:id,label,provider')->get()
            ->mapWithKeys(fn (IntegrationBucket $bucket) => [(int) $bucket->backup_destination_id => [
                'label' => $bucket->integration->label,
                'provider' => $bucket->integration->provider()->name(),
                'href' => '/integrations/'.$bucket->integration_id,
            ]])->all();
    }

    public function refreshUsage(Integration $integration, Provider&StoresBackups $provider, IntegrationBucket $bucket): void
    {
        try {
            $usage = $provider->bucketUsage($integration, $bucket);
            $bucket->forceFill(['usage' => $usage, 'usage_error' => null, 'usage_checked_at' => now()])->save();
        } catch (IntegrationFailed $e) {
            $bucket->forceFill(['usage_error' => mb_substr($e->getMessage(), 0, 500), 'usage_checked_at' => now()])->save();
        }
    }

    public static function summary(Integration $integration): string
    {
        $buckets = IntegrationBucket::query()->where('integration_id', $integration->id)->get(['id', 'backup_destination_id']);
        if ($buckets->isEmpty()) {
            return self::t('No bucket set up yet');
        }
        $machines = self::machinesOf($buckets);
        $first = $machines->first();

        return match (true) {
            $first === null => (string) trans_choice(':count bucket, no backups yet|:count buckets, no backups yet', $buckets->count(), ['count' => $buckets->count()]),
            $machines->count() === 1 => self::t('Backups of :machine', ['machine' => $first->name]),
            default => self::t('Backups of :machine and :count more', ['machine' => $first->name, 'count' => $machines->count() - 1]),
        };
    }

    /** @return list<string> */
    public static function usageLines(Integration $integration): array
    {
        $buckets = IntegrationBucket::query()->where('integration_id', $integration->id)->orderBy('id')->get();
        if ($buckets->isEmpty()) {
            return [self::t('No bucket yet. Set up backups and ServerOS makes one here.')];
        }

        return array_values($buckets->map(function (IntegrationBucket $bucket): string {
            $names = self::machinesOf(collect([$bucket]))->pluck('name');

            return match (true) {
                $bucket->state === IntegrationBucket::NEEDS_KEYS => self::t(':bucket needs an access key before backups can go there.', ['bucket' => $bucket->bucket]),
                $bucket->backup_destination_id === null => self::t(':bucket isn’t a backup destination any more. It stays at the provider with its files.', ['bucket' => $bucket->bucket]),
                $names->isEmpty() => self::t(':bucket is ready, but no machine backs up there yet.', ['bucket' => $bucket->bucket]),
                $names->count() <= 3 => self::t('Backups of :machines go to :bucket.', ['machines' => $names->implode(', '), 'bucket' => $bucket->bucket]),
                default => self::t('Backups of :count machines go to :bucket.', ['count' => $names->count(), 'bucket' => $bucket->bucket]),
            };
        })->all());
    }

    /** @return list<string> */
    public static function dependents(Integration $integration): array
    {
        $out = [];
        foreach (IntegrationBucket::query()->where('integration_id', $integration->id)->orderBy('id')->get() as $bucket) {
            $names = self::machinesOf(collect([$bucket]))->pluck('name');
            if ($names->isNotEmpty()) {
                $out[] = (string) trans_choice(
                    ':machines backs up to :bucket. It keeps backing up there after you disconnect, unless you also delete the keys ServerOS made.|:machines back up to :bucket. They keep backing up there after you disconnect, unless you also delete the keys ServerOS made.',
                    $names->count(),
                    ['machines' => $names->count() <= 3 ? $names->implode(', ') : trans_choice(':count machine|:count machines', $names->count(), ['count' => $names->count()]), 'bucket' => $bucket->bucket],
                );
            }
        }

        return $out;
    }

    /** @return list<array{key: string, label: string, detail: string}> */
    public static function disconnectChoices(Integration $integration, Provider $provider): array
    {
        $made = IntegrationBucket::query()->where('integration_id', $integration->id)->whereNotNull('key_id')->orderBy('id')->pluck('bucket');
        if ($made->isEmpty()) {
            return [];
        }

        return [[
            'key' => 'keys',
            'label' => self::t('Also delete the keys ServerOS made'),
            'detail' => self::t('Backups to :buckets stop and their destinations are removed. The buckets and the backups in them stay at :provider.', ['buckets' => $made->implode(', '), 'provider' => $provider->name()]),
        ]];
    }

    /**
     * @param  Collection<int, Machine>  $machines
     * @param  array{perGbMonth: float, label: string}  $price
     * @return array<string, mixed>
     */
    private function bucketProps(Integration $integration, Provider&StoresBackups $provider, IntegrationBucket $bucket, Collection $machines, array $price): array
    {
        $usage = $bucket->usage;
        $bytes = is_array($usage) ? (int) ($usage['bytes'] ?? 0) : null;

        return [
            'id' => $bucket->id,
            'name' => $bucket->bucket,
            'endpoint' => $bucket->endpoint,
            'region' => $bucket->region,
            'location' => $bucket->location,
            'jurisdiction' => $bucket->jurisdiction,
            'state' => $bucket->state,
            'error' => $bucket->error,
            'keepDays' => $bucket->keep_days,
            'keyMade' => $bucket->key_id !== null,
            'destination' => $bucket->destination === null ? null : ['id' => $bucket->destination->id, 'name' => $bucket->destination->name],
            'machines' => array_values($machines
                ->filter(fn (Machine $m) => $bucket->backup_destination_id !== null && $m->backup_destination_id === $bucket->backup_destination_id)
                ->map(fn (Machine $m) => ['id' => $m->id, 'name' => $m->name, 'schedule' => $m->backup_schedule])
                ->values()->all()),
            'waiting' => count($bucket->pending['machines'] ?? []),
            'usage' => $bytes === null ? null : [
                'bytes' => $bytes,
                'files' => (int) ($usage['files'] ?? 0),
                'more' => (bool) ($usage['more'] ?? false),
                'checkedAt' => $bucket->usage_checked_at?->toIso8601String(),
            ],
            'usageError' => $bucket->usage_error,
            'cost' => $bytes === null ? null : self::cost($bytes, $price['perGbMonth']),
            'keysHelp' => $bucket->state === IntegrationBucket::NEEDS_KEYS ? $provider->keysHelp($integration, $bucket) : null,
        ];
    }

    /** About what the stored bytes cost a month at the provider's list price, before any free allowance. */
    public static function cost(int $bytes, float $perGbMonth): string
    {
        $dollars = $bytes / 1_000_000_000 * $perGbMonth;

        return $dollars > 0 && $dollars < 0.01 ? self::t('under $0.01') : '$'.number_format($dollars, 2);
    }

    /**
     * Sends the bucket's backups somewhere: makes its destination with these keys.
     *
     * @return string|null a warning when the test upload didn't go through
     */
    private function connect(IntegrationBucket $bucket, Provider&StoresBackups $provider, string $access, string $secret, User $user, bool $checked = false): ?string
    {
        $settings = $this->settings($bucket, $provider, $access, $secret);
        $problem = $checked ? null : $this->probe->check($settings);
        $pending = $bucket->pending ?? [];
        $integration = $bucket->integration;

        $destination = new BackupDestination([
            'name' => mb_substr(($pending['name'] ?? '') !== '' ? (string) $pending['name'] : $provider->name(), 0, 60),
            'type' => 's3',
            'settings' => $settings,
        ]);
        $destination->workspace_id = $integration->workspace_id;
        $destination->save();
        $bucket->forceFill(['backup_destination_id' => $destination->id, 'state' => IntegrationBucket::READY, 'error' => null])->save();
        $bucket->setRelation('destination', $destination);

        $this->choose($bucket, array_map('intval', $pending['machines'] ?? []), (string) ($pending['schedule'] ?? 'daily'), $user, onlyAdd: true);
        $bucket->forceFill(['pending' => null])->save();

        return $problem === null ? null : self::t('The test upload didn’t go through yet: :problem Backups may fail until it does.', ['problem' => rtrim($problem, '.').'.']);
    }

    /** @return array{endpoint: string, region: string, bucket: string, access_key: string, secret_key: string, path_style: bool, prefix: string} */
    private function settings(IntegrationBucket $bucket, Provider&StoresBackups $provider, string $access, string $secret): array
    {
        return [
            'endpoint' => $bucket->endpoint,
            'region' => $bucket->region ?: 'auto',
            'bucket' => $bucket->bucket,
            'access_key' => $access,
            'secret_key' => $secret,
            'path_style' => $provider->pathStyle(),
            'prefix' => '',
        ];
    }

    /**
     * @param  Collection<int, IntegrationBucket>  $buckets
     * @return Collection<int, Machine>
     */
    private static function machinesOf(Collection $buckets): Collection
    {
        $destinations = $buckets->pluck('backup_destination_id')->filter()->values()->all();

        return $destinations === [] ? collect() : Machine::query()->whereIn('backup_destination_id', $destinations)->orderBy('name')->get(['id', 'name']);
    }

    /** @param  list<string>  $taken */
    private static function free(string $name, array $taken): string
    {
        $candidate = $name;
        for ($n = 2; in_array($candidate, $taken, true); $n++) {
            $candidate = substr($name, 0, 60).'-'.$n;
        }

        return $candidate;
    }

    /** @param  array<string, string|int>  $replace */
    private static function t(string $text, array $replace = []): string
    {
        return is_string($line = __($text, $replace)) ? $line : $text;
    }
}
