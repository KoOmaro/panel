<?php

namespace App\Console\Commands;

use App\Models\Workspace;
use App\Support\Workspaces\Dashboard;
use App\Support\WorkspaceSlugs;
use Illuminate\Console\Command;

class CheckReservedSlugs extends Command
{
    protected $signature = 'workspaces:reserved-slugs';

    protected $description = 'List workspaces whose slug is a reserved word, so their dashboard address would clash with a panel page';

    public function handle(): int
    {
        $clashing = Workspace::query()->whereIn('slug', WorkspaceSlugs::RESERVED)->orderBy('slug')->get(['id', 'name', 'slug']);
        if ($clashing->isEmpty()) {
            $this->info('No workspace uses a reserved slug.');

            return self::SUCCESS;
        }

        $this->error('These workspaces can’t open at '.Dashboard::host().'/<slug> until their slug changes:');
        $this->table(['ID', 'Slug', 'Name'], $clashing->map(fn (Workspace $workspace) => [$workspace->id, $workspace->slug, $workspace->name])->all());

        return self::FAILURE;
    }
}
