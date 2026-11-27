<?php

namespace App\Support\Machines\Readme;

use App\Jobs\WriteMachineReadme;
use App\Models\Machine;
use App\Models\MachineReadme;
use App\Models\User;
use App\Support\Code\CodeRepository;
use App\Support\Code\WorkspaceExport;
use App\Support\GitHub\GitHubApp;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;

/**
 * @phpstan-import-type Facts from ReadmeFacts
 * @phpstan-import-type Sections from ReadmeWords
 */
final class MachineReadmes
{
    public const int CHECK_EVERY_SECONDS = 3600;

    public const int STALE_SECONDS = 180;

    public const int RECENT_DAYS = 7;

    public const int WRITES_PER_HOUR = 5;

    public function __construct(private readonly ReadmeFacts $facts) {}

    public static function queue(Machine $machine, bool $force = false): MachineReadme
    {
        $readme = MachineReadme::query()->firstOrNew(['machine_id' => $machine->id]);
        $readme->forceFill(['state' => MachineReadme::WRITING, 'requested_at' => now()])->save();
        Cache::put(self::checkKey($machine), true, self::CHECK_EVERY_SECONDS);
        WriteMachineReadme::dispatch($machine->id, $force);

        return $readme;
    }

    public static function changed(Machine $machine): void
    {
        if (! Cache::add(self::checkKey($machine), true, self::CHECK_EVERY_SECONDS)) {
            return;
        }
        $readme = MachineReadme::query()->where('machine_id', $machine->id)->first(['id', 'state', 'generated_at']);
        if ($readme === null || $readme->state !== MachineReadme::READY) {
            return;
        }
        WriteMachineReadme::dispatch($machine->id, false);
    }

    public static function opensFirst(Machine $machine, User $user): bool
    {
        if ($machine->created_at === null || $machine->created_at->lt(now()->subDays(self::RECENT_DAYS))) {
            return false;
        }
        $readme = $machine->readme;

        return $readme !== null && ! $readme->seenBy($user);
    }

    public static function seen(Machine $machine, User $user): void
    {
        $readme = $machine->readme;
        if ($readme === null || $readme->seenBy($user)) {
            return;
        }
        $seen = $readme->seen_by ?? [];
        $seen[(string) $user->id] = now()->toIso8601String();
        $readme->forceFill(['seen_by' => $seen])->save();
    }

    /** @return array<string, mixed> */
    public function page(Machine $machine, User $user): array
    {
        $facts = $this->facts->build($machine);
        $readme = $machine->readme()->first();
        if ($readme === null && $machine->inventory !== null) {
            $readme = self::queue($machine);
        } elseif ($readme?->state === MachineReadme::READY && $readme->facts_hash !== ReadmeWords::hash($facts)) {
            self::changed($machine);
        }

        $writing = $readme !== null && $readme->state === MachineReadme::WRITING
            && $readme->requested_at !== null && $readme->requested_at->gt(now()->subSeconds(self::STALE_SECONDS));
        $words = $source = $reason = null;
        if (! $writing && $machine->inventory !== null) {
            $sections = $readme?->sections;
            $source = $sections === null ? 'panel' : $readme->source;
            $reason = $sections === null ? 'slow' : $readme->reason;
            $words = self::words($sections, $facts);
        }

        $repository = CodeRepository::of($machine->workspace);
        $file = WorkspaceExport::fileName($machine->name).'.md';

        return [
            'scanned' => $machine->inventory !== null,
            'writing' => $writing,
            'generatedAt' => $readme?->generated_at?->toIso8601String(),
            'source' => $source,
            'reason' => $reason,
            'facts' => $facts,
            'words' => $words,
            'markdown' => $words === null ? null : ReadmeMarkdown::render($facts, $words, $readme?->generated_at),
            'repository' => $repository === null ? null : [
                'repo' => $repository['repo'],
                'file' => $file,
                'connected' => GitHubApp::enabled() && GitHubApp::connected($machine->workspace, $repository['owner']),
                'pullRequest' => $repository['pullRequest']['url'] ?? null,
            ],
            'canCommit' => Gate::forUser($user)->allows('manageMachines', $machine->workspace),
            'writesLeft' => max(0, self::WRITES_PER_HOUR - (int) RateLimiter::attempts(self::writeKey($machine))),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $sections
     * @param  Facts  $facts
     * @return Sections
     */
    public static function words(?array $sections, array $facts): array
    {
        $fallback = ReadmeWords::panel($facts);
        $services = [];
        foreach ($facts['services'] as $service) {
            $written = $sections['services'][$service['id']] ?? null;
            $services[$service['id']] = is_string($written) && $written !== '' ? $written : $fallback['services'][$service['id']];
        }

        return [
            'intro' => is_string($sections['intro'] ?? null) && $sections['intro'] !== '' ? $sections['intro'] : $fallback['intro'],
            'wiring' => is_string($sections['wiring'] ?? null) && $sections['wiring'] !== '' ? $sections['wiring'] : $fallback['wiring'],
            'services' => $services,
        ];
    }

    public static function writeKey(Machine $machine): string
    {
        return "machine-readme:write:{$machine->id}";
    }

    private static function checkKey(Machine $machine): string
    {
        return "machine-readme:check:{$machine->id}";
    }
}
