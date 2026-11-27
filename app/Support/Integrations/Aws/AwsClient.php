<?php

namespace App\Support\Integrations\Aws;

use App\Support\Integrations\IntegrationFailed;
use App\Support\Integrations\ProviderClient;
use DOMDocument;
use DOMElement;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Psr\Http\Message\RequestInterface;
use Throwable;

/**
 * AWS's APIs, signed with Signature Version 4 by AwsSigner: the EC2 and STS
 * Query APIs (form-encoded POSTs answered in XML), and the JSON 1.1 APIs of
 * Systems Manager and the Price List Query API. One access key reaches
 * every region; each call names its own.
 */
final class AwsClient extends ProviderClient
{
    public const string EC2_VERSION = '2016-11-15';

    public const string STS_VERSION = '2011-06-15';

    public const string PRICING_REGION = 'us-east-1';

    private const int MAX_PAGES = 20;

    private const array THROTTLED = ['Throttling', 'ThrottlingException', 'RequestLimitExceeded', 'TooManyRequestsException', 'RequestThrottled', 'RequestThrottledException', 'ProvisionedThroughputExceededException'];

    private const array UNKNOWN_KEY = ['AuthFailure', 'InvalidClientTokenId', 'UnrecognizedClientException', 'InvalidAccessKeyId'];

    private const array NOT_ALLOWED = ['UnauthorizedOperation', 'AccessDenied', 'AccessDeniedException'];

    /** @var array{service: string, region: string, url: string, body: string, type: string, accept: string, target: string|null, json: bool, retry: bool} */
    private array $call = ['service' => 'ec2', 'region' => 'us-east-1', 'url' => 'https://ec2.us-east-1.amazonaws.com', 'body' => '', 'type' => '', 'accept' => 'text/xml', 'target' => null, 'json' => false, 'retry' => false];

    public function __construct(
        private readonly string $keyId,
        private readonly string $secret,
    ) {}

    /**
     * An EC2 Query API action. Describe actions are reads, tried again when
     * EC2 is busy; anything else only when EC2 turned it away unread.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function ec2(string $region, string $action, array $params = [], bool $repeatable = false): array
    {
        return $this->query('ec2', $region, $action, self::EC2_VERSION, $params, $repeatable || str_starts_with($action, 'Describe'));
    }

    /**
     * Every page of an EC2 Describe action's list.
     *
     * @param  array<string, mixed>  $params
     * @return list<array<string, mixed>>
     */
    public function ec2All(string $region, string $action, array $params, string $key, ?int $page = 1000): array
    {
        $items = [];
        $token = null;
        for ($i = 0; $i < self::MAX_PAGES; $i++) {
            $body = $this->ec2($region, $action, [...$params, 'MaxResults' => $page, 'NextToken' => $token]);
            array_push($items, ...self::items($body[$key] ?? null));
            $next = $body['nextToken'] ?? null;
            if (! is_string($next) || $next === '' || $next === $token) {
                break;
            }
            $token = $next;
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function sts(string $region, string $action, array $params = []): array
    {
        return $this->query('sts', $region, $action, self::STS_VERSION, $params, true);
    }

    /**
     * A Systems Manager action, like GetParameter.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function ssm(string $region, string $action, array $body): array
    {
        return $this->json('ssm', $region, 'https://ssm.'.$this->checked($region).'.amazonaws.com', 'AmazonSSM.'.$action, $body);
    }

    /**
     * A Price List Query API action, like GetProducts. Prices are the same
     * from every endpoint, so it always asks the one in us-east-1.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function pricing(string $action, array $body): array
    {
        return $this->json('pricing', self::PRICING_REGION, 'https://api.pricing.'.self::PRICING_REGION.'.amazonaws.com', 'AWSPriceListService.'.$action, $body);
    }

    /**
     * @param  array<string, mixed>  $names  filter name => value or values
     * @return array{Filter: list<array{Name: string, Value: list<string>}>}
     */
    public static function filters(array $names): array
    {
        $filters = [];
        foreach ($names as $name => $values) {
            $filters[] = ['Name' => (string) $name, 'Value' => array_values(array_map('strval', is_array($values) ? $values : [$values]))];
        }

        return ['Filter' => $filters];
    }

    /**
     * A Query API list (item or member elements) as arrays.
     *
     * @return list<array<string, mixed>>
     */
    public static function items(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_array'));
    }

    /**
     * @param  array<string, mixed>  $resource
     * @return array<string, string>
     */
    public static function tags(array $resource): array
    {
        $tags = [];
        foreach (self::items($resource['tagSet'] ?? null) as $tag) {
            if (is_string($tag['key'] ?? null)) {
                $tags[$tag['key']] = is_string($tag['value'] ?? null) ? $tag['value'] : '';
            }
        }

        return $tags;
    }

    /**
     * Query API parameters as AWS names them: lists numbered from 1
     * (Filter.1.Value.2), structures joined with dots, nulls left out.
     *
     * @param  array<array-key, mixed>  $params
     * @return array<string, string>
     */
    public static function flatten(array $params, string $prefix = ''): array
    {
        $out = [];
        foreach ($params as $key => $value) {
            $name = $prefix === '' ? (string) $key : $prefix.'.'.(is_int($key) ? $key + 1 : $key);
            if (is_array($value)) {
                $out += self::flatten($value, $name);
            } elseif ($value !== null) {
                $out[$name] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            }
        }

        return $out;
    }

    /**
     * An XML answer as arrays: elements become keys, lists of item or member
     * elements become lists, and text becomes strings.
     *
     * @return array<string, mixed>
     */
    public static function xml(string $body): array
    {
        if (trim($body) === '') {
            return [];
        }
        $dom = new DOMDocument;
        $errors = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($body, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($errors);
        if (! $loaded || $dom->documentElement === null) {
            return [];
        }
        $value = self::node($dom->documentElement);

        return is_array($value) ? $value : [];
    }

    public static function errorCode(Response $response): ?string
    {
        $type = $response->header('x-amzn-ErrorType');
        $json = $response->json();
        $code = match (true) {
            is_array($json) && is_string($json['__type'] ?? null) => $json['__type'],
            is_array($json) && is_string($json['code'] ?? null) => $json['code'],
            $type !== '' => $type,
            default => self::xmlError($response)['Code'] ?? null,
        };
        if (! is_string($code) || $code === '') {
            return null;
        }
        $code = str_contains($code, '#') ? substr($code, (int) strrpos($code, '#') + 1) : $code;

        return explode(':', $code)[0];
    }

    protected function baseUrl(): string
    {
        return $this->call['url'];
    }

    protected function providerName(): string
    {
        return 'AWS';
    }

    protected function headers(): array
    {
        return [];
    }

    protected function request(): PendingRequest
    {
        $call = $this->call;
        $signer = new AwsSigner($this->keyId, $this->secret);

        return parent::request()
            ->withBody($call['body'], $call['type'])
            ->withHeaders(array_filter(['Accept' => $call['accept'], 'X-Amz-Target' => $call['target']], fn (?string $value) => $value !== null))
            ->withRequestMiddleware(fn (RequestInterface $request): RequestInterface => $signer->sign($request, $call['region'], $call['service'], now()));
    }

    protected function decode(Response $response): array
    {
        return $this->call['json'] ? parent::decode($response) : self::xml($response->body());
    }

    protected function providerMessage(Response $response): ?string
    {
        $json = $response->json();
        if (is_array($json)) {
            $message = $json['message'] ?? $json['Message'] ?? null;

            return is_string($message) ? $message : null;
        }
        $message = self::xmlError($response)['Message'] ?? null;

        return is_string($message) ? $message : null;
    }

    protected function failure(Response $response): ?IntegrationFailed
    {
        $code = (string) self::errorCode($response);
        $said = (string) $this->providerMessage($response);
        $status = $response->status();
        $region = $this->call['region'];

        return match (true) {
            in_array($code, self::UNKNOWN_KEY, true) => new IntegrationFailed($this->t('AWS doesn’t know this access key. Check the access key ID is copied in full and the key is still active in IAM.'), IntegrationFailed::CREDENTIALS, $status, 'access_key_id'),
            $code === 'SignatureDoesNotMatch' => new IntegrationFailed($this->t('AWS says the secret access key doesn’t match the access key ID. Copy the secret again.'), IntegrationFailed::CREDENTIALS, $status, 'secret_access_key'),
            in_array($code, ['RequestExpired', 'RequestTimeTooSkewed'], true) || str_contains(strtolower($said), 'signature expired') => new IntegrationFailed($this->t('AWS turned the request down because this panel’s clock is off. Check the server’s time is kept in sync.'), IntegrationFailed::UNAVAILABLE, $status),
            in_array($code, self::NOT_ALLOWED, true) => new IntegrationFailed(preg_match('/perform: ([a-z0-9-]+:[A-Za-z0-9]+)/', $said, $m) === 1
                ? $this->t('This access key isn’t allowed to use :action. Add it to the key’s IAM policy.', ['action' => $m[1]])
                : $this->t('This access key isn’t allowed to do that. Check its IAM policy has everything in the policy on the connect form.'), IntegrationFailed::PERMISSION, $status),
            $code === 'OptInRequired' => new IntegrationFailed($this->t('AWS says this account can’t use :region yet. If it’s a region you have to turn on, turn it on under Account › AWS Regions.', ['region' => $region])),
            $code === 'Blocked' => new IntegrationFailed($this->t('AWS has blocked this account. Check the AWS console.'), IntegrationFailed::PERMISSION, $status),
            $status === 429 || in_array($code, self::THROTTLED, true) => new IntegrationFailed($this->t('AWS is limiting requests from this account right now. Try again in a few minutes.'), IntegrationFailed::RATE_LIMITED, $status),
            $code === 'InsufficientInstanceCapacity' => new IntegrationFailed($this->t('AWS has no room for this instance type in :region right now. Pick another type, or try again later.', ['region' => $region]), IntegrationFailed::INVALID, $status),
            in_array($code, ['InstanceLimitExceeded', 'VcpuLimitExceeded'], true) => new IntegrationFailed($this->t('This account has used its vCPU limit for this kind of instance in :region. Ask AWS for more under Service Quotas, or stop another instance first.', ['region' => $region]), IntegrationFailed::INVALID, $status),
            in_array($code, ['IncorrectInstanceState', 'IncorrectState'], true) => new IntegrationFailed($this->t('The instance isn’t in a state that allows that right now. Try again in a minute.'), IntegrationFailed::CONFLICT, $status),
            $code === 'InvalidSnapshot.InUse' => new IntegrationFailed($this->t('An AMI uses this snapshot, so AWS won’t delete it. Deregister the AMI first.'), IntegrationFailed::CONFLICT, $status),
            str_ends_with($code, '.NotFound') || in_array($code, ['ParameterNotFound', 'NotFoundException'], true) => new IntegrationFailed($this->t('AWS can’t find it. It may have been deleted there.'), IntegrationFailed::NOT_FOUND, $status),
            default => null,
        };
    }

    protected function retryable(Throwable $e, ?string $method): bool
    {
        if (! $e instanceof RequestException) {
            return $this->call['retry'] && $e instanceof ConnectionException;
        }
        $status = $e->response->status();
        if ($status === 429 || in_array(self::errorCode($e->response), self::THROTTLED, true)) {
            return true;
        }

        return $this->call['retry'] && $status >= 500;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function query(string $service, string $region, string $action, string $version, array $params, bool $retry): array
    {
        $region = $this->checked($region);
        $fields = self::flatten(['Action' => $action, 'Version' => $version, ...$params]);
        $this->call = [
            'service' => $service,
            'region' => $region,
            'url' => 'https://'.$service.'.'.$region.'.amazonaws.com',
            'body' => implode('&', array_map(fn (string $key, string $value) => AwsSigner::encode($key).'='.AwsSigner::encode($value), array_keys($fields), $fields)),
            'type' => 'application/x-www-form-urlencoded; charset=utf-8',
            'accept' => 'text/xml',
            'target' => null,
            'json' => false,
            'retry' => $retry,
        ];

        return $this->send('POST', '', []);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function json(string $service, string $region, string $url, string $target, array $body): array
    {
        $this->call = [
            'service' => $service,
            'region' => $this->checked($region),
            'url' => $url,
            'body' => (string) json_encode($body === [] ? (object) [] : $body, JSON_UNESCAPED_SLASHES),
            'type' => 'application/x-amz-json-1.1',
            'accept' => 'application/json',
            'target' => $target,
            'json' => true,
            'retry' => true,
        ];

        return $this->send('POST', '', []);
    }

    private function checked(string $region): string
    {
        if (! AwsRegions::valid($region)) {
            throw new IntegrationFailed($this->t('That isn’t an AWS region.'));
        }

        return $region;
    }

    /** @return array<string, mixed> */
    private static function xmlError(Response $response): array
    {
        $body = self::xml($response->body());
        $error = $body['Errors']['Error'] ?? $body['Error'] ?? [];

        return is_array($error) ? $error : [];
    }

    /** @return array<array-key, mixed>|string */
    private static function node(DOMElement $element): array|string
    {
        $children = [];
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $children[] = $child;
            }
        }
        if ($children === []) {
            return trim($element->textContent);
        }
        $listed = array_filter($children, fn (DOMElement $child) => in_array($child->localName, ['item', 'member'], true));
        if (count($listed) === count($children)) {
            return array_map(fn (DOMElement $child) => self::node($child), $children);
        }
        $out = [];
        foreach ($children as $child) {
            $out[(string) $child->localName] ??= self::node($child);
        }

        return $out;
    }
}
