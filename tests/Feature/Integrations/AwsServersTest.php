<?php

use App\Enums\Plan;
use App\Jobs\AdvanceIntegrationOperation;
use App\Models\Activity;
use App\Models\DaemonEnrolment;
use App\Models\Integration;
use App\Models\IntegrationOperation;
use App\Models\IntegrationServer;
use App\Models\Machine;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Activity\ActivityLine;
use App\Support\Integrations\Aws\AwsProvider;
use App\Support\Integrations\IntegrationFailed;
use App\Support\Integrations\Servers\CloudServers;
use App\Support\Integrations\Servers\ServerOperations;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeAws;

const AWS_SIGV4_SHAPE = '#^AWS4-HMAC-SHA256 Credential=FAKEAWSKEYIDFORTESTS/\d{8}/[a-z0-9-]+/(ec2|sts|ssm|pricing)/aws4_request, SignedHeaders=(?=[a-z0-9;-]*\bhost\b)(?=[a-z0-9;-]*\bx-amz-date\b)content-type;[a-z0-9;-]+, Signature=[0-9a-f]{64}$#';

/** @param  array<string, mixed>  $settings */
function awsAccount(Workspace $workspace, string $region = 'eu-west-2', array $settings = []): Integration
{
    $credentials = ['access_key_id' => FakeAws::KEY_ID, 'secret_access_key' => FakeAws::SECRET, 'region' => $region];

    return $workspace->integrations()->create([
        'provider' => 'aws',
        'label' => 'AWS '.FakeAws::ACCOUNT,
        'credentials' => $credentials,
        'fingerprint' => Integration::fingerprintOf('aws', $credentials),
        'meta' => ['account' => FakeAws::ACCOUNT, 'arn' => FakeAws::ARN, 'region' => $region, 'servers' => 0],
        'settings' => $settings === [] ? null : $settings,
        'status' => Integration::CONNECTED,
        'last_checked_at' => now(),
    ]);
}

/** @param  array<string, string>  $credentials */
function awsConnect(Workspace $workspace, array $credentials = []): TestResponse
{
    return test()->actingAs($workspace->owner)
        ->from($workspace->url('/integrations'))
        ->post($workspace->url('/integrations/connect/aws'), ['credentials' => [
            'access_key_id' => FakeAws::KEY_ID,
            'secret_access_key' => FakeAws::SECRET,
            'region' => 'eu-west-2',
            ...$credentials,
        ]]);
}

/** @return array{0: Workspace, 1: Machine, 2: Integration, 3: FakeAws, 4: string} */
function awsLinkedMachine(): array
{
    $instance = FakeAws::instanceId(1);
    $aws = FakeAws::install()->instance('eu-west-2', $instance, 'web-1', '198.51.100.7', ['disk' => 40]);
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $machine = Machine::factory()->for($workspace)->create(['name' => 'web-1', 'ip_address' => '198.51.100.7']);
    $integration = awsAccount($workspace);
    app(CloudServers::class)->sync($integration);
    $aws->calls = [];

    return [$workspace, $machine, $integration, $aws, 'eu-west-2_'.$instance];
}

function awsUrl(Machine $machine, string $path): string
{
    return $machine->workspace->url("/machines/{$machine->id}/server{$path}");
}

beforeEach(function () {
    Sleep::fake();
});

test('connecting AWS checks the key with STS and EC2, names the account after it and links instances by public IP', function () {
    FakeAws::install()
        ->instance('eu-west-2', FakeAws::instanceId(1), 'web-1', '198.51.100.7', ['disk' => 40])
        ->instance('eu-west-2', FakeAws::instanceId(2), 'worker', null, ['instanceState' => ['code' => 80, 'name' => 'stopped'], 'instanceType' => 't4g.small'])
        ->instance('eu-west-2', FakeAws::instanceId(3), 'gone', '198.51.100.9', ['instanceState' => ['code' => 48, 'name' => 'terminated']])
        ->instance('us-east-1', FakeAws::instanceId(4), 'elsewhere', '198.51.100.10')
        ->elasticIp('eu-west-2', '198.51.100.7', FakeAws::instanceId(1));
    $workspace = Workspace::factory()->create();
    $web = Machine::factory()->for($workspace)->create(['name' => 'web-1', 'ip_address' => '198.51.100.7']);

    awsConnect($workspace)->assertSessionHasNoErrors()->assertRedirect();

    $integration = Integration::query()->sole();
    $rows = IntegrationServer::query()->get()->keyBy('server_id');
    expect($integration->label)->toBe('AWS 123456789012')
        ->and($integration->meta)->toBe(['account' => FakeAws::ACCOUNT, 'arn' => FakeAws::ARN, 'region' => 'eu-west-2', 'servers' => 2])
        ->and($integration->credential('secret_access_key'))->toBe(FakeAws::SECRET)
        ->and(DB::table('integrations')->value('credentials'))->not->toContain(FakeAws::SECRET)->not->toContain(FakeAws::KEY_ID)
        ->and($rows->keys()->sort()->values()->all())->toBe(['eu-west-2_'.FakeAws::instanceId(1), 'eu-west-2_'.FakeAws::instanceId(2)])
        ->and($rows['eu-west-2_'.FakeAws::instanceId(1)]->machine_id)->toBe($web->id)
        ->and($rows['eu-west-2_'.FakeAws::instanceId(1)]->details)->toMatchArray([
            'name' => 'web-1', 'status' => 'running', 'type' => 't3.small', 'cores' => 2, 'memoryGb' => 2, 'diskGb' => 40,
            'location' => 'eu-west-2', 'city' => 'London', 'country' => 'GB', 'ipv4' => '198.51.100.7', 'architecture' => 'x86', 'fixedIp' => true,
            'createdAt' => '2026-10-01T09:00:00.000Z',
        ])
        ->and($rows['eu-west-2_'.FakeAws::instanceId(2)]->details)->toMatchArray(['status' => 'off', 'ipv4' => null, 'architecture' => 'arm', 'fixedIp' => null]);
    Http::assertSentCount(count(Http::recorded()));
    Http::assertNotSent(fn (Request $request) => preg_match(AWS_SIGV4_SHAPE, (string) ($request->header('Authorization')[0] ?? '')) !== 1 || ! $request->hasHeader('X-Amz-Date'));
    Http::assertNotSent(fn (Request $request) => str_contains($request->body(), FakeAws::SECRET) || str_contains(implode(' ', $request->header('Authorization')), FakeAws::SECRET));
    expect(ActivityLine::sentence(Activity::query()->where('action', 'integration.connect')->sole()))->toBe($workspace->owner->name.' connected AWS 123456789012 (AWS)')
        ->and(Activity::query()->get()->toJson())->not->toContain(FakeAws::SECRET);

    $this->actingAs($workspace->owner)->get($workspace->url('/integrations/'.$integration->id))
        ->assertInertia(fn (Assert $page) => $page
            ->where('integration.facts', [
                ['label' => 'AWS account', 'value' => FakeAws::ACCOUNT],
                ['label' => 'IAM identity', 'value' => 'user/serveros'],
                ['label' => 'Default region', 'value' => 'London (eu-west-2)'],
                ['label' => 'Looks in', 'value' => 'London'],
                ['label' => 'Instances', 'value' => '2'],
            ])
            ->where('integration.summary', '2 instances')
            ->where('provider.fields.0.key', 'access_key_id')
            ->where('provider.fields.0.secret', false)
            ->where('provider.fields.0.snippetLabel', 'IAM policy')
            ->where('provider.fields.1.secret', true)
            ->where('provider.fields.2.value', 'us-east-1')
            ->where('provider.fields.2.options.0', ['value' => 'us-east-1', 'label' => 'N. Virginia (us-east-1)'])
            ->where('provider.capabilities.2.description', 'Stops a linked instance, changes its instance type and starts it again. Its EBS disk stays as it is.')
            ->missing('integration.credentials')
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('regions.list.0', ['name' => 'us-east-1', 'city' => 'N. Virginia', 'country' => 'US', 'zone' => 'North America', 'chosen' => false, 'default' => false, 'servers' => 0, 'linked' => []])
                ->where('regions.list.1', ['name' => 'eu-west-2', 'city' => 'London', 'country' => 'GB', 'zone' => 'Europe', 'chosen' => true, 'default' => true, 'servers' => 2, 'linked' => ['web-1']])
                ->where('regions.error', null)
                ->where('servers.list.0.name', 'web-1')));
    $this->actingAs($workspace->owner)->get($workspace->url("/machines/{$web->id}"))
        ->assertInertia(fn (Assert $page) => $page
            ->where('cloudServer.integration.name', 'AWS')
            ->where('cloudServer.server.fixedIp', true)
            ->where('cloudServer.rules.disk', 'kept')
            ->where('cloudServer.rules.powerCycleSteps', true)
            ->where('cloudServer.rules.stopChangesIp', true));
});

test('the connect form shows an IAM policy with exactly the actions ServerOS uses', function () {
    $policy = json_decode(AwsProvider::policy(), true);

    expect($policy['Statement'][0]['Action'])->toBe(AwsProvider::ACTIONS)
        ->and($policy['Statement'][1])->toBe(['Effect' => 'Allow', 'Action' => 'ssm:GetParameter', 'Resource' => 'arn:aws:ssm:*::parameter/aws/service/canonical/ubuntu/*'])
        ->and(AwsProvider::ACTIONS)->not->toContain('ec2:*')->toContain('ec2:RunInstances', 'ec2:ModifyInstanceAttribute', 'ec2:CreateSnapshot', 'pricing:GetProducts');
});

test('keys AWS turns down are never saved, and each reason lands on the field to fix', function () {
    $aws = FakeAws::install();
    $workspace = Workspace::factory()->create();

    awsConnect($workspace, ['access_key_id' => 'NOTAREALKEYIDATALL'])
        ->assertSessionHasErrors(['credentials.access_key_id' => 'AWS doesn’t know this access key. Check the access key ID is copied in full and the key is still active in IAM.']);
    awsConnect($workspace, ['secret_access_key' => 'not-the-right-secret-for-it'])
        ->assertSessionHasErrors(['credentials.secret_access_key' => 'AWS says the secret access key doesn’t match the access key ID. Copy the secret again.']);
    $aws->failNext('DescribeRegions', 403, 'UnauthorizedOperation', 'You are not authorized to perform this operation. User: '.FakeAws::ARN.' is not authorized to perform: ec2:DescribeRegions because no identity-based policy allows the ec2:DescribeRegions action.');
    awsConnect($workspace)
        ->assertSessionHasErrors(['credentials.secret_access_key' => 'This access key isn’t allowed to use ec2:DescribeRegions. Add it to the key’s IAM policy.']);
    awsConnect($workspace, ['region' => 'ap-southeast-2'])
        ->assertSessionHasErrors(['credentials.region' => 'Sydney (ap-southeast-2) isn’t turned on for this AWS account. Pick another, or turn it on under Account › AWS Regions.']);
    awsConnect($workspace, ['region' => 'mars-north-1'])->assertSessionHasErrors('credentials.region');

    expect(Integration::query()->count())->toBe(0)
        ->and(collect($aws->calls)->pluck('authorization')->implode(' '))->not->toContain(FakeAws::SECRET);
});

test('the regions ServerOS looks in are picked on the account page, and one with a linked machine can’t be dropped', function () {
    [$workspace, $machine, $integration, $aws] = awsLinkedMachine();
    $aws->instance('us-east-1', FakeAws::instanceId(7), 'api-1', '198.51.100.70');
    $api = Machine::factory()->for($workspace)->create(['name' => 'api-1', 'ip_address' => '198.51.100.70']);
    $url = $workspace->url("/integrations/{$integration->id}/regions");

    $this->actingAs($workspace->owner)->put($url, ['regions' => ['us-east-1', 'eu-west-2']])->assertSessionHasNoErrors()->assertRedirect();

    expect($integration->fresh()->settings)->toBe(['regions' => ['us-east-1']])
        ->and($integration->fresh()->provider()->chosenRegions($integration->fresh()))->toBe(['eu-west-2', 'us-east-1'])
        ->and(IntegrationServer::query()->where('server_id', 'us-east-1_'.FakeAws::instanceId(7))->value('machine_id'))->toBe($api->id)
        ->and(ActivityLine::sentence(Activity::query()->where('action', 'integration.regions')->sole()))->toBe($workspace->owner->name.' changed the regions ServerOS looks in for AWS 123456789012 to eu-west-2, us-east-1');

    $this->actingAs($workspace->owner)->put($url, ['regions' => ['eu-west-2', 'us-east-1']])->assertRedirect();
    $this->actingAs($workspace->owner)->put($url, ['regions' => ['eu-west-2']])
        ->assertSessionHasErrors(['regions' => 'Keep us-east-1: api-1 is linked to a server there.']);
    $this->actingAs($workspace->owner)->put($url, ['regions' => ['eu-west-2', 'sa-east-1']])
        ->assertSessionHasErrors(['regions' => 'Pick regions from the list.']);
    $this->actingAs($workspace->owner)->put($url, ['regions' => []])->assertSessionHasErrors('regions');

    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);
    $this->actingAs($viewer)->put($url, ['regions' => ['eu-west-2']])->assertForbidden();
    expect(Activity::query()->where('action', 'integration.regions')->count())->toBe(1);
});

test('making an instance resolves Ubuntu from SSM, opens a serveros security group once, and starts it with cloud-init that installs ServerOS', function () {
    config()->set('daemon.install_ready', true);
    Queue::fake([AdvanceIntegrationOperation::class]);
    $aws = FakeAws::install()
        ->keyPair('eu-west-2', 'sam@laptop', 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFakeLaptopKeyForTests sam@laptop')
        ->keyPair('us-east-1', 'ci', 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFakeCiKeyForTests ci');
    $aws->notInZone['eu-west-2b'] = ['t3.small'];
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $integration = awsAccount($workspace, settings: ['regions' => ['us-east-1']]);

    $plans = $this->actingAs($workspace->owner)->getJson($workspace->url("/integrations/{$integration->id}/server-options"))->assertOk();
    expect(collect($plans->json('locations'))->pluck('name')->all())->toBe(['eu-west-2', 'us-east-1'])
        ->and(collect($plans->json('types'))->pluck('name')->all())->toBe(['t3.micro', 't3.small', 't3.medium', 't4g.small', 'm7i.large', 'c7i.xlarge', 'm7g.large'])
        ->and($plans->json('types.1'))->toMatchArray(['name' => 't3.small', 'cores' => 2, 'memoryGb' => 2, 'diskGb' => 20, 'cpu' => 'shared', 'architecture' => 'x86', 'storage' => 'ebs', 'locations' => ['eu-west-2', 'us-east-1']])
        ->and($plans->json('types.1.prices.eu-west-2'))->toEqual(['monthly' => 15.18, 'hourly' => 0.0208])
        ->and($plans->json('types.6'))->toMatchArray(['name' => 'm7g.large', 'cpu' => 'dedicated', 'architecture' => 'arm'])
        ->and(collect($plans->json('images'))->map(fn ($i) => [$i['id'], $i['architecture'], $i['recommended']])->all())->toBe([['ubuntu-24.04-x86', 'x86', true], ['ubuntu-24.04-arm', 'arm', true]])
        ->and(collect($plans->json('keys'))->pluck('id')->all())->toBe(['ci', 'sam@laptop'])
        ->and($plans->json('currency'))->toBe('USD')
        ->and($plans->json('rules.stopChangesIp'))->toBeTrue()
        ->and($plans->json('rules.rootPassword'))->toBeFalse();

    $this->actingAs($workspace->owner)->postJson($workspace->url("/integrations/{$integration->id}/servers"), [
        'name' => 'web-3', 'location' => 'eu-west-2', 'type' => 't3.small', 'image' => 'ubuntu-24.04-x86', 'keys' => ['sam@laptop', 'ci'],
    ])->assertCreated();

    expect($aws->paramsOf('GetParameter'))->toBe(['Name' => '/aws/service/canonical/ubuntu/server/24.04/stable/current/amd64/hvm/ebs-gp3/ami-id'])
        ->and($aws->paramsOf('CreateSecurityGroup'))->toMatchArray(['GroupName' => 'serveros', 'VpcId' => 'vpc-0'.substr(md5('eu-west-2'), 0, 12), 'TagSpecification.1.ResourceType' => 'security-group'])
        ->and(collect($aws->securityGroups['eu-west-2'][0]['ipPermissions'])->map(fn ($p) => [$p['fromPort'], $p['ipRanges'][0]['cidrIp'], $p['ipv6Ranges'][0]['cidrIpv6']])->all())->toBe([[22, '0.0.0.0/0', '::/0'], [80, '0.0.0.0/0', '::/0'], [443, '0.0.0.0/0', '::/0']]);
    $run = $aws->paramsOf('RunInstances');
    expect($run)->toMatchArray([
        'ImageId' => FakeAws::ami('eu-west-2', 'amd64'), 'InstanceType' => 't3.small', 'MinCount' => '1', 'MaxCount' => '1', 'KeyName' => 'sam@laptop',
        'NetworkInterface.1.DeviceIndex' => '0', 'NetworkInterface.1.SubnetId' => 'subnet-eu-west-2a', 'NetworkInterface.1.AssociatePublicIpAddress' => 'true',
        'NetworkInterface.1.SecurityGroupId.1' => $aws->securityGroups['eu-west-2'][0]['groupId'],
        'BlockDeviceMapping.1.DeviceName' => '/dev/sda1', 'BlockDeviceMapping.1.Ebs.VolumeSize' => '20', 'BlockDeviceMapping.1.Ebs.VolumeType' => 'gp3', 'BlockDeviceMapping.1.Ebs.DeleteOnTermination' => 'true',
        'MetadataOptions.HttpTokens' => 'required', 'TagSpecification.1.ResourceType' => 'instance', 'TagSpecification.1.Tag.1.Key' => 'Name', 'TagSpecification.1.Tag.1.Value' => 'web-3',
        'TagSpecification.1.Tag.2.Key' => 'ManagedBy', 'TagSpecification.1.Tag.2.Value' => 'ServerOS', 'TagSpecification.2.ResourceType' => 'volume',
    ])->and($run['ClientToken'])->toMatch('/^[0-9a-f-]{36}$/');
    $userData = (string) base64_decode((string) $run['UserData'], true);
    expect($userData)->toStartWith("#cloud-config\nssh_authorized_keys:\n  - \"ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFakeCiKeyForTests ci\"\n")
        ->toContain("hostname: web-3\n")->toContain('get.docker.com')->not->toContain('FakeLaptopKey');
    preg_match('/--token=\x27([a-f0-9]{64})\x27/', $userData, $match);
    $enrolment = DaemonEnrolment::query()->sole();
    expect(hash('sha256', $match[1] ?? ''))->toBe($enrolment->token_hash);

    $operation = IntegrationOperation::query()->sole();
    $row = IntegrationServer::query()->sole();
    expect($operation->only(['kind', 'step', 'action_id']))->toBe(['kind' => 'create', 'step' => 'boot', 'action_id' => null])
        ->and($row->server_id)->toStartWith('eu-west-2_i-')
        ->and($row->details)->toMatchArray(['status' => 'starting', 'ipv4' => null, 'diskGb' => 20, 'cores' => 2])
        ->and(ActivityLine::sentence(Activity::query()->where('action', 'machine.server_create')->sole()))->toBe($workspace->owner->name.' made the server web-3 at AWS (t3.small in London)');

    $operations = app(ServerOperations::class);
    expect($operations->advance($operation))->toBe(5)
        ->and($operations->advance($operation))->toBe(20)
        ->and($operation->fresh()->step)->toBe('install')
        ->and($row->fresh()->details['ipv4'])->toStartWith('203.0.113.')
        ->and($row->fresh()->details['status'])->toBe('running');

    $machine = Machine::factory()->for($workspace)->create(['name' => 'web-3']);
    app(CloudServers::class)->claim($enrolment, $machine);
    expect($row->fresh()->machine_id)->toBe($machine->id)
        ->and($operation->fresh()->state)->toBe('done');

    $aws->calls = [];
    $this->actingAs($workspace->owner)->postJson($workspace->url("/integrations/{$integration->id}/servers"), [
        'name' => 'arm-1', 'location' => 'eu-west-2', 'type' => 't4g.small', 'image' => 'ubuntu-24.04-arm',
    ])->assertCreated();
    expect($aws->called('ec2'))->not->toContain('eu-west-2 CreateSecurityGroup')->not->toContain('eu-west-2 AuthorizeSecurityGroupIngress')
        ->and($aws->paramsOf('GetParameter')['Name'])->toContain('/arm64/')
        ->and($aws->paramsOf('RunInstances'))->toMatchArray(['ImageId' => FakeAws::ami('eu-west-2', 'arm64'), 'NetworkInterface.1.SubnetId' => 'subnet-eu-west-2a'])
        ->and($aws->paramsOf('RunInstances'))->not->toHaveKey('KeyName');
});

test('a region without a default VPC says so, gives the join token back and makes nothing', function () {
    config()->set('daemon.install_ready', true);
    Queue::fake([AdvanceIntegrationOperation::class]);
    $aws = FakeAws::install();
    $aws->defaultVpc = false;
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $integration = awsAccount($workspace);

    $this->actingAs($workspace->owner)->postJson($workspace->url("/integrations/{$integration->id}/servers"), [
        'name' => 'web-3', 'location' => 'eu-west-2', 'type' => 't3.small', 'image' => 'ubuntu-24.04-x86',
    ])->assertJsonValidationErrors(['form' => 'London (eu-west-2) has no default VPC, so ServerOS has nowhere to put the instance. Make one in the VPC console (Actions › Create default VPC), or pick another region.']);

    expect($aws->called('ec2'))->not->toContain('eu-west-2 RunInstances')
        ->and(IntegrationOperation::query()->count())->toBe(0)
        ->and(DaemonEnrolment::query()->sole()->expires_at->isPast())->toBeTrue();
});

test('without pricing permission there are no prices, and the types still show', function () {
    $aws = FakeAws::install();
    $aws->pricingAllowed = false;
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $integration = awsAccount($workspace);

    $plans = $this->actingAs($workspace->owner)->getJson($workspace->url("/integrations/{$integration->id}/server-options"))->assertOk();

    expect($plans->json('types'))->toHaveCount(7)
        ->and(collect($plans->json('types'))->pluck('prices')->filter()->all())->toBe([])
        ->and($aws->called('pricing'))->toBe(['us-east-1 GetProducts']);
});

test('resizing stops the instance, changes its type to one of the same architecture offered in its zone and starts it again', function () {
    [$workspace, $machine, $integration, $aws, $id] = awsLinkedMachine();
    $instance = FakeAws::instanceId(1);
    $aws->notInZone['eu-west-2a'] = ['c7i.xlarge'];

    $types = $this->actingAs($workspace->owner)->getJson(awsUrl($machine, '/types'))->assertOk();
    expect(collect($types->json('types'))->pluck('name')->all())->toBe(['t3.micro', 't3.medium', 'm7i.large'])
        ->and($types->json('types.0'))->toMatchArray(['diskGb' => 40, 'monthly' => 7.59, 'architecture' => 'x86'])
        ->and($types->json('current'))->toMatchArray(['name' => 't3.small', 'diskGb' => 40])
        ->and($types->json('needsPowerOff'))->toBeTrue()
        ->and($types->json('rules'))->toMatchArray(['disk' => 'kept', 'down' => true, 'stopChangesIp' => true])
        ->and($aws->paramsOf('DescribeInstanceTypeOfferings'))->toMatchArray(['LocationType' => 'availability-zone', 'Filter.1.Name' => 'location', 'Filter.1.Value.1' => 'eu-west-2a']);

    $this->actingAs($workspace->owner)->postJson(awsUrl($machine, '/resize'), ['type' => 'm7g.large'])
        ->assertUnprocessable()->assertJsonValidationErrors('type');

    $aws->calls = [];
    $this->actingAs($workspace->owner)->postJson(awsUrl($machine, '/resize'), ['type' => 'm7i.large'])->assertCreated();

    $operation = IntegrationOperation::query()->sole();
    $operations = app(ServerOperations::class);
    for ($i = 0; $i < 10 && $operation->fresh()->active(); $i++) {
        $operations->advance($operation->fresh());
    }

    $operation->refresh();
    expect($operation->state)->toBe('done')
        ->and(collect($aws->called('ec2'))->filter(fn ($c) => ! str_contains($c, 'Describe'))->values()->all())->toBe(['eu-west-2 StopInstances', 'eu-west-2 ModifyInstanceAttribute', 'eu-west-2 StartInstances'])
        ->and($aws->paramsOf('StopInstances'))->toMatchArray(['InstanceId.1' => $instance])->not->toHaveKey('Force')
        ->and($aws->paramsOf('ModifyInstanceAttribute'))->toMatchArray(['InstanceId' => $instance, 'InstanceType.Value' => 'm7i.large'])
        ->and($aws->instances['eu-west-2'][$instance]['instanceType'])->toBe('m7i.large')
        ->and($machine->cloudServer->details)->toMatchArray(['type' => 'm7i.large', 'memoryGb' => 8, 'diskGb' => 40, 'status' => 'running']);
});

test('when the type can’t be changed, the instance is started again and the reason is kept', function () {
    [$workspace, $machine, $integration, $aws, $id] = awsLinkedMachine();
    $aws->failNext('ModifyInstanceAttribute', 400, 'Unsupported', 'The instance configuration for this AWS Marketplace product is not supported.');

    $this->actingAs($workspace->owner)->postJson(awsUrl($machine, '/resize'), ['type' => 't3.medium'])->assertCreated();
    $operation = IntegrationOperation::query()->sole();
    $operations = app(ServerOperations::class);
    for ($i = 0; $i < 10 && $operation->fresh()->active(); $i++) {
        $operations->advance($operation->fresh());
    }

    expect($operation->fresh()->state)->toBe('failed')
        ->and($operation->fresh()->error)->toBe('The instance configuration for this AWS Marketplace product is not supported. ServerOS turned it back on.')
        ->and($aws->called('ec2'))->toContain('eu-west-2 StartInstances')
        ->and($aws->instances['eu-west-2'][FakeAws::instanceId(1)]['instanceType'])->toBe('t3.small');
});

test('restart reboots the instance, and power cycle stops it hard and starts it once it’s off', function () {
    [$workspace, $machine, $integration, $aws, $id] = awsLinkedMachine();
    Queue::fake([AdvanceIntegrationOperation::class]);
    $instance = FakeAws::instanceId(1);

    $this->actingAs($workspace->owner)->postJson(awsUrl($machine, '/reboot'))->assertOk()
        ->assertJson(['message' => 'Restarting web-1 through AWS.']);
    expect($aws->paramsOf('RebootInstances'))->toMatchArray(['InstanceId.1' => $instance]);

    $cycle = $this->actingAs($workspace->owner)->postJson(awsUrl($machine, '/power-cycle'))->assertOk()
        ->assertJson(['message' => 'Power cycling web-1 through AWS. It turns off and starts again, which takes a few minutes.']);
    expect($cycle->json('operation.kind'))->toBe('power_cycle')
        ->and(collect($cycle->json('operation.steps'))->pluck('key')->all())->toBe(['stop', 'start']);
    $this->actingAs($workspace->owner)->postJson(awsUrl($machine, '/power-cycle'))
        ->assertJsonValidationErrors(['form' => 'Something else is changing this server. Wait for it to finish.']);

    $operation = IntegrationOperation::query()->sole();
    $operations = app(ServerOperations::class);
    for ($i = 0; $i < 10 && $operation->fresh()->active(); $i++) {
        $operations->advance($operation->fresh());
    }

    expect($operation->fresh()->state)->toBe('done')
        ->and($aws->paramsOf('StopInstances'))->toMatchArray(['InstanceId.1' => $instance, 'Force' => 'true'])
        ->and($aws->called('ec2'))->toContain('eu-west-2 StartInstances')
        ->and($aws->instances['eu-west-2'][$instance]['instanceState']['name'])->toBe('running')
        ->and(Activity::query()->orderBy('id')->pluck('action')->all())->toBe(['machine.server_reboot', 'machine.server_power_cycle'])
        ->and(ActivityLine::sentence(Activity::query()->where('action', 'machine.server_power_cycle')->sole()))->toBe($workspace->owner->name.' power cycled web-1 through AWS');

    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);
    $this->actingAs($viewer)->postJson(awsUrl($machine, '/power-cycle'))->assertForbidden();
});

test('a stop without an Elastic IP gives the instance a new public address, and the machine stays linked', function () {
    $instance = FakeAws::instanceId(1);
    $aws = FakeAws::install()->instance('eu-west-2', $instance, 'web-1', '198.51.100.7');
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $machine = Machine::factory()->for($workspace)->create(['name' => 'web-1', 'ip_address' => '198.51.100.7']);
    $integration = awsAccount($workspace);
    app(CloudServers::class)->sync($integration);
    expect($machine->cloudServer->details['fixedIp'])->toBeFalse();

    Queue::fake([AdvanceIntegrationOperation::class]);
    $this->actingAs($workspace->owner)->postJson(awsUrl($machine, '/power-cycle'))->assertOk();
    $operation = IntegrationOperation::query()->sole();
    for ($i = 0; $i < 10 && $operation->fresh()->active(); $i++) {
        app(ServerOperations::class)->advance($operation->fresh());
    }

    expect($machine->fresh()->cloudServer->details['ipv4'])->not->toBe('198.51.100.7')->toStartWith('198.51.100.')
        ->and($machine->fresh()->cloudServer->machine_id)->toBe($machine->id);
});

test('snapshots are EBS snapshots of the root volume, newest first, taken without stopping it and deleted once ready', function () {
    [$workspace, $machine, $integration, $aws, $id] = awsLinkedMachine();
    $volume = $aws->volumeOf('eu-west-2', FakeAws::instanceId(1));
    $aws->snapshots['eu-west-2']['snap-0older'] = FakeAws::snapshotOf('snap-0older', $volume, 'before upgrade', started: '2026-11-02T09:00:00.000Z', size: 40);
    $aws->snapshots['eu-west-2']['snap-0newer'] = FakeAws::snapshotOf('snap-0newer', $volume, 'nightly', started: '2026-11-20T09:00:00.000Z', size: 40, tags: [['key' => 'Name', 'value' => 'db-1 nightly']]);
    $aws->snapshots['eu-west-2']['snap-0other'] = FakeAws::snapshotOf('snap-0other', 'vol-0someoneelse', 'not this one');

    $this->actingAs($workspace->owner)->getJson(awsUrl($machine, '/snapshots'))->assertOk()
        ->assertJsonCount(2, 'snapshots')
        ->assertJsonPath('snapshots.0', ['id' => 'eu-west-2_snap-0newer', 'name' => 'db-1 nightly', 'status' => 'available', 'sizeGb' => null, 'diskGb' => 40, 'createdAt' => '2026-11-20T09:00:00.000Z'])
        ->assertJsonPath('snapshots.1.id', 'eu-west-2_snap-0older');
    expect($aws->paramsOf('DescribeSnapshots'))->toMatchArray(['Owner.1' => 'self', 'Filter.1.Name' => 'volume-id', 'Filter.1.Value.1' => $volume]);

    $taken = $this->actingAs($workspace->owner)->postJson(awsUrl($machine, '/snapshots'), ['name' => 'before resize'])->assertCreated();
    expect($taken->json('snapshot.status'))->toBe('creating')
        ->and($aws->paramsOf('CreateSnapshot'))->toMatchArray(['VolumeId' => $volume, 'Description' => 'before resize', 'TagSpecification.1.ResourceType' => 'snapshot', 'TagSpecification.1.Tag.1.Value' => 'before resize'])
        ->and($aws->called('ec2'))->not->toContain('eu-west-2 StopInstances');

    $this->actingAs($workspace->owner)->deleteJson(awsUrl($machine, '/snapshots/'.$taken->json('snapshot.id')))
        ->assertJsonValidationErrors(['form' => 'It’s still being taken. Delete it once it’s ready.']);
    $aws->failNext('DeleteSnapshot', 400, 'InvalidSnapshot.InUse', 'The snapshot snap-0older is currently in use by ami-0fake');
    $this->actingAs($workspace->owner)->deleteJson(awsUrl($machine, '/snapshots/eu-west-2_snap-0older'))
        ->assertJsonValidationErrors(['form' => 'An AMI uses this snapshot, so AWS won’t delete it. Deregister the AMI first.']);
    $this->actingAs($workspace->owner)->deleteJson(awsUrl($machine, '/snapshots/eu-west-2_snap-0older'))->assertOk();

    expect($aws->paramsOf('DeleteSnapshot'))->toMatchArray(['SnapshotId' => 'snap-0older'])
        ->and(array_keys($aws->snapshots['eu-west-2']))->not->toContain('snap-0older')
        ->and(Activity::query()->orderBy('id')->pluck('action')->all())->toBe(['machine.snapshot_create', 'machine.snapshot_delete']);
});

test('deleting the server terminates the instance and can keep the machine', function () {
    [$workspace, $machine, $integration, $aws, $id] = awsLinkedMachine();

    $this->actingAs($workspace->owner)->delete(awsUrl($machine, ''), ['confirm' => 'web-2'])->assertSessionHasErrors('confirm');
    $this->actingAs($workspace->owner)->delete(awsUrl($machine, ''), ['confirm' => 'web-1', 'keep' => true])->assertRedirect();

    expect($aws->paramsOf('TerminateInstances'))->toMatchArray(['InstanceId.1' => FakeAws::instanceId(1)])
        ->and($aws->instances['eu-west-2'][FakeAws::instanceId(1)]['instanceState']['name'])->toBe('shutting-down')
        ->and($machine->fresh())->not->toBeNull()
        ->and(IntegrationServer::query()->count())->toBe(0)
        ->and(ActivityLine::sentence(Activity::query()->where('action', 'machine.server_delete')->sole()))->toBe($workspace->owner->name.' deleted the server of web-1 at AWS, and kept the machine');
});

test('throttled AWS calls are tried again, reads are tried again when EC2 is down, but a reboot is never sent twice', function () {
    [$workspace, $machine, $integration, $aws, $id] = awsLinkedMachine();

    $aws->failNext('DescribeInstances', 503, 'RequestLimitExceeded', 'Request limit exceeded.');
    $aws->failNext('DescribeInstances', 500, 'InternalError', 'An internal error has occurred.');
    expect(app(CloudServers::class)->sync($integration)->count())->toBe(1)
        ->and(collect($aws->called('ec2'))->filter(fn ($c) => $c === 'eu-west-2 DescribeInstances')->count())->toBe(3);

    $aws->calls = [];
    $aws->failNext('RebootInstances', 500, 'InternalError', 'An internal error has occurred.');
    $this->actingAs($workspace->owner)->postJson(awsUrl($machine, '/reboot'))
        ->assertJsonValidationErrors(['form' => 'AWS isn’t answering properly right now. Try again in a few minutes.']);
    expect($aws->called('ec2'))->toBe(['eu-west-2 RebootInstances']);

    $aws->calls = [];
    $aws->failNext('RebootInstances', 503, 'RequestLimitExceeded', 'Request limit exceeded.');
    $this->actingAs($workspace->owner)->postJson(awsUrl($machine, '/reboot'))->assertOk();
    expect($aws->called('ec2'))->toBe(['eu-west-2 RebootInstances', 'eu-west-2 RebootInstances']);
});

test('a key that loses EC2 access marks the account failing, and the error names what’s missing', function () {
    [$workspace, $machine, $integration, $aws] = awsLinkedMachine();
    $aws->failNext('DescribeInstances', 403, 'UnauthorizedOperation', 'You are not authorized to perform this operation. User: '.FakeAws::ARN.' is not authorized to perform: ec2:DescribeInstances because no identity-based policy allows the ec2:DescribeInstances action.');

    expect(fn () => app(CloudServers::class)->sync($integration))->toThrow(IntegrationFailed::class, 'This access key isn’t allowed to use ec2:DescribeInstances. Add it to the key’s IAM policy.');
    expect($integration->fresh()->status)->toBe(Integration::FAILING)
        ->and($machine->cloudServer)->not->toBeNull();
});
