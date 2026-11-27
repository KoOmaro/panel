<?php

namespace Tests\Support;

use App\Support\Integrations\Aws\AwsSigner;
use DateTimeImmutable;
use DateTimeZone;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * A small stand-in for the AWS APIs ServerOS uses (EC2 and STS Query APIs,
 * SSM and the Price List Query API), so tests never call the real ones.
 * Every request must carry a well formed SigV4 Authorization header whose
 * signature checks out with the fake's secret, or it's turned down the way
 * AWS does. It keeps instances, volumes, snapshots, key pairs and security
 * groups per region, records every call, and can fail the next calls to an
 * action. Like EC2, changes show on an instance after a few reads.
 */
final class FakeAws
{
    public const string KEY_ID = 'FAKEAWSKEYIDFORTESTS';

    public const string SECRET = 'fake-aws-secret-for-tests';

    public const string ACCOUNT = '123456789012';

    public const string ARN = 'arn:aws:iam::123456789012:user/serveros';

    private const string EC2_NS = 'http://ec2.amazonaws.com/doc/2016-11-15/';

    /** @var list<string> */
    public array $regions = ['us-east-1', 'eu-west-2', 'eu-central-1'];

    /** @var array<string, array<string, array<string, mixed>>> region => id => instance */
    public array $instances = [];

    /** @var array<string, array<string, int>> region => volume id => size */
    public array $volumes = [];

    /** @var array<string, array<string, array<string, mixed>>> */
    public array $snapshots = [];

    /** @var array<string, list<array<string, mixed>>> */
    public array $addresses = [];

    /** @var array<string, list<array{keyName: string, keyFingerprint: string, publicKey: string}>> */
    public array $keyPairs = [];

    /** @var array<string, list<array<string, mixed>>> */
    public array $securityGroups = [];

    public bool $defaultVpc = true;

    /** @var array<string, list<string>> zone => instance types it doesn't offer */
    public array $notInZone = [];

    /** @var array<string, float> on-demand Linux price an hour, the same in every region */
    public array $prices = ['t3.micro' => 0.0104, 't3.small' => 0.0208, 't3.medium' => 0.0416, 't4g.small' => 0.0168, 'm7i.large' => 0.1008, 'm7g.large' => 0.0816, 'c7i.xlarge' => 0.1785];

    public bool $pricingAllowed = true;

    /** @var list<array{service: string, region: string, action: string, params: array<string, mixed>, authorization: string}> */
    public array $calls = [];

    public int $settlePolls = 1;

    /** @var array<string, array{polls: int, apply: callable}> */
    private array $pending = [];

    /** @var array<string, list<array{status: int, code: string, message: string}>> */
    private array $failures = [];

    /** @var array<string, string> client token => instance id */
    private array $tokens = [];

    private int $next = 1;

    /** @var array<string, array{0: int, 1: int, 2: string, 3: bool}> vCPUs, MiB, architecture, burstable */
    private const array TYPES = [
        't2.micro' => [1, 1024, 'x86_64', true],
        't3.micro' => [2, 1024, 'x86_64', true],
        't3.small' => [2, 2048, 'x86_64', true],
        't3.medium' => [2, 4096, 'x86_64', true],
        't4g.small' => [2, 2048, 'arm64', true],
        'm7i.large' => [2, 8192, 'x86_64', false],
        'm7g.large' => [2, 8192, 'arm64', false],
        'c7i.xlarge' => [4, 8192, 'x86_64', false],
        'm7i.48xlarge' => [192, 786432, 'x86_64', false],
    ];

    public static function install(bool $strict = true): self
    {
        $fake = new self;
        if ($strict) {
            Http::preventStrayRequests();
        }
        Http::fake(['*.amazonaws.com*' => fn (Request $request) => $fake->handle($request)]);

        return $fake;
    }

    public static function instanceId(int $n): string
    {
        return sprintf('i-0fa%014x', $n);
    }

    /** @param  array<string, mixed>  $overrides */
    public function instance(string $region, string $id, string $name, ?string $ipv4, array $overrides = []): self
    {
        $n = $this->next++;
        $volume = sprintf('vol-0fa%014x', $n);
        $this->volumes[$region][$volume] = (int) ($overrides['disk'] ?? 30);
        unset($overrides['disk']);
        $type = (string) ($overrides['instanceType'] ?? 't3.small');
        $this->instances[$region][$id] = array_replace([
            'instanceId' => $id,
            'imageId' => 'ami-0fake00000000ubunt',
            'instanceState' => ['code' => 16, 'name' => 'running'],
            'privateDnsName' => 'ip-172-31-0-'.$n.'.internal',
            'dnsName' => '',
            'instanceType' => $type,
            'launchTime' => '2026-11-01T10:00:00.000Z',
            'placement' => ['availabilityZone' => $region.'a', 'tenancy' => 'default'],
            'privateIpAddress' => '172.31.0.'.$n,
            'ipAddress' => $ipv4,
            'architecture' => self::TYPES[$type][2] ?? 'x86_64',
            'rootDeviceType' => 'ebs',
            'rootDeviceName' => '/dev/sda1',
            'blockDeviceMapping' => [['deviceName' => '/dev/sda1', 'ebs' => ['volumeId' => $volume, 'status' => 'attached', 'deleteOnTermination' => true]]],
            'tagSet' => [['key' => 'Name', 'value' => $name]],
            'networkInterfaceSet' => [['networkInterfaceId' => sprintf('eni-0fa%014x', $n), 'attachment' => ['deviceIndex' => 0, 'attachTime' => '2026-10-01T09:00:00.000Z', 'status' => 'attached'], 'ipv6AddressesSet' => []]],
            'cpuOptions' => ['coreCount' => 1, 'threadsPerCore' => 2],
        ], $overrides);

        return $this;
    }

    public function elasticIp(string $region, string $ip, ?string $instanceId): self
    {
        $this->addresses[$region][] = ['publicIp' => $ip, 'allocationId' => 'eipalloc-0fa'.count($this->addresses[$region] ?? []), 'domain' => 'vpc', 'instanceId' => $instanceId];

        return $this;
    }

    public function keyPair(string $region, string $name, string $publicKey): self
    {
        $this->keyPairs[$region][] = ['keyName' => $name, 'keyFingerprint' => 'SHA256:'.substr(base64_encode(hash('sha256', $publicKey, true)), 0, 43), 'publicKey' => $publicKey];

        return $this;
    }

    public function failNext(string $action, int $status, string $code, string $message = 'Something went wrong.'): self
    {
        $this->failures[$action][] = ['status' => $status, 'code' => $code, 'message' => $message];

        return $this;
    }

    /** @return list<string> "region Action" for each call, or just the actions of one service */
    public function called(?string $service = null): array
    {
        return array_values(array_map(fn (array $call) => $call['region'].' '.$call['action'], array_filter($this->calls, fn (array $call) => $service === null || $call['service'] === $service)));
    }

    /** @return array<string, mixed>|null the last call's parameters */
    public function paramsOf(string $action): ?array
    {
        foreach (array_reverse($this->calls) as $call) {
            if ($call['action'] === $action) {
                return $call['params'];
            }
        }

        return null;
    }

    public function handle(Request $request): PromiseInterface
    {
        $host = (string) parse_url($request->url(), PHP_URL_HOST);
        if (preg_match('/^api\.pricing\.([a-z0-9-]+)\.amazonaws\.com$/', $host, $m) === 1) {
            [$service, $region] = ['pricing', $m[1]];
        } elseif (preg_match('/^(ec2|sts|ssm)\.([a-z0-9-]+)\.amazonaws\.com$/', $host, $m) === 1) {
            [$service, $region] = [$m[1], $m[2]];
        } else {
            return Http::response('Unknown host', 404);
        }
        $json = $service === 'ssm' || $service === 'pricing';
        $body = $request->body();
        if ($json) {
            $params = (array) json_decode($body, true);
            $action = substr((string) ($request->header('X-Amz-Target')[0] ?? ''), (int) strpos((string) ($request->header('X-Amz-Target')[0] ?? ''), '.') + 1);
        } else {
            $params = self::form($body);
            $action = (string) ($params['Action'] ?? '');
        }
        $authorization = (string) ($request->header('Authorization')[0] ?? '');
        $this->calls[] = ['service' => $service, 'region' => $region, 'action' => $action, 'params' => $params, 'authorization' => $authorization];

        $refused = $this->checkSignature($request, $service, $region, $json);
        if ($refused !== null) {
            return $refused;
        }
        if (($this->failures[$action] ?? []) !== []) {
            $failure = array_shift($this->failures[$action]);

            return $this->error($service, $failure['status'], $failure['code'], $failure['message']);
        }

        return match ($service) {
            'sts' => $this->sts($action),
            'ssm' => $this->ssm($region, $action, $params),
            'pricing' => $this->pricing($action, $params),
            default => $this->ec2($region, $action, $params),
        };
    }

    /** The request's SigV4 Authorization header, checked like AWS checks it. */
    private function checkSignature(Request $request, string $service, string $region, bool $json): ?PromiseInterface
    {
        $authorization = (string) ($request->header('Authorization')[0] ?? '');
        $date = (string) ($request->header('X-Amz-Date')[0] ?? '');
        if (preg_match('#^AWS4-HMAC-SHA256 Credential=([A-Z0-9]+)/(\d{8})/([a-z0-9-]+)/([a-z0-9]+)/aws4_request, SignedHeaders=([a-z0-9;-]+), Signature=([0-9a-f]{64})$#', $authorization, $m) !== 1
            || preg_match('/^\d{8}T\d{6}Z$/', $date) !== 1) {
            return $this->error($service, 400, 'IncompleteSignature', 'Authorization header requires a Credential, SignedHeaders and Signature.');
        }
        [, $keyId, $day, $scopeRegion, $scopeService, $signed] = $m;
        $signedHeaders = explode(';', $signed);
        if ($day !== substr($date, 0, 8) || $scopeRegion !== $region || $scopeService !== $service
            || ! in_array('host', $signedHeaders, true) || ! in_array('x-amz-date', $signedHeaders, true) || ! in_array('content-type', $signedHeaders, true)
            || ($json && ! in_array('x-amz-target', $signedHeaders, true))) {
            return $this->error($service, 403, 'SignatureDoesNotMatch', 'Credential should be scoped to a valid region and service, and sign host, x-amz-date and content-type.');
        }
        if ($keyId !== self::KEY_ID) {
            return match ($service) {
                'ec2' => $this->error($service, 401, 'AuthFailure', 'AWS was not able to validate the provided access credentials'),
                'sts' => $this->error($service, 403, 'InvalidClientTokenId', 'The security token included in the request is invalid.'),
                default => $this->error($service, 400, 'UnrecognizedClientException', 'The security token included in the request is invalid.'),
            };
        }
        $psr = $request->toPsrRequest();
        $headers = [];
        foreach ($signedHeaders as $name) {
            $headers[$name] = $psr->getHeader($name);
        }
        $at = DateTimeImmutable::createFromFormat('Ymd\THis\Z', $date, new DateTimeZone('UTC'));
        $expected = (new AwsSigner(self::KEY_ID, self::SECRET))->authorization($psr->getMethod(), $psr->getUri()->getPath(), $psr->getUri()->getQuery(), $headers, (string) $psr->getBody(), $region, $service, $at ?: new DateTimeImmutable);
        if (! hash_equals($expected, $authorization)) {
            return $this->error($service, 403, 'SignatureDoesNotMatch', 'The request signature we calculated does not match the signature you provided. Check your AWS Secret Access Key and signing method.');
        }

        return null;
    }

    private function sts(string $action): PromiseInterface
    {
        if ($action !== 'GetCallerIdentity') {
            return $this->error('sts', 400, 'InvalidAction', 'Could not find operation '.$action);
        }

        return Http::response(self::xml('GetCallerIdentityResponse', [
            'GetCallerIdentityResult' => ['Arn' => self::ARN, 'UserId' => 'AIDAFAKEUSERFORTESTS', 'Account' => self::ACCOUNT],
            'ResponseMetadata' => ['RequestId' => 'fake-request'],
        ], 'https://sts.amazonaws.com/doc/2011-06-15/'), 200, ['Content-Type' => 'text/xml']);
    }

    /** @param  array<string, mixed>  $params */
    private function ssm(string $region, string $action, array $params): PromiseInterface
    {
        $name = (string) ($params['Name'] ?? '');
        if ($action !== 'GetParameter' || preg_match('#^/aws/service/canonical/ubuntu/server/24\.04/stable/current/(amd64|arm64)/hvm/ebs-gp3/ami-id$#', $name, $m) !== 1) {
            return $this->error('ssm', 400, 'ParameterNotFound', 'Parameter '.$name.' not found.');
        }

        return Http::response(['Parameter' => ['Name' => $name, 'Type' => 'String', 'Value' => self::ami($region, $m[1]), 'Version' => 7, 'DataType' => 'aws:ec2:image']]);
    }

    /** @param  array<string, mixed>  $params */
    private function pricing(string $action, array $params): PromiseInterface
    {
        if (! $this->pricingAllowed) {
            return $this->error('pricing', 400, 'AccessDeniedException', 'User: '.self::ARN.' is not authorized to perform: pricing:GetProducts');
        }
        $filters = [];
        foreach ((array) ($params['Filters'] ?? []) as $filter) {
            $filters[(string) ($filter['Field'] ?? '')] = [(string) ($filter['Type'] ?? ''), (string) ($filter['Value'] ?? '')];
        }
        if ($action !== 'GetProducts' || ($params['ServiceCode'] ?? null) !== 'AmazonEC2' || ($filters['operatingSystem'] ?? null) !== ['TERM_MATCH', 'Linux'] || ($filters['instanceType'][0] ?? null) !== 'ANY_OF') {
            return $this->error('pricing', 400, 'InvalidParameterException', 'Unexpected filters.');
        }
        $region = $filters['regionCode'][1] ?? '';
        $list = [];
        foreach (explode(',', $filters['instanceType'][1]) as $type) {
            if (isset($this->prices[$type])) {
                $sku = strtoupper(substr(md5($region.$type), 0, 16));
                $list[] = (string) json_encode([
                    'product' => ['productFamily' => 'Compute Instance', 'sku' => $sku, 'attributes' => ['instanceType' => $type, 'regionCode' => $region, 'operatingSystem' => 'Linux', 'tenancy' => 'Shared', 'preInstalledSw' => 'NA', 'capacitystatus' => 'Used', 'operation' => 'RunInstances']],
                    'serviceCode' => 'AmazonEC2',
                    'terms' => [
                        'OnDemand' => [$sku.'.JRTCKXETXF' => ['priceDimensions' => [$sku.'.JRTCKXETXF.6YS6EN2CT7' => ['unit' => 'Hrs', 'pricePerUnit' => ['USD' => sprintf('%.10f', $this->prices[$type])], 'description' => '$'.$this->prices[$type].' per On Demand Linux '.$type.' Instance Hour']], 'sku' => $sku, 'offerTermCode' => 'JRTCKXETXF']],
                        'Reserved' => [$sku.'.4NA7Y494T4' => ['priceDimensions' => [$sku.'.4NA7Y494T4.6YS6EN2CT7' => ['unit' => 'Hrs', 'pricePerUnit' => ['USD' => '0.0100000000']]], 'termAttributes' => ['LeaseContractLength' => '1yr']]],
                    ],
                ]);
            }
        }

        return Http::response(['FormatVersion' => 'aws_v1', 'PriceList' => $list]);
    }

    /** @param  array<string, mixed>  $params */
    private function ec2(string $region, string $action, array $params): PromiseInterface
    {
        if (! in_array($region, $this->regions, true) && $action !== 'DescribeRegions') {
            return $this->error('ec2', 401, 'OptInRequired', 'You are not subscribed to this service. Please go to http://aws.amazon.com to subscribe.');
        }
        if (($params['Version'] ?? null) !== '2016-11-15') {
            return $this->error('ec2', 400, 'InvalidParameterValue', 'Bad version.');
        }
        $filters = self::filtersOf($params);

        return match ($action) {
            'DescribeRegions' => $this->ok($action, ['regionInfo' => array_map(fn (string $r) => ['regionName' => $r, 'regionEndpoint' => 'ec2.'.$r.'.amazonaws.com', 'optInStatus' => 'opt-in-not-required'], $this->regions)]),
            'DescribeInstances' => $this->describeInstances($region, $params, $filters),
            'DescribeInstanceTypes' => $this->describeTypes($params, $filters),
            'DescribeInstanceTypeOfferings' => $this->offerings($region, $params, $filters),
            'DescribeVolumes' => $this->ok($action, ['volumeSet' => array_values(array_map(fn (string $id) => ['volumeId' => $id, 'size' => $this->volumes[$region][$id] ?? 8, 'volumeType' => 'gp3', 'status' => 'in-use'], array_filter(self::listOf($params, 'VolumeId'), fn (string $id) => isset($this->volumes[$region][$id]))))]),
            'DescribeAddresses' => $this->ok($action, ['addressesSet' => array_values(array_filter($this->addresses[$region] ?? [], fn (array $a) => ! isset($filters['instance-id']) || in_array($a['instanceId'], $filters['instance-id'], true)))]),
            'DescribeKeyPairs' => $this->ok($action, ['keySet' => array_map(fn (array $k) => [
                'keyPairId' => 'key-0'.substr(md5($k['keyName']), 0, 16), 'keyName' => $k['keyName'], 'keyFingerprint' => $k['keyFingerprint'], 'keyType' => 'ed25519',
                ...(($params['IncludePublicKey'] ?? null) === 'true' ? ['publicKey' => $k['publicKey']] : []),
            ], $this->keyPairs[$region] ?? [])]),
            'DescribeImages' => $this->ok($action, ['imagesSet' => array_map(fn (string $ami) => ['imageId' => $ami, 'rootDeviceName' => '/dev/sda1', 'rootDeviceType' => 'ebs', 'architecture' => str_contains($ami, 'arm') ? 'arm64' : 'x86_64'], self::listOf($params, 'ImageId'))]),
            'DescribeVpcs' => $this->ok($action, ['vpcSet' => $this->defaultVpc && ($filters['is-default'] ?? null) === ['true'] ? [['vpcId' => self::vpc($region), 'isDefault' => true, 'cidrBlock' => '172.31.0.0/16']] : []]),
            'DescribeSubnets' => $this->ok($action, ['subnetSet' => ($filters['vpc-id'] ?? null) === [self::vpc($region)] ? array_map(fn (string $zone) => ['subnetId' => 'subnet-'.$zone, 'availabilityZone' => $zone, 'state' => 'available', 'defaultForAz' => true, 'vpcId' => self::vpc($region)], [$region.'b', $region.'a']) : []]),
            'DescribeSecurityGroups' => $this->ok($action, ['securityGroupInfo' => array_values(array_filter($this->securityGroups[$region] ?? [], fn (array $g) => (! isset($filters['group-name']) || in_array($g['groupName'], $filters['group-name'], true)) && (! isset($filters['vpc-id']) || in_array($g['vpcId'], $filters['vpc-id'], true))))]),
            'CreateSecurityGroup' => $this->createGroup($region, $params),
            'AuthorizeSecurityGroupIngress' => $this->authorizeIngress($region, $params),
            'RunInstances' => $this->run($region, $params),
            'StopInstances', 'StartInstances', 'RebootInstances', 'TerminateInstances' => $this->power($region, $action, $params),
            'ModifyInstanceAttribute' => $this->modify($region, $params),
            'DescribeSnapshots' => $this->ok($action, ['snapshotSet' => array_values(array_filter($this->snapshots[$region] ?? [], fn (array $s) => self::listOf($params, 'Owner') === ['self'] && (! isset($filters['volume-id']) || in_array($s['volumeId'], $filters['volume-id'], true))))]),
            'CreateSnapshot' => $this->createSnapshot($region, $params),
            'DeleteSnapshot' => $this->deleteSnapshot($region, $params),
            default => $this->error('ec2', 400, 'InvalidAction', 'The action '.$action.' is not valid for this web service.'),
        };
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, list<string>>  $filters
     */
    private function describeInstances(string $region, array $params, array $filters): PromiseInterface
    {
        $ids = self::listOf($params, 'InstanceId');
        foreach ($ids as $id) {
            if (! isset($this->instances[$region][$id])) {
                return $this->error('ec2', 400, 'InvalidInstanceID.NotFound', "The instance ID '{$id}' does not exist");
            }
        }
        $found = [];
        foreach (array_keys($this->instances[$region] ?? []) as $id) {
            $instance = $this->read($region, $id);
            if (($ids === [] || in_array($id, $ids, true)) && (! isset($filters['instance-state-name']) || in_array($instance['instanceState']['name'], $filters['instance-state-name'], true))) {
                $found[] = ['reservationId' => 'r-'.substr($id, 2), 'ownerId' => self::ACCOUNT, 'groupSet' => [], 'instancesSet' => [$instance]];
            }
        }
        [$page, $next] = self::page($found, $params);

        return $this->ok('DescribeInstances', ['reservationSet' => $page, ...($next === null ? [] : ['nextToken' => $next])]);
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, list<string>>  $filters
     */
    private function describeTypes(array $params, array $filters): PromiseInterface
    {
        $names = self::listOf($params, 'InstanceType');
        $rows = [];
        foreach (self::TYPES as $name => [$vcpus, $mib, $arch, $burst]) {
            if ($names !== [] && ! in_array($name, $names, true)) {
                continue;
            }
            if (isset($filters['instance-type']) && ! self::matchesAny($name, $filters['instance-type'])) {
                continue;
            }
            $rows[] = [
                'instanceType' => $name, 'currentGeneration' => $name !== 't2.micro', 'freeTierEligible' => $name === 't3.micro', 'bareMetal' => false,
                'supportedUsageClasses' => ['on-demand', 'spot'], 'supportedRootDeviceTypes' => ['ebs'], 'hypervisor' => 'nitro',
                'processorInfo' => ['supportedArchitectures' => [$arch], 'sustainedClockSpeedInGhz' => 3.1],
                'vCpuInfo' => ['defaultVCpus' => $vcpus, 'defaultCores' => max(1, intdiv($vcpus, 2)), 'defaultThreadsPerCore' => 2],
                'memoryInfo' => ['sizeInMiB' => $mib], 'instanceStorageSupported' => false, 'burstablePerformanceSupported' => $burst,
            ];
        }
        [$page, $next] = self::page($rows, $params);

        return $this->ok('DescribeInstanceTypes', ['instanceTypeSet' => $page, ...($next === null ? [] : ['nextToken' => $next])]);
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, list<string>>  $filters
     */
    private function offerings(string $region, array $params, array $filters): PromiseInterface
    {
        if (($params['LocationType'] ?? null) !== 'availability-zone') {
            return $this->error('ec2', 400, 'InvalidParameterValue', 'Only zones here.');
        }
        $rows = [];
        foreach ([$region.'a', $region.'b'] as $zone) {
            if (isset($filters['location']) && ! in_array($zone, $filters['location'], true)) {
                continue;
            }
            foreach (array_keys(self::TYPES) as $name) {
                if ((! isset($filters['instance-type']) || self::matchesAny($name, $filters['instance-type'])) && ! in_array($name, $this->notInZone[$zone] ?? [], true)) {
                    $rows[] = ['instanceType' => $name, 'locationType' => 'availability-zone', 'location' => $zone];
                }
            }
        }

        return $this->ok('DescribeInstanceTypeOfferings', ['instanceTypeOfferingSet' => $rows]);
    }

    /** @param  array<string, mixed>  $params */
    private function createGroup(string $region, array $params): PromiseInterface
    {
        foreach ($this->securityGroups[$region] ?? [] as $group) {
            if ($group['groupName'] === ($params['GroupName'] ?? null) && $group['vpcId'] === ($params['VpcId'] ?? null)) {
                return $this->error('ec2', 400, 'InvalidGroup.Duplicate', "The security group '{$group['groupName']}' already exists for VPC '{$group['vpcId']}'");
            }
        }
        $id = sprintf('sg-0fa%014x', $this->next++);
        $this->securityGroups[$region][] = ['groupId' => $id, 'groupName' => (string) ($params['GroupName'] ?? ''), 'groupDescription' => (string) ($params['GroupDescription'] ?? ''), 'vpcId' => (string) ($params['VpcId'] ?? ''), 'ownerId' => self::ACCOUNT, 'ipPermissions' => [], 'ipPermissionsEgress' => [['ipProtocol' => '-1', 'ipRanges' => [['cidrIp' => '0.0.0.0/0']]]]];

        return $this->ok('CreateSecurityGroup', ['return' => true, 'groupId' => $id]);
    }

    /** @param  array<string, mixed>  $params */
    private function authorizeIngress(string $region, array $params): PromiseInterface
    {
        foreach ($this->securityGroups[$region] ?? [] as $i => $group) {
            if ($group['groupId'] === ($params['GroupId'] ?? null)) {
                for ($n = 1; isset($params["IpPermissions.{$n}.IpProtocol"]); $n++) {
                    $this->securityGroups[$region][$i]['ipPermissions'][] = [
                        'ipProtocol' => $params["IpPermissions.{$n}.IpProtocol"], 'fromPort' => (int) $params["IpPermissions.{$n}.FromPort"], 'toPort' => (int) $params["IpPermissions.{$n}.ToPort"],
                        'ipRanges' => [['cidrIp' => $params["IpPermissions.{$n}.IpRanges.1.CidrIp"] ?? null]], 'ipv6Ranges' => [['cidrIpv6' => $params["IpPermissions.{$n}.Ipv6Ranges.1.CidrIpv6"] ?? null]],
                    ];
                }

                return $this->ok('AuthorizeSecurityGroupIngress', ['return' => true]);
            }
        }

        return $this->error('ec2', 400, 'InvalidGroup.NotFound', 'The security group does not exist');
    }

    /** @param  array<string, mixed>  $params */
    private function run(string $region, array $params): PromiseInterface
    {
        $token = (string) ($params['ClientToken'] ?? '');
        if ($token !== '' && isset($this->tokens[$token])) {
            return $this->ok('RunInstances', ['instancesSet' => [$this->instances[$region][$this->tokens[$token]]]]);
        }
        $type = (string) ($params['InstanceType'] ?? '');
        $userData = base64_decode((string) ($params['UserData'] ?? ''), true);
        $key = $params['KeyName'] ?? null;
        $subnet = (string) ($params['NetworkInterface.1.SubnetId'] ?? '');
        $groups = self::listOf($params, 'NetworkInterface.1.SecurityGroupId');
        $knownGroups = array_column($this->securityGroups[$region] ?? [], 'groupId');
        $zone = substr($subnet, strlen('subnet-'));
        $problem = match (true) {
            ! isset(self::TYPES[$type]) => ['InvalidParameterValue', "Invalid value '{$type}' for InstanceType."],
            ! in_array((string) ($params['ImageId'] ?? ''), [self::ami($region, 'amd64'), self::ami($region, 'arm64')], true) => ['InvalidAMIID.NotFound', 'The image id does not exist'],
            str_contains((string) $params['ImageId'], 'arm') !== (self::TYPES[$type][2] === 'arm64') => ['InvalidParameterValue', "The architecture of the image doesn't match the instance type."],
            $userData === false || $userData === '' => ['InvalidParameterValue', 'Invalid BASE64 encoding of user data.'],
            $key !== null && ! in_array($key, array_column($this->keyPairs[$region] ?? [], 'keyName'), true) => ['InvalidKeyPair.NotFound', "The key pair '{$key}' does not exist"],
            ! str_starts_with($zone, $region) => ['InvalidSubnetID.NotFound', "The subnet ID '{$subnet}' does not exist"],
            in_array($type, $this->notInZone[$zone] ?? [], true) => ['Unsupported', "Your requested instance type ({$type}) is not supported in your requested Availability Zone ({$zone})."],
            $groups === [] || array_diff($groups, $knownGroups) !== [] => ['InvalidGroup.NotFound', 'The security group does not exist'],
            ($params['MinCount'] ?? null) !== '1' || ($params['MaxCount'] ?? null) !== '1' => ['InvalidParameterValue', 'MinCount and MaxCount must be 1 here.'],
            default => null,
        };
        if ($problem !== null) {
            return $this->error('ec2', 400, $problem[0], $problem[1]);
        }
        $n = $this->next;
        $id = self::instanceId(900 + $n);
        $tags = [];
        for ($t = 1; isset($params["TagSpecification.1.Tag.{$t}.Key"]); $t++) {
            $tags[] = ['key' => $params["TagSpecification.1.Tag.{$t}.Key"], 'value' => $params["TagSpecification.1.Tag.{$t}.Value"] ?? ''];
        }
        $this->instance($region, $id, '', null, [
            'instanceType' => $type, 'imageId' => $params['ImageId'], 'instanceState' => ['code' => 0, 'name' => 'pending'], 'keyName' => $key,
            'placement' => ['availabilityZone' => $zone, 'tenancy' => 'default'], 'tagSet' => $tags, 'launchTime' => '2026-11-27T10:00:00.000Z',
            'disk' => (int) ($params['BlockDeviceMapping.1.Ebs.VolumeSize'] ?? 8),
        ]);
        $this->instances[$region][$id]['userData'] = $userData;
        $this->tokens[$token] = $id;
        $this->later($region, $id, function () use ($region, $id, $n): void {
            $this->instances[$region][$id]['instanceState'] = ['code' => 16, 'name' => 'running'];
            $this->instances[$region][$id]['ipAddress'] = '203.0.113.'.(100 + $n);
        });
        $made = $this->instances[$region][$id];
        unset($made['userData']);

        return $this->ok('RunInstances', ['reservationId' => 'r-0fa'.$n, 'ownerId' => self::ACCOUNT, 'instancesSet' => [$made]]);
    }

    /** @param  array<string, mixed>  $params */
    private function power(string $region, string $action, array $params): PromiseInterface
    {
        $ids = self::listOf($params, 'InstanceId');
        foreach ($ids as $id) {
            if (! isset($this->instances[$region][$id])) {
                return $this->error('ec2', 400, 'InvalidInstanceID.NotFound', "The instance ID '{$id}' does not exist");
            }
            $state = $this->instances[$region][$id]['instanceState']['name'];
            $wrong = match ($action) {
                'StartInstances' => $state !== 'stopped',
                'RebootInstances' => $state !== 'running',
                'StopInstances' => ! in_array($state, ['running', 'pending', 'stopping', 'stopped'], true),
                default => false,
            };
            if ($wrong) {
                return $this->error('ec2', 400, 'IncorrectInstanceState', "The instance '{$id}' is not in a state from which it can be ".strtolower(str_replace('Instances', '', $action)).'ped.');
            }
        }
        $changes = [];
        foreach ($ids as $id) {
            $fixed = in_array($this->instances[$region][$id]['ipAddress'] ?? null, array_column($this->addresses[$region] ?? [], 'publicIp'), true);
            [$now, $then] = match ($action) {
                'StopInstances' => ['stopping', 'stopped'],
                'StartInstances' => ['pending', 'running'],
                'TerminateInstances' => ['shutting-down', 'terminated'],
                default => ['running', 'running'],
            };
            $previous = $this->instances[$region][$id]['instanceState']['name'];
            $this->instances[$region][$id]['instanceState'] = ['code' => 0, 'name' => $now];
            $n = $this->next++;
            $this->later($region, $id, function () use ($region, $id, $then, $fixed, $n): void {
                $this->instances[$region][$id]['instanceState'] = ['code' => 80, 'name' => $then];
                if ($then === 'stopped' && ! $fixed) {
                    $this->instances[$region][$id]['ipAddress'] = null;
                }
                if ($then === 'running' && ($this->instances[$region][$id]['ipAddress'] ?? null) === null) {
                    $this->instances[$region][$id]['ipAddress'] = '198.51.100.'.(100 + $n);
                }
            });
            $changes[] = ['instanceId' => $id, 'currentState' => ['name' => $now], 'previousState' => ['name' => $previous]];
        }

        return $this->ok($action, $action === 'RebootInstances' ? ['return' => true] : ['instancesSet' => $changes]);
    }

    /** @param  array<string, mixed>  $params */
    private function modify(string $region, array $params): PromiseInterface
    {
        $id = (string) ($params['InstanceId'] ?? '');
        $type = (string) ($params['InstanceType.Value'] ?? '');
        if (! isset($this->instances[$region][$id])) {
            return $this->error('ec2', 400, 'InvalidInstanceID.NotFound', "The instance ID '{$id}' does not exist");
        }
        if ($this->instances[$region][$id]['instanceState']['name'] !== 'stopped') {
            return $this->error('ec2', 400, 'IncorrectInstanceState', "The instance '{$id}' is not in the 'stopped' state.");
        }
        if (! isset(self::TYPES[$type]) || self::TYPES[$type][2] !== $this->instances[$region][$id]['architecture']) {
            return $this->error('ec2', 400, 'InvalidParameterValue', "The instance type '{$type}' does not support the architecture of this instance.");
        }
        $this->instances[$region][$id]['instanceType'] = $type;

        return $this->ok('ModifyInstanceAttribute', ['return' => true]);
    }

    /** @param  array<string, mixed>  $params */
    private function createSnapshot(string $region, array $params): PromiseInterface
    {
        $volume = (string) ($params['VolumeId'] ?? '');
        if (! isset($this->volumes[$region][$volume])) {
            return $this->error('ec2', 400, 'InvalidVolume.NotFound', "The volume '{$volume}' does not exist.");
        }
        $id = sprintf('snap-0fa%014x', $this->next++);
        $tags = [];
        for ($t = 1; isset($params["TagSpecification.1.Tag.{$t}.Key"]); $t++) {
            $tags[] = ['key' => $params["TagSpecification.1.Tag.{$t}.Key"], 'value' => $params["TagSpecification.1.Tag.{$t}.Value"] ?? ''];
        }
        $this->snapshots[$region][$id] = self::snapshotOf($id, $volume, (string) ($params['Description'] ?? ''), 'pending', '2026-11-27T10:00:00.000Z', $this->volumes[$region][$volume], $tags);

        return $this->ok('CreateSnapshot', $this->snapshots[$region][$id]);
    }

    /** @param  array<string, mixed>  $params */
    private function deleteSnapshot(string $region, array $params): PromiseInterface
    {
        $id = (string) ($params['SnapshotId'] ?? '');
        if (! isset($this->snapshots[$region][$id])) {
            return $this->error('ec2', 400, 'InvalidSnapshot.NotFound', "The snapshot '{$id}' does not exist.");
        }
        unset($this->snapshots[$region][$id]);

        return $this->ok('DeleteSnapshot', ['return' => true]);
    }

    /**
     * @param  list<array<string, string>>  $tags
     * @return array<string, mixed>
     */
    public static function snapshotOf(string $id, string $volume, string $description, string $status = 'completed', string $started = '2026-11-20T09:00:00.000Z', int $size = 30, array $tags = []): array
    {
        return ['snapshotId' => $id, 'volumeId' => $volume, 'status' => $status, 'startTime' => $started, 'progress' => $status === 'completed' ? '100%' : '0%', 'ownerId' => self::ACCOUNT, 'volumeSize' => $size, 'description' => $description, 'encrypted' => false, 'tagSet' => $tags];
    }

    /** The root volume of an instance in the fake. */
    public function volumeOf(string $region, string $id): string
    {
        return (string) $this->instances[$region][$id]['blockDeviceMapping'][0]['ebs']['volumeId'];
    }

    public static function ami(string $region, string $arch): string
    {
        return 'ami-0'.substr(md5($region), 0, 8).($arch === 'arm64' ? 'arm' : 'x86').'0001';
    }

    private static function vpc(string $region): string
    {
        return 'vpc-0'.substr(md5($region), 0, 12);
    }

    /** @return array<string, mixed> */
    private function read(string $region, string $id): array
    {
        $key = $region.'/'.$id;
        if (isset($this->pending[$key]) && $this->pending[$key]['polls']-- <= 0) {
            $apply = $this->pending[$key]['apply'];
            unset($this->pending[$key]);
            $apply();
        }
        $instance = $this->instances[$region][$id];
        unset($instance['userData']);

        return $instance;
    }

    private function later(string $region, string $id, callable $apply): void
    {
        $this->pending[$region.'/'.$id] = ['polls' => $this->settlePolls, 'apply' => $apply];
    }

    /** @param  array<string, mixed>  $data */
    private function ok(string $action, array $data): PromiseInterface
    {
        return Http::response(self::xml($action.'Response', ['requestId' => 'fake-'.$action, ...$data], self::EC2_NS), 200, ['Content-Type' => 'text/xml;charset=UTF-8']);
    }

    private function error(string $service, int $status, string $code, string $message): PromiseInterface
    {
        return match ($service) {
            'ec2' => Http::response(self::xml('Response', ['Errors' => ['Error' => ['Code' => $code, 'Message' => $message]], 'RequestID' => 'fake-error']), $status, ['Content-Type' => 'text/xml;charset=UTF-8']),
            'sts' => Http::response(self::xml('ErrorResponse', ['Error' => ['Type' => 'Sender', 'Code' => $code, 'Message' => $message], 'RequestId' => 'fake-error'], 'https://sts.amazonaws.com/doc/2011-06-15/'), $status, ['Content-Type' => 'text/xml']),
            default => Http::response(['__type' => $code, 'message' => $message], $status, ['Content-Type' => 'application/x-amz-json-1.1', 'x-amzn-ErrorType' => $code.':']),
        };
    }

    /**
     * @param  list<mixed>  $rows
     * @param  array<string, mixed>  $params
     * @return array{0: list<mixed>, 1: string|null}
     */
    private static function page(array $rows, array $params): array
    {
        $size = isset($params['MaxResults']) ? max(1, (int) $params['MaxResults']) : count($rows) + 1;
        $offset = isset($params['NextToken']) ? (int) base64_decode((string) $params['NextToken']) : 0;

        return [array_slice($rows, $offset, $size), $offset + $size < count($rows) ? base64_encode((string) ($offset + $size)) : null];
    }

    /** @return array<string, string> a form body, keeping AWS's dotted names as they are */
    private static function form(string $body): array
    {
        $params = [];
        foreach (explode('&', $body) as $pair) {
            if ($pair !== '') {
                [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
                $params[rawurldecode($key)] = rawurldecode($value);
            }
        }

        return $params;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return list<string>
     */
    private static function listOf(array $params, string $name): array
    {
        $values = [];
        for ($n = 1; isset($params["{$name}.{$n}"]); $n++) {
            $values[] = (string) $params["{$name}.{$n}"];
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, list<string>>
     */
    private static function filtersOf(array $params): array
    {
        $filters = [];
        for ($n = 1; isset($params["Filter.{$n}.Name"]); $n++) {
            $filters[(string) $params["Filter.{$n}.Name"]] = self::listOf($params, "Filter.{$n}.Value");
        }

        return $filters;
    }

    /** @param  list<string>  $patterns */
    private static function matchesAny(string $name, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (fnmatch($pattern, $name)) {
                return true;
            }
        }

        return false;
    }

    /** @param  array<string, mixed>  $data */
    private static function xml(string $root, array $data, ?string $namespace = null): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<'.$root.($namespace === null ? '' : ' xmlns="'.$namespace.'"').'>'.self::children($data).'</'.$root.'>';
    }

    /** @param  array<array-key, mixed>  $data */
    private static function children(array $data): string
    {
        $out = '';
        foreach ($data as $key => $value) {
            if ($value === null) {
                continue;
            }
            $name = is_int($key) ? 'item' : (string) $key;
            $out .= '<'.$name.'>'.(is_array($value) ? self::children($value) : htmlspecialchars(is_bool($value) ? ($value ? 'true' : 'false') : (string) $value, ENT_XML1)).'</'.$name.'>';
        }

        return $out;
    }
}
