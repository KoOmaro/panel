<?php

namespace App\Support\Workspaces;

use App\Support\Edition;
use App\Support\WorkspaceSlugs;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class Dashboard
{
    public static function host(): string
    {
        $configured = config('serveros.dashboard_domain');
        if (is_string($configured) && trim($configured) !== '') {
            return strtolower(trim($configured));
        }
        $domain = strtolower((string) config('app.domain'));

        return Edition::selfHosted() ? $domain : 'cloud.'.$domain;
    }

    public static function root(): string
    {
        $app = (string) config('app.url');
        $scheme = parse_url($app, PHP_URL_SCHEME) ?: 'https';
        $port = parse_url($app, PHP_URL_PORT);
        $samePort = is_int($port) && strtolower((string) parse_url($app, PHP_URL_HOST)) === self::host();

        return $scheme.'://'.self::host().($samePort ? ':'.$port : '');
    }

    public static function url(string $slug, string $path = ''): string
    {
        $path = '/'.ltrim($path, '/');

        return self::root().'/'.$slug.($path === '/' ? '' : $path);
    }

    public static function on(Request $request): bool
    {
        return strtolower($request->getHost()) === self::host();
    }

    public static function isWorkspaceRoute(mixed $route): bool
    {
        return $route instanceof Route
            && str_starts_with($route->uri(), '{workspace}')
            && $route->getDomain() === self::host();
    }

    public static function legacyHost(string $slug): string
    {
        return $slug.'.'.strtolower((string) config('app.domain'));
    }

    public static function slugFromLegacyHost(string $host): ?string
    {
        $suffix = '.'.strtolower((string) config('app.domain'));
        $host = strtolower($host);
        if ($host === self::host() || ! str_ends_with($host, $suffix)) {
            return null;
        }
        $slug = substr($host, 0, -strlen($suffix));

        return preg_match(WorkspaceSlugs::PATTERN, $slug) === 1 ? $slug : null;
    }

    public static function routes(Request $request, string $slug, ?string $path = null): bool
    {
        $path = '/'.ltrim($path ?? $request->getPathInfo(), '/');

        return self::workspaceAt($request, '/'.$slug.($path === '/' ? '' : $path)) === $slug;
    }

    public static function workspaceAt(Request $request, string $path): ?string
    {
        $probe = Request::create(self::root().'/'.ltrim($path, '/'), $request->getMethod());

        try {
            $route = Router::getRoutes()->match($probe);
        } catch (HttpException) {
            return null;
        }
        $slug = $route->parameter('workspace');

        return self::isWorkspaceRoute($route) && is_string($slug) ? $slug : null;
    }
}
