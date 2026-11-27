<?php

namespace App\Support\Integrations\Aws;

use App\Models\Integration;
use App\Support\Integrations\Capability;
use App\Support\Integrations\ConnectedAccount;
use App\Support\Integrations\ConnectField;
use App\Support\Integrations\IntegrationFailed;
use App\Support\Integrations\Provider;
use App\Support\Integrations\Servers\ActionState;
use App\Support\Integrations\Servers\ChoosesRegions;
use App\Support\Integrations\Servers\CloudInit;
use App\Support\Integrations\Servers\ManagesServers;
use App\Support\Integrations\Servers\NewServer;
use App\Support\Integrations\Servers\Server;
use App\Support\Integrations\Servers\ServerImage;
use App\Support\Integrations\Servers\ServerPlans;
use App\Support\Integrations\Servers\ServerRules;
use App\Support\Integrations\Servers\ServerType;
use App\Support\Integrations\Servers\Snapshot;
use App\Support\Integrations\Servers\SshKey;
use App\Support\Integrations\Servers\StartedServer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use LogicException;

/**
 * Amazon EC2. An IAM access key reaches every region, so servers are listed
 * in the regions picked on the account page. Ids carry their region
 * ("eu-west-2_i-0abc…", "eu-west-2_snap-0abc…") because every EC2 call is
 * made to one region. EC2 has no action objects, so changes are followed by
 * watching the instance, and snapshots are EBS snapshots of the root volume.
 */
final class AwsProvider extends Provider implements ChoosesRegions, ManagesServers
{
    public const string SECURITY_GROUP = 'serveros';

    public const string TAG = 'ManagedBy';

    public const string TAG_VALUE = 'ServerOS';

    public const int DISK_GB = 20;

    /** The instance families ServerOS offers: burstable, then general purpose, compute and memory, Intel, AMD and Graviton. */
    public const array FAMILIES = ['t3', 't3a', 't4g', 'm7i', 'm7i-flex', 'm7a', 'm7g', 'c7i', 'c7g', 'r7i', 'r7g'];

    private const int LARGEST = 64;

    /** @var array<string, string> Canonical's public SSM parameters for the current Ubuntu 24.04 LTS AMI, by architecture. */
    public const array UBUNTU = [
        'x86' => '/aws/service/canonical/ubuntu/server/24.04/stable/current/amd64/hvm/ebs-gp3/ami-id',
        'arm' => '/aws/service/canonical/ubuntu/server/24.04/stable/current/arm64/hvm/ebs-gp3/ami-id',
    ];

    private const array LISTED_STATES = ['pending', 'running', 'shutting-down', 'stopping', 'stopped'];

    /** The EC2 and SSM actions ServerOS uses, for the policy on the connect form. */
    public const array ACTIONS = [
        'ec2:DescribeRegions',
        'ec2:DescribeInstances',
        'ec2:DescribeInstanceTypes',
        'ec2:DescribeInstanceTypeOfferings',
        'ec2:DescribeVolumes',
        'ec2:DescribeAddresses',
        'ec2:DescribeKeyPairs',
        'ec2:DescribeImages',
        'ec2:DescribeVpcs',
        'ec2:DescribeSubnets',
        'ec2:DescribeSecurityGroups',
        'ec2:CreateSecurityGroup',
        'ec2:AuthorizeSecurityGroupIngress',
        'ec2:RunInstances',
        'ec2:CreateTags',
        'ec2:StartInstances',
        'ec2:StopInstances',
        'ec2:RebootInstances',
        'ec2:ModifyInstanceAttribute',
        'ec2:TerminateInstances',
        'ec2:DescribeSnapshots',
        'ec2:CreateSnapshot',
        'ec2:DeleteSnapshot',
        'pricing:GetProducts',
    ];

    public function key(): string
    {
        return 'aws';
    }

    public function name(): string
    {
        return 'AWS';
    }

    public function fields(): array
    {
        return [
            new ConnectField(
                key: 'access_key_id',
                label: self::t('Access key ID'),
                secret: false,
                help: self::t('In the IAM console, add a user for ServerOS with this policy as its only permissions, then make an access key for it under Security credentials (an application running outside AWS).'),
                link: 'https://console.aws.amazon.com/iam/home#/users',
                linkLabel: self::t('Open IAM'),
                placeholder: 'AKIA…',
                rules: ['required', 'string', 'min:16', 'max:128', 'regex:/^[A-Z0-9]+$/'],
                snippet: self::policy(),
                snippetLabel: self::t('IAM policy'),
            ),
            new ConnectField(
                key: 'secret_access_key',
                label: self::t('Secret access key'),
                placeholder: self::t('Paste the secret'),
                rules: ['required', 'string', 'min:16', 'max:128', 'regex:/^\S+$/'],
            ),
            new ConnectField(
                key: 'region',
                label: self::t('Default region'),
                secret: false,
                help: self::t('New instances go here first, and ServerOS looks for instances here. Add more regions on the account page.'),
                rules: ['required', 'string', 'in:'.implode(',', AwsRegions::codes())],
                value: 'us-east-1',
                options: AwsRegions::options(),
            ),
        ];
    }

    public static function policy(): string
    {
        $json = (string) json_encode([
            'Version' => '2012-10-17',
            'Statement' => [
                ['Effect' => 'Allow', 'Action' => self::ACTIONS, 'Resource' => '*'],
                ['Effect' => 'Allow', 'Action' => 'ssm:GetParameter', 'Resource' => 'arn:aws:ssm:*::parameter/aws/service/canonical/ubuntu/*'],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return (string) preg_replace_callback('/^( {4})+/m', fn (array $m) => str_repeat(' ', intdiv(strlen($m[0]), 2)), $json);
    }

    public function capabilities(): array
    {
        return [Capability::Servers, Capability::CreateServers, Capability::Resize, Capability::Power, Capability::Snapshots, Capability::DeleteServers];
    }

    public function describe(Capability $capability): string
    {
        return match ($capability) {
            Capability::Servers => self::t('Matches EC2 instances in the regions you pick to your machines by public IP address, and lists the ones that aren’t in ServerOS yet.'),
            Capability::CreateServers => self::t('Makes an EC2 instance from the latest Ubuntu 24.04 LTS, with a public IP address and a “serveros” security group for SSH, HTTP and HTTPS. It installs ServerOS as it starts, so it joins on its own.'),
            Capability::Resize => self::t('Stops a linked instance, changes its instance type and starts it again. Its EBS disk stays as it is.'),
            Capability::Power => self::t('Restarts a linked instance, or stops it and starts it again, even when its daemon is offline.'),
            Capability::Snapshots => self::t('Takes, lists and deletes EBS snapshots of a linked instance’s root volume, without stopping it.'),
            Capability::DeleteServers => self::t('Terminates a linked instance for good. Volumes set to stay after it ends, and Elastic IP addresses, stay in the account.'),
            default => parent::describe($capability),
        };
    }

    public function verify(array $credentials): ConnectedAccount
    {
        $region = $credentials['region'] ?? '';
        if (! AwsRegions::valid($region)) {
            throw new IntegrationFailed(self::t('Pick a region from the list.'), IntegrationFailed::INVALID, null, 'region');
        }
        $client = new AwsClient($credentials['access_key_id'] ?? '', $credentials['secret_access_key'] ?? '');
        $who = (array) ($client->sts($region, 'GetCallerIdentity')['GetCallerIdentityResult'] ?? []);
        $enabled = self::enabledRegions($client, $region);
        if (! in_array($region, $enabled, true)) {
            throw new IntegrationFailed(self::t(':region isn’t turned on for this AWS account. Pick another, or turn it on under Account › AWS Regions.', ['region' => AwsRegions::label($region)]), IntegrationFailed::INVALID, null, 'region');
        }
        $account = is_string($who['Account'] ?? null) ? $who['Account'] : '';

        return new ConnectedAccount($account === '' ? 'AWS' : 'AWS '.$account, [
            'account' => $account === '' ? null : $account,
            'arn' => is_string($who['Arn'] ?? null) && $who['Arn'] !== '' ? $who['Arn'] : null,
            'region' => $region,
        ]);
    }

    public function summary(Integration $integration): string
    {
        $count = (int) ($integration->meta['servers'] ?? 0);

        return $count === 1 ? self::t('1 instance') : self::t(':count instances', ['count' => $count]);
    }

    public function facts(Integration $integration): array
    {
        $meta = $integration->meta ?? [];
        $regions = array_map(fn (string $code) => AwsRegions::location($code)->city, $this->chosenRegions($integration));

        return array_values(array_filter([
            is_string($meta['account'] ?? null) ? ['label' => self::t('AWS account'), 'value' => $meta['account']] : null,
            is_string($meta['arn'] ?? null) ? ['label' => self::t('IAM identity'), 'value' => (string) preg_replace('/^arn:[^:]+:[^:]+::\d+:/', '', $meta['arn'])] : null,
            ['label' => self::t('Default region'), 'value' => AwsRegions::label($this->defaultRegion($integration))],
            ['label' => self::t('Looks in'), 'value' => implode(', ', $regions)],
            ['label' => self::t('Instances'), 'value' => (string) (int) ($meta['servers'] ?? 0)],
        ]));
    }

    public function regions(Integration $integration): array
    {
        $codes = Cache::remember("integrations:{$integration->id}:aws:regions", now()->addDay(), fn (): array => self::enabledRegions($this->client($integration), $this->defaultRegion($integration)));
        $codes = array_values(array_filter((array) $codes, 'is_string'));

        return array_map(fn (string $code) => AwsRegions::location($code), AwsRegions::sort($codes));
    }

    public function chosenRegions(Integration $integration): array
    {
        $default = $this->defaultRegion($integration);
        $picked = array_filter(array_map('strval', (array) $integration->setting('regions', [])), fn (string $code) => AwsRegions::valid($code) && $code !== $default);

        return [$default, ...AwsRegions::sort(array_values($picked))];
    }

    public function defaultRegion(Integration $integration): string
    {
        $region = $integration->credential('region');

        return AwsRegions::valid($region) ? $region : 'us-east-1';
    }

    public function servers(Integration $integration): array
    {
        $client = $this->client($integration);
        $servers = [];
        foreach ($this->chosenRegions($integration) as $region) {
            $instances = [];
            foreach ($client->ec2All($region, 'DescribeInstances', AwsClient::filters(['instance-state-name' => self::LISTED_STATES]), 'reservationSet') as $reservation) {
                array_push($instances, ...AwsClient::items($reservation['instancesSet'] ?? null));
            }
            array_push($servers, ...$this->toServers($client, $region, $instances, $this->elasticIps($client, $region)));
        }

        return $servers;
    }

    public function server(Integration $integration, string $id): ?Server
    {
        [$region, $instanceId] = self::split($id);
        $client = $this->client($integration);
        $instance = $this->instance($client, $region, $instanceId);
        if ($instance === null) {
            return null;
        }
        $fixed = AwsClient::items($client->ec2($region, 'DescribeAddresses', AwsClient::filters(['instance-id' => $instanceId]))['addressesSet'] ?? null);

        return $this->toServers($client, $region, [$instance], array_values(array_filter(array_map(fn (array $a) => $a['publicIp'] ?? null, $fixed), 'is_string')))[0] ?? null;
    }

    public function plans(Integration $integration): ServerPlans
    {
        $regions = $this->chosenRegions($integration);

        return ServerPlans::fromArray(Cache::remember("integrations:{$integration->id}:plans:".implode(',', $regions), now()->addMinutes(10), function () use ($integration, $regions): array {
            $client = $this->client($integration);
            $types = [];
            $keys = [];
            foreach ($regions as $region) {
                $here = $this->regionTypes($integration, $client, $region);
                $prices = AwsPrices::in($client, $region, array_keys($here));
                foreach ($here as $name => $row) {
                    $types[$name] ??= [...$row, 'locations' => [], 'prices' => []];
                    $types[$name]['locations'][] = $region;
                    if (isset($prices[$name])) {
                        $types[$name]['prices'][$region] = $prices[$name];
                    }
                }
                foreach ($this->keyPairs($client, $region) as $pair) {
                    $keys[$pair['name']] ??= new SshKey($pair['name'], $pair['name'], $pair['fingerprint']);
                }
            }

            $made = array_map(fn (array $row) => self::type($row, self::DISK_GB), array_values($types));
            usort($made, fn (ServerType $a, ServerType $b) => [$a->cpu !== 'shared', $a->architecture !== 'x86', $a->cores, $a->memoryGb, $a->name] <=> [$b->cpu !== 'shared', $b->architecture !== 'x86', $b->cores, $b->memoryGb, $b->name]);
            ksort($keys, SORT_NATURAL | SORT_FLAG_CASE);

            return (new ServerPlans(
                array_map(fn (string $code) => AwsRegions::location($code), $regions),
                $made,
                [
                    new ServerImage('ubuntu-24.04-x86', 'Ubuntu 24.04 LTS', 'ubuntu', '24.04', 'x86', true),
                    new ServerImage('ubuntu-24.04-arm', 'Ubuntu 24.04 LTS', 'ubuntu', '24.04', 'arm', true),
                ],
                array_values($keys),
                'USD',
            ))->toArray();
        }));
    }

    public function create(Integration $integration, NewServer $server, string $userData): StartedServer
    {
        $region = $server->location;
        if (! in_array($region, $this->chosenRegions($integration), true)) {
            throw new IntegrationFailed(self::t('Pick one of the regions ServerOS looks in.'));
        }
        $type = $this->plans($integration)->type($server->type) ?? throw new IntegrationFailed(self::t('That instance type can’t be made here. Pick another.'));
        $client = $this->client($integration);

        $parameter = $client->ssm($region, 'GetParameter', ['Name' => self::UBUNTU[$type->architecture] ?? self::UBUNTU['x86']]);
        $ami = is_string($parameter['Parameter']['Value'] ?? null) ? $parameter['Parameter']['Value'] : '';
        if (! str_starts_with($ami, 'ami-')) {
            throw new IntegrationFailed(self::t('AWS didn’t say which Ubuntu image to use in :region. Try again in a minute.', ['region' => $region]));
        }
        $image = AwsClient::items($client->ec2($region, 'DescribeImages', ['ImageId' => [$ami]])['imagesSet'] ?? null)[0] ?? [];
        $vpc = $this->defaultVpc($client, $region);
        $subnet = $this->subnetFor($client, $region, $vpc, $type->name);
        $group = $this->securityGroup($client, $region, $vpc);
        [$keyName, $publicKeys] = $this->keysFor($integration, $client, $region, $server->keys);

        $tags = [['Key' => 'Name', 'Value' => $server->name], ['Key' => self::TAG, 'Value' => self::TAG_VALUE]];
        $body = $client->ec2($region, 'RunInstances', [
            'ImageId' => $ami,
            'InstanceType' => $type->name,
            'MinCount' => 1,
            'MaxCount' => 1,
            'ClientToken' => (string) Str::uuid(),
            'KeyName' => $keyName,
            'UserData' => base64_encode(CloudInit::withKeys($userData, $publicKeys)),
            'NetworkInterface' => [['DeviceIndex' => 0, 'SubnetId' => $subnet, 'AssociatePublicIpAddress' => true, 'DeleteOnTermination' => true, 'SecurityGroupId' => [$group]]],
            'BlockDeviceMapping' => [['DeviceName' => is_string($image['rootDeviceName'] ?? null) && $image['rootDeviceName'] !== '' ? $image['rootDeviceName'] : '/dev/sda1', 'Ebs' => ['VolumeSize' => self::DISK_GB, 'VolumeType' => 'gp3', 'DeleteOnTermination' => true]]],
            'MetadataOptions' => ['HttpTokens' => 'required', 'HttpEndpoint' => 'enabled'],
            'TagSpecification' => [['ResourceType' => 'instance', 'Tag' => $tags], ['ResourceType' => 'volume', 'Tag' => $tags]],
        ], repeatable: true);

        $instance = AwsClient::items($body['instancesSet'] ?? null)[0] ?? null;
        if (! is_string($instance['instanceId'] ?? null)) {
            throw new IntegrationFailed(self::t('AWS didn’t say which instance it made. Check the EC2 console before trying again.'));
        }

        return new StartedServer($this->toServer($region, $instance, ['vcpus' => $type->cores, 'memoryGb' => $type->memoryGb], self::DISK_GB, false), null);
    }

    public function resizeTypes(Integration $integration, Server $server): array
    {
        [$region, $instanceId] = self::split($server->id);
        $client = $this->client($integration);
        $zone = $this->instance($client, $region, $instanceId)['placement']['availabilityZone'] ?? null;
        $offered = is_string($zone) ? $this->offeredIn($client, $region, 'availability-zone', $zone) : [];
        $here = $this->regionTypes($integration, $client, $region);
        $prices = AwsPrices::in($client, $region, array_keys($here));

        $types = [];
        foreach ($here as $name => $row) {
            if ($name !== $server->type && $row['architecture'] === ($server->architecture ?? 'x86') && in_array($name, $offered, true)) {
                $types[] = self::type([...$row, 'locations' => [$region], 'prices' => isset($prices[$name]) ? [$region => $prices[$name]] : []], $server->diskGb);
            }
        }
        usort($types, fn (ServerType $a, ServerType $b) => [$a->cpu !== 'shared', $a->cores, $a->memoryGb, $a->name] <=> [$b->cpu !== 'shared', $b->cores, $b->memoryGb, $b->name]);

        return $types;
    }

    public function resize(Integration $integration, string $id, string $type, bool $growDisk): ?string
    {
        [$region, $instanceId] = self::split($id);
        $this->client($integration)->ec2($region, 'ModifyInstanceAttribute', ['InstanceId' => $instanceId, 'InstanceType' => ['Value' => $type]]);

        return null;
    }

    public function rules(): ServerRules
    {
        return new ServerRules(
            resizeNeedsPowerOff: true,
            resizeDisk: ServerRules::DISK_KEPT,
            resizeDown: true,
            gracefulRestart: true,
            snapshotsPerServer: true,
            powerCycleSteps: true,
            stopChangesIp: true,
            rootPassword: false,
        );
    }

    public function shutdown(Integration $integration, string $id): ?string
    {
        return $this->instanceAction($integration, $id, 'StopInstances');
    }

    public function powerOff(Integration $integration, string $id): ?string
    {
        return $this->instanceAction($integration, $id, 'StopInstances', ['Force' => true]);
    }

    public function powerOn(Integration $integration, string $id): ?string
    {
        return $this->instanceAction($integration, $id, 'StartInstances');
    }

    public function reboot(Integration $integration, string $id): ?string
    {
        return $this->instanceAction($integration, $id, 'RebootInstances');
    }

    // EC2 can't start an instance until it has stopped, so rules() has powerCycleSteps and ServerOperations does the waiting.
    public function powerCycle(Integration $integration, string $id): ?string
    {
        throw new LogicException('AWS power cycles run as a ServerOperations change.');
    }

    public function action(Integration $integration, string $actionId): ActionState
    {
        return new ActionState(ActionState::DONE);
    }

    public function snapshots(Integration $integration, string $serverId): array
    {
        [$region, $instanceId] = self::split($serverId);
        $client = $this->client($integration);
        $volume = $this->rootVolume($client, $region, $instanceId);
        if ($volume === null) {
            return [];
        }
        $rows = $client->ec2All($region, 'DescribeSnapshots', ['Owner' => ['self'], ...AwsClient::filters(['volume-id' => $volume])], 'snapshotSet');
        $snapshots = array_map(fn (array $s) => self::toSnapshot($region, $s), $rows);
        usort($snapshots, fn (Snapshot $a, Snapshot $b) => strcmp((string) $b->createdAt, (string) $a->createdAt));

        return $snapshots;
    }

    public function snapshot(Integration $integration, string $serverId, string $name): Snapshot
    {
        [$region, $instanceId] = self::split($serverId);
        $client = $this->client($integration);
        $volume = $this->rootVolume($client, $region, $instanceId) ?? throw new IntegrationFailed(self::t('This instance has no EBS root volume to take a snapshot of.'));
        $body = $client->ec2($region, 'CreateSnapshot', [
            'VolumeId' => $volume,
            'Description' => mb_substr($name, 0, 255),
            'TagSpecification' => [['ResourceType' => 'snapshot', 'Tag' => [['Key' => 'Name', 'Value' => $name], ['Key' => self::TAG, 'Value' => self::TAG_VALUE]]]],
        ]);

        return self::toSnapshot($region, $body);
    }

    public function deleteSnapshot(Integration $integration, string $snapshotId): void
    {
        [$region, $id] = self::split($snapshotId);
        $this->client($integration)->ec2($region, 'DeleteSnapshot', ['SnapshotId' => $id]);
    }

    public function delete(Integration $integration, string $id): void
    {
        $this->instanceAction($integration, $id, 'TerminateInstances');
    }

    /** @return array{0: string, 1: string} region and EC2 id */
    public static function split(string $id): array
    {
        $at = strpos($id, '_');
        $region = $at === false ? '' : substr($id, 0, $at);
        if (! AwsRegions::valid($region)) {
            throw new IntegrationFailed(self::t('AWS can’t find it. It may have been deleted there.'), IntegrationFailed::NOT_FOUND);
        }

        return [$region, substr($id, $at + 1)];
    }

    public static function id(string $region, string $id): string
    {
        return $region.'_'.$id;
    }

    private function client(Integration $integration): AwsClient
    {
        return new AwsClient($integration->credential('access_key_id'), $integration->credential('secret_access_key'));
    }

    /**
     * Starts, stops, reboots or terminates one instance. EC2 has no action
     * to follow, so there's never an id to give back.
     *
     * @param  array<string, mixed>  $extra
     */
    private function instanceAction(Integration $integration, string $id, string $action, array $extra = []): null
    {
        [$region, $instanceId] = self::split($id);
        $this->client($integration)->ec2($region, $action, ['InstanceId' => [$instanceId], ...$extra]);

        return null;
    }

    /** @return list<string> */
    private static function enabledRegions(AwsClient $client, string $region): array
    {
        $rows = AwsClient::items($client->ec2($region, 'DescribeRegions')['regionInfo'] ?? null);

        return array_values(array_filter(array_map(fn (array $r) => $r['regionName'] ?? null, $rows), fn ($code) => is_string($code) && AwsRegions::valid($code)));
    }

    /** @return array<string, mixed>|null */
    private function instance(AwsClient $client, string $region, string $instanceId): ?array
    {
        try {
            $body = $client->ec2($region, 'DescribeInstances', ['InstanceId' => [$instanceId]]);
        } catch (IntegrationFailed $e) {
            if ($e->reason === IntegrationFailed::NOT_FOUND) {
                return null;
            }
            throw $e;
        }
        $instance = AwsClient::items(AwsClient::items($body['reservationSet'] ?? null)[0]['instancesSet'] ?? null)[0] ?? null;

        return $instance === null || ($instance['instanceState']['name'] ?? null) === 'terminated' ? null : $instance;
    }

    private function rootVolume(AwsClient $client, string $region, string $instanceId): ?string
    {
        $instance = $this->instance($client, $region, $instanceId) ?? throw new IntegrationFailed(self::t('AWS can’t find it. It may have been deleted there.'), IntegrationFailed::NOT_FOUND);

        return self::rootVolumeOf($instance);
    }

    /** @param  array<string, mixed>  $instance */
    private static function rootVolumeOf(array $instance): ?string
    {
        $root = $instance['rootDeviceName'] ?? null;
        foreach (AwsClient::items($instance['blockDeviceMapping'] ?? null) as $mapping) {
            if (($mapping['deviceName'] ?? null) === $root && is_string($mapping['ebs']['volumeId'] ?? null)) {
                return $mapping['ebs']['volumeId'];
            }
        }

        return null;
    }

    /** @return list<string> public IPv4 addresses that are Elastic IPs */
    private function elasticIps(AwsClient $client, string $region): array
    {
        $rows = AwsClient::items($client->ec2($region, 'DescribeAddresses')['addressesSet'] ?? null);

        return array_values(array_filter(array_map(fn (array $a) => $a['publicIp'] ?? null, $rows), 'is_string'));
    }

    /**
     * @param  list<array<string, mixed>>  $instances
     * @param  list<string>  $fixed
     * @return list<Server>
     */
    private function toServers(AwsClient $client, string $region, array $instances, array $fixed): array
    {
        if ($instances === []) {
            return [];
        }
        $specs = $this->specs($client, $region, array_values(array_unique(array_filter(array_map(fn (array $i) => $i['instanceType'] ?? null, $instances), 'is_string'))));
        $volumes = array_values(array_filter(array_map(fn (array $i) => self::rootVolumeOf($i), $instances)));
        $sizes = [];
        foreach (array_chunk($volumes, 200) as $chunk) {
            foreach (AwsClient::items($client->ec2($region, 'DescribeVolumes', ['VolumeId' => $chunk])['volumeSet'] ?? null) as $volume) {
                $sizes[(string) ($volume['volumeId'] ?? '')] = (int) ($volume['size'] ?? 0);
            }
        }

        return array_map(fn (array $i) => $this->toServer(
            $region,
            $i,
            $specs[(string) ($i['instanceType'] ?? '')] ?? null,
            $sizes[(string) self::rootVolumeOf($i)] ?? 0,
            is_string($i['ipAddress'] ?? null) && in_array($i['ipAddress'], $fixed, true),
        ), $instances);
    }

    /**
     * @param  array<string, mixed>  $i
     * @param  array{vcpus: int, memoryGb: float}|null  $spec
     */
    private function toServer(string $region, array $i, ?array $spec, int $diskGb, bool $fixedIp): Server
    {
        $place = AwsRegions::location($region);
        $tags = AwsClient::tags($i);
        $ipv4 = is_string($i['ipAddress'] ?? null) && $i['ipAddress'] !== '' ? $i['ipAddress'] : null;
        $ipv6 = is_string($i['ipv6Address'] ?? null) && $i['ipv6Address'] !== '' ? $i['ipv6Address'] : null;
        $created = null;
        foreach (AwsClient::items($i['networkInterfaceSet'] ?? null) as $interface) {
            $ipv6 ??= is_string($interface['ipv6AddressesSet'][0]['ipv6Address'] ?? null) ? $interface['ipv6AddressesSet'][0]['ipv6Address'] : null;
            if ((string) ($interface['attachment']['deviceIndex'] ?? '') === '0' && is_string($interface['attachment']['attachTime'] ?? null)) {
                $created = $interface['attachment']['attachTime'];
            }
        }
        $cores = $spec['vcpus'] ?? (int) ($i['cpuOptions']['coreCount'] ?? 0) * max(1, (int) ($i['cpuOptions']['threadsPerCore'] ?? 1));

        return new Server(
            id: self::id($region, (string) ($i['instanceId'] ?? '')),
            name: ($tags['Name'] ?? '') !== '' ? $tags['Name'] : (string) ($i['instanceId'] ?? ''),
            status: match ($i['instanceState']['name'] ?? null) {
                'pending' => 'starting',
                'running' => 'running',
                'stopping' => 'stopping',
                'stopped' => 'off',
                'shutting-down', 'terminated' => 'deleting',
                default => 'other',
            },
            type: (string) ($i['instanceType'] ?? ''),
            typeLabel: (string) ($i['instanceType'] ?? ''),
            cores: $cores,
            memoryGb: (float) ($spec['memoryGb'] ?? 0),
            diskGb: $diskGb,
            location: $region,
            city: $place->city,
            country: $place->country === '' ? null : $place->country,
            ipv4: $ipv4,
            ipv6: $ipv6 === null ? null : $ipv6.'/128',
            architecture: ($i['architecture'] ?? null) === 'arm64' ? 'arm' : 'x86',
            createdAt: $created ?? (is_string($i['launchTime'] ?? null) ? $i['launchTime'] : null),
            fixedIp: $ipv4 === null ? null : $fixedIp,
        );
    }

    /**
     * vCPUs and memory of instance types, which are the same everywhere, so
     * they're kept for a day.
     *
     * @param  list<string>  $names
     * @return array<string, array{vcpus: int, memoryGb: float}>
     */
    private function specs(AwsClient $client, string $region, array $names): array
    {
        $specs = [];
        $missing = [];
        foreach ($names as $name) {
            $cached = Cache::get('integrations:aws:type:'.$name);
            if (is_array($cached)) {
                $specs[$name] = ['vcpus' => (int) ($cached['vcpus'] ?? 0), 'memoryGb' => (float) ($cached['memoryGb'] ?? 0)];
            } else {
                $missing[] = $name;
            }
        }
        foreach (array_chunk($missing, 100) as $chunk) {
            foreach ($client->ec2All($region, 'DescribeInstanceTypes', ['InstanceType' => $chunk], 'instanceTypeSet', null) as $row) {
                $spec = self::spec($row);
                Cache::put('integrations:aws:type:'.$spec['name'], $spec, now()->addDay());
                $specs[$spec['name']] = ['vcpus' => $spec['vcpus'], 'memoryGb' => $spec['memoryGb']];
            }
        }

        return $specs;
    }

    /**
     * The instance types ServerOS offers in a region, kept for a day.
     *
     * @return array<string, array{name: string, vcpus: int, memoryGb: float, architecture: string, cpu: string}>
     */
    private function regionTypes(Integration $integration, AwsClient $client, string $region): array
    {
        $rows = Cache::remember("integrations:{$integration->id}:aws:types:{$region}", now()->addDay(), function () use ($client, $region): array {
            $rows = [];
            foreach ($client->ec2All($region, 'DescribeInstanceTypes', AwsClient::filters([
                'instance-type' => array_map(fn (string $family) => $family.'.*', self::FAMILIES),
                'bare-metal' => 'false',
                'supported-usage-class' => 'on-demand',
                'supported-root-device-type' => 'ebs',
            ]), 'instanceTypeSet', 100) as $row) {
                $spec = self::spec($row);
                if ($spec['vcpus'] > 0 && $spec['vcpus'] <= self::LARGEST) {
                    $rows[$spec['name']] = $spec;
                }
            }

            return $rows;
        });

        return array_filter((array) $rows, 'is_array');
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{name: string, vcpus: int, memoryGb: float, architecture: string, cpu: string}
     */
    private static function spec(array $row): array
    {
        $name = (string) ($row['instanceType'] ?? '');
        $architectures = (array) ($row['processorInfo']['supportedArchitectures'] ?? []);
        $burstable = ($row['burstablePerformanceSupported'] ?? null) === 'true' || str_contains($name, '-flex.');

        return [
            'name' => $name,
            'vcpus' => (int) ($row['vCpuInfo']['defaultVCpus'] ?? 0),
            'memoryGb' => round((int) ($row['memoryInfo']['sizeInMiB'] ?? 0) / 1024, 2),
            'architecture' => in_array('arm64', $architectures, true) ? 'arm' : 'x86',
            'cpu' => $burstable ? 'shared' : 'dedicated',
        ];
    }

    /** @param  array<string, mixed>  $row */
    private static function type(array $row, int $diskGb): ServerType
    {
        return new ServerType(
            name: (string) $row['name'],
            label: (string) $row['name'],
            cores: (int) $row['vcpus'],
            memoryGb: (float) $row['memoryGb'],
            diskGb: $diskGb,
            cpu: (string) $row['cpu'],
            architecture: (string) $row['architecture'],
            storage: 'ebs',
            locations: array_values(array_map('strval', (array) ($row['locations'] ?? []))),
            prices: array_map(fn (array $p) => ['monthly' => (float) $p['monthly'], 'hourly' => (float) $p['hourly']], array_filter((array) ($row['prices'] ?? []), 'is_array')),
        );
    }

    /** @return list<string> instance types of ours offered in one region or zone */
    private function offeredIn(AwsClient $client, string $region, string $kind, string $location): array
    {
        $rows = $client->ec2All($region, 'DescribeInstanceTypeOfferings', [
            'LocationType' => $kind,
            ...AwsClient::filters(['location' => $location, 'instance-type' => array_map(fn (string $family) => $family.'.*', self::FAMILIES)]),
        ], 'instanceTypeOfferingSet');

        return array_values(array_filter(array_map(fn (array $row) => $row['instanceType'] ?? null, $rows), 'is_string'));
    }

    /** @return list<array{name: string, fingerprint: string|null, publicKey: string|null}> */
    private function keyPairs(AwsClient $client, string $region, bool $public = false): array
    {
        $rows = AwsClient::items($client->ec2($region, 'DescribeKeyPairs', $public ? ['IncludePublicKey' => true] : [])['keySet'] ?? null);

        return array_values(array_filter(array_map(fn (array $k) => is_string($k['keyName'] ?? null) && $k['keyName'] !== '' ? [
            'name' => $k['keyName'],
            'fingerprint' => is_string($k['keyFingerprint'] ?? null) ? $k['keyFingerprint'] : null,
            'publicKey' => is_string($k['publicKey'] ?? null) ? $k['publicKey'] : null,
        ] : null, $rows)));
    }

    /**
     * The key pair to start the instance with (the first picked that's in
     * its region), and the public keys of the rest for cloud-init, since an
     * instance starts with one key pair.
     *
     * @param  list<string>  $picked
     * @return array{0: string|null, 1: list<string>}
     */
    private function keysFor(Integration $integration, AwsClient $client, string $region, array $picked): array
    {
        if ($picked === []) {
            return [null, []];
        }
        $public = [];
        $here = [];
        foreach ([$region, ...array_diff($this->chosenRegions($integration), [$region])] as $code) {
            foreach ($this->keyPairs($client, $code, true) as $pair) {
                $public[$pair['name']] ??= $pair['publicKey'];
                if ($code === $region) {
                    $here[] = $pair['name'];
                }
            }
            if (array_diff($picked, array_keys($public)) === []) {
                break;
            }
        }
        $keyName = null;
        foreach ($picked as $name) {
            if (in_array($name, $here, true)) {
                $keyName = $name;
                break;
            }
        }
        $others = [];
        foreach ($picked as $name) {
            if ($name !== $keyName && is_string($public[$name] ?? null)) {
                $others[] = $public[$name];
            }
        }

        return [$keyName, $others];
    }

    private function defaultVpc(AwsClient $client, string $region): string
    {
        $vpc = AwsClient::items($client->ec2($region, 'DescribeVpcs', AwsClient::filters(['is-default' => 'true']))['vpcSet'] ?? null)[0]['vpcId'] ?? null;
        if (! is_string($vpc) || $vpc === '') {
            throw new IntegrationFailed(self::t(':region has no default VPC, so ServerOS has nowhere to put the instance. Make one in the VPC console (Actions › Create default VPC), or pick another region.', ['region' => AwsRegions::label($region)]));
        }

        return $vpc;
    }

    private function subnetFor(AwsClient $client, string $region, string $vpc, string $type): string
    {
        $zones = $this->offeredInZones($client, $region, $type);
        $subnets = AwsClient::items($client->ec2($region, 'DescribeSubnets', AwsClient::filters(['vpc-id' => $vpc, 'default-for-az' => 'true']))['subnetSet'] ?? null);
        usort($subnets, fn (array $a, array $b) => strcmp((string) ($a['availabilityZone'] ?? ''), (string) ($b['availabilityZone'] ?? '')));
        foreach ($subnets as $subnet) {
            if (in_array($subnet['availabilityZone'] ?? null, $zones, true) && ($subnet['state'] ?? 'available') === 'available' && is_string($subnet['subnetId'] ?? null)) {
                return $subnet['subnetId'];
            }
        }

        throw new IntegrationFailed(self::t('AWS doesn’t offer :type in a zone of :region that has a default subnet. Pick another type.', ['type' => $type, 'region' => AwsRegions::label($region)]));
    }

    /** @return list<string> */
    private function offeredInZones(AwsClient $client, string $region, string $type): array
    {
        $rows = $client->ec2All($region, 'DescribeInstanceTypeOfferings', ['LocationType' => 'availability-zone', ...AwsClient::filters(['instance-type' => $type])], 'instanceTypeOfferingSet');

        return array_values(array_filter(array_map(fn (array $row) => $row['location'] ?? null, $rows), 'is_string'));
    }

    // The group is made once per VPC with SSH, HTTP and HTTPS open; rules someone changes there afterwards are left alone.
    private function securityGroup(AwsClient $client, string $region, string $vpc): string
    {
        $found = AwsClient::items($client->ec2($region, 'DescribeSecurityGroups', AwsClient::filters(['vpc-id' => $vpc, 'group-name' => self::SECURITY_GROUP]))['securityGroupInfo'] ?? null)[0] ?? null;
        $id = is_string($found['groupId'] ?? null) ? $found['groupId'] : null;
        if ($id === null) {
            $made = $client->ec2($region, 'CreateSecurityGroup', [
                'GroupName' => self::SECURITY_GROUP,
                'GroupDescription' => 'ServerOS machines: SSH, HTTP and HTTPS',
                'VpcId' => $vpc,
                'TagSpecification' => [['ResourceType' => 'security-group', 'Tag' => [['Key' => 'Name', 'Value' => self::SECURITY_GROUP], ['Key' => self::TAG, 'Value' => self::TAG_VALUE]]]],
            ]);
            $id = is_string($made['groupId'] ?? null) ? $made['groupId'] : throw new IntegrationFailed(self::t('AWS didn’t say which security group it made. Try again.'));
        }
        if ($found === null || AwsClient::items($found['ipPermissions'] ?? null) === []) {
            $client->ec2($region, 'AuthorizeSecurityGroupIngress', [
                'GroupId' => $id,
                'IpPermissions' => array_map(fn (int $port) => [
                    'IpProtocol' => 'tcp',
                    'FromPort' => $port,
                    'ToPort' => $port,
                    'IpRanges' => [['CidrIp' => '0.0.0.0/0']],
                    'Ipv6Ranges' => [['CidrIpv6' => '::/0']],
                ], [22, 80, 443]),
            ]);
        }

        return $id;
    }

    /** @param  array<string, mixed>  $s */
    private static function toSnapshot(string $region, array $s): Snapshot
    {
        $tags = AwsClient::tags($s);
        $size = is_numeric($s['volumeSize'] ?? null) ? (int) $s['volumeSize'] : null;

        return new Snapshot(
            id: self::id($region, (string) ($s['snapshotId'] ?? '')),
            name: ($tags['Name'] ?? '') !== '' ? $tags['Name'] : (string) ($s['description'] ?? ''),
            status: match ($s['status'] ?? null) {
                'completed' => 'available',
                'pending' => 'creating',
                'error' => 'failed',
                default => (string) ($s['status'] ?? 'creating'),
            },
            sizeGb: null,
            diskGb: $size,
            createdAt: is_string($s['startTime'] ?? null) ? $s['startTime'] : null,
        );
    }
}
