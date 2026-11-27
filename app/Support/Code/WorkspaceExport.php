<?php

namespace App\Support\Code;

use App\Models\Machine;
use App\Models\MachinePolicy;
use App\Models\Monitor;
use App\Models\Project;
use App\Models\ProjectNode;
use App\Models\Workspace;
use App\Support\Deploys\AppReleases;
use App\Support\Projects\AppSettings;
use App\Support\Projects\Previews;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Writes a workspace out as the YAML files the "Workspace as code" screens
 * show. Read only: it describes what the panel already knows, and never
 * includes a secret's value, only its name.
 */
final class WorkspaceExport
{
    public const array HEADER = [
        "# Written by ServerOS from what's running now.",
        '# Change it here, or keep using the panel.',
    ];

    /**
     * @param  array<string, list<string>>  $notes  comment lines to add at the end of a file, by path
     * @return array{files: list<array{path: string, lines: list<string>}>, machines: int, services: int, monitors: int, projects: int, secrets: int, projectPaths: array<string, string>, machinePaths: array<int, string>, machineModels: Collection<int, Machine>, projectModels: Collection<int, Project>}
     */
    public static function build(Workspace $workspace, array $notes = []): array
    {
        $machines = $workspace->countedMachines()->with(['policy', 'group', 'backupDestination'])->orderByRaw('lower(name)')->get();
        $projects = self::projects($workspace);
        $monitors = $workspace->monitors()->orderBy('name')->get();
        $policies = $workspace->machinePolicies()->orderByDesc('is_default')->orderBy('name')->get();
        $default = $policies->firstWhere('is_default', true);

        $files = [];
        $used = [];
        $path = function (string $folder, string $name) use (&$used): string {
            $base = $folder.'/'.self::fileName($name);
            $path = $base.'.yml';
            for ($n = 2; isset($used[$path]); $n++) {
                $path = $base.'-'.$n.'.yml';
            }
            $used[$path] = true;

            return $path;
        };

        $services = 0;
        $machinePaths = [];
        foreach ($machines as $machine) {
            $running = self::services($machine);
            $services += count($running);
            $machinePaths[$machine->id] = $path('machines', $machine->name);
            $files[] = ['path' => $machinePaths[$machine->id], 'lines' => self::machineFile($machine, $running, $default)];
        }

        $secrets = [];
        $projectPaths = [];
        foreach ($projects as $project) {
            $projectPaths[$project->uid] = $path('projects', $project->name);
            $files[] = ['path' => $projectPaths[$project->uid], 'lines' => self::projectFile($workspace, $project, $monitors)];
            foreach (self::apps($project) as $app) {
                foreach (self::env($project, $app) as $name => $reference) {
                    if (str_starts_with($reference, '${{ secrets.')) {
                        $secrets[$name] = true;
                    }
                }
            }
        }

        $files[] = ['path' => 'backups.yml', 'lines' => self::backupsFile($machines)];
        $files[] = ['path' => 'monitors.yml', 'lines' => self::monitorsFile($monitors)];
        $files[] = ['path' => 'policies.yml', 'lines' => self::policiesFile($policies)];
        $files[] = ['path' => 'serveros.yml', 'lines' => self::rootFile($workspace)];

        return [
            'files' => self::annotate($files, $notes),
            'machines' => $machines->count(),
            'services' => $services,
            'monitors' => $monitors->count(),
            'projects' => $projects->count(),
            'secrets' => count($secrets),
            'projectPaths' => $projectPaths,
            'machinePaths' => $machinePaths,
            'machineModels' => $machines,
            'projectModels' => $projects,
        ];
    }

    /**
     * Adds comment lines at the end of files, by path.
     *
     * @param  list<array{path: string, lines: list<string>}>  $files
     * @param  array<string, list<string>>  $notes
     * @return list<array{path: string, lines: list<string>}>
     */
    public static function annotate(array $files, array $notes): array
    {
        foreach ($files as $index => $file) {
            if (($extra = $notes[$file['path']] ?? []) !== []) {
                $files[$index]['lines'] = [...$file['lines'], '', ...$extra];
            }
        }

        return $files;
    }

    /** @return Collection<int, Project> */
    public static function projects(Workspace $workspace): Collection
    {
        return $workspace->projects()->with(['nodes.machine', 'environments', 'links.resource'])->orderByRaw('lower(name)')->get();
    }

    /**
     * One project's file, the one Set up code asks an agent to write.
     *
     * @param  Collection<int, Monitor>  $monitors
     * @return list<string>
     */
    public static function projectFile(Workspace $workspace, Project $project, Collection $monitors): array
    {
        $repo = self::repoOf($project);
        $lines = [...self::HEADER, 'project: '.self::scalar(self::slug($project->name))];
        if ($repo !== null) {
            $lines[] = 'repo: '.self::scalar($repo);
        }
        $lines[] = 'environment: '.self::scalar(self::slug(self::environmentOf($project)));

        $nodes = self::nodes($project);
        if ($nodes->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'services:';
            foreach ($nodes as $node) {
                $lines[] = '  '.self::key($node->name).':';
                if ($node->isApp()) {
                    if ($repo !== null) {
                        $lines[] = '    from: repo';
                    }
                } elseif ($node->template !== null) {
                    $lines[] = '    template: '.self::scalar($node->template);
                }
                $lines[] = '    machine: '.self::scalar($node->machine->name);
                if ($node->isApp()) {
                    $spec = self::spec($node);
                    $domains = array_values(array_filter(array_map('strval', (array) ($spec['domains'] ?? []))));
                    if (count($domains) === 1) {
                        $lines[] = '    domain: '.self::scalar($domains[0]);
                    } elseif ($domains !== []) {
                        $lines[] = '    domains: ['.implode(', ', array_map(self::scalar(...), $domains)).']';
                    }
                    if (is_numeric($spec['port'] ?? null)) {
                        $lines[] = '    port: '.(int) $spec['port'];
                    }
                    if (($env = self::env($project, $node)) !== []) {
                        $lines[] = '    env:';
                        foreach ($env as $name => $reference) {
                            $lines[] = '      '.self::key($name).': '.$reference;
                        }
                    }
                }
                if ($node->kind === 'database' && $node->machine->backup_schedule !== 'off') {
                    $lines[] = '    backups: '.self::scalar($node->machine->backup_schedule);
                }
            }
        }

        $checks = self::monitorsOf($project, $monitors);
        if ($checks !== []) {
            $lines[] = '';
            $lines[] = 'monitors:';
            foreach ($checks as $check) {
                $lines[] = '  - '.self::scalar($check);
            }
        }

        return $lines;
    }

    public static function repoOf(Project $project): ?string
    {
        $repo = Previews::settings($project)['repo'];
        if ($repo !== '') {
            return $repo;
        }
        foreach (self::apps($project) as $app) {
            if (($found = Previews::repoOf(self::spec($app))) !== null) {
                return $found;
            }
        }

        return null;
    }

    public static function environmentOf(Project $project): string
    {
        return $project->environments->first()->name ?? 'Production';
    }

    /**
     * The monitors that check one of a project's addresses: their address
     * names the project.
     *
     * @param  Collection<int, Monitor>  $monitors
     * @return list<string>
     */
    public static function monitorsOf(Project $project, Collection $monitors): array
    {
        $slug = self::slug($project->name);
        $domains = self::apps($project)->flatMap(fn (ProjectNode $app) => array_map('strval', (array) (self::spec($app)['domains'] ?? [])))->all();

        return array_values($monitors
            ->filter(function (Monitor $monitor) use ($slug, $domains) {
                $host = strtolower((string) parse_url((string) $monitor->url, PHP_URL_HOST));

                return $host !== '' && (in_array($host, $domains, true) || str_contains($host, $slug));
            })
            ->map(fn (Monitor $monitor) => (string) $monitor->url)
            ->sort()
            ->all());
    }

    /** @return Collection<int, ProjectNode> */
    public static function nodes(Project $project): Collection
    {
        $first = $project->environments->first()?->id;

        return $project->nodes
            ->filter(fn (ProjectNode $node) => $node->environment_id === null || $node->environment_id === $first)
            ->sortBy(fn (ProjectNode $node) => [$node->isApp() ? 0 : 1, $node->name])
            ->values();
    }

    /** @return Collection<int, ProjectNode> */
    public static function apps(Project $project): Collection
    {
        return self::nodes($project)->filter(fn (ProjectNode $node) => $node->isApp())->values();
    }

    /** @return array<string, mixed> */
    public static function spec(ProjectNode $app): array
    {
        return (array) (AppSettings::lastDeploy($app->machine, $app->name)?->command['job'] ?? []);
    }

    /**
     * An app's environment as references, never values: a variable a
     * project link sets points at its service, one that looks secret at
     * the workspace's secrets, and the rest at plain variables.
     *
     * @return array<string, string>
     */
    public static function env(Project $project, ProjectNode $app): array
    {
        $names = array_map('strval', array_keys((array) (self::spec($app)['env'] ?? [])));
        sort($names);
        $linked = [];
        foreach ($project->links->where('app_node_id', $app->id)->where('state', 'applied') as $link) {
            foreach ((array) ($link->env ?? []) as $name) {
                $linked[(string) $name] = $link->resource->name;
            }
        }

        $env = [];
        foreach ($names as $name) {
            $env[$name] = match (true) {
                isset($linked[$name]) => '${{ services.'.$linked[$name].'.'.$name.' }}',
                Previews::secret($name) => '${{ secrets.'.$name.' }}',
                default => '${{ vars.'.$name.' }}',
            };
        }

        return $env;
    }

    /**
     * What a machine runs, as the Services page lists it, without the ones
     * someone hid.
     *
     * @return list<array<string, mixed>>
     */
    public static function services(Machine $machine): array
    {
        return array_values(array_filter(
            AppReleases::visible($machine),
            fn (array $service) => isset($service['key']) && ! ($service['hidden'] ?? false),
        ));
    }

    public static function slug(string $name): string
    {
        return Str::slug($name) ?: 'project';
    }

    public static function fileName(string $name): string
    {
        return Str::slug($name) ?: 'unnamed';
    }

    /**
     * @param  list<array<string, mixed>>  $services
     * @return list<string>
     */
    private static function machineFile(Machine $machine, array $services, ?MachinePolicy $default): array
    {
        $lines = [...self::HEADER, 'machine: '.self::scalar($machine->name)];
        if (filled($machine->provider)) {
            $lines[] = 'provider: '.self::scalar(Str::slug((string) $machine->provider));
        }
        if (filled($machine->region)) {
            $lines[] = 'region: '.self::scalar((string) $machine->region);
        }
        $lines[] = 'environment: '.$machine->environment->value;
        if ($machine->group !== null) {
            $lines[] = 'group: '.self::scalar($machine->group->name);
        }
        $policy = $machine->policy ?? $default;
        if ($policy !== null) {
            $lines[] = 'policy: '.self::scalar(Str::slug($policy->name));
        }

        if ($services !== []) {
            $lines[] = '';
            $lines[] = 'services:';
            foreach ($services as $service) {
                $lines[] = '  '.self::key((string) ($service['label'] ?? null ?: $service['name'])).':';
                if (filled($service['manager'] ?? null)) {
                    $lines[] = '    runs: '.self::scalar((string) $service['manager']);
                }
                if (filled($service['details']['image'] ?? null)) {
                    $lines[] = '    image: '.self::scalar((string) $service['details']['image']);
                }
                if (($service['ports'] ?? []) !== []) {
                    $lines[] = '    ports: ['.implode(', ', array_map('intval', $service['ports'])).']';
                }
            }
        }

        return $lines;
    }

    /**
     * @param  Collection<int, Machine>  $machines
     * @return list<string>
     */
    private static function backupsFile(Collection $machines): array
    {
        $lines = self::HEADER;
        $destinations = $machines->pluck('backupDestination')->filter()->unique('id')->values();
        if ($destinations->isNotEmpty()) {
            $lines[] = 'destinations:';
            foreach ($destinations as $destination) {
                $lines[] = '  '.self::key(Str::slug($destination->name)).':';
                $lines[] = '    type: '.self::scalar($destination->type);
            }
            $lines[] = '';
        }
        $lines[] = 'machines:';
        foreach ($machines as $machine) {
            $schedule = $machine->backup_schedule === 'off' ? 'none' : $machine->backup_schedule;
            if ($machine->backupDestination !== null && $schedule !== 'none') {
                $lines[] = '  '.self::key($machine->name).':';
                $lines[] = '    every: '.self::scalar($schedule);
                $lines[] = '    to: '.self::scalar(Str::slug($machine->backupDestination->name));
            } else {
                $lines[] = '  '.self::key($machine->name).': '.self::scalar($schedule);
            }
        }

        return $lines;
    }

    /**
     * @param  Collection<int, Monitor>  $monitors
     * @return list<string>
     */
    private static function monitorsFile(Collection $monitors): array
    {
        $lines = [...self::HEADER, 'monitors:'];
        if ($monitors->isEmpty()) {
            return [...self::HEADER, 'monitors: []'];
        }
        foreach ($monitors as $monitor) {
            $lines[] = '  - name: '.self::scalar($monitor->name);
            $lines[] = '    check: '.self::scalar($monitor->url ?? trim($monitor->type.' '.$monitor->host.($monitor->port ? ':'.$monitor->port : '')));
            $lines[] = '    every: '.$monitor->interval_seconds.'s';
            if ($monitor->paused) {
                $lines[] = '    paused: true';
            }
        }

        return $lines;
    }

    /**
     * @param  Collection<int, MachinePolicy>  $policies
     * @return list<string>
     */
    private static function policiesFile(Collection $policies): array
    {
        if ($policies->isEmpty()) {
            return [...self::HEADER, 'policies: {}'];
        }
        $lines = [...self::HEADER, 'policies:'];
        foreach ($policies as $policy) {
            $lines[] = '  '.self::key(Str::slug($policy->name)).':';
            if ($policy->is_default) {
                $lines[] = '    default: true';
            }
            $lines[] = '    updates: '.self::scalar($policy->updates);
            $lines[] = '    discovery: '.($policy->discovery ? 'true' : 'false');
            $lines[] = '    logs: '.($policy->logs ? 'true' : 'false');
            $lines[] = '    security_watch: '.($policy->security_watch ? 'true' : 'false');
        }

        return $lines;
    }

    /** @return list<string> */
    private static function rootFile(Workspace $workspace): array
    {
        return [
            ...self::HEADER,
            'workspace: '.self::scalar($workspace->slug),
            'name: '.self::scalar($workspace->name),
            '',
            'include:',
            '  - machines/*.yml',
            '  - projects/*.yml',
            '  - backups.yml',
            '  - monitors.yml',
            '  - policies.yml',
        ];
    }

    private static function key(string $key): string
    {
        return preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.-]*$/', $key) === 1 ? $key : (string) json_encode($key, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function scalar(string $value): string
    {
        $plain = preg_match('#^[A-Za-z0-9_./][A-Za-z0-9_./:@ -]*$#', $value) === 1
            && ! str_ends_with($value, ' ')
            && ! str_ends_with($value, ':')
            && ! str_contains($value, ': ')
            && ! is_numeric($value)
            && ! in_array(strtolower($value), ['true', 'false', 'yes', 'no', 'on', 'off', 'null', '~'], true);

        return $plain ? $value : (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
