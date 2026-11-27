<?php

namespace App\Console\Commands;

use App\Support\Machines\SshAccess;
use Illuminate\Console\Command;

class EndSshAccess extends Command
{
    protected $signature = 'ssh-access:end';

    protected $description = 'Take SSH keys off machines when their access has expired or their person has left the workspace';

    public function handle(SshAccess $ssh): int
    {
        $done = $ssh->sweep();

        $this->info("Ended {$done['ended']} SSH access, stopped waiting on {$done['stale']}.");

        return self::SUCCESS;
    }
}
