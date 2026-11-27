<?php

namespace App\Support\Code;

use App\Models\Machine;
use Illuminate\Support\Collection;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * What a pull request would change, as sentences: its files compared, key by
 * key, with the workspace written out as it runs now.
 *
 * @phpstan-import-type DiffLine from Diffs
 *
 * @phpstan-type MachineRef array{id: int, name: string, provider: string|null}
 * @phpstan-type Change array{id: string, op: string, logo: string|null, icon: string, title: string, file: string, path: string, machine: MachineRef|null, risk: array{tone: string, label: string}|null, diff: list<DiffLine>}
 * @phpstan-type Context array{file: string, kind: string, subject: string|null, old: list<string>|null, new: list<string>|null}
 */
final class CodePlan
{
    private const array DATA_KEYS = ['template', 'backups', 'volume', 'volumes'];

    /** @var array<string, Machine> */
    private array $machines = [];

    /** @var list<Change> */
    private array $changes = [];

    /**
     * @param  Collection<int, Machine>  $machines
     */
    private function __construct(Collection $machines)
    {
        foreach ($machines as $machine) {
            $this->machines[strtolower($machine->name)] = $machine;
        }
    }

    /**
     * @param  array<string, list<string>>  $live  the files as the workspace runs now, by path
     * @param  list<array{path: string, content: string|null}>  $proposed  the pull request's files by path in the folder, null when it deletes one
     * @param  Collection<int, Machine>  $machines
     * @return array{changes: list<Change>, unreadable: list<string>}
     */
    public static function make(array $live, array $proposed, Collection $machines): array
    {
        $plan = new self($machines);
        $unreadable = [];
        foreach ($proposed as $file) {
            $old = $live[$file['path']] ?? null;
            $new = $file['content'] === null ? null : self::lines($file['content']);
            if ($old === null && $new === null) {
                continue;
            }
            $before = $old === null ? null : self::parse($old);
            $after = $new === null ? null : self::parse($new);
            if (($old !== null && $before === null) || ($new !== null && $after === null)) {
                $unreadable[] = $file['path'];

                continue;
            }
            $context = [
                'file' => $file['path'],
                'kind' => self::kind($file['path']),
                'subject' => self::subject($file['path'], $after ?? $before),
                'old' => $old,
                'new' => $new,
            ];
            if ($before === null || $after === null) {
                $plan->wholeFile($context, $before ?? $after);
            } else {
                $plan->compare($context, $before, $after);
            }
        }

        return ['changes' => $plan->changes, 'unreadable' => $unreadable];
    }

    /**
     * @param  Context  $context
     * @param  array<mixed>  $values
     */
    private function wholeFile(array $context, array $values): void
    {
        $op = $context['old'] === null ? 'add' : 'remove';
        $name = $context['subject'] ?? $context['file'];
        $title = match ($context['kind']) {
            'machine' => $op === 'add' ? __('Add the machine :name', ['name' => $name]) : __('Remove the machine :name', ['name' => $name]),
            'project' => $op === 'add' ? __('Add the project :name', ['name' => $name]) : __('Remove the project :name', ['name' => $name]),
            default => $op === 'add' ? __('Add :file', ['file' => $context['file']]) : __('Remove :file', ['file' => $context['file']]),
        };
        $holdsData = $op === 'remove' && collect((array) ($values['services'] ?? []))->contains(fn ($service) => is_array($service) && array_intersect(self::DATA_KEYS, array_keys($service)) !== []);
        $lines = $context['new'] ?? $context['old'] ?? [];

        $this->changes[] = [
            'id' => 'c'.count($this->changes),
            'op' => $op,
            'logo' => $context['kind'] === 'machine' && is_string($values['provider'] ?? null) ? $values['provider'] : null,
            'icon' => $context['kind'] === 'machine' ? 'machines-fill' : ($context['kind'] === 'project' ? 'projects-fill' : 'file-fill'),
            'title' => $title,
            'file' => $context['file'],
            'path' => '',
            'machine' => $context['kind'] === 'machine' && ($machine = $this->machine($context['subject'])) !== null
                ? ['id' => $machine->id, 'name' => $machine->name, 'provider' => $machine->provider]
                : null,
            'risk' => $holdsData ? ['tone' => 'red', 'label' => __('Holds data')] : null,
            'diff' => $op === 'add' ? Diffs::between([], 0, $lines, 0) : Diffs::between($lines, 0, [], 0),
        ];
    }

    /**
     * @param  Context  $context
     * @param  array<mixed>  $before
     * @param  array<mixed>  $after
     */
    private function compare(array $context, array $before, array $after): void
    {
        foreach (array_unique([...array_keys($after), ...array_keys($before)]) as $key) {
            $key = (string) $key;
            $had = array_key_exists($key, $before);
            $has = array_key_exists($key, $after);
            $a = $before[$key] ?? null;
            $b = $after[$key] ?? null;
            if ($had && $has && self::same($a, $b)) {
                continue;
            }

            if (self::isMap($a) || self::isMap($b)) {
                $a = is_array($a) ? $a : [];
                $b = is_array($b) ? $b : [];
                foreach (array_unique([...array_keys($b), ...array_keys($a)]) as $child) {
                    $in = array_key_exists($child, $a);
                    $out = array_key_exists($child, $b);
                    if ($in && $out && self::same($a[$child], $b[$child])) {
                        continue;
                    }
                    $this->add($context, $in ? ($out ? 'change' : 'remove') : 'add', $key, (string) $child, [$key, (string) $child], [$key, (string) $child], $a[$child] ?? null, $b[$child] ?? null);
                }

                continue;
            }

            if ((is_array($a) && array_is_list($a)) || (is_array($b) && array_is_list($b))) {
                $this->compareList($context, $key, is_array($a) ? array_values($a) : [], is_array($b) ? array_values($b) : []);

                continue;
            }

            $this->add($context, $had ? ($has ? 'change' : 'remove') : 'add', $key, null, [$key], [$key], $a, $b);
        }
    }

    /**
     * @param  Context  $context
     * @param  list<mixed>  $a
     * @param  list<mixed>  $b
     */
    private function compareList(array $context, string $key, array $a, array $b): void
    {
        $named = fn (array $items) => $items !== [] && collect($items)->every(fn ($item) => is_array($item) && is_scalar($item['name'] ?? null));
        if ($named($a) || $named($b)) {
            $index = fn (array $items) => collect($items)->mapWithKeys(fn ($item, int $i) => [(string) (is_array($item) ? ($item['name'] ?? $i) : $i) => $i])->all();
            $old = $index($a);
            $new = $index($b);
            foreach ($new as $name => $i) {
                if (! isset($old[$name])) {
                    $this->add($context, 'add', $key, (string) $name, [], [$key, $i], null, $b[$i]);
                } elseif (! self::same($a[$old[$name]], $b[$i])) {
                    $this->add($context, 'change', $key, (string) $name, [$key, $old[$name]], [$key, $i], $a[$old[$name]], $b[$i]);
                }
            }
            foreach ($old as $name => $i) {
                if (! isset($new[$name])) {
                    $this->add($context, 'remove', $key, (string) $name, [$key, $i], [], $a[$i], null);
                }
            }

            return;
        }

        $text = fn (array $items) => array_map(fn ($item) => self::inline($item), $items);
        $old = $text($a);
        $new = $text($b);
        foreach ($new as $i => $item) {
            if (! in_array($item, $old, true)) {
                $this->add($context, 'add', $key, $item, [], [$key, $i], null, $b[$i]);
            }
        }
        foreach ($old as $i => $item) {
            if (! in_array($item, $new, true)) {
                $this->add($context, 'remove', $key, $item, [$key, $i], [], $a[$i], null);
            }
        }
    }

    /**
     * @param  Context  $context
     * @param  list<string|int>  $oldPath
     * @param  list<string|int>  $newPath
     */
    private function add(array $context, string $op, string $key, ?string $name, array $oldPath, array $newPath, mixed $a, mixed $b): void
    {
        $listed = ($newPath[1] ?? $oldPath[1] ?? null);
        $machine = match (true) {
            $context['kind'] === 'machine' => $this->machine($context['subject']),
            $key === 'services' => $this->machine(self::text(is_array($b) ? ($b['machine'] ?? null) : null) ?? self::text(is_array($a) ? ($a['machine'] ?? null) : null)),
            $context['kind'] === 'backups' && $key === 'machines' => $this->machine($name),
            default => null,
        };
        $old = $op === 'add' ? null : ($context['old'] === null ? null : Diffs::block($context['old'], $oldPath));
        $new = $op === 'remove' ? null : ($context['new'] === null ? null : Diffs::block($context['new'], $newPath));
        $value = is_array($b) ? $b : (is_array($a) ? $a : []);

        $this->changes[] = [
            'id' => 'c'.count($this->changes),
            'op' => $op,
            'logo' => $key === 'services' ? self::logo($name, $value) : null,
            'icon' => match ($key) {
                'services' => 'package-fill',
                'monitors' => 'monitors-fill',
                'policies' => 'setup-fill',
                'destinations', 'machines', 'backups' => 'database-fill',
                default => 'file-fill',
            },
            'title' => $this->title($context, $op, $key, $name, $a, $b, $machine?->name),
            'file' => $context['file'],
            'path' => $key.($name === null ? '' : (is_int($listed) ? '['.$listed.']' : '.'.$name)),
            'machine' => $machine === null ? null : ['id' => $machine->id, 'name' => $machine->name, 'provider' => $machine->provider],
            'risk' => self::risk($context, $op, $key, $name, $a, $b),
            'diff' => Diffs::between($old['lines'] ?? [], $old['start'] ?? 0, $new['lines'] ?? [], $new['start'] ?? 0),
        ];
    }

    /** @param  Context  $context */
    private function title(array $context, string $op, string $key, ?string $name, mixed $a, mixed $b, ?string $machine): string
    {
        $what = fn () => self::what($a, $b);
        $value = self::inline($b);

        if ($name === null) {
            $where = $context['subject'];

            return match ($op) {
                'remove' => $where === null ? __('Remove :key from :file', ['key' => $key, 'file' => $context['file']]) : __('Remove :key from :subject', ['key' => $key, 'subject' => $where]),
                default => $where === null ? __('Set :key to :value in :file', ['key' => $key, 'value' => $value, 'file' => $context['file']]) : __('Set :key of :subject to :value', ['key' => $key, 'subject' => $where, 'value' => $value]),
            };
        }

        return match (true) {
            $key === 'services' && $op === 'add' => $machine === null ? __('Add :service', ['service' => $name]) : __('Add :service on :machine', ['service' => $name, 'machine' => $machine]),
            $key === 'services' && $op === 'remove' => $machine === null ? __('Remove :service', ['service' => $name]) : __('Remove :service from :machine', ['service' => $name, 'machine' => $machine]),
            $key === 'services' => $context['kind'] === 'machine' && $machine !== null
                ? __('Change :service on :machine: :what', ['service' => $name, 'machine' => $machine, 'what' => $what()])
                : __('Change :service: :what', ['service' => $name, 'what' => $what()]),
            $key === 'monitors' && ! is_array($a ?? $b) => $op === 'add' ? __('Add a monitor for :address', ['address' => $name]) : __('Remove the monitor for :address', ['address' => $name]),
            $key === 'monitors' => match ($op) {
                'add' => __('Add the :name monitor', ['name' => $name]),
                'remove' => __('Remove the :name monitor', ['name' => $name]),
                default => __('Change the :name monitor: :what', ['name' => $name, 'what' => $what()]),
            },
            $key === 'policies' => match ($op) {
                'add' => __('Add the :name policy', ['name' => $name]),
                'remove' => __('Remove the :name policy', ['name' => $name]),
                default => __('Change the :name policy: :what', ['name' => $name, 'what' => $what()]),
            },
            $key === 'destinations' => match ($op) {
                'add' => __('Add the :name backup destination', ['name' => $name]),
                'remove' => __('Remove the :name backup destination', ['name' => $name]),
                default => __('Change the :name backup destination: :what', ['name' => $name, 'what' => $what()]),
            },
            $context['kind'] === 'backups' && $key === 'machines' => $op === 'remove'
                ? __('Remove the backup setting for :machine', ['machine' => $name])
                : __('Set backups for :machine to :value', ['machine' => $name, 'value' => $value]),
            $key === 'include' => $op === 'add' ? __('Include :item', ['item' => $name]) : __('Stop including :item', ['item' => $name]),
            $op === 'add' => __('Add :item to :key', ['item' => $name, 'key' => $key]),
            $op === 'remove' => __('Remove :item from :key', ['item' => $name, 'key' => $key]),
            default => __('Change :item in :key: :what', ['item' => $name, 'key' => $key, 'what' => $what()]),
        };
    }

    /**
     * The settings one change sets, adds and removes, in a few words.
     */
    private static function what(mixed $a, mixed $b): string
    {
        if (! is_array($a) || ! is_array($b)) {
            return __('set it to :value', ['value' => self::inline($b)]);
        }
        $before = self::flatten($a);
        $after = self::flatten($b);
        $parts = [];
        foreach ($after as $path => $value) {
            if (! array_key_exists($path, $before)) {
                $parts[] = mb_strlen($value) > 24 ? __('add :key', ['key' => $path]) : __('set :key to :value', ['key' => $path, 'value' => $value]);
            } elseif ($before[$path] !== $value) {
                $parts[] = __('set :key to :value', ['key' => $path, 'value' => $value]);
            }
        }
        foreach (array_keys($before) as $path) {
            if (! array_key_exists($path, $after)) {
                $parts[] = __('remove :key', ['key' => $path]);
            }
        }
        if (count($parts) > 3) {
            $parts = [...array_slice($parts, 0, 2), __(':count more', ['count' => count($parts) - 2])];
        }

        return implode(', ', $parts);
    }

    /**
     * @param  Context  $context
     * @return array{tone: string, label: string}|null
     */
    private static function risk(array $context, string $op, string $key, ?string $name, mixed $a, mixed $b): ?array
    {
        if ($key !== 'services' || $name === null || ! is_array($a)) {
            return null;
        }
        if ($op === 'remove' && array_intersect(self::DATA_KEYS, array_keys($a)) !== []) {
            return ['tone' => 'red', 'label' => __('Holds data')];
        }
        if ($op === 'change' && $context['kind'] === 'project' && is_array($b) && (isset($a['from']) || isset($b['from']) || isset($a['env']) || isset($b['env']))) {
            return ['tone' => 'amber', 'label' => __('Restarts :service', ['service' => $name])];
        }

        return null;
    }

    /** @param  array<mixed>  $value */
    private static function logo(?string $name, array $value): ?string
    {
        $image = self::text($value['image'] ?? null);
        $template = self::text($value['template'] ?? null);
        if ($template !== null) {
            return $template;
        }
        if ($image !== null) {
            return (string) str($image)->before(':')->afterLast('/');
        }

        return $name;
    }

    private function machine(?string $name): ?Machine
    {
        return $name === null ? null : ($this->machines[strtolower($name)] ?? null);
    }

    /**
     * @param  array<mixed>  $values
     * @return array<string, string>
     */
    private static function flatten(array $values, string $prefix = ''): array
    {
        $flat = [];
        foreach ($values as $key => $value) {
            $path = $prefix.$key;
            if (is_array($value) && $value !== [] && ! array_is_list($value)) {
                $flat = [...$flat, ...self::flatten($value, $path.'.')];
            } else {
                $flat[$path] = self::inline($value);
            }
        }

        return $flat;
    }

    private static function inline(mixed $value): string
    {
        $text = match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => 'null',
            is_scalar($value) => (string) $value,
            default => trim(Yaml::dump($value, 0)),
        };

        return mb_strlen($text) > 60 ? mb_substr($text, 0, 59).'…' : $text;
    }

    private static function text(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    private static function same(mixed $a, mixed $b): bool
    {
        return is_array($a) && is_array($b) ? $a == $b : self::inline($a) === self::inline($b) && gettype($a) === gettype($b);
    }

    private static function isMap(mixed $value): bool
    {
        return is_array($value) && $value !== [] && ! array_is_list($value);
    }

    /** @return list<string> */
    private static function lines(string $content): array
    {
        return explode("\n", rtrim(str_replace("\r\n", "\n", $content), "\n"));
    }

    /**
     * @param  list<string>  $lines
     * @return array<mixed>|null
     */
    private static function parse(array $lines): ?array
    {
        try {
            $parsed = Yaml::parse(implode("\n", $lines));
        } catch (ParseException) {
            return null;
        }

        return $parsed === null ? [] : (is_array($parsed) ? $parsed : null);
    }

    private static function kind(string $path): string
    {
        return match (true) {
            str_starts_with($path, 'machines/') => 'machine',
            str_starts_with($path, 'projects/') => 'project',
            default => pathinfo($path, PATHINFO_FILENAME),
        };
    }

    /** @param  array<mixed>  $values */
    private static function subject(string $path, array $values): ?string
    {
        return match (self::kind($path)) {
            'machine' => self::text($values['machine'] ?? null) ?? pathinfo($path, PATHINFO_FILENAME),
            'project' => self::text($values['project'] ?? null) ?? pathinfo($path, PATHINFO_FILENAME),
            default => null,
        };
    }
}
