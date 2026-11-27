<?php

namespace App\Support\Mcp;

use App\Mcp\Scopes;
use App\Models\Workspace;
use App\Support\Extensions\Extensions;
use App\Support\Workspaces\Dashboard;

final class OAuth
{
    public const CODE_MINUTES = 10;

    public static function issuer(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    public static function resource(Workspace $workspace, bool $legacy = false): string
    {
        return route($legacy ? 'mcp.legacy' : 'mcp', ['workspace' => $workspace->slug]);
    }

    public static function workspaceFor(?string $resource): ?Workspace
    {
        if (! is_string($resource) || $resource === '') {
            return null;
        }
        $host = strtolower((string) parse_url($resource, PHP_URL_HOST));
        $slug = $host === Dashboard::host()
            ? explode('/', ltrim((string) parse_url($resource, PHP_URL_PATH), '/'), 2)[0]
            : Dashboard::slugFromLegacyHost($host);
        if ($slug === null || $slug === '') {
            return null;
        }
        $workspace = Workspace::query()->where('slug', $slug)->first();
        $named = fn (Workspace $workspace) => collect([self::resource($workspace), self::resource($workspace, true)])
            ->contains(fn (string $url) => rtrim($resource, '/') === rtrim($url, '/'));

        return $workspace !== null && $named($workspace) ? $workspace : null;
    }

    public static function resourceFor(Workspace $workspace, mixed $requested): string
    {
        return is_string($requested) && self::workspaceFor($requested)?->is($workspace) ? $requested : self::resource($workspace);
    }

    public static function redirectAllowed(mixed $uri): bool
    {
        if (! is_string($uri) || $uri === '' || strlen($uri) > 2000 || preg_match('/[\s<>"\\\\]/', $uri) === 1) {
            return false;
        }
        $parts = parse_url($uri);
        if (! is_array($parts) || ! isset($parts['scheme']) || isset($parts['fragment'])) {
            return false;
        }
        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host'] ?? '');

        return match (true) {
            $scheme === 'https' => $host !== '' && ! isset($parts['user']) && ! isset($parts['pass']),
            $scheme === 'http' => in_array($host, ['localhost', '127.0.0.1', '[::1]', '::1'], true),
            in_array($scheme, ['javascript', 'data', 'file', 'vbscript', 'about', 'blob', 'ftp', 'ws', 'wss', 'mailto', 'tel', 'sms', 'intent', 'chrome', 'view-source'], true) => false,
            default => preg_match('/^[a-z][a-z0-9.+-]{1,60}$/', $scheme) === 1,
        };
    }

    public static function knownApp(string $redirectUri): ?string
    {
        $parts = parse_url($redirectUri) ?: [];
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');

        return match (true) {
            $scheme === 'https' && in_array($host, ['claude.ai', 'claude.com'], true) => 'claude',
            $scheme === 'https' && in_array($host, ['chatgpt.com', 'chat.openai.com', 'platform.openai.com'], true) => 'openai',
            $scheme === 'cursor' => 'cursor',
            $scheme === 'https' && $host === 'vscode.dev', $scheme === 'vscode' => 'copilot',
            $scheme === 'windsurf' => 'windsurf',
            default => null,
        };
    }

    /** @return array<string, mixed> */
    public static function serverMetadata(): array
    {
        $issuer = self::issuer();

        return [
            'issuer' => $issuer,
            'authorization_endpoint' => $issuer.'/oauth/authorize',
            'token_endpoint' => $issuer.'/oauth/token',
            'registration_endpoint' => $issuer.'/oauth/register',
            'revocation_endpoint' => $issuer.'/oauth/revoke',
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'revocation_endpoint_auth_methods_supported' => ['none'],
            'scopes_supported' => array_merge(...array_values(array_map(fn (OAuthAudience $audience) => $audience->scopes(), app(Extensions::class)->oauthAudiences()))),
            'authorization_response_iss_parameter_supported' => true,
            'service_documentation' => 'https://docs.'.config('app.domain').'/',
        ];
    }

    /** @return array<string, mixed> */
    public static function resourceMetadata(Workspace $workspace, bool $legacy = false): array
    {
        return [
            'resource' => self::resource($workspace, $legacy),
            'authorization_servers' => [self::issuer()],
            'scopes_supported' => Scopes::ALL,
            'bearer_methods_supported' => ['header'],
            'resource_name' => 'ServerOS',
            'resource_documentation' => 'https://docs.'.config('app.domain').'/',
        ];
    }
}
