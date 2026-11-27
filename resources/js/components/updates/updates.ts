import { trans } from 'laravel-vue-i18n';
import type { Tone } from '@/lib/jobStatus';
import { failIfBad } from '@/lib/errors';

export type UpdatesSummary = {
    updates: number;
    packages: number;
    security: number;
    machines: number;
    total: number;
    unchecked: number;
    rebootRequired: number;
    held: number;
    checkedAt: string | null;
    newestCheck: string | null;
};

export type Severity = 'critical' | 'high' | 'medium' | 'low' | 'unknown';

export type SecurityLevel = {
    cves: number;
    exploited: number;
    packages: string[];
};

export type SecuritySummary = {
    updates: number;
    packages: number;
    cves: number;
    exploited: number;
    levels: Record<'critical' | 'high' | 'medium' | 'low', SecurityLevel>;
    scannedAt: string | null;
};

export type PackageCve = {
    cve: string;
    title: string | null;
    severity: Severity;
    cvss: number | null;
    epss: number | null;
    exploited: boolean;
    url: string;
};

export type PackageFixes = {
    severity: Severity;
    count: number;
    exploited: number;
    epss: number | null;
    cves: PackageCve[];
};

export type UpdatesMachine = {
    id: number;
    name: string;
    provider: string | null;
    ip: string | null;
    os: string | null;
    release: string;
    online: boolean;
    connection: 'demo' | 'connecting' | 'online' | 'degraded' | 'offline';
    groupId: number | null;
    updates: number;
    security: number;
    rebootRequired: boolean;
    checkedAt: string | null;
    complete: boolean;
    held: string[];
    canVersions: boolean;
    canPick: boolean;
};

export type PackageVersion = {
    version: string;
    release: string;
    os: string | null;
    machineIds: number[];
};

export type UpdatesPackage = {
    name: string;
    security: boolean;
    machineIds: number[];
    versions: PackageVersion[];
    installed: string[];
    heldOn: number[];
    on: { id: number; installed: string | null; candidate: string }[];
    restartAfterInstall: boolean;
    fixes: PackageFixes | null;
};

export type RolloutState =
    | 'pending'
    | 'running'
    | 'paused'
    | 'done'
    | 'failed'
    | 'cancelled';

export type Rollout = {
    id: number;
    name: string;
    state: RolloutState;
    mode: 'latest' | 'exact';
    everything: boolean;
    packages: { name: string; version: string | null }[];
    allowDowngrade: boolean;
    batchSize: number | null;
    stopOnFailure: boolean;
    reboot: 'never' | 'if_required';
    scheduledFor: string | null;
    startedAt: string | null;
    finishedAt: string | null;
    createdAt: string | null;
    by: string | null;
    total: number;
    finished: number;
    done: number;
    failed: number;
    skipped: number;
    running: number;
};

export type RolloutTarget = {
    machineId: number;
    name: string;
    provider: string | null;
    state: 'pending' | 'running' | 'done' | 'failed' | 'skipped';
    outcome: string | null;
    installed: { name: string; version: string | null }[];
    jobId: string | null;
    rebootJobId: string | null;
    startedAt: string | null;
    finishedAt: string | null;
};

export type RolloutDetail = Rollout & { machines: RolloutTarget[] };

export type MachineGroupChoice = {
    id: number;
    name: string;
    machineIds: number[];
};

export function rolloutStatus(rollout: Rollout): {
    label: string;
    tone: Tone;
    busy: boolean;
} {
    switch (rollout.state) {
        case 'pending':
            return rollout.scheduledFor
                ? { label: trans('Planned'), tone: 'blue', busy: false }
                : { label: trans('Starting'), tone: 'blue', busy: true };
        case 'running':
            return { label: trans('Running'), tone: 'amber', busy: true };
        case 'paused':
            return { label: trans('Paused'), tone: 'neutral', busy: false };
        case 'done':
            return { label: trans('Done'), tone: 'green', busy: false };
        case 'failed':
            return { label: trans('Failed'), tone: 'red', busy: false };
        default:
            return { label: trans('Cancelled'), tone: 'neutral', busy: false };
    }
}

export function targetStatus(target: RolloutTarget): {
    label: string;
    tone: Tone;
    busy: boolean;
} {
    switch (target.state) {
        case 'pending':
            return { label: trans('Waiting'), tone: 'neutral', busy: false };
        case 'running':
            return target.rebootJobId
                ? { label: trans('Restarting'), tone: 'amber', busy: true }
                : { label: trans('Installing'), tone: 'amber', busy: true };
        case 'done':
            return { label: trans('Done'), tone: 'green', busy: false };
        case 'failed':
            return { label: trans('Failed'), tone: 'red', busy: false };
        default:
            return { label: trans('Skipped'), tone: 'neutral', busy: false };
    }
}

export const toneText: Record<Tone, string> = {
    green: 'text-(--ds-green)',
    red: 'text-(--ds-red)',
    amber: 'text-(--ds-amber)',
    blue: 'text-(--ds-blue)',
    neutral: 'text-(--ds-text-faint)',
};

export const severityColour: Record<Severity, string> = {
    critical: 'var(--dash-down)',
    high: 'var(--dash-pending)',
    medium: 'var(--dash-stopped)',
    low: 'var(--dash-stopped)',
    unknown: 'var(--dash-stopped)',
};

export const severityDot: Record<Severity, string> = {
    critical: 'var(--dash-down)',
    high: 'var(--dash-down)',
    medium: 'var(--dash-pending)',
    low: 'var(--dash-stopped)',
    unknown: 'var(--dash-stopped)',
};

export function severityLabel(severity: Severity): string {
    return {
        critical: trans('Critical'),
        high: trans('High'),
        medium: trans('Medium'),
        low: trans('Low'),
        unknown: trans('Unrated'),
    }[severity];
}

export function shortAgo(iso: string | null | undefined): string {
    if (!iso) return trans('never');
    const minutes = Math.max(
        0,
        Math.round((Date.now() - new Date(iso).getTime()) / 60000),
    );
    if (minutes < 1) return trans('just now');
    if (minutes < 60)
        return trans(':count min ago', { count: String(minutes) });
    const hours = Math.round(minutes / 60);
    if (hours < 24) return trans(':count h ago', { count: String(hours) });

    return trans(':count d ago', { count: String(Math.round(hours / 24)) });
}

export function machineOnline(machine: UpdatesMachine): boolean {
    return ['online', 'degraded', 'demo'].includes(machine.connection);
}

export function rolloutOpen(rollout: Rollout): boolean {
    return ['pending', 'running', 'paused'].includes(rollout.state);
}

function csrf(): string {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}

export async function sendJson<T>(
    method: 'GET' | 'POST' | 'DELETE',
    url: string,
    body?: object,
): Promise<T> {
    const init: RequestInit = {
        method,
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
            Accept: 'application/json',
            ...(body ? { 'Content-Type': 'application/json' } : {}),
            'X-CSRF-TOKEN': csrf(),
        },
    };
    if (body && method !== 'GET') {
        init.body = JSON.stringify(body);
    }
    const response = await failIfBad(await fetch(url, init));

    return (await response.json().catch(() => ({}))) as T;
}
