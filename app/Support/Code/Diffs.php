<?php

namespace App\Support\Code;

/**
 * Line diffs for the code screens, in the shape the diff view draws: a
 * line number, a sign and the text. Numbers are the new file's, except on
 * removed lines, which keep the old file's.
 *
 * @phpstan-type DiffLine array{no: int|null, sign: string, text: string}
 */
final class Diffs
{
    private const int MAX_LINES = 400;

    /**
     * @param  list<string>  $old
     * @param  list<string>  $new
     * @return list<DiffLine>
     */
    public static function between(array $old, int $oldStart, array $new, int $newStart): array
    {
        $old = array_slice($old, 0, self::MAX_LINES);
        $new = array_slice($new, 0, self::MAX_LINES);
        $n = count($old);
        $m = count($new);
        $longest = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $longest[$i][$j] = $old[$i] === $new[$j]
                    ? $longest[$i + 1][$j + 1] + 1
                    : max($longest[$i + 1][$j], $longest[$i][$j + 1]);
            }
        }

        $lines = [];
        $i = 0;
        $j = 0;
        while ($i < $n || $j < $m) {
            if ($i < $n && $j < $m && $old[$i] === $new[$j]) {
                $lines[] = ['no' => $newStart + $j + 1, 'sign' => ' ', 'text' => $new[$j]];
                $i++;
                $j++;
            } elseif ($j >= $m || ($i < $n && $longest[$i + 1][$j] >= $longest[$i][$j + 1])) {
                $lines[] = ['no' => $oldStart + $i + 1, 'sign' => '-', 'text' => $old[$i]];
                $i++;
            } else {
                $lines[] = ['no' => $newStart + $j + 1, 'sign' => '+', 'text' => $new[$j]];
                $j++;
            }
        }

        return $lines;
    }

    /**
     * GitHub's patch for one file, with a line for each hunk's header.
     *
     * @return list<DiffLine>
     */
    public static function patch(string $patch): array
    {
        $lines = [];
        $old = 0;
        $new = 0;
        foreach (explode("\n", $patch) as $line) {
            if (count($lines) >= self::MAX_LINES) {
                break;
            }
            if (preg_match('/^@@ -(\d+)(?:,\d+)? \+(\d+)(?:,\d+)? @@/', $line, $hunk) === 1) {
                $old = (int) $hunk[1];
                $new = (int) $hunk[2];
                $lines[] = ['no' => null, 'sign' => '@', 'text' => $line];

                continue;
            }
            if ($line === '' || $line[0] === '\\') {
                continue;
            }
            $text = substr($line, 1);
            $lines[] = match ($line[0]) {
                '+' => ['no' => $new++, 'sign' => '+', 'text' => $text],
                '-' => ['no' => $old++, 'sign' => '-', 'text' => $text],
                default => ['no' => $new++, 'sign' => ' ', 'text' => $text],
            };
            if ($line[0] === ' ') {
                $old++;
            }
        }

        return $lines;
    }

    /**
     * Where a key, a key inside it, or the n-th item of a list sits in a YAML
     * file, by its lines: the first line and the lines that belong to it.
     *
     * @param  list<string>  $lines
     * @param  list<string|int>  $path
     * @return array{start: int, lines: list<string>}|null
     */
    public static function block(array $lines, array $path): ?array
    {
        $from = 0;
        $to = count($lines);
        $start = null;
        $end = $to;
        foreach ($path as $segment) {
            $level = null;
            $count = 0;
            $start = null;
            for ($i = $from; $i < $to; $i++) {
                if (self::blank($lines[$i])) {
                    continue;
                }
                $indent = self::indent($lines[$i]);
                $item = self::item($lines[$i]);
                if (is_int($segment) && ! $item) {
                    continue;
                }
                $level ??= $indent;
                if ($indent !== $level || (! is_int($segment) && $item)) {
                    continue;
                }
                if (is_int($segment) ? $count++ === $segment : self::key($lines[$i]) === $segment) {
                    $start = $i;

                    break;
                }
            }
            if ($start === null) {
                return null;
            }
            $end = self::end($lines, $start, $to);
            $from = $start + 1;
            $to = $end;
        }

        return $start === null ? null : ['start' => $start, 'lines' => array_slice($lines, $start, $end - $start)];
    }

    /** @param  list<string>  $lines */
    private static function end(array $lines, int $at, int $to): int
    {
        $indent = self::indent($lines[$at]);
        $item = self::item($lines[$at]);
        for ($i = $at + 1; $i < $to; $i++) {
            if (self::blank($lines[$i])) {
                continue;
            }
            $next = self::indent($lines[$i]);
            if ($next > $indent || ($next === $indent && ! $item && self::item($lines[$i]))) {
                continue;
            }

            break;
        }
        while ($i > $at + 1 && self::blank($lines[$i - 1])) {
            $i--;
        }

        return $i;
    }

    private static function blank(string $line): bool
    {
        return trim($line) === '' || preg_match('/^\s*#/', $line) === 1;
    }

    private static function indent(string $line): int
    {
        return strlen($line) - strlen(ltrim($line, ' '));
    }

    private static function item(string $line): bool
    {
        return preg_match('/^\s*-(\s|$)/', $line) === 1;
    }

    private static function key(string $line): ?string
    {
        if (preg_match('/^\s*("(?:[^"\\\\]|\\\\.)*"|\'(?:[^\']|\'\')*\'|[^\s#\'"][^#]*?)\s*:(?:\s|$)/', $line, $match) !== 1) {
            return null;
        }
        $key = $match[1];

        return match ($key[0]) {
            '"' => is_string($decoded = json_decode($key)) ? $decoded : substr($key, 1, -1),
            "'" => str_replace("''", "'", substr($key, 1, -1)),
            default => $key,
        };
    }
}
