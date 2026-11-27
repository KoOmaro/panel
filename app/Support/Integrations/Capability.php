<?php

namespace App\Support\Integrations;

enum Capability: string
{
    case Servers = 'servers';
    case CreateServers = 'create_servers';
    case Resize = 'resize';
    case Power = 'power';
    case Snapshots = 'snapshots';
    case DeleteServers = 'delete_servers';

    public function label(): string
    {
        return self::t(match ($this) {
            self::Servers => 'Link machines',
            self::CreateServers => 'Make servers',
            self::Resize => 'Resize',
            self::Power => 'Restart and power cycle',
            self::Snapshots => 'Snapshots',
            self::DeleteServers => 'Delete servers',
        });
    }

    public function description(): string
    {
        return self::t(match ($this) {
            self::Servers => 'Matches its servers to your machines by IP address, and lists the ones that aren’t in ServerOS yet.',
            self::CreateServers => 'Makes a server that installs ServerOS as it starts, so it joins on its own.',
            self::Resize => 'Moves a linked machine to a bigger or smaller server type.',
            self::Power => 'Restarts a linked machine, or cuts its power and starts it again, even when its daemon is offline.',
            self::Snapshots => 'Takes, lists and deletes snapshots of a linked machine’s disk.',
            self::DeleteServers => 'Deletes a linked machine’s server for good.',
        });
    }

    /** @return array{key: string, label: string, description: string} */
    public function toArray(): array
    {
        return ['key' => $this->value, 'label' => $this->label(), 'description' => $this->description()];
    }

    private static function t(string $text): string
    {
        return is_string($line = __($text)) ? $line : $text;
    }
}
