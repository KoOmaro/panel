<?php

namespace App\Support\Integrations\Proxmox;

use App\Support\Edition;
use App\Support\Integrations\IntegrationFailed;
use App\Support\Integrations\ProviderClient;
use App\Support\Monitors\HostResolver;
use GuzzleHttp\Handler\StreamHandler;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Symfony\Component\HttpFoundation\IpUtils;
use Throwable;

/**
 * The Proxmox VE API (pve.proxmox.com/pve-docs/api-viewer) of one node or
 * cluster, signed in with an API token.
 *
 * The address is resolved for every request and the connection pinned to
 * that answer, so it can't be pointed somewhere else between the check and
 * the call. A private address is refused unless this is a self-hosted panel
 * with MONITORS_ALLOW_PRIVATE on, like monitors and GitLab; link-local
 * never works. Redirects aren't followed.
 *
 * Proxmox usually has a self-signed certificate. With a pinned SHA-256
 * fingerprint the request goes through PHP's stream transport, which checks
 * the certificate against it during the handshake, before the token is
 * sent; without one the certificate must be trusted as usual.
 */
final class ProxmoxClient extends ProviderClient
{
    public const int PORT = 8006;

    private const array LINK_LOCAL = ['169.254.0.0/16', 'fe80::/10'];

    /** Statuses worth another try: pveproxy's own "can't reach that node" ones, and a busy proxy in front. */
    private const array RETRY = [502, 503, 504, 595, 596];

    private ?string $ip = null;

    private bool $bare = false;

    private ?string $transportError = null;

    public function __construct(
        private readonly string $address,
        #[\SensitiveParameter] private readonly string $token,
        private readonly ?string $fingerprint = null,
    ) {}

    /**
     * The address as it's kept: https, a host and a port (8006 when none is
     * given), nothing else. Null when it isn't one.
     */
    public static function normalise(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }
        if (! preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
            $url = 'https://'.$url;
        }
        $parts = parse_url($url);
        if (! is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') === ''
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }
        $path = rtrim((string) ($parts['path'] ?? ''), '/');
        if ($path !== '' && preg_match('#^/api2/json$#', $path) !== 1) {
            return null;
        }
        $host = strtolower($parts['host']);
        if (preg_match('/^(?:\[[0-9a-f:.]+\]|[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)*)$/', $host) !== 1) {
            return null;
        }

        return "https://{$host}:".($parts['port'] ?? self::PORT);
    }

    /** A SHA-256 fingerprint as 64 lowercase hex digits, from Proxmox's AB:CD:… form. Null when it isn't one. */
    public static function fingerprint(?string $value): ?string
    {
        $hex = strtolower((string) preg_replace('/[\s:]+/', '', (string) preg_replace('/^sha256\s*(?:fingerprint)?\s*[=:]?\s*/i', '', trim((string) $value))));

        return preg_match('/^[0-9a-f]{64}$/', $hex) === 1 ? $hex : null;
    }

    /** "AB:CD:…", the way Proxmox shows it. */
    public static function showFingerprint(string $hex): string
    {
        return strtoupper(implode(':', str_split($hex, 2)));
    }

    /** The token as Proxmox's header wants it after "PVEAPIToken=": user@realm!tokenid=secret. Null when it isn't one. */
    public static function token(?string $value): ?string
    {
        $token = (string) preg_replace('/^PVEAPIToken[=\s]+/i', '', trim((string) $value));

        return preg_match('/^[^\s@!=]+@[A-Za-z0-9._-]+![A-Za-z][A-Za-z0-9._-]*=[A-Za-z0-9-]{8,128}$/', $token) === 1 ? $token : null;
    }

    /** The token's id without its secret, like serveros@pve!panel. */
    public static function tokenId(string $token): string
    {
        return explode('=', $token, 2)[0];
    }

    public static function host(string $address): string
    {
        return (string) parse_url($address, PHP_URL_HOST);
    }

    public static function port(string $address): int
    {
        return (int) (parse_url($address, PHP_URL_PORT) ?? self::PORT);
    }

    /**
     * What a call returned, from Proxmox's {"data": …} envelope.
     *
     * @param  array<string, mixed>  $query
     */
    public function read(string $path, array $query = []): mixed
    {
        return $this->get($path, $query)['data'] ?? null;
    }

    /**
     * Proxmox answers changes with the task's UPID, or nothing.
     *
     * @param  array<string, mixed>  $body
     */
    public function task(string $method, string $path, array $body = []): ?string
    {
        $data = match ($method) {
            'PUT' => $this->put($path, $body)['data'] ?? null,
            'DELETE' => $this->delete($path)['data'] ?? null,
            default => $this->post($path, $body)['data'] ?? null,
        };

        return is_string($data) && str_starts_with($data, 'UPID:') ? $data : null;
    }

    public function post(string $path, array $body = []): array
    {
        return $body === [] ? $this->bare('POST', $path) : parent::post($path, $body);
    }

    public function put(string $path, array $body = []): array
    {
        return $body === [] ? $this->bare('PUT', $path) : parent::put($path, $body);
    }

    public function delete(string $path): array
    {
        return $this->bare('DELETE', $path);
    }

    /**
     * The node a task runs on, from its UPID (UPID:node:pid:…).
     */
    public static function nodeOfTask(string $upid): ?string
    {
        $parts = explode(':', $upid);

        return $parts[0] === 'UPID' && ($parts[1] ?? '') !== '' ? $parts[1] : null;
    }

    protected function baseUrl(): string
    {
        if ($this->fingerprint !== null && $this->ip !== null) {
            $ip = str_contains($this->ip, ':') ? "[{$this->ip}]" : $this->ip;

            return "https://{$ip}:".self::port($this->address).'/api2/json';
        }

        return $this->address.'/api2/json';
    }

    protected function providerName(): string
    {
        return 'Proxmox';
    }

    protected function headers(): array
    {
        $headers = ['Authorization' => 'PVEAPIToken='.$this->token, 'User-Agent' => 'ServerOS'];

        return $this->fingerprint === null ? $headers : [...$headers, 'Host' => $this->hostHeader()];
    }

    protected function providerMessage(Response $response): ?string
    {
        $message = $response->json('message');
        if (is_string($message) && trim($message) !== '') {
            return trim($message);
        }
        $errors = $response->json('errors');
        if (is_array($errors) && $errors !== []) {
            return collect($errors)->map(fn ($line, $key) => is_string($line) ? (is_string($key) ? "{$key}: ".trim($line) : trim($line)) : null)->filter()->implode(' ');
        }
        $reason = trim($response->reason());
        $standard = [500 => 'Internal Server Error', 400 => 'Bad Request', 403 => 'Forbidden', 401 => 'Unauthorized', 404 => 'Not Found'];

        return $reason !== '' && $reason !== ($standard[$response->status()] ?? null) ? $reason : null;
    }

    protected function failure(Response $response): ?IntegrationFailed
    {
        $status = $response->status();
        $said = $this->providerMessage($response);
        if ($status === 403) {
            return new IntegrationFailed($said === null
                ? $this->t('This token isn’t allowed to do that in Proxmox. Give it a role like PVEVMAdmin on /.')
                : $this->t('This token isn’t allowed to do that in Proxmox (:reason). Give it a role with that privilege, like PVEVMAdmin on /.', ['reason' => rtrim($said, '.')]), IntegrationFailed::PERMISSION, 403);
        }
        if (in_array($status, [595, 596], true)) {
            return new IntegrationFailed($this->t('Proxmox couldn’t reach the node for that. Check it’s online in the cluster.'), IntegrationFailed::UNAVAILABLE, $status);
        }
        if (($status === 500 || $status === 400) && $said !== null) {
            $busy = preg_match('/\b(?:is locked|can\'t lock|got timeout|already running|in progress)\b/i', $said) === 1;

            return new IntegrationFailed($this->t('Proxmox said: :message', ['message' => self::finish($said)]), $busy ? IntegrationFailed::CONFLICT : IntegrationFailed::INVALID, $status);
        }

        return null;
    }

    protected function retryable(Throwable $e, ?string $method): bool
    {
        if ($e instanceof ConnectionException) {
            $this->transportError = $e->getMessage();
            if (self::tlsProblem($e->getMessage()) !== null) {
                return false;
            }
        }
        if ($e instanceof RequestException && $e->response->status() >= 500 && ! in_array($e->response->status(), self::RETRY, true)) {
            return false;
        }

        return parent::retryable($e, $method);
    }

    protected function send(string $method, string $path, array $options): array
    {
        try {
            return parent::send($method, $path, $options);
        } catch (IntegrationFailed $e) {
            throw $this->tlsFailure($e) ?? $e;
        } finally {
            $this->transportError = null;
        }
    }

    // A connection that failed in the TLS handshake, said in words about the certificate, on the fingerprint field.
    private function tlsFailure(IntegrationFailed $e): ?IntegrationFailed
    {
        $tls = $e->reason === IntegrationFailed::UNAVAILABLE && $this->transportError !== null ? self::tlsProblem($this->transportError) : null;
        $host = self::host($this->address);

        return match ($tls) {
            'fingerprint' => new IntegrationFailed($this->t('The certificate at :host doesn’t match the fingerprint you pinned. If you renewed it, connect again with the new fingerprint.', ['host' => $host]), IntegrationFailed::INVALID, null, 'fingerprint'),
            'untrusted' => new IntegrationFailed($this->fingerprint === null
                ? $this->t('ServerOS doesn’t trust the certificate at :host. Proxmox makes its own, so pin it: paste its SHA-256 fingerprint from the node’s System › Certificates.', ['host' => $host])
                : $this->t('The connection to :host couldn’t be secured. Check the address and port.', ['host' => $host]), IntegrationFailed::INVALID, null, 'fingerprint'),
            default => null,
        };
    }

    protected function request(): PendingRequest
    {
        $host = trim(self::host($this->address), '[]');
        $addresses = app(HostResolver::class)->resolve($host);
        if ($addresses === [] || array_filter($addresses, fn (string $ip) => IpUtils::checkIp($ip, self::LINK_LOCAL)) !== []) {
            throw new IntegrationFailed($this->t('ServerOS can’t reach :host, or it points into a private network.', ['host' => $host])
                .(Edition::selfHosted() && ! (bool) config('monitors.allow_private') ? ' '.$this->t('To use a private address, set MONITORS_ALLOW_PRIVATE=true.') : ''), IntegrationFailed::INVALID, null, 'url');
        }
        $this->ip = $addresses[0];

        $request = parent::request();
        if ($this->bare) {
            $request->asForm();
        }

        if ($this->fingerprint !== null) {
            return $request->setHandler(new StreamHandler)->withOptions([
                'allow_redirects' => false,
                'verify' => false,
                'stream_context' => ['ssl' => ['peer_fingerprint' => ['sha256' => $this->fingerprint]]],
            ]);
        }

        $options = ['allow_redirects' => false];
        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            $ip = str_contains($this->ip, ':') ? "[{$this->ip}]" : $this->ip;
            $options['curl'] = [CURLOPT_RESOLVE => ["{$host}:".self::port($this->address).":{$ip}"]];
        }

        return $request->withOptions($options);
    }

    /** @return array<string, mixed> */
    private function bare(string $method, string $path): array
    {
        $this->bare = true;
        try {
            return $this->send($method, $path, []);
        } finally {
            $this->bare = false;
        }
    }

    private function hostHeader(): string
    {
        $host = self::host($this->address);
        $port = self::port($this->address);

        return $port === 443 ? $host : "{$host}:{$port}";
    }

    private static function tlsProblem(string $message): ?string
    {
        if (str_contains($message, 'peer_fingerprint match failure')) {
            return 'fingerprint';
        }

        return preg_match('/SSL certificate problem|self[- ]signed certificate|unable to get local issuer|certificate verify failed|no alternative certificate subject name|SSL: certificate subject name|cURL error (?:51|60)\b/i', $message) === 1 ? 'untrusted' : null;
    }

    private static function finish(string $text): string
    {
        $text = trim($text);

        return preg_match('/[.!?]$/u', $text) === 1 ? $text : $text.'.';
    }
}
