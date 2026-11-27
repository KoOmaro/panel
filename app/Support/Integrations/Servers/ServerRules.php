<?php

namespace App\Support\Integrations\Servers;

/**
 * How a provider's servers behave, where providers differ: whether a resize
 * needs the server off, what happens to the disk, whether it can go smaller,
 * whether a restart is gentle, whether snapshots belong to one server
 * or to the whole account, and whether sizes are fixed types or picked by
 * hand (SizesFreely). A power cycle that has to wait for the server to stop
 * before starting it runs as a ServerOperations change (powerCycleSteps),
 * and stopChangesIp says a stop gives the server a new public IPv4 address,
 * unless its address is fixed (Server::$fixedIp). rootPassword is false
 * where there's no root password to reset at the provider (AWS), so SSH
 * keys are the only way in besides the ServerOS terminal.
 */
final readonly class ServerRules
{
    /** The disk can grow with the new type, or stay as it is so the server can move to a smaller type later. */
    public const string DISK_OPTIONAL = 'optional';

    /** The disk always moves to the new type's size. */
    public const string DISK_ALWAYS = 'always';

    /** The disk isn't part of the type and stays as it is (AWS's EBS volumes). */
    public const string DISK_KEPT = 'kept';

    /** Servers are made and resized by picking one of the provider's types. */
    public const string SIZING_TYPES = 'types';

    /** Cores, memory and disk are picked by hand (SizesFreely). */
    public const string SIZING_CUSTOM = 'custom';

    public function __construct(
        public bool $resizeNeedsPowerOff,
        public string $resizeDisk = self::DISK_OPTIONAL,
        public bool $resizeDown = true,
        public bool $gracefulRestart = true,
        public bool $snapshotsPerServer = true,
        public string $sizing = self::SIZING_TYPES,
        public bool $powerCycleSteps = false,
        public bool $stopChangesIp = false,
        public bool $rootPassword = true,
    ) {}

    /** @return array{powerOff: bool, disk: string, down: bool, gracefulRestart: bool, snapshotsPerServer: bool, sizing: string, powerCycleSteps: bool, stopChangesIp: bool, rootPassword: bool} */
    public function toArray(): array
    {
        return [
            'powerOff' => $this->resizeNeedsPowerOff,
            'disk' => $this->resizeDisk,
            'down' => $this->resizeDown,
            'gracefulRestart' => $this->gracefulRestart,
            'snapshotsPerServer' => $this->snapshotsPerServer,
            'sizing' => $this->sizing,
            'powerCycleSteps' => $this->powerCycleSteps,
            'stopChangesIp' => $this->stopChangesIp,
            'rootPassword' => $this->rootPassword,
        ];
    }
}
