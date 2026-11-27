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
use Tests\Support\FakeCloudflare;
use Tests\Support\FakeR2;

beforeEach(function () {
    Sleep::fake();
    $this->cloudflare = new FakeCloudflare;
    $this->r2 = FakeR2::install($this->cloudflare);
    app()->instance(HostResolver::class, new class extends HostResolver
    {
        protected function addresses(string $host): array
        {
            return ['172.66.0.10'];
        }
    });
});

function r2Workspace(string $slug = 'acme'): Workspace
{
    return Workspace::factory()->create(['slug' => $slug]);
}

/** @param  array<string, mixed>  $body */
function r2Connect(Workspace $workspace, array $body = ['credentials' => ['token' => FakeR2::TOKEN, 'account_id' => '']], ?User $as = null): TestResponse
{
    return test()->actingAs($as ?? $workspace->owner)
        ->from($workspace->url('/integrations'))
        ->post($workspace->url('/integrations/connect/r2'), $body);
}

function r2Account(Workspace $workspace, string $token = FakeR2::TOKEN): Integration
{
    $credentials = ['token' => $token, 'account_id' => FakeR2::ACCOUNT];

    return $workspace->integrations()->create([
        'provider' => 'r2',
        'label' => 'Acme',
        'credentials' => $credentials,
        'fingerprint' => Integration::fingerprintOf('r2', $credentials),
        'meta' => ['account' => 'Acme', 'account_id' => FakeR2::ACCOUNT],
        'status' => Integration::CONNECTED,
        'last_checked_at' => now(),
        'created_by' => $workspace->owner_id,
    ]);
}

/** @param  array<string, mixed>  $choices */
function r2SetUp(Workspace $workspace, Integration $integration, array $choices = []): TestResponse
{
    return test()->actingAs($workspace->owner)
        ->from($workspace->url('/integrations/'.$integration->id))
        ->post($workspace->url('/integrations/'.$integration->id.'/storage'), $choices);
}

/** @return array<string, mixed> */
function r2AccountProps(Workspace $workspace, Integration $integration): array
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

test('connecting finds the account the token reaches R2 in, keeps it with the token and never shows the token', function () {
    $workspace = r2Workspace();

    r2Connect($workspace)->assertSessionHasNoErrors()->assertRedirect();

    $integration = Integration::query()->sole();
    expect($integration->label)->toBe('Acme')
        ->and($integration->credentials)->toBe(['token' => FakeR2::TOKEN, 'account_id' => FakeR2::ACCOUNT])
        ->and($integration->meta)->toBe(['account' => 'Acme', 'account_id' => FakeR2::ACCOUNT])
        ->and($this->r2->called())->toBe(['GET accounts', 'GET accounts/'.FakeR2::ACCOUNT.'/r2/buckets'])
        ->and(json_encode(Activity::query()->get()->toArray()))->not->toContain(FakeR2::TOKEN)
        ->and(json_encode($this->get($workspace->url('/integrations/'.$integration->id))->viewData('page')))->not->toContain(FakeR2::TOKEN);
});

test('a token that can’t use R2, a turned down token and one that reaches R2 in two accounts are told what to do', function () {
    $workspace = r2Workspace();

    $this->r2->token('fake-r2-token-without-r2-access', r2: false);
    r2Connect($workspace, ['credentials' => ['token' => 'fake-r2-token-without-r2-access']])
        ->assertSessionHasErrors(['credentials.token' => 'Cloudflare says this token isn’t allowed to do that. Give it Account › Workers R2 Storage › Edit.']);

    r2Connect($workspace, ['credentials' => ['token' => 'fake-r2-token-nobody-knows-about']])
        ->assertSessionHasErrors(['credentials.token' => 'Cloudflare turned the token down. Check it’s copied in full and hasn’t been deleted.']);

    $this->r2->token('fake-r2-token-for-two-accounts', [FakeR2::ACCOUNT => 'Acme', 'cd34cd34cd34cd34cd34cd34cd34cd34' => 'Acme EU']);
    r2Connect($workspace, ['credentials' => ['token' => 'fake-r2-token-for-two-accounts']])
        ->assertSessionHasErrors(['credentials.account_id' => 'This token reaches R2 in Acme, Acme EU. Fill in the account ID to pick one.']);
    r2Connect($workspace, ['credentials' => ['token' => 'fake-r2-token-for-two-accounts', 'account_id' => 'cd34cd34cd34cd34cd34cd34cd34cd34']])->assertSessionHasNoErrors();
    expect(Integration::query()->sole()->credentials['account_id'])->toBe('cd34cd34cd34cd34cd34cd34cd34cd34');

    $this->r2->failNext('GET', 'accounts/'.FakeR2::ACCOUNT.'/r2/buckets', 403, FakeCloudflare::error(10042, 'Please enable R2 through the Cloudflare Dashboard.'));
    r2Connect(r2Workspace('other'))->assertSessionHasErrors(['credentials.token' => 'R2 isn’t turned on for this Cloudflare account yet. Open R2 in the Cloudflare dashboard and turn it on first.']);
});

test('a connected Cloudflare account’s token can be used for R2', function () {
    $workspace = r2Workspace();
    $this->r2->token(FakeCloudflare::TOKEN);
    $this->cloudflare->zone('acme.dev');
    $this->actingAs($workspace->owner)->post($workspace->url('/integrations/connect/cloudflare'), ['credentials' => ['token' => FakeCloudflare::TOKEN]])->assertSessionHasNoErrors();
    $cloudflare = Integration::query()->where('provider', 'cloudflare')->sole();

    $providers = $this->get($workspace->url('/integrations'))->viewData('page')['props']['providers'];
    expect($providers['r2']['reuse'])->toBe(['provider' => 'Cloudflare', 'accounts' => [['id' => $cloudflare->id, 'label' => 'Acme']]])
        ->and($providers['cloudflare'])->not->toHaveKey('reuse');

    r2Connect($workspace, ['from' => $cloudflare->id, 'credentials' => ['account_id' => '']])->assertSessionHasNoErrors();

    $r2 = Integration::query()->where('provider', 'r2')->sole();
    expect($r2->credentials)->toBe(['token' => FakeCloudflare::TOKEN, 'account_id' => FakeR2::ACCOUNT])
        ->and($r2->fingerprint)->not->toBe($cloudflare->fingerprint);

    $other = r2Workspace('other');
    r2Connect($other, ['from' => $cloudflare->id, 'credentials' => []], $other->owner)->assertSessionHasErrors('from');
});

test('a Cloudflare token without R2 says so when it’s reused', function () {
    $workspace = r2Workspace();
    $this->r2->token(FakeCloudflare::TOKEN, r2: false);
    $cloudflare = $workspace->integrations()->create([
        'provider' => 'cloudflare', 'label' => 'Acme', 'credentials' => ['token' => FakeCloudflare::TOKEN],
        'fingerprint' => Integration::fingerprintOf('cloudflare', ['token' => FakeCloudflare::TOKEN]), 'meta' => ['zones' => 1], 'status' => Integration::CONNECTED,
    ]);

    r2Connect($workspace, ['from' => $cloudflare->id, 'credentials' => []])
        ->assertSessionHasErrors(['credentials.token' => 'Cloudflare says this token isn’t allowed to do that. Give it Account › Workers R2 Storage › Edit.']);
    expect(Integration::query()->where('provider', 'r2')->count())->toBe(0);
});

test('setting up backups makes an R2 bucket, its lifecycle rules and an account token for just that bucket', function () {
    $workspace = r2Workspace();
    $integration = r2Account($workspace);
    $web = Machine::factory()->for($workspace)->create(['name' => 'web-1']);

    r2SetUp($workspace, $integration, ['keep_days' => 30, 'location' => 'weur', 'jurisdiction' => 'eu', 'machines' => [$web->id], 'name' => 'Off-site'])
        ->assertSessionHasNoErrors();

    $account = FakeR2::ACCOUNT;
    $create = collect($this->r2->calls)->first(fn ($c) => $c['method'] === 'POST' && $c['path'] === "accounts/{$account}/r2/buckets");
    expect($create['body'])->toBe(['name' => 'serveros-acme-backups', 'locationHint' => 'weur', 'storageClass' => 'Standard'])
        ->and($create['jurisdiction'])->toBe('eu')
        ->and($this->r2->lifecycle['eu/serveros-acme-backups'])->toBe([
            ['id' => 'serveros-abort-uploads', 'enabled' => true, 'conditions' => ['prefix' => ''], 'abortMultipartUploadsTransition' => ['condition' => ['type' => 'Age', 'maxAge' => 604800]]],
            ['id' => 'serveros-keep', 'enabled' => true, 'conditions' => ['prefix' => ''], 'deleteObjectsTransition' => ['condition' => ['type' => 'Age', 'maxAge' => 2592000]]],
        ]);

    $token = $this->r2->madeToken();
    $tokenId = array_key_last($this->r2->made);
    expect($token['kind'])->toBe('account')
        ->and($token['name'])->toBe('ServerOS backups serveros-acme-backups')
        ->and($token['policies'])->toBe([[
            'effect' => 'allow',
            'resources' => ["com.cloudflare.edge.r2.bucket.{$account}_eu_serveros-acme-backups" => '*'],
            'permission_groups' => [['id' => FakeR2::WRITE_GROUP]],
        ]])
        ->and(collect($this->r2->calls)->firstWhere('path', "accounts/{$account}/tokens/permission_groups")['query'])->toBe(['name' => 'Workers R2 Storage Bucket Item Write']);

    $destination = BackupDestination::query()->sole();
    expect($destination->name)->toBe('Off-site')
        ->and($destination->settings)->toBe([
            'endpoint' => "{$account}.eu.r2.cloudflarestorage.com", 'region' => 'auto', 'bucket' => 'serveros-acme-backups',
            'access_key' => $tokenId, 'secret_key' => hash('sha256', $token['value']), 'path_style' => true, 'prefix' => '',
        ])
        ->and($this->r2->uploads)->toBe([['key' => $tokenId, 'path' => '/serveros-acme-backups/.serveros-check']])
        ->and(IntegrationBucket::query()->sole())->toMatchArray(['jurisdiction' => 'eu', 'location' => 'weur', 'key_id' => $tokenId, 'key_kind' => 'account', 'keep_days' => 30, 'state' => 'ready'])
        ->and($web->fresh()->backup_destination_id)->toBe($destination->id)
        ->and(json_encode(Activity::query()->get()->toArray()))->not->toContain($token['value']);
});

test('a token that can only make user tokens makes one of those, and it’s deleted the same way', function () {
    $workspace = r2Workspace();
    $this->r2->token(FakeR2::TOKEN, make: ['user']);
    $integration = r2Account($workspace);

    r2SetUp($workspace, $integration)->assertSessionHasNoErrors();
    $bucket = IntegrationBucket::query()->sole();
    expect($bucket->key_kind)->toBe('user')
        ->and($this->r2->madeToken()['policies'][0]['resources'])->toBe(['com.cloudflare.edge.r2.bucket.'.FakeR2::ACCOUNT.'_default_serveros-acme-backups' => '*']);

    $this->delete($workspace->url('/integrations/'.$integration->id), ['choices' => ['keys'], 'confirm' => 'Acme'])->assertSessionHasNoErrors();
    expect($this->r2->made)->toBe([])
        ->and($this->r2->called())->toContain('DELETE user/tokens/'.$bucket->key_id)
        ->and($this->r2->buckets)->toHaveKey('default/serveros-acme-backups');
});

test('when the token can’t make tokens the bucket waits for a key someone pastes, which is checked first', function () {
    $workspace = r2Workspace();
    $this->r2->token(FakeR2::TOKEN, make: []);
    $integration = r2Account($workspace);
    $web = Machine::factory()->for($workspace)->create(['name' => 'web-1']);

    r2SetUp($workspace, $integration, ['machines' => [$web->id]])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Made serveros-acme-backups. Add an access key for it to finish.');

    $bucket = IntegrationBucket::query()->sole();
    expect($bucket->state)->toBe('needs_keys')
        ->and(BackupDestination::query()->count())->toBe(0)
        ->and(r2AccountProps($workspace, $integration)['buckets'][0]['keysHelp'])->toContain('R2 › Manage API tokens');

    $keys = fn (string $access) => $this->post($workspace->url("/integrations/{$integration->id}/storage/{$bucket->id}/keys"), [
        'access_key' => $access, 'secret_key' => 'fake-r2-pasted-secret-for-tests',
    ]);
    $keys('fakepastedaccesskeyunknown')->assertSessionHasErrors(['secret_key' => 'Those keys were turned down. Check the access key and secret.']);
    expect(BackupDestination::query()->count())->toBe(0);

    $this->r2->pastedKey('fakepastedaccesskeyforbucket', 'serveros-acme-backups');
    $keys('fakepastedaccesskeyforbucket')->assertSessionHasNoErrors();

    $destination = BackupDestination::query()->sole();
    expect($bucket->fresh())->toMatchArray(['state' => 'ready', 'key_id' => null, 'backup_destination_id' => $destination->id, 'pending' => null])
        ->and($destination->settings['access_key'])->toBe('fakepastedaccesskeyforbucket')
        ->and($web->fresh()->backup_destination_id)->toBe($destination->id)
        ->and(ActivityLine::describe(Activity::query()->where('action', 'integration.storage_keys')->sole(), true)['verb'])->toBe('added an access key for the bucket');

    $page = $this->get($workspace->url('/integrations/'.$integration->id))->viewData('page')['props'];
    expect($page['disconnectChoices'])->toBe([]);
});

test('usage comes from the GraphQL storage dataset, with the jurisdiction in the bucket name, and the cost is an estimate', function () {
    config()->set('services.r2.price_per_gb_month', 0.015);
    $workspace = r2Workspace();
    $integration = r2Account($workspace);
    r2SetUp($workspace, $integration, ['jurisdiction' => 'eu'])->assertSessionHasNoErrors();
    $this->r2->storage['eu_serveros-acme-backups'] = ['objectCount' => 42, 'payloadSize' => 120_000_000_000, 'metadataSize' => 50_000];

    $props = r2AccountProps($workspace, $integration);

    expect($props['buckets'][0]['usage'])->toMatchArray(['bytes' => 120_000_050_000, 'files' => 42])
        ->and($props['buckets'][0]['cost'])->toBe('$1.80')
        ->and($props['price'])->toBe('$0.015 per GB a month')
        ->and(collect($props['options'])->pluck('key')->all())->toBe(['location', 'jurisdiction'])
        ->and($this->r2->bodyOf('POST', 'graphql')['variables'])->toMatchArray(['accountTag' => FakeR2::ACCOUNT, 'bucketName' => 'eu_serveros-acme-backups']);
});

test('a token without Account Analytics says how to see the size', function () {
    $workspace = r2Workspace();
    $this->r2->token(FakeR2::TOKEN, analytics: false);
    $integration = r2Account($workspace);
    r2SetUp($workspace, $integration)->assertSessionHasNoErrors();

    $bucket = r2AccountProps($workspace, $integration)['buckets'][0];

    expect($bucket['usage'])->toBeNull()
        ->and($bucket['usageError'])->toBe('Give the token Account › Account Analytics › Read to see how much is stored here.');
});

test('a bucket name already in the account gets a number, and a clash on create picks another', function () {
    $workspace = r2Workspace();
    $integration = r2Account($workspace);
    $this->r2->bucket('serveros-acme-backups');
    $this->r2->failNext('POST', 'accounts/'.FakeR2::ACCOUNT.'/r2/buckets', 409, FakeCloudflare::error(10004, 'The bucket you tried to create already exists, and you own it.'));

    r2SetUp($workspace, $integration)->assertSessionHasNoErrors();

    $names = collect($this->r2->calls)->filter(fn ($c) => $c['method'] === 'POST' && str_ends_with($c['path'], '/r2/buckets'))->pluck('body.name')->values();
    expect($names[0])->toBe('serveros-acme-backups-2')
        ->and($names[1])->toMatch('/^serveros-acme-backups-[a-z0-9]{5}$/')
        ->and(IntegrationBucket::query()->sole()->bucket)->toBe($names[1]);
});

test('disconnecting with the keys ticked deletes the account token ServerOS made and leaves the bucket', function () {
    $workspace = r2Workspace();
    $integration = r2Account($workspace);
    r2SetUp($workspace, $integration)->assertSessionHasNoErrors();
    $bucket = IntegrationBucket::query()->sole();

    $this->delete($workspace->url('/integrations/'.$integration->id), ['choices' => ['keys'], 'confirm' => 'Acme'])
        ->assertRedirect()
        ->assertInertiaFlash('toast.message', 'Disconnected Acme and deleted 1 key ServerOS made. The buckets and backups stay at Cloudflare R2.');

    expect($this->r2->called())->toContain('DELETE accounts/'.FakeR2::ACCOUNT.'/tokens/'.$bucket->key_id)
        ->and($this->r2->made)->toBe([])
        ->and($this->r2->buckets)->toHaveKey('default/serveros-acme-backups')
        ->and(BackupDestination::query()->count())->toBe(0);
});
