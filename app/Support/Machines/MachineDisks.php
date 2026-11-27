<?php

namespace App\Support\Machines;

/**
 * @phpstan-type Disk array{mount: string, used: int, total: int}
 * @phpstan-type DiskFact array{mount: string, device: string, fs_type: string, total_bytes: int}
 */
final class MachineDisks
{
    public const MAX = 8;

    private const PSEUDO = [
        'proc', 'sysfs', 'devtmpfs', 'devpts', 'tmpfs', 'cgroup', 'cgroup2', 'pstore', 'bpf', 'securityfs',
        'debugfs', 'tracefs', 'configfs', 'fusectl', 'mqueue', 'hugetlbfs', 'autofs', 'overlay', 'squashfs',
        'nsfs', 'binfmt_misc', 'rpc_pipefs', 'efivarfs', 'ramfs', 'fuse.lxcfs', 'fuse.portal', 'fuse.gvfsd-fuse',
    ];

    private const FILES = [
        '/etc/resolv.conf', '/etc/hostname', '/etc/hosts', '/etc/localtime', '/etc/timezone', '/etc/machine-id',
        '/etc/mtab', '/etc/passwd', '/etc/group', '/etc/shadow', '/etc/os-release', '/.dockerenv', '/run/.containerenv',
    ];

    private const FILE_EXTENSIONS = '/\.(conf|cnf|cfg|ini|json|ya?ml|toml|env|pem|crt|key|sock|log|txt|xml|properties|lock|pid)$/i';

    /**
     * @param  array<mixed>  $disks
     * @return list<Disk>
     */
    public static function clean(array $disks, mixed $facts): array
    {
        $known = self::known($facts);
        $entries = [];
        foreach ($disks as $disk) {
            if (! is_array($disk) || ! is_string($disk['mount'] ?? null) || ! is_numeric($disk['used'] ?? null) || ! is_numeric($disk['total'] ?? null)) {
                continue;
            }
            $entry = ['mount' => $disk['mount'], 'used' => (int) $disk['used'], 'total' => (int) $disk['total']];
            $fact = $known[$entry['mount']] ?? null;
            if ($entry['total'] < 1 << 30 || self::skipped($entry['mount'], $fact['fs_type'] ?? null)) {
                continue;
            }
            $entries[] = [$entry, ($fact['device'] ?? '') !== '' ? $fact['device'] : null];
        }
        usort($entries, fn (array $a, array $b) => ($a[1] === null) <=> ($b[1] === null));

        $devices = $sizes = $picked = [];
        foreach ($entries as [$entry, $device]) {
            $size = [$entry['used'], $entry['total']];
            $at = $device === null ? array_search($size, $sizes, true) : array_search($device, $devices, true);
            if ($at === false) {
                $devices[] = $device;
                $sizes[] = $size;
                $picked[] = $entry;
            } elseif (self::clearer($entry['mount'], $picked[$at]['mount'])) {
                $picked[$at] = $entry;
            }
        }
        usort($picked, fn (array $a, array $b) => [$b['used'] / $b['total'], $a['mount']] <=> [$a['used'] / $a['total'], $b['mount']]);

        return array_slice($picked, 0, self::MAX);
    }

    /** @return list<DiskFact> */
    public static function facts(mixed $facts): array
    {
        $devices = [];
        foreach (self::known($facts) as $fact) {
            if (self::skipped($fact['mount'], $fact['fs_type'])) {
                continue;
            }
            $device = $fact['device'] !== '' ? $fact['device'] : $fact['mount'];
            if (! isset($devices[$device]) || self::clearer($fact['mount'], $devices[$device]['mount'])) {
                $devices[$device] = $fact;
            }
        }

        return array_values($devices);
    }

    /** @param  list<Disk>  $disks */
    public static function fullest(array $disks): ?int
    {
        return $disks === [] ? null : max(array_map(fn (array $d) => (int) floor($d['used'] / $d['total'] * 100), $disks));
    }

    /** @return array<string, DiskFact> */
    private static function known(mixed $facts): array
    {
        $known = [];
        foreach (is_array($facts) && is_array($facts['disks'] ?? null) ? $facts['disks'] : [] as $disk) {
            if (is_array($disk) && is_string($disk['mount'] ?? null)) {
                $known[$disk['mount']] = [
                    'mount' => $disk['mount'],
                    'device' => is_string($disk['device'] ?? null) ? $disk['device'] : '',
                    'fs_type' => is_string($disk['fs_type'] ?? null) ? $disk['fs_type'] : '',
                    'total_bytes' => is_numeric($disk['total_bytes'] ?? null) ? (int) $disk['total_bytes'] : 0,
                ];
            }
        }

        return $known;
    }

    private static function skipped(string $mount, ?string $fsType): bool
    {
        return in_array($fsType, self::PSEUDO, true)
            || in_array($mount, self::FILES, true)
            || preg_match(self::FILE_EXTENSIONS, $mount) === 1
            || preg_match('#^/(snap|boot/efi|run|dev|sys|proc)(/|$)#', $mount) === 1;
    }

    private static function clearer(string $mount, string $than): bool
    {
        if ($mount === '/' || $than === '/') {
            return $mount === '/' && $than !== '/';
        }

        return [strlen($mount), $mount] < [strlen($than), $than];
    }
}
