import { trans } from 'laravel-vue-i18n';
import type { Machine } from '@/components/machines/machines';

const GB = 1024 ** 3;

export type Load = 'calm' | 'busy' | 'high';

export function loadOf(percent: number | null | undefined): Load {
    if (percent === null || percent === undefined) return 'calm';
    if (percent >= 80) return 'high';
    if (percent >= 60) return 'busy';

    return 'calm';
}

function trim(value: number, digits: number): string {
    return String(Number(value.toFixed(digits)));
}

export function sizePair(
    usedBytes: number,
    totalBytes: number,
): { used: string; total: string } {
    const tera = totalBytes >= 1000 * GB;
    const unit = tera ? GB * 1024 : GB;
    const used = usedBytes / unit;
    const total = totalBytes / unit;

    return {
        used: used < 10 ? trim(used, 1) : String(Math.round(used)),
        total: `${total < 10 ? trim(total, 1) : Math.round(total)} ${tera ? 'TB' : 'GB'}`,
    };
}

export function bytesLabel(bytes: number): string {
    if (bytes >= 1000 * GB) return `${trim(bytes / (GB * 1024), 1)} TB`;
    if (bytes >= GB) return `${trim(bytes / GB, 1)} GB`;
    if (bytes >= 1024 ** 2) return `${Math.round(bytes / 1024 ** 2)} MB`;

    return `${Math.max(0, Math.round(bytes / 1024))} KB`;
}

export function rateParts(bytesPerSecond: number): {
    value: string;
    unit: string;
} {
    const units = ['B/s', 'KB/s', 'MB/s', 'GB/s'];
    let value = Math.max(0, bytesPerSecond);
    let step = 0;
    while (value >= 1000 && step < units.length - 1) {
        value /= 1024;
        step += 1;
    }

    return {
        value:
            step === 0 || value >= 100
                ? String(Math.round(value))
                : trim(value, 1),
        unit: units[step],
    };
}

export function uptimeLabel(seconds: number): string {
    const days = Math.floor(seconds / 86_400);
    if (days >= 1)
        return days === 1
            ? trans('1 day')
            : trans(':count days', { count: String(days) });
    const hours = Math.floor(seconds / 3600);
    if (hours >= 1)
        return hours === 1
            ? trans('1 hour')
            : trans(':count hours', { count: String(hours) });
    const minutes = Math.max(1, Math.floor(seconds / 60));

    return minutes === 1
        ? trans('1 minute')
        : trans(':count minutes', { count: String(minutes) });
}

export function memoryOf(
    machine: Machine,
): { used: number; total: number; percent: number } | null {
    const total = machine.facts?.memory_bytes ?? 0;
    if (machine.memoryMb === null || machine.memoryMb === undefined || !total)
        return null;
    const used = machine.memoryMb * 1024 ** 2;

    return { used, total, percent: Math.min(100, (used / total) * 100) };
}

export function diskOf(
    machine: Machine,
): { used: number; total: number; percent: number } | null {
    const disks = machine.disks ?? [];
    const total = disks.reduce((sum, disk) => sum + disk.total, 0);
    if (!total) return null;
    const used = disks.reduce((sum, disk) => sum + disk.used, 0);

    return { used, total, percent: Math.min(100, (used / total) * 100) };
}

export function reporting(machine: Machine): boolean {
    return (
        machine.connection === 'demo' ||
        machine.connection === 'online' ||
        machine.connection === 'degraded'
    );
}

export function systemName(machine: Machine, short = false): string | null {
    const os = machine.facts?.os;
    if (!os) return null;
    const version = machine.facts?.os_version ?? '';
    const [number, ...rest] = version.split(' ');
    const release = number.split('.').slice(0, 2).join('.');

    return [os, release, short ? '' : rest.join(' ')]
        .filter(Boolean)
        .join(' ')
        .trim();
}

const VIRTUAL =
    /^(lo|docker|br-|veth|virbr|cni|flannel|cali|tailscale|wg|zt|tun|kube)/;

function isPrivate(address: string): boolean {
    return (
        address.startsWith('10.') ||
        address.startsWith('192.168.') ||
        /^172\.(1[6-9]|2\d|3[01])\./.test(address)
    );
}

export function privateAddress(machine: Machine): string | null {
    for (const network of machine.facts?.interfaces ?? []) {
        if (VIRTUAL.test(network.name)) continue;
        for (const raw of network.addresses) {
            const address = raw.split('/')[0];
            if (isPrivate(address)) return address;
        }
    }

    return null;
}

export function sizeName(machine: Machine): string | null {
    const cores = machine.facts?.cpu_cores;
    const memory = machine.facts?.memory_bytes;
    const parts = [
        cores ? `${cores} vCPU` : null,
        memory ? `${Math.round(memory / GB)} GB` : null,
    ].filter(Boolean);

    return parts.length ? parts.join(' · ') : null;
}
