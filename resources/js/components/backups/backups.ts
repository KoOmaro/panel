import { trans } from 'laravel-vue-i18n';

export type Schedule = 'off' | 'daily' | 'weekly';

export type BackupState =
    | 'queued'
    | 'running'
    | 'succeeded'
    | 'failed'
    | 'skipped';

export type ServiceBackup = {
    id: number;
    serviceName: string;
    state: BackupState;
    trigger: string;
    bytes: number | null;
    error: string | null;
    createdAt: string | null;
    finishedAt: string | null;
    cannotRestore: string | null;
};

export type BackupService = {
    key: string;
    name: string;
    kind: string | null;
    image: string | null;
    iconUrl: string | null;
    last: ServiceBackup | null;
};

export type LastRun = {
    state: 'running' | 'failed' | 'succeeded' | 'skipped';
    at: string | null;
    bytes: number | null;
    failed: string[];
    error: string | null;
};

export type BackupMachine = {
    id: number;
    name: string;
    ip: string;
    provider: string | null;
    connection: string;
    destinationId: number | null;
    schedule: Schedule;
    lastBackup: string | null;
    services: number;
    serviceList: BackupService[];
    lastRun: LastRun | null;
    unavailable: string | null;
};

export type Destination = {
    id: number;
    name: string;
    type: 's3' | 'machine';
    where: string;
    machineId: number | null;
    status: 'ready' | 'setting_up' | 'failed';
    statusMessage: string | null;
    machines: number;
    settings: {
        endpoint: string;
        region: string;
        bucket: string;
        prefix: string;
        pathStyle: boolean;
        accessKey: string;
    } | null;
    provider: string | null;
    stored: number;
    madeBy?: { label: string; provider: string; href: string } | null;
};

export type BackupRow = {
    id: number;
    machine: { id: number; name: string };
    destination: string | null;
    service: string;
    serviceName: string;
    serviceLabel: string;
    trigger: 'manual' | 'scheduled' | 'workflow' | 'pre-restore' | 'pre-deploy';
    state: BackupState;
    bytes: number | null;
    location: string | null;
    error: string | null;
    restore: {
        state: 'queued' | 'running' | 'succeeded' | 'failed';
        error: string | null;
        at: string | null;
    } | null;
    cannotRestore: string | null;
    createdAt: string;
    finishedAt: string | null;
};

export type BackupsSummary = {
    machines: number;
    covered: number;
    services: number;
    stored: number;
    lastRunAt: string | null;
    succeededRate: number | null;
    restores: number;
    day: {
        done: number;
        failed: number;
        running: number;
        total: number;
        shown: {
            id: number;
            service: string;
            machine: string;
            state: BackupState;
            bytes: number | null;
            at: string | null;
        }[];
    };
};

export type Restorable = {
    id: number;
    serviceName: string;
    label: string;
    machine: string;
    createdAt: string;
    undo: boolean;
};

export function size(value: number | null | undefined): string {
    if (value === null || value === undefined) return '–';
    const units: [number, string][] = [
        [1e12, 'TB'],
        [1e9, 'GB'],
        [1e6, 'MB'],
        [1e3, 'KB'],
    ];
    for (const [unit, label] of units) {
        if (value >= unit) {
            const n = value / unit;

            return `${n < 10 ? n.toFixed(1).replace(/\.0$/, '') : Math.round(n)} ${label}`;
        }
    }

    return `${value} B`;
}

export function sizeParts(value: number): { value: string; unit: string } {
    const [n, unit] = size(value).split(' ');

    return { value: n, unit: unit ?? '' };
}

export function when(iso: string | null | undefined, lower = false): string {
    if (!iso) return lower ? trans('never') : trans('Never');
    const date = new Date(iso);
    const time = date.toLocaleTimeString(undefined, {
        hour: '2-digit',
        minute: '2-digit',
    });
    const day = new Date(date);
    day.setHours(0, 0, 0, 0);
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const days = Math.round((today.getTime() - day.getTime()) / 86400000);
    if (days === 0)
        return lower
            ? trans('today, :time', { time })
            : trans('Today, :time', { time });
    if (days === 1)
        return lower
            ? trans('yesterday, :time', { time })
            : trans('Yesterday, :time', { time });

    return `${date.toLocaleDateString(undefined, { day: 'numeric', month: 'short' })}, ${time}`;
}

export function startedAgo(iso: string | null | undefined): string {
    if (!iso) return '';
    const minutes = Math.max(
        1,
        Math.round((Date.now() - new Date(iso).getTime()) / 60000),
    );

    return minutes < 60
        ? trans('Started :count min ago', { count: String(minutes) })
        : trans('Started :when', { when: when(iso) });
}

export function destinationKind(destination: Destination): {
    logo: string | null;
    label: string;
} {
    if (destination.type === 'machine') {
        return { logo: destination.provider, label: trans('Over SFTP') };
    }
    const endpoint = destination.settings?.endpoint ?? '';
    const known: [RegExp, string, string][] = [
        [/r2\.cloudflarestorage/, 'cloudflare', 'Cloudflare R2'],
        [/backblazeb2/, 'backblaze', 'Backblaze B2'],
        [/amazonaws/, 'aws', 'Amazon S3'],
        [/digitaloceanspaces/, 'digitalocean', 'DigitalOcean Spaces'],
        [/hetzner|your-objectstorage/, 'hetzner', 'Hetzner'],
        [/wasabisys/, 'wasabi', 'Wasabi'],
        [/vultrobjects/, 'vultr', 'Vultr'],
        [/linodeobjects/, 'linode', 'Linode'],
        [/minio/, 'minio', 'MinIO'],
    ];
    const match = known.find(([pattern]) => pattern.test(endpoint));

    return match
        ? { logo: match[1], label: match[2] }
        : { logo: null, label: trans('S3 bucket') };
}

export function serviceLogo(service: BackupService): string {
    return `${service.name} ${service.image ?? ''}`.trim();
}

export function serviceKind(kind: string | null): string {
    switch (kind) {
        case 'database':
            return trans('Database');
        case 'proxy':
            return trans('Proxy');
        case 'container':
            return trans('Container');
        default:
            return trans('Service');
    }
}

export async function post(
    url: string,
    body?: object,
): Promise<{ ok: boolean; data: Record<string, unknown> }> {
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            ...(body ? { 'Content-Type': 'application/json' } : {}),
            'X-CSRF-TOKEN':
                document.querySelector<HTMLMetaElement>(
                    'meta[name="csrf-token"]',
                )?.content ?? '',
        },
        body: body ? JSON.stringify(body) : undefined,
    });
    const data = (await response.json().catch(() => ({}))) as Record<
        string,
        unknown
    >;

    return { ok: response.ok, data };
}

export function firstError(
    data: Record<string, unknown>,
    fallback: string,
): string {
    const errors = data.errors as Record<string, string[]> | undefined;
    const first = errors ? Object.values(errors)[0]?.[0] : undefined;

    return (
        first ??
        (typeof data.error === 'string' ? data.error : null) ??
        (typeof data.message === 'string' ? data.message : null) ??
        fallback
    );
}
