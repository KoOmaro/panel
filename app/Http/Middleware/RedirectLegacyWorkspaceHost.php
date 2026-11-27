<?php

namespace App\Http\Middleware;

use App\Models\Workspace;
use App\Support\Workspaces\Dashboard;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Symfony\Component\HttpFoundation\Response;

class RedirectLegacyWorkspaceHost
{
    public function handle(Request $request, Closure $next): Response
    {
        $slug = Dashboard::slugFromLegacyHost($request->getHost());
        $path = $request->getPathInfo();
        if ($slug === null || $request->is('mcp', '.well-known', '.well-known/*') || ! Dashboard::routes($request, $slug, $path)
            || self::routedHost(strtolower($request->getHost()))) {
            return $next($request);
        }
        abort_unless(Workspace::query()->where('slug', $slug)->exists(), 404);

        $query = (string) $request->server->get('QUERY_STRING', '');

        return new RedirectResponse(
            Dashboard::url($slug, $path).($query === '' ? '' : '?'.$query),
            $request->isMethod('GET') || $request->isMethod('HEAD') ? 301 : 308,
        );
    }

    private static function routedHost(string $host): bool
    {
        return collect(Router::getRoutes()->getRoutes())->contains(fn (Route $route) => $route->getDomain() === $host);
    }
}
