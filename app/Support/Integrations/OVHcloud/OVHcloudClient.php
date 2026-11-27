<?php

namespace App\Support\Integrations\OVHcloud;

use App\Support\Integrations\IntegrationFailed;
use App\Support\Integrations\ProviderClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;

/**
 * The OVHcloud API v1 (api.ovh.com). Every call is signed with the
 * application secret and consumer key: "$1$" + SHA1 of the secret, consumer
 * key, method, full URL, body and timestamp joined with "+", on OVHcloud's
 * clock (from /auth/time). The signature is made from the request exactly
 * as it's sent, so the body and query are never encoded twice.
 */
final class OVHcloudClient extends ProviderClient
{
    public const array ENDPOINTS = [
        'ovh-eu' => 'https://eu.api.ovh.com/1.0',
        'ovh-ca' => 'https://ca.api.ovh.com/1.0',
        'ovh-us' => 'https://api.us.ovhcloud.com/1.0',
    ];

    private const int CLOCK_MINUTES = 60;

    public function __construct(
        private readonly string $endpoint,
        private readonly string $applicationKey,
        private readonly string $applicationSecret,
        private readonly string $consumerKey,
        private readonly bool $signed = true,
    ) {}

    /** The same API without keys, for the calls OVHcloud answers for anyone (its catalogue). */
    public function anonymous(): self
    {
        return new self($this->endpoint, '', '', '', signed: false);
    }

    public static function signature(string $secret, string $consumerKey, string $method, string $url, string $body, int $timestamp): string
    {
        return '$1$'.sha1(implode('+', [$secret, $consumerKey, strtoupper($method), $url, $body, (string) $timestamp]));
    }

    public static function url(string $endpoint): string
    {
        return self::ENDPOINTS[$endpoint] ?? self::ENDPOINTS['ovh-eu'];
    }

    protected function baseUrl(): string
    {
        return self::url($this->endpoint);
    }

    protected function providerName(): string
    {
        return 'OVHcloud';
    }

    protected function headers(): array
    {
        return $this->signed ? ['X-Ovh-Application' => $this->applicationKey, 'X-Ovh-Consumer' => $this->consumerKey] : [];
    }

    protected function request(): PendingRequest
    {
        $request = parent::request();
        if (! $this->signed) {
            return $request;
        }
        $delta = $this->clockDelta();

        return $request->withRequestMiddleware(function (RequestInterface $request) use ($delta): RequestInterface {
            $timestamp = time() + $delta;
            $body = (string) $request->getBody();
            if ($request->getBody()->isSeekable()) {
                $request->getBody()->rewind();
            }

            return $request
                ->withHeader('X-Ovh-Timestamp', (string) $timestamp)
                ->withHeader('X-Ovh-Signature', self::signature($this->applicationSecret, $this->consumerKey, $request->getMethod(), (string) $request->getUri(), $body, $timestamp));
        });
    }

    protected function providerMessage(Response $response): ?string
    {
        $message = $response->json('message');

        return is_string($message) ? $message : null;
    }

    protected function failure(Response $response): ?IntegrationFailed
    {
        $code = (string) $response->json('errorCode');
        $said = strtolower((string) $this->providerMessage($response));
        $status = $response->status();

        if ($code === 'QUERY_TIME_OUT' || str_contains($said, 'query out of time')) {
            Cache::forget($this->clockKey());

            return new IntegrationFailed($this->t('OVHcloud says the request’s clock was off. Try again.'), IntegrationFailed::UNAVAILABLE, $status);
        }

        $refused = in_array($status, [400, 401, 403], true);

        return match (true) {
            $code === 'INVALID_SIGNATURE' || ($refused && str_contains($said, 'invalid signature')) => new IntegrationFailed($this->t('OVHcloud turned the application secret down. Check it’s copied in full.'), IntegrationFailed::CREDENTIALS, $status, 'application_secret'),
            $code === 'INVALID_KEY' || ($refused && str_contains($said, 'application key')) => new IntegrationFailed($this->t('OVHcloud doesn’t know this application key. Check it’s copied in full and that the region is where you made it.'), IntegrationFailed::CREDENTIALS, $status, 'application_key'),
            $code === 'INVALID_CREDENTIAL' || $code === 'NOT_CREDENTIAL' || ($refused && str_contains($said, 'credential')) => new IntegrationFailed($this->t('OVHcloud turned the consumer key down. It may have expired or been revoked. Make new keys and connect again.'), IntegrationFailed::CREDENTIALS, $status, 'consumer_key'),
            $code === 'NOT_GRANTED_CALL' || ($refused && str_contains($said, 'not been granted')) => new IntegrationFailed($this->t('These keys aren’t allowed to do that. Make new keys from the link in the connect form, which asks for what ServerOS needs.'), IntegrationFailed::PERMISSION, $status),
            $status === 401 => new IntegrationFailed($this->t('OVHcloud turned the keys down. Check all three are copied in full.'), IntegrationFailed::CREDENTIALS, $status),
            default => null,
        };
    }

    /** Seconds between OVHcloud's clock and this one, which every signature uses. */
    private function clockDelta(): int
    {
        return (int) Cache::remember($this->clockKey(), now()->addMinutes(self::CLOCK_MINUTES), function (): int {
            try {
                $response = Http::baseUrl(rtrim($this->baseUrl(), '/').'/')
                    ->acceptJson()
                    ->timeout(self::TIMEOUT)
                    ->connectTimeout(self::CONNECT_TIMEOUT)
                    ->get('auth/time');
            } catch (ConnectionException) {
                throw new IntegrationFailed($this->t(':provider couldn’t be reached. Try again in a few minutes.'), IntegrationFailed::UNAVAILABLE);
            }
            $time = trim($response->body());
            if (! $response->successful() || ! ctype_digit($time)) {
                throw new IntegrationFailed($this->t(':provider isn’t answering properly right now. Try again in a few minutes.'), IntegrationFailed::UNAVAILABLE, $response->status());
            }

            return (int) $time - time();
        });
    }

    private function clockKey(): string
    {
        return 'integrations:ovhcloud:clock:'.$this->endpoint;
    }
}
