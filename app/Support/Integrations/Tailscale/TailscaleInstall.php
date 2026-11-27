<?php

namespace App\Support\Integrations\Tailscale;

use App\Models\Machine;
use App\Support\Projects\Placement;
use Illuminate\Support\Str;

/**
 * How Tailscale gets onto a machine. The daemon only runs commands inside
 * containers, so ServerOS deploys Tailscale's own image on the host's
 * network with kernel networking (/dev/net/tun, NET_ADMIN): the machine
 * itself gets the tailnet address, and the container keeps its state in a
 * volume, so it stays on the tailnet across restarts. The one-off key goes
 * in as TS_AUTHKEY when the job is sent and is never stored.
 */
final class TailscaleInstall
{
    public const string SERVICE = 'tailscale';

    public const string CONTAINER = 'serveros-tailscale';

    public const string KEY_ENV = 'TS_AUTHKEY';

    public const string INSTALL_SCRIPT = 'https://tailscale.com/install.sh';

    public static function image(): string
    {
        $image = config('services.tailscale.image');

        return is_string($image) && preg_match('#^[a-z0-9][a-z0-9._/:@-]*$#i', $image) === 1 ? $image : 'tailscale/tailscale:stable';
    }

    public static function keyMinutes(): int
    {
        return max(5, min(60, (int) config('services.tailscale.key_minutes', 15)));
    }

    /** The machine's name as a DNS label, which is what Tailscale calls it. */
    public static function hostname(Machine $machine): string
    {
        $label = trim((string) preg_replace('/-+/', '-', (string) preg_replace('/[^a-z0-9-]+/', '-', Str::lower(Str::ascii($machine->name)))), '-');
        $label = rtrim(substr($label, 0, 63), '-');

        return $label === '' ? 'machine-'.$machine->id : $label;
    }

    /** @param  list<string>  $tags */
    public static function extraArgs(array $tags): string
    {
        return $tags === [] ? '' : '--advertise-tags='.implode(',', $tags);
    }

    public static function compose(): string
    {
        $image = self::image();
        $container = self::CONTAINER;

        return <<<YAML
services:
  tailscale:
    image: {$image}
    container_name: {$container}
    network_mode: host
    restart: unless-stopped
    environment:
      TS_AUTHKEY: \${TS_AUTHKEY:-}
      TS_HOSTNAME: \${TS_HOSTNAME}
      TS_EXTRA_ARGS: \${TS_EXTRA_ARGS:-}
      TS_STATE_DIR: /var/lib/tailscale
      TS_USERSPACE: "false"
      TS_AUTH_ONCE: "true"
      TS_ACCEPT_DNS: "false"
    volumes:
      - state:/var/lib/tailscale
    devices:
      - /dev/net/tun:/dev/net/tun
    cap_add:
      - NET_ADMIN
      - NET_RAW
volumes:
  state:

YAML;
    }

    /**
     * The deploy job, without the key: DaemonJobQueue adds it as the job is sent.
     *
     * @param  list<string>  $tags
     * @return array<string, mixed>
     */
    public static function job(string $hostname, array $tags): array
    {
        $compose = self::compose();

        return [
            'type' => 'deploy',
            'service' => self::SERVICE,
            'repo' => '',
            'commit' => sha1("tailscale\n".$compose),
            'files' => ['compose.yaml' => $compose],
            'compose_file' => 'compose.yaml',
            'dockerfile' => null,
            'env' => array_filter(['TS_HOSTNAME' => $hostname, 'TS_EXTRA_ARGS' => self::extraArgs($tags)], fn (string $value) => $value !== ''),
            'health' => null,
            'domains' => [],
            'port' => null,
            'snapshot_before' => false,
        ];
    }

    /** @return array<string, mixed>|null the container ServerOS runs Tailscale in, as the machine reported it */
    public static function container(Machine $machine): ?array
    {
        foreach (Placement::containers($machine, self::SERVICE) as $service) {
            if (str_starts_with((string) ($service['key'] ?? ''), 'docker:')) {
                return $service;
            }
        }
        foreach ($machine->inventory['services'] ?? [] as $service) {
            if (is_array($service) && ($service['name'] ?? null) === self::CONTAINER && str_starts_with((string) ($service['key'] ?? ''), 'docker:')) {
                return $service;
            }
        }

        return null;
    }

    /** Tailscale already runs on the machine, put there some other way than by ServerOS. */
    public static function native(Machine $machine): bool
    {
        if (self::container($machine) !== null) {
            return false;
        }
        foreach ($machine->inventory['services'] ?? [] as $service) {
            if (is_array($service) && in_array($service['key'] ?? null, ['systemd:tailscaled.service', 'systemd:tailscaled'], true)) {
                return true;
            }
        }
        foreach ($machine->facts['interfaces'] ?? [] as $interface) {
            if (is_array($interface) && str_starts_with((string) ($interface['name'] ?? ''), 'tailscale')) {
                return true;
            }
        }

        return false;
    }

    /**
     * What to run on the machine instead, with a one-off key.
     *
     * @param  list<string>  $tags
     * @return list<string>
     */
    public static function joinCommands(string $key, string $hostname, array $tags, bool $installed): array
    {
        $up = 'sudo tailscale up --auth-key='.$key.' --hostname='.$hostname;
        if ($tags !== []) {
            $up .= ' '.self::extraArgs($tags);
        }

        return $installed ? [$up] : ['curl -fsSL '.self::INSTALL_SCRIPT.' | sh', $up];
    }

    /** @return list<string> */
    public static function leaveCommands(Machine $machine): array
    {
        if (self::container($machine) === null) {
            return ['sudo tailscale logout'];
        }

        return [
            'sudo docker exec '.self::CONTAINER.' tailscale logout',
            'sudo docker rm -f '.self::CONTAINER,
            'sudo docker volume rm '.self::CONTAINER.'_state',
        ];
    }
}
