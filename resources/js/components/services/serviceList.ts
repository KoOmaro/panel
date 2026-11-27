import { trans } from 'laravel-vue-i18n';
import type { WorkspaceService } from '@/components/machines/serviceFilters';
import { isManaged, statusGroup } from '@/components/machines/serviceFormat';
import type { ServiceStat } from '@/components/machines/serviceFormat';
import { uptimeLabel } from '@/components/machines/machineFormat';
import { brandMark } from '@/lib/brandLogos';

export type ListMachine = {
    id: number;
    name: string;
    connection: string;
    scanned: boolean;
    scannedAt: number | null;
    memoryBytes: number | null;
};

export type AdoptJob = {
    id: string;
    machineId: number;
    service: string | null;
    state: string;
    error: Record<string, unknown> | null;
};

export type StatusView = {
    dot: string;
    label: string;
    tone: string;
    extra: string | null;
};

const dots = {
    live: 'bg-status-live',
    down: 'bg-status-down',
    partial: 'bg-[#f5a833]',
    quiet: 'bg-[#a3a3a3] dark:bg-[#6b6b6b]',
};

export function displayName(
    service: WorkspaceService,
    inStack = false,
): string {
    return (
        service.label ??
        service.app ??
        service.found ??
        composeName(service, inStack) ??
        service.name
    );
}

function composeName(
    service: WorkspaceService,
    inStack: boolean,
): string | null {
    const project = service.details?.compose_project;
    if (!project) return null;

    const name = service.name.replace(/^\//, '');
    if (inStack) {
        return name.startsWith(`${project}-`)
            ? name.slice(project.length + 1).replace(/-\d+$/, '') || null
            : null;
    }

    return project.startsWith('serveros-')
        ? project.slice('serveros-'.length) || null
        : null;
}

export function imageLabel(image: string | null | undefined): string | null {
    if (!image) return null;
    const clean = image.replace(/@sha256:[0-9a-f]+$/i, '');
    const parts = clean.split('/');

    return parts.length > 1 && /[.:]|^localhost$/.test(parts[0])
        ? parts.slice(1).join('/')
        : clean;
}

export function logoName(service: WorkspaceService): string {
    const image = service.details?.image;

    return image && brandMark(image) ? image : service.name;
}

export function subtitle(service: WorkspaceService, offline = false): string {
    const parts = [
        imageLabel(service.details?.image) ??
            `${service.manager} · ${service.kind}`,
    ];
    if (!offline && !isManaged(service) && service.status === 'running') {
        parts.push(trans('already running'));
    }
    if (service.hidden) {
        parts.push(trans('hidden'));
    }

    return parts.join(' · ');
}

export function serviceStatus(
    service: WorkspaceService,
    offline: boolean,
): StatusView {
    if (offline) {
        return {
            dot: dots.quiet,
            label: trans('Offline'),
            tone: '',
            extra: null,
        };
    }

    const stat = service.stat;
    switch (service.status) {
        case 'running':
            return {
                dot: dots.live,
                label: trans('Running'),
                tone: '',
                extra: stat?.startedAt
                    ? uptimeLabel(Date.now() / 1000 - stat.startedAt)
                    : null,
            };
        case 'failed':
            return {
                dot: dots.down,
                label: trans('Failed'),
                tone: 'text-status-down',
                extra: null,
            };
        case 'restarting':
            return {
                dot: dots.down,
                label: trans('Restarting'),
                tone: 'text-status-down',
                extra: stat?.restarts
                    ? trans(':count restarts', {
                          count: String(stat.restarts),
                      })
                    : null,
            };
        case 'unknown':
            return {
                dot: dots.quiet,
                label: trans('Unknown'),
                tone: '',
                extra: null,
            };
        default:
            return {
                dot: dots.quiet,
                label: trans('Stopped'),
                tone: '',
                extra: null,
            };
    }
}

export function groupStatus(
    services: WorkspaceService[],
    offline: boolean,
): StatusView {
    const total = services.length;
    const counts = { running: 0, stopped: 0, problems: 0 };
    services.forEach((service) => counts[statusGroup(service)]++);

    if (offline) {
        return {
            dot: dots.quiet,
            label: trans('Offline'),
            tone: '',
            extra: null,
        };
    }
    if (counts.problems > 0) {
        return {
            dot: dots.down,
            label: trans(':count failing', { count: String(counts.problems) }),
            tone: 'text-status-down',
            extra: null,
        };
    }
    if (counts.running === total) {
        return {
            dot: dots.live,
            label: trans(':count running', { count: String(total) }),
            tone: '',
            extra: null,
        };
    }
    if (counts.running === 0) {
        return {
            dot: dots.quiet,
            label: trans('Stopped'),
            tone: '',
            extra: null,
        };
    }

    return {
        dot: dots.partial,
        label: trans(':running of :total up', {
            running: String(counts.running),
            total: String(total),
        }),
        tone: '',
        extra: null,
    };
}

export function cpuOf(stats: (ServiceStat | null)[]): number | null {
    const values = stats
        .map((stat) => stat?.cpu)
        .filter((value): value is number => typeof value === 'number');

    return values.length
        ? Math.round(values.reduce((sum, value) => sum + value, 0))
        : null;
}

export function memoryOf(
    stats: (ServiceStat | null)[],
    machineBytes: number | null,
): { used: number; percent: number } | null {
    const live = stats.filter(
        (stat): stat is ServiceStat => typeof stat?.memUsed === 'number',
    );
    if (live.length === 0) return null;

    const used = live.reduce((sum, stat) => sum + (stat.memUsed ?? 0), 0);
    const limit =
        live.length === 1 && live[0].memLimit && live[0].memLimit < 2 ** 50
            ? live[0].memLimit
            : machineBytes;

    return { used, percent: limit ? (used / limit) * 100 : 0 };
}

export function portsLabel(
    ports: number[],
    max = 2,
): { shown: string; more: number } {
    const sorted = [...new Set(ports)].sort((a, b) => a - b);

    return {
        shown: sorted.slice(0, max).join(', '),
        more: Math.max(0, sorted.length - max),
    };
}
