<?php

namespace App\Support\Integrations;

use App\Support\Edition;
use App\Support\Monitors\HostResolver;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * An address a workspace typed for a service it runs itself, like its own
 * n8n or Grafana. It's https (http too on a self-hosted panel), resolved for
 * every request and the connection pinned to that answer, with no
 * redirects, so it can't be pointed into the panel's own network between
 * the check and the call. Private addresses work only on a self-hosted panel
 * with MONITORS_ALLOW_PRIVATE on, as monitors, GitLab and Proxmox do;
 * link-local never does.
 */
final class PinnedAddress
{
    private const array LINK_LOCAL = ['169.254.0.0/16', 'fe80::/10'];

    /**
     * The address as it's kept: a scheme, a host, an optional port and path,
     * no trailing slash. A path matching $strip (like /api/v1) is dropped.
     * Null when it isn't one.
     */
    public static function normalise(?string $url, string $strip = ''): ?string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }
        if (! preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
            $url = 'https://'.$url;
        }
        $parts = parse_url($url);
        $scheme = strtolower(is_array($parts) ? (string) ($parts['scheme'] ?? '') : '');
        if (! is_array($parts) || ! in_array($scheme, self::schemes(), true) || ($parts['host'] ?? '') === ''
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }
        $host = strtolower($parts['host']);
        if (preg_match('/^(?:\[[0-9a-f:.]+\]|[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)*)$/', $host) !== 1) {
            return null;
        }
        $path = rtrim($parts['path'] ?? '', '/');
        if ($strip !== '') {
            $path = rtrim((string) preg_replace($strip, '', $path), '/');
        }
        if ($path !== '' && preg_match('#^(?:/[A-Za-z0-9._~-]+){1,5}$#', $path) !== 1) {
            return null;
        }
        $default = $scheme === 'https' ? 443 : 80;
        $port = isset($parts['port']) && $parts['port'] !== $default ? ':'.$parts['port'] : '';

        return "{$scheme}://{$host}{$port}{$path}";
    }

    public static function host(string $address): string
    {
        return (string) parse_url($address, PHP_URL_HOST);
    }

    /**
     * Guzzle options that pin a request to $url's host to the address it
     * resolves to now.
     *
     * @return array<string, mixed>
     *
     * @throws IntegrationFailed when the host can't be found or points somewhere ServerOS won't go
     */
    public static function options(string $url, ?string $field = 'url'): array
    {
        $parts = parse_url($url);
        $scheme = strtolower(is_array($parts) ? (string) ($parts['scheme'] ?? '') : '');
        $host = trim(is_array($parts) ? (string) ($parts['host'] ?? '') : '', '[]');
        if ($host === '' || ! in_array($scheme, self::schemes(), true)) {
            throw new IntegrationFailed(self::t('That isn’t an address ServerOS can call. Use one that starts with https://.'), IntegrationFailed::INVALID, null, $field);
        }

        $addresses = app(HostResolver::class)->resolve($host);
        if ($addresses === [] || array_filter($addresses, fn (string $ip) => IpUtils::checkIp($ip, self::LINK_LOCAL)) !== []) {
            throw new IntegrationFailed(self::t('ServerOS can’t reach :host, or it points into a private network.', ['host' => $host])
                .(Edition::selfHosted() && ! (bool) config('monitors.allow_private') ? ' '.self::t('To use a private address, set MONITORS_ALLOW_PRIVATE=true.') : ''), IntegrationFailed::INVALID, null, $field);
        }

        $options = ['allow_redirects' => false];
        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
            $ip = str_contains($addresses[0], ':') ? "[{$addresses[0]}]" : $addresses[0];
            $options['curl'] = [CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"]];
        }

        return $options;
    }

    /** @return list<string> */
    private static function schemes(): array
    {
        return Edition::selfHosted() ? ['https', 'http'] : ['https'];
    }

    /** @param  array<string, string|int>  $replace */
    private static function t(string $text, array $replace = []): string
    {
        return is_string($line = __($text, $replace)) ? $line : $text;
    }
}
