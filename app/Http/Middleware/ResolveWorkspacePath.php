<?php

namespace App\Http\Middleware;

use App\Models\Workspace;
use App\Support\Workspaces\Dashboard;
use App\Support\WorkspaceSlugs;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route as Router;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ResolveWorkspacePath
{
    public const string COOKIE = 'workspace';

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->attributes->has('custom_domain') || ! Dashboard::on($request)) {
            return $next($request);
        }

        $path = $request->getPathInfo();
        $slug = self::slug(self::refererPath($request));
        if ($slug !== null && ! self::under($path, $slug) && Dashboard::routes($request, $slug, $path)) {
            $uri = self::prefixed($request, $slug, $path);
            if ($request->isMethod('GET') && ! $request->header('X-Inertia') && ! $request->ajax() && ! $request->expectsJson()) {
                return new RedirectResponse($uri, 302);
            }
            ResolveCustomDomain::rewrite($request, $request->getHost(), uri: $uri);
        } elseif ($slug === null && $request->isMethod('GET') && ($last = self::slug('/'.$request->cookies->get(self::COOKIE, ''))) !== null
            && ! self::under($path, $last) && Dashboard::routes($request, $last, $path) && ! self::routable($request)) {
            return new RedirectResponse(self::prefixed($request, $last, $path), 302);
        }

        $response = $next($request);

        return self::remember($request, $response);
    }

    private static function remember(Request $request, Response $response): Response
    {
        $workspace = $request->route('workspace');
        if (! Dashboard::isWorkspaceRoute($request->route()) || ! $workspace instanceof Workspace || ! $request->isMethod('GET')
            || ! $response->isSuccessful() || $request->cookies->get(self::COOKIE) === $workspace->slug) {
            return $response;
        }
        $response->headers->setCookie(new Cookie(self::COOKIE, $workspace->slug, now()->addYear(), '/', null, $request->isSecure(), true, false, Cookie::SAMESITE_LAX));

        return $response;
    }

    private static function refererPath(Request $request): string
    {
        $parts = parse_url((string) $request->headers->get('referer', ''));

        return is_array($parts) && strtolower($parts['host'] ?? '') === strtolower($request->getHost()) ? ($parts['path'] ?? '') : '';
    }

    private static function slug(string $path): ?string
    {
        $slug = explode('/', ltrim($path, '/'), 2)[0];

        return preg_match(WorkspaceSlugs::PATTERN, $slug) === 1 && ! in_array($slug, WorkspaceSlugs::RESERVED, true) ? $slug : null;
    }

    private static function under(string $path, string $slug): bool
    {
        return $path === '/'.$slug || str_starts_with($path, '/'.$slug.'/');
    }

    private static function prefixed(Request $request, string $slug, string $path): string
    {
        $query = (string) $request->server->get('QUERY_STRING', '');

        return '/'.$slug.($path === '/' ? '' : $path).($query === '' ? '' : '?'.$query);
    }

    private static function routable(Request $request): bool
    {
        try {
            $route = Router::getRoutes()->match($request);
        } catch (HttpException) {
            return false;
        }
        $slug = $route->parameter('workspace');

        return ! Dashboard::isWorkspaceRoute($route) || (is_string($slug) && Workspace::query()->where('slug', $slug)->exists());
    }
}
