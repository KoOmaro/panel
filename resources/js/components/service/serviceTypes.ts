import { trans } from 'laravel-vue-i18n';
import type { DashStatus } from '@/components/dash/StatusBadge.vue';
import type { AppLimits } from '@/components/machines/AppLimitsDialog.vue';
import type { SiteSwitches } from '@/components/machines/AppSiteDialog.vue';
import type { SiteTrafficState } from '@/components/machines/SiteTraffic.vue';
import { fixed } from '@/lib/formatting';

export type AppVersion = {
    id: string;
    commit: string;
    live: boolean;
    deployedAt: string | null;
    by: string | null;
    push: boolean;
    repo: string | null;
    data: boolean;
    dataAt: string | null;
};

export type AppVariable = { name: string; from: string | null; build: boolean };

export type ServiceApp = {
    service: string;
    port: number | null;
    https: boolean;
    commit: string | null;
    fromUpload: boolean;
    repo: string | null;
    domains: string[];
    mentions: string[];
    canMove: boolean;
    deployedAt: string | null;
    snapshotBefore: boolean;
    deployOnPush: { on: boolean; branch: string; onlyNewest: boolean } | null;
    site: SiteSwitches;
    traffic: SiteTrafficState;
    database: {
        setting: string;
        current: { name: string; display: string } | null;
        options: { value: string; label: string }[];
    };
    versions: AppVersion[];
    variables: AppVariable[];
    limits: AppLimits;
};

export type ServiceUsage = {
    requests: number;
    p50: number | null;
    p95: number | null;
} | null;

export type StackMember = {
    name: string;
    status: string | null;
    logo: string | null;
    url: string;
};

export type ServiceStack = {
    kind: 'project' | 'compose';
    name: string;
    url: string | null;
    members: StackMember[];
} | null;

export type LinkedDatabase = {
    name: string;
    status: string | null;
    machine: string;
    sameMachine: boolean;
    logo: string | null;
    url: string | null;
} | null;

export type LogSummary = {
    today: number | null;
    stored: boolean;
    shipping: boolean;
    retentionDays: number;
    names: string[];
};

export type UsagePoint = {
    ts: number;
    cpu: number | null;
    mem: number | null;
    rx: number | null;
    tx: number | null;
};

export function statusOf(status: string | null | undefined): {
    status: DashStatus;
    label: string;
} {
    switch (status) {
        case 'running':
            return { status: 'live', label: trans('Running') };
        case 'restarting':
            return { status: 'pending', label: trans('Restarting') };
        case 'failed':
            return { status: 'down', label: trans('Failed') };
        case 'stopped':
        case 'exited':
            return { status: 'stopped', label: trans('Stopped') };
        default:
            return {
                status: 'stopped',
                label: status ? status : trans('Unknown'),
            };
    }
}

export function dotClass(status: string | null | undefined): string {
    return (
        {
            running: 'bg-status-live',
            restarting: 'bg-status-pending',
            failed: 'bg-status-down',
        }[status ?? ''] ?? 'bg-status-stopped'
    );
}

export function size(value: number | null | undefined): string {
    if (value === null || value === undefined) return '–';
    if (value >= 1024 ** 3) {
        return `${fixed(value / 1024 ** 3, 1).replace(/\.0$/, '')} GB`;
    }
    if (value >= 1024 ** 2) return `${Math.round(value / 1024 ** 2)} MB`;
    if (value >= 1024) return `${Math.round(value / 1024)} KB`;

    return `${Math.round(value)} B`;
}

export function megabytes(mb: number): string {
    return mb >= 1024 && mb % 1024 === 0 ? `${mb / 1024} GB` : `${mb} MB`;
}

export function cpus(count: number): string {
    return count === 1
        ? trans('1 CPU')
        : trans(':count CPUs', { count: String(count) });
}

export function duration(seconds: number): string {
    const days = Math.floor(seconds / 86400);
    const hours = Math.floor((seconds % 86400) / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);

    if (days >= 1) {
        return days === 1
            ? trans('1 day')
            : trans(':count days', { count: String(days) });
    }
    if (hours >= 1) {
        return hours === 1
            ? trans('1 hour')
            : trans(':count hours', { count: String(hours) });
    }
    if (minutes >= 1) {
        return minutes === 1
            ? trans('1 minute')
            : trans(':count minutes', { count: String(minutes) });
    }

    return trans('a few seconds');
}

export function restartPolicy(policy: string | null | undefined): string {
    return (
        {
            'unless-stopped': trans('Unless stopped'),
            always: trans('Always'),
            'on-failure': trans('On failure'),
            no: trans('Never'),
        }[policy ?? ''] ??
        policy ??
        '–'
    );
}
