<?php

namespace App\Support\Integrations\Aws;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Psr\Http\Message\RequestInterface;

/**
 * AWS Signature Version 4 (docs.aws.amazon.com/IAM/latest/UserGuide/create-signed-request.html),
 * checked against AWS's published SigV4 test suite in tests/Feature/Integrations/AwsSignatureTest.php.
 */
final readonly class AwsSigner
{
    public const string ALGORITHM = 'AWS4-HMAC-SHA256';

    public function __construct(
        private string $keyId,
        private string $secret,
    ) {}

    /**
     * Adds X-Amz-Date and Authorization to a request, signing its host,
     * content type, x-amz-* headers and body.
     */
    public function sign(RequestInterface $request, string $region, string $service, DateTimeInterface $at): RequestInterface
    {
        $request = $request->withHeader('X-Amz-Date', self::amzDate($at));
        $headers = [];
        foreach (array_keys($request->getHeaders()) as $name) {
            $lower = strtolower((string) $name);
            if ($lower === 'host' || $lower === 'content-type' || str_starts_with($lower, 'x-amz-')) {
                $headers[$lower] = array_values($request->getHeader((string) $name));
            }
        }
        if (! isset($headers['host'])) {
            $headers['host'] = [$request->getUri()->getHost()];
        }
        $body = $request->getBody();
        $payload = (string) $body;
        if ($body->isSeekable()) {
            $body->rewind();
        }

        return $request->withHeader('Authorization', $this->authorization(
            $request->getMethod(),
            $request->getUri()->getPath(),
            $request->getUri()->getQuery(),
            $headers,
            $payload,
            $region,
            $service,
            $at,
        ));
    }

    /**
     * The Authorization header for a request, signing exactly the headers given.
     *
     * @param  array<string, string|list<string>>  $headers
     */
    public function authorization(string $method, string $path, string $query, array $headers, string $payload, string $region, string $service, DateTimeInterface $at): string
    {
        [$canonical, $signed] = self::canonicalRequest($method, $path, $query, $headers, hash('sha256', $payload));
        $scope = self::scope($at, $region, $service);
        $signature = hash_hmac('sha256', self::stringToSign($at, $scope, $canonical), self::signingKey($this->secret, $at, $region, $service));

        return self::ALGORITHM.' Credential='.$this->keyId.'/'.$scope.', SignedHeaders='.$signed.', Signature='.$signature;
    }

    /**
     * @param  array<string, string|list<string>>  $headers
     * @return array{0: string, 1: string} the canonical request and its signed headers
     */
    public static function canonicalRequest(string $method, string $path, string $query, array $headers, string $payloadHash): array
    {
        $lines = [];
        foreach ($headers as $name => $values) {
            $name = strtolower(trim((string) $name));
            $values = array_map(fn (string $value) => (string) preg_replace('/\s+/', ' ', trim($value)), is_array($values) ? $values : [$values]);
            $lines[$name] = isset($lines[$name]) ? $lines[$name].','.implode(',', $values) : implode(',', $values);
        }
        ksort($lines, SORT_STRING);
        $signed = implode(';', array_keys($lines));
        $canonicalHeaders = implode('', array_map(fn (string $name, string $value) => $name.':'.$value."\n", array_keys($lines), $lines));

        return [implode("\n", [
            strtoupper($method),
            self::canonicalPath($path),
            self::canonicalQuery($query),
            $canonicalHeaders,
            $signed,
            $payloadHash,
        ]), $signed];
    }

    public static function stringToSign(DateTimeInterface $at, string $scope, string $canonicalRequest): string
    {
        return implode("\n", [self::ALGORITHM, self::amzDate($at), $scope, hash('sha256', $canonicalRequest)]);
    }

    public static function signingKey(string $secret, DateTimeInterface $at, string $region, string $service): string
    {
        $date = hash_hmac('sha256', self::utc($at)->format('Ymd'), 'AWS4'.$secret, true);
        $region = hash_hmac('sha256', $region, $date, true);
        $service = hash_hmac('sha256', $service, $region, true);

        return hash_hmac('sha256', 'aws4_request', $service, true);
    }

    public static function scope(DateTimeInterface $at, string $region, string $service): string
    {
        return self::utc($at)->format('Ymd').'/'.$region.'/'.$service.'/aws4_request';
    }

    public static function amzDate(DateTimeInterface $at): string
    {
        return self::utc($at)->format('Ymd\THis\Z');
    }

    /** Every byte URI-encoded except A-Z, a-z, 0-9, hyphen, period, underscore and tilde. */
    public static function encode(string $value): string
    {
        return rawurlencode($value);
    }

    private static function canonicalPath(string $path): string
    {
        if ($path === '' || $path === '/') {
            return '/';
        }

        return implode('/', array_map(fn (string $segment) => self::encode(rawurldecode($segment)), explode('/', $path)));
    }

    private static function canonicalQuery(string $query): string
    {
        if ($query === '') {
            return '';
        }
        $pairs = [];
        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $pairs[] = [self::encode(rawurldecode($key)), self::encode(rawurldecode($value))];
        }
        usort($pairs, fn (array $a, array $b) => strcmp($a[0], $b[0]) ?: strcmp($a[1], $b[1]));

        return implode('&', array_map(fn (array $pair) => $pair[0].'='.$pair[1], $pairs));
    }

    private static function utc(DateTimeInterface $at): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($at)->setTimezone(new DateTimeZone('UTC'));
    }
}
