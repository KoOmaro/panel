<?php

namespace App\Support\Code;

use App\Models\Machine;
use App\Models\Project;
use App\Models\ProjectNode;
use App\Support\Projects\Previews;
use Illuminate\Support\Collection;

/**
 * The parts of a workspace its files can't describe faithfully, from what
 * the machines report and how the apps were deployed: services ServerOS only
 * found, services it can't tell the kind of, and apps that didn't come from a
 * GitHub repository. Each one is also written as a comment in its file.
 *
 * @phpstan-type Item array{title: string, body: string, icon: string, path: string, comment: list<string>}
 */
final class NeedsLook
{
    private const array UNKNOWN_KINDS = ['', 'other', 'unknown'];

    /**
     * @param  Collection<int, Machine>  $machines
     * @param  array<int, string>  $machinePaths  by machine id
     * @param  Collection<int, Project>  $projects
     * @param  array<string, string>  $projectPaths  by project uid
     * @return list<Item>
     */
    public static function items(Collection $machines, array $machinePaths, Collection $projects, array $projectPaths): array
    {
        $items = [];
        foreach ($machines as $machine) {
            $path = $machinePaths[$machine->id] ?? null;
            if ($path === null) {
                continue;
            }
            $services = WorkspaceExport::services($machine);
            $found = array_values(array_filter($services, fn (array $service) => ($service['details']['managed'] ?? null) !== 'true'));
            if ($found !== []) {
                $names = self::names($found);
                $items[] = [
                    'title' => count($found) === 1
                        ? __(':service on :machine isn’t managed by ServerOS', ['service' => $names[0], 'machine' => $machine->name])
                        : __(':count services on :machine aren’t managed by ServerOS', ['count' => count($found), 'machine' => $machine->name]),
                    'body' => count($found) === 1
                        ? __('ServerOS found it but doesn’t control it, so :path only lists it.', ['path' => $path])
                        : __('ServerOS found :names but doesn’t control them, so :path only lists them.', ['names' => self::join($names), 'path' => $path]),
                    'icon' => 'machines-fill',
                    'path' => $path,
                    'comment' => ['# Needs a look: ServerOS found '.self::join($names).' but doesn’t control '.(count($found) === 1 ? 'it' : 'them').', so this file only lists '.(count($found) === 1 ? 'it' : 'them').'.'],
                ];
            }

            $unknown = array_values(array_filter($services, fn (array $service) => ($service['details']['managed'] ?? null) === 'true'
                && in_array(strtolower((string) ($service['kind'] ?? '')), self::UNKNOWN_KINDS, true)
                && blank($service['details']['image'] ?? null)));
            if ($unknown !== []) {
                $names = self::names($unknown);
                $items[] = [
                    'title' => count($unknown) === 1
                        ? __('We can’t tell what :service on :machine is', ['service' => $names[0], 'machine' => $machine->name])
                        : __('We can’t tell what :count services on :machine are', ['count' => count($unknown), 'machine' => $machine->name]),
                    'body' => count($unknown) === 1
                        ? __(':path only says how it runs, not what it is.', ['path' => $path])
                        : __(':path only says how :names run, not what they are.', ['names' => self::join($names), 'path' => $path]),
                    'icon' => 'circle-help',
                    'path' => $path,
                    'comment' => ['# Needs a look: this file only says how '.self::join($names).' '.(count($unknown) === 1 ? 'runs' : 'run').', not what '.(count($unknown) === 1 ? 'it is' : 'they are').'.'],
                ];
            }
        }

        foreach ($projects as $project) {
            $path = $projectPaths[$project->uid] ?? null;
            if ($path === null) {
                continue;
            }
            $apps = array_values(WorkspaceExport::apps($project)
                ->filter(fn (ProjectNode $app) => Previews::repoOf(WorkspaceExport::spec($app)) === null)
                ->map(fn (ProjectNode $app) => $app->name)
                ->all());
            if ($apps === []) {
                continue;
            }
            $items[] = [
                'title' => count($apps) === 1
                    ? __(':app in :project has no repository', ['app' => $apps[0], 'project' => $project->name])
                    : __(':count apps in :project have no repository', ['count' => count($apps), 'project' => $project->name]),
                'body' => count($apps) === 1
                    ? __('It wasn’t deployed from a GitHub repository, so :path can’t say where its code comes from.', ['path' => $path])
                    : __(':names weren’t deployed from a GitHub repository, so :path can’t say where their code comes from.', ['names' => self::join($apps), 'path' => $path]),
                'icon' => 'branch-fill',
                'path' => $path,
                'comment' => ['# Needs a look: '.self::join($apps).' '.(count($apps) === 1 ? 'wasn’t' : 'weren’t').' deployed from a GitHub repository, so this file can’t say where '.(count($apps) === 1 ? 'its' : 'their').' code comes from.'],
            ];
        }

        return $items;
    }

    /**
     * Comment lines to add to each file, by path.
     *
     * @param  list<Item>  $items
     * @return array<string, list<string>>
     */
    public static function notes(array $items): array
    {
        $notes = [];
        foreach ($items as $item) {
            $notes[$item['path']] = [...($notes[$item['path']] ?? []), ...$item['comment']];
        }

        return $notes;
    }

    /**
     * @param  list<array<string, mixed>>  $services
     * @return list<string>
     */
    private static function names(array $services): array
    {
        return array_values(array_unique(array_map(fn (array $service) => (string) (($service['label'] ?? null) ?: ($service['name'] ?? $service['key'])), $services)));
    }

    /** @param  list<string>  $names */
    private static function join(array $names): string
    {
        if (count($names) > 4) {
            return implode(', ', array_slice($names, 0, 3)).' and '.(count($names) - 3).' more';
        }

        return count($names) === 1 ? $names[0] : implode(', ', array_slice($names, 0, -1)).' and '.$names[count($names) - 1];
    }
}
