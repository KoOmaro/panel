<?php

use App\Models\Activity;
use App\Models\BackupDestination;
use App\Models\Integration;
use App\Models\IntegrationBucket;
use App\Models\Machine;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Activity\ActivityLine;
use App\Support\Monitors\HostResolver;
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeB2;

beforeEach(function () {
    Sleep::fake();
    $this->b2 = FakeB2::install();
    app()->instance(HostResolver::class, new class extends HostResolver
    {
        protected function addresses(string $host): array
        {
            return ['104.153.233.177'];
        }
    });
});

function b2Workspace(string $slug = 'acme'): Workspace
{
    return Workspace::factory()->create(['slug' => $slug]);
}

/** @param  array<string, string>  $credentials */
function b2Connect(Workspace $workspace, array $credentials = ['key_id' => FakeB2::KEY_ID, 'application_key' => FakeB2::KEY], ?User $as = null): TestResponse
{
    return test()->actingAs($as ?? $workspace->owner)
        ->from($workspace->url('/integrations'))
        ->post($workspace->url('/integrations/connect/backblaze'), ['credentials' => $credentials]);
}

function b2Account(Workspace $workspace, string $missing = ''): Integration
{
    $credentials = ['key_id' => FakeB2::KEY_ID, 'application_key' => FakeB2::KEY];

    return $workspace->integrations()->create([
        'provider' => 'backblaze',
        'label' => 'Backblaze B2',
        'credentials' => $credentials,
        'fingerprint' => Integration::fingerprintOf('backblaze', $credentials),
        'meta' => ['account_id' => FakeB2::ACCOUNT, 'region' => FakeB2::REGION, 'missing' => $missing],
        'status' => Integration::CONNECTED,
        'last_checked_at' => now(),
        'created_by' => $workspace->owner_id,
    ]);
}

/** @param  array<string, mixed>  $choices */
function b2SetUp(Workspace $workspace, Integration $integration, array $choices = [], ?User $as = null): TestResponse
{
    return test()->actingAs($as ?? $workspace->owner)
        ->from($workspace->url('/integrations/'.$integration->id))
        ->post($workspace->url('/integrations/'.$integration->id.'/storage'), $choices);
}

/** @return array<string, mixed> */
function b2AccountProps(Workspace $workspace, Integration $integration): array
{
    $props = null;
    test()->actingAs($workspace->owner)->get($workspace->url('/integrations/'.$integration->id))
        ->assertOk()
        ->assertInertia(function (Assert $page) use (&$props) {
            $page->loadDeferredProps(function (Assert $reload) use (&$props) {
                $props = $reload->toArray()['props']['account'];
            });
        });

    return $props;
}

test('connecting checks the key with Backblaze, keeps it encrypted and opens the account ready to set up backups', function () {
    $workspace = b2Workspace();

    b2Connect($workspace)->assertSessionHasNoErrors()->assertRedirect();

    $integration = Integration::query()->sole();
    expect($integration->provider)->toBe('backblaze')
        ->and($integration->meta)->toMatchArray(['account_id' => FakeB2::ACCOUNT, 'region' => FakeB2::REGION, 'missing' => ''])
        ->and($integration->getRawOriginal('credentials'))->not->toContain(FakeB2::KEY)
        ->and($this->b2->called())->toBe(['GET b2_authorize_account'])
        ->and(json_encode(Activity::query()->get()->toArray()))->not->toContain(FakeB2::KEY);

    $page = $this->get($workspace->url('/integrations/'.$integration->id))->assertOk();
    expect(json_encode($page->viewData('page')))->not->toContain(FakeB2::KEY)
        ->and($page->viewData('page')['props']['provider']['capabilities'][0]['key'])->toBe('backup_storage')
        ->and($page->viewData('page')['props']['integration']['summary'])->toBe('No bucket set up yet');
});

test('the connect redirect opens the set up dialog', function () {
    $workspace = b2Workspace();

    $response = b2Connect($workspace);

    expect($response->headers->get('Location'))->toBe($workspace->url('/integrations/'.Integration::query()->sole()->id.'?setup'));
});

test('a wrong key, a key for one bucket and a key without writeKeys are turned down with words', function () {
    $workspace = b2Workspace();

    b2Connect($workspace, ['key_id' => FakeB2::KEY_ID, 'application_key' => 'fake-b2-wrong-key-for-tests'])
        ->assertSessionHasErrors(['credentials.application_key' => 'Backblaze turned the key down. Check the key ID and the application key are copied in full and the key hasn’t been deleted.']);

    $this->b2->bucket('photos');
    $one = $this->b2->accountKey('fakeb2onebucket', ['listBuckets', 'writeFiles'], [array_key_first($this->b2->buckets)]);
    b2Connect($workspace, ['key_id' => 'fakeb2onebucket', 'application_key' => $one])
        ->assertSessionHasErrors(['credentials.application_key' => 'This key only reaches photos. Use the master key or a key for all buckets, so ServerOS can make a bucket for backups.']);

    $read = $this->b2->accountKey('fakeb2readonly', ['listBuckets', 'listFiles', 'readFiles']);
    b2Connect($workspace, ['key_id' => 'fakeb2readonly', 'application_key' => $read])
        ->assertSessionHasErrors(['credentials.application_key' => 'This key can’t writeBuckets, writeKeys. Use the master key, or a key with Read and Write for all buckets.']);

    expect(Integration::query()->count())->toBe(0);
});

test('setting up backups makes a private bucket, a key that can only write there, and the destination, and sends the chosen machines there', function () {
    $workspace = b2Workspace();
    $integration = b2Account($workspace);
    $web = Machine::factory()->for($workspace)->create(['name' => 'web-1', 'backup_schedule' => 'off']);
    $db = Machine::factory()->for($workspace)->create(['name' => 'db-1', 'backup_schedule' => 'weekly']);
    $spare = Machine::factory()->for($workspace)->create(['name' => 'spare-1']);

    b2SetUp($workspace, $integration, ['machines' => [$web->id, $db->id], 'schedule' => 'daily'])
        ->assertSessionHasNoErrors()
        ->assertRedirect()
        ->assertInertiaFlash('toast.message', 'Backups go to serveros-acme-backups now. A test upload went through.');

    $made = $this->b2->bucketNamed('serveros-acme-backups');
    expect($made)->not->toBeNull()
        ->and($this->b2->bodyOf('b2_create_bucket'))->toBe([
            'accountId' => FakeB2::ACCOUNT,
            'bucketName' => 'serveros-acme-backups',
            'bucketType' => 'allPrivate',
            'bucketInfo' => ['managed-by' => 'ServerOS'],
            'lifecycleRules' => [['fileNamePrefix' => '', 'daysFromUploadingToHiding' => null, 'daysFromHidingToDeleting' => 1]],
        ])
        ->and($this->b2->bodyOf('b2_create_key'))->toBe([
            'accountId' => FakeB2::ACCOUNT,
            'capabilities' => ['writeFiles'],
            'keyName' => 'serveros-serveros-acme-backups',
            'bucketIds' => [$made['bucketId']],
        ]);

    $key = $this->b2->madeKey();
    $keyId = array_search($key, $this->b2->keys, true);
    $bucket = IntegrationBucket::query()->sole();
    $destination = BackupDestination::query()->sole();
    expect($bucket)->toMatchArray(['bucket' => 'serveros-acme-backups', 'bucket_id' => $made['bucketId'], 'endpoint' => 's3.us-west-004.backblazeb2.com', 'region' => 'us-west-004', 'key_id' => $keyId, 'key_kind' => 'b2', 'state' => 'ready', 'pending' => null])
        ->and($bucket->backup_destination_id)->toBe($destination->id)
        ->and($destination->name)->toBe('Backblaze B2')
        ->and($destination->status)->toBe('ready')
        ->and($destination->settings)->toBe([
            'endpoint' => 's3.us-west-004.backblazeb2.com', 'region' => 'us-west-004', 'bucket' => 'serveros-acme-backups',
            'access_key' => $keyId, 'secret_key' => $key['key'], 'path_style' => false, 'prefix' => '',
        ])
        ->and($destination->getRawOriginal('settings'))->not->toContain($key['key'])
        ->and($this->b2->uploads)->toBe([['key' => $keyId, 'bucket' => 'serveros-acme-backups', 'path' => '/.serveros-check']])
        ->and($web->fresh()->only(['backup_destination_id', 'backup_schedule']))->toBe(['backup_destination_id' => $destination->id, 'backup_schedule' => 'daily'])
        ->and($db->fresh()->only(['backup_destination_id', 'backup_schedule']))->toBe(['backup_destination_id' => $destination->id, 'backup_schedule' => 'weekly'])
        ->and($spare->fresh()->backup_destination_id)->toBeNull();

    $setup = Activity::query()->where('action', 'integration.storage_setup')->sole();
    expect($setup->subject)->toBe('serveros-acme-backups')
        ->and($setup->meta)->toMatchArray(['provider' => 'Backblaze B2', 'ok' => true, 'machines' => 2])
        ->and(ActivityLine::describe($setup, true)['verb'])->toBe('set up backups in the bucket')
        ->and(Activity::query()->where('action', 'backups.schedule')->pluck('subject')->sort()->values()->all())->toBe(['db-1', 'web-1'])
        ->and(json_encode(Activity::query()->get()->toArray()))->not->toContain($key['key']);
});

test('a name the account has already gets a number, and one another account has gets a random end', function () {
    $workspace = b2Workspace();
    $integration = b2Account($workspace);
    $this->b2->bucket('serveros-acme-backups');
    $this->b2->bucket('serveros-acme-backups-2', ours: false);

    b2SetUp($workspace, $integration)->assertSessionHasNoErrors();

    $name = IntegrationBucket::query()->sole()->bucket;
    expect($this->b2->bodyOf('b2_list_buckets'))->toBe(['accountId' => FakeB2::ACCOUNT])
        ->and($name)->toStartWith('serveros-acme-backups-')
        ->and($name)->not->toBe('serveros-acme-backups-2')
        ->and($name)->toMatch('/^serveros-acme-backups-[a-z0-9]{5}$/')
        ->and(collect($this->b2->calls)->where('path', 'b2_create_bucket')->pluck('body.bucketName')->all())->toBe(['serveros-acme-backups-2', $name]);
});

test('how long backups stay is set as a lifecycle rule, at set up and later', function () {
    $workspace = b2Workspace();
    $integration = b2Account($workspace);

    b2SetUp($workspace, $integration, ['keep_days' => 90])->assertSessionHasNoErrors();
    $bucket = IntegrationBucket::query()->sole();
    expect($this->b2->bodyOf('b2_create_bucket')['lifecycleRules'])->toBe([['fileNamePrefix' => '', 'daysFromUploadingToHiding' => 90, 'daysFromHidingToDeleting' => 1]])
        ->and($bucket->keep_days)->toBe(90);

    $this->put($workspace->url("/integrations/{$integration->id}/storage/{$bucket->id}"), ['keep_days' => 30])->assertSessionHasNoErrors();
    expect($this->b2->bodyOf('b2_update_bucket'))->toBe([
        'accountId' => FakeB2::ACCOUNT, 'bucketId' => $bucket->bucket_id,
        'lifecycleRules' => [['fileNamePrefix' => '', 'daysFromUploadingToHiding' => 30, 'daysFromHidingToDeleting' => 1]],
    ])->and($bucket->fresh()->keep_days)->toBe(30)
        ->and(ActivityLine::describe(Activity::query()->where('action', 'integration.storage_retention')->sole(), true)['after'])->toBe('to 30 days');

    $this->put($workspace->url("/integrations/{$integration->id}/storage/{$bucket->id}"), ['keep_days' => null])->assertSessionHasNoErrors();
    expect($this->b2->bodyOf('b2_update_bucket')['lifecycleRules'][0]['daysFromUploadingToHiding'])->toBeNull()
        ->and($bucket->fresh()->keep_days)->toBeNull();

    $this->put($workspace->url("/integrations/{$integration->id}/storage/{$bucket->id}"), ['keep_days' => 45])->assertSessionHasErrors('keep_days');

    $this->b2->failNext('b2_update_bucket', 401, FakeB2::error(401, 'unauthorized', 'nope'));
    $this->put($workspace->url("/integrations/{$integration->id}/storage/{$bucket->id}"), ['keep_days' => 365])
        ->assertSessionHasErrors(['keep_days' => 'Backblaze says this key isn’t allowed to do that. Use the master key, or a key for all buckets with listBuckets, writeBuckets, writeKeys, deleteKeys and listFiles.']);
    expect($bucket->fresh()->keep_days)->toBeNull();
});

test('choosing machines later sends exactly those there, and the ones left out stop', function () {
    $workspace = b2Workspace();
    $integration = b2Account($workspace);
    $web = Machine::factory()->for($workspace)->create(['name' => 'web-1']);
    $db = Machine::factory()->for($workspace)->create(['name' => 'db-1']);
    b2SetUp($workspace, $integration, ['machines' => [$web->id]])->assertSessionHasNoErrors();
    $bucket = IntegrationBucket::query()->sole();

    $this->put($workspace->url("/integrations/{$integration->id}/storage/{$bucket->id}"), ['machines' => [$db->id], 'schedule' => 'weekly'])->assertSessionHasNoErrors();

    expect($web->fresh()->only(['backup_destination_id', 'backup_schedule']))->toBe(['backup_destination_id' => null, 'backup_schedule' => 'off'])
        ->and($db->fresh()->only(['backup_destination_id', 'backup_schedule']))->toBe(['backup_destination_id' => $bucket->backup_destination_id, 'backup_schedule' => 'weekly']);

    $other = Machine::factory()->for(b2Workspace('other'))->create();
    $this->put($workspace->url("/integrations/{$integration->id}/storage/{$bucket->id}"), ['machines' => [$other->id]])->assertSessionHasErrors('machines.0');
});

test('the account page shows each bucket’s size, file count and an estimated monthly cost', function () {
    config()->set('services.backblaze.price_per_tb_month', 6.95);
    $workspace = b2Workspace();
    $integration = b2Account($workspace);
    $web = Machine::factory()->for($workspace)->create(['name' => 'web-1']);
    b2SetUp($workspace, $integration, ['machines' => [$web->id]])->assertSessionHasNoErrors();
    $bucket = IntegrationBucket::query()->sole();
    $this->b2->file($bucket->bucket_id, 'acme/web-1/db/1/data.tar.zst', 60_000_000_000);
    $this->b2->file($bucket->bucket_id, 'acme/web-1/db/1/manifest.json', 2_000);
    $this->b2->file($bucket->bucket_id, 'acme/web-1/db/0/data.tar.zst', 0, 'hide');

    $props = b2AccountProps($workspace, $integration);

    expect($props['buckets'][0])->toMatchArray([
        'name' => 'serveros-acme-backups', 'state' => 'ready', 'keepDays' => null, 'keyMade' => true,
        'machines' => [['id' => $web->id, 'name' => 'web-1', 'schedule' => 'daily']],
        'cost' => '$0.42', 'usageError' => null,
    ])->and($props['buckets'][0]['usage'])->toMatchArray(['bytes' => 60_000_002_000, 'files' => 2, 'more' => false])
        ->and($props['price'])->toBe('$6.95 per TB a month')
        ->and($props['suggestedName'])->toBe('serveros-acme-backups')
        ->and($props['keepOptions'])->toBe([30, 90, 180, 365])
        ->and($props['notes'])->toBe([])
        ->and($this->b2->bodyOf('b2_list_file_versions'))->toBe(['bucketId' => $bucket->bucket_id, 'maxFileCount' => '1000']);

    $calls = count($this->b2->calls);
    b2AccountProps($workspace, $integration);
    expect(count($this->b2->calls))->toBe($calls);

    $this->travel(2)->hours();
    $this->b2->failNext('b2_list_file_versions', 401, FakeB2::error(401, 'unauthorized', 'nope'));
    $props = b2AccountProps($workspace, $integration);
    expect($props['buckets'][0]['usage']['bytes'])->toBe(60_000_002_000)
        ->and($props['buckets'][0]['usageError'])->toStartWith('Backblaze says this key isn’t allowed');
});

test('a key without listFiles or deleteKeys says what it can’t show or clean up', function () {
    $workspace = b2Workspace();
    $key = $this->b2->accountKey('fakeb2fewcaps', ['listBuckets', 'writeBuckets', 'writeKeys']);

    b2Connect($workspace, ['key_id' => 'fakeb2fewcaps', 'application_key' => $key])->assertSessionHasNoErrors();
    $integration = Integration::query()->sole();

    expect($integration->meta['missing'])->toBe('deleteKeys,listFiles')
        ->and(b2AccountProps($workspace, $integration)['notes'])->toHaveCount(2);
});

test('when the key can’t be made the bucket is kept, and trying again carries on from it', function () {
    $workspace = b2Workspace();
    $integration = b2Account($workspace);
    $web = Machine::factory()->for($workspace)->create(['name' => 'web-1']);
    $this->b2->failNext('b2_create_key', 503);

    b2SetUp($workspace, $integration, ['machines' => [$web->id]])->assertSessionHasErrors('storage');

    $bucket = IntegrationBucket::query()->sole();
    expect($bucket->state)->toBe('failed')
        ->and($bucket->error)->toBe('Backblaze isn’t answering properly right now. Try again in a few minutes.')
        ->and($bucket->pending['machines'])->toBe([$web->id])
        ->and(BackupDestination::query()->count())->toBe(0)
        ->and(Activity::query()->where('action', 'integration.storage_setup')->sole()->meta['ok'])->toBeFalse();

    $this->post($workspace->url("/integrations/{$integration->id}/storage/{$bucket->id}/retry"))->assertSessionHasNoErrors();

    expect($bucket->fresh()->state)->toBe('ready')
        ->and($web->fresh()->backup_destination_id)->toBe(BackupDestination::query()->sole()->id)
        ->and(collect($this->b2->calls)->where('path', 'b2_create_bucket')->count())->toBe(1);
});

test('a test upload that doesn’t go through still sets it up, and says so', function () {
    $workspace = b2Workspace();
    $integration = b2Account($workspace);
    app()->instance(HostResolver::class, new class extends HostResolver
    {
        protected function addresses(string $host): array
        {
            return [];
        }
    });

    b2SetUp($workspace, $integration)->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.type', 'error')
        ->assertInertiaFlash('toast.message', 'The test upload didn’t go through yet: Couldn\'t reach s3.us-west-004.backblazeb2.com, or it points into a private network. Backups may fail until it does.');

    expect(IntegrationBucket::query()->sole()->state)->toBe('ready')
        ->and($this->b2->uploads)->toBe([]);
});

test('disconnecting keeps the bucket, its key and the backups going unless the keys are ticked', function () {
    $workspace = b2Workspace();
    $integration = b2Account($workspace);
    $web = Machine::factory()->for($workspace)->create(['name' => 'web-1']);
    b2SetUp($workspace, $integration, ['machines' => [$web->id]])->assertSessionHasNoErrors();
    $keyId = IntegrationBucket::query()->sole()->key_id;

    $page = $this->get($workspace->url('/integrations/'.$integration->id))->viewData('page')['props'];
    expect($page['dependents'])->toBe(['web-1 backs up to serveros-acme-backups. It keeps backing up there after you disconnect, unless you also delete the keys ServerOS made.'])
        ->and($page['disconnectChoices'][0]['key'])->toBe('keys');

    $this->delete($workspace->url('/integrations/'.$integration->id))->assertSessionHasErrors('confirm');
    $this->delete($workspace->url('/integrations/'.$integration->id), ['confirm' => 'Backblaze B2'])->assertRedirect($workspace->url('/integrations'));

    $destination = BackupDestination::query()->sole();
    expect(Integration::query()->count())->toBe(0)
        ->and(IntegrationBucket::query()->count())->toBe(0)
        ->and($this->b2->keys)->toHaveKey($keyId)
        ->and($this->b2->bucketNamed('serveros-acme-backups'))->not->toBeNull()
        ->and($web->fresh()->backup_destination_id)->toBe($destination->id)
        ->and(collect($this->b2->calls)->where('path', 'b2_delete_key')->count())->toBe(0);
});

test('disconnecting with the keys ticked deletes only the keys ServerOS made and their destinations', function () {
    $workspace = b2Workspace();
    $integration = b2Account($workspace);
    $web = Machine::factory()->for($workspace)->create(['name' => 'web-1']);
    b2SetUp($workspace, $integration, ['machines' => [$web->id]])->assertSessionHasNoErrors();
    $keyId = IntegrationBucket::query()->sole()->key_id;

    $this->delete($workspace->url('/integrations/'.$integration->id), ['choices' => ['keys']])->assertSessionHasErrors('confirm');
    $this->delete($workspace->url('/integrations/'.$integration->id), ['choices' => ['keys', 'buckets'], 'confirm' => 'Backblaze B2'])->assertRedirect();

    expect($this->b2->keys)->not->toHaveKey($keyId)
        ->and($this->b2->keys)->toHaveKey(FakeB2::KEY_ID)
        ->and($this->b2->bodyOf('b2_delete_key'))->toBe(['applicationKeyId' => $keyId])
        ->and($this->b2->bucketNamed('serveros-acme-backups'))->not->toBeNull()
        ->and(BackupDestination::query()->count())->toBe(0)
        ->and($web->fresh()->backup_schedule)->toBe('off')
        ->and(collect($this->b2->calls)->pluck('path')->filter(fn ($p) => str_contains($p, 'delete_bucket') || str_contains($p, 'delete_file'))->all())->toBe([])
        ->and(Activity::query()->where('action', 'integration.disconnect')->sole()->meta['also'])->toBe('keys');
});

test('removing the destination on the Backups page deletes the key ServerOS made and leaves the bucket', function () {
    $workspace = b2Workspace();
    $integration = b2Account($workspace);
    b2SetUp($workspace, $integration)->assertSessionHasNoErrors();
    $bucket = IntegrationBucket::query()->sole();

    $this->delete($workspace->url('/backup-destinations/'.$bucket->backup_destination_id))->assertRedirect();

    expect($this->b2->keys)->not->toHaveKey($bucket->key_id)
        ->and(IntegrationBucket::query()->count())->toBe(0)
        ->and($this->b2->bucketNamed('serveros-acme-backups'))->not->toBeNull();
});

test('a key that can’t delete keys leaves the bucket recorded with what to do', function () {
    $workspace = b2Workspace();
    $integration = b2Account($workspace);
    b2SetUp($workspace, $integration)->assertSessionHasNoErrors();
    $bucket = IntegrationBucket::query()->sole();
    $this->b2->failNext('b2_delete_key', 401, FakeB2::error(401, 'unauthorized', 'nope'));

    $this->delete($workspace->url('/backup-destinations/'.$bucket->backup_destination_id))->assertRedirect();

    expect($bucket->fresh())->toMatchArray(['state' => 'failed', 'backup_destination_id' => null])
        ->and($bucket->fresh()->error)->toStartWith('ServerOS couldn’t delete the key it made for this bucket')
        ->and($this->b2->keys)->toHaveKey($bucket->key_id);
});

test('only people who manage machines can set up backups, and only in their workspace', function () {
    $workspace = b2Workspace();
    $integration = b2Account($workspace);
    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);

    b2SetUp($workspace, $integration, as: $viewer)->assertForbidden();
    $other = b2Workspace('other');
    b2SetUp($other, $integration, as: $other->owner)->assertNotFound();

    expect($this->b2->calls)->toBe([])
        ->and(IntegrationBucket::query()->count())->toBe(0);
});

test('the Integrations row connects Backblaze, and the Backups page offers it', function () {
    $workspace = b2Workspace();

    $row = collect($this->actingAs($workspace->owner)->get($workspace->url('/integrations'))->viewData('page')['props']['integrations'])->firstWhere('key', 'backblaze');
    expect($row['action'])->toBe(['label' => 'Connect', 'connect' => 'backblaze']);

    $integration = b2Account($workspace);
    $web = Machine::factory()->for($workspace)->create(['name' => 'web-1']);
    b2SetUp($workspace, $integration, ['machines' => [$web->id]])->assertSessionHasNoErrors();

    $props = $this->get($workspace->url('/integrations'))->viewData('page')['props'];
    $connections = collect($props['connections']);
    expect(collect($props['integrations'])->firstWhere('key', 'backblaze')['action'])->toBe(['label' => 'Manage', 'href' => '/integrations/'.$integration->id])
        ->and($connections->where('logo', 'backblaze')->values()->all())->toHaveCount(1)
        ->and($connections->firstWhere('logo', 'backblaze')['detail'])->toBe('Backups of web-1');

    $backups = $this->get($workspace->url('/backups'))->viewData('page')['props'];
    expect(collect($backups['storage'])->firstWhere('key', 'backblaze'))->toMatchArray([
        'name' => 'Backblaze B2',
        'accounts' => [['id' => $integration->id, 'label' => 'Backblaze B2', 'href' => '/integrations/'.$integration->id.'?setup']],
        'connect' => '/integrations?connect=backblaze',
    ])->and(collect($backups['destinations'])->first()['madeBy'])->toBe(['label' => 'Backblaze B2', 'provider' => 'Backblaze B2', 'href' => '/integrations/'.$integration->id]);
});
