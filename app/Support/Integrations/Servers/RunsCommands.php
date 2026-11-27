<?php

namespace App\Support\Integrations\Servers;

use App\Models\Integration;
use App\Support\Integrations\IntegrationFailed;

/**
 * A provider that can run a command inside a server without SSH, like
 * Proxmox through the QEMU guest agent. Providers that can't take
 * cloud-init user data install ServerOS this way: create() is still given
 * the user data, and ServerOperations runs the install script once
 * runner() says READY. When it says NEVER, or doesn't get there in time,
 * the person is shown the one-line command to run instead.
 *
 * @throws IntegrationFailed
 */
interface RunsCommands
{
    public const string READY = 'ready';

    public const string WAITING = 'waiting';

    public const string NEVER = 'never';

    /** Whether a command can be run in the server now: READY, WAITING (not answering yet) or NEVER. */
    public function runner(Integration $integration, string $id): string;

    /** Starts a bash script as root and returns a handle to ask about it. */
    public function run(Integration $integration, string $id, string $script): string;

    /** How it went, or null while it's still running. */
    public function ran(Integration $integration, string $id, string $handle): ?CommandRun;
}
