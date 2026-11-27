<?php

namespace App\Support\Integrations\Servers;

/**
 * The cloud-init a server made from ServerOS starts with: Docker, then the
 * panel's install command, so the machine joins on its own.
 */
final class CloudInit
{
    public static function userData(string $hostname, string $install): string
    {
        $script = implode("\n", array_map(fn (string $line) => '      '.$line, [
            '#!/bin/bash',
            'set -e',
            'curl -fsSL https://get.docker.com | sh',
            $install,
            'rm -f /root/serveros-install.sh',
        ]));

        return <<<YAML
        #cloud-config
        hostname: {$hostname}
        write_files:
          - path: /root/serveros-install.sh
            permissions: '0700'
            content: |
        {$script}
        runcmd:
          - [ bash, /root/serveros-install.sh ]
        YAML;
    }

    /**
     * The same install as a script to run in a server that's already up
     * (through a guest agent), once cloud-init has finished with it.
     */
    public static function script(string $install): string
    {
        return implode("\n", [
            '#!/bin/bash',
            'set -e',
            'cloud-init status --wait >/dev/null 2>&1 || true',
            'command -v docker >/dev/null 2>&1 || curl -fsSL https://get.docker.com | sh',
            $install,
        ])."\n";
    }

    /** The same install as one line to paste into a root shell. */
    public static function oneLine(string $install): string
    {
        return '(command -v docker >/dev/null 2>&1 || curl -fsSL https://get.docker.com | sh) && '.$install;
    }

    /**
     * Adds SSH public keys to the default user, for providers that take only
     * one key when the server is made.
     *
     * @param  list<string>  $publicKeys
     */
    public static function withKeys(string $userData, array $publicKeys): string
    {
        $keys = array_values(array_filter(array_map(fn (string $key) => trim((string) preg_replace('/\s+/', ' ', $key)), $publicKeys), fn (string $key) => preg_match('/^[a-z0-9@.-]+ [A-Za-z0-9+\/=]+( .*)?$/', $key) === 1));
        if ($keys === [] || ! str_starts_with($userData, "#cloud-config\n")) {
            return $userData;
        }
        $lines = array_map(fn (string $key) => '  - '.json_encode($key, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $keys);

        return "#cloud-config\nssh_authorized_keys:\n".implode("\n", $lines)."\n".substr($userData, strlen("#cloud-config\n"));
    }

    public static function hostname(string $name): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9-]+/', '-', strtolower($name)), '-');

        return substr($slug === '' ? 'server' : $slug, 0, 63);
    }
}
