<?php

namespace Tests\Support;

use App\Support\Workspaces\Dashboard;
use Illuminate\Support\Facades\Route as Router;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class BareWorkspacePaths
{
    public const array SKIP = ['actions', 'routes', 'wayfinder', 'marketing', 'pages/admin', 'components/admin'];

    /** @return list<string> */
    public static function in(string $root): array
    {
        $found = [];
        foreach (self::files($root) as $file) {
            $found = [...$found, ...self::inSource((string) file_get_contents($file), substr($file, strlen(rtrim($root, '/')) + 1))];
        }

        return $found;
    }

    /** @return list<string> */
    public static function inSource(string $source, string $name = 'source'): array
    {
        $found = [];
        preg_match_all(self::pattern(), $source, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        foreach ($matches as $match) {
            $offset = $match[0][1];
            if (str_ends_with(rtrim(substr($source, 0, $offset)), 'workspacePath(')) {
                continue;
            }
            $literal = self::literal($source, $offset, $match[1][0]);
            if (! self::accountLevel($literal)) {
                $found[] = $name.':'.(substr_count($source, "\n", 0, $offset) + 1).'  '.$match[1][0].$literal.$match[1][0];
            }
        }

        return $found;
    }

    /** @return list<string> */
    public static function segments(): array
    {
        $segments = [];
        foreach (Router::getRoutes()->getRoutes() as $route) {
            if (Dashboard::isWorkspaceRoute($route) && ($segment = explode('/', $route->uri())[1] ?? '') !== '' && ! str_starts_with($segment, '{')) {
                $segments[$segment] = true;
            }
        }

        return array_keys($segments);
    }

    private static function pattern(): string
    {
        static $pattern = null;

        return $pattern ??= '#([\'"`])/(?:'.implode('|', array_map(fn (string $segment) => preg_quote($segment, '#'), self::segments())).')(?=[/\'"`?\#$])#';
    }

    private static function literal(string $source, int $offset, string $quote): string
    {
        $rest = substr($source, $offset + 1, 300);
        $end = strcspn($rest, $quote."\n");

        return substr($rest, 0, $end);
    }

    private static function accountLevel(string $literal): bool
    {
        $path = preg_split('/[?#]/', (string) preg_replace('/\$\{[^}]*(\}|$)/', "\0", $literal), 2)[0] ?? '';
        $segments = explode('/', ltrim($path, '/'));

        foreach (Router::getRoutes()->getRoutes() as $route) {
            if ($route->isFallback || Dashboard::isWorkspaceRoute($route) || ! in_array($route->getDomain(), [null, Dashboard::host()], true)) {
                continue;
            }
            $parts = explode('/', $route->uri());
            if (count($parts) === count($segments) && array_filter($parts, fn (string $part, int $i) => ! str_starts_with($part, '{')
                && ($part !== $segments[$i] || str_contains($segments[$i], "\0")), ARRAY_FILTER_USE_BOTH) === []) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private static function files(string $root): array
    {
        $root = rtrim($root, '/');
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if (! $file instanceof SplFileInfo || ! in_array($file->getExtension(), ['vue', 'ts', 'tsx', 'js'], true)) {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($root) + 1);
            foreach (self::SKIP as $skip) {
                if (str_starts_with($relative, $skip.'/')) {
                    continue 2;
                }
            }
            $files[] = $file->getPathname();
        }
        sort($files);

        return $files;
    }
}
