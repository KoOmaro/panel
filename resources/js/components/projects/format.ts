import { trans } from 'laravel-vue-i18n';
import { timeUnits } from '@/lib/formatting';
import type {
    DashStatus,
    NodeKind,
    ProjectNode,
    Software,
    StackItem,
} from './types';

const shortNames: Record<string, string> = {
    postgres: 'Postgres',
    postgis: 'PostGIS',
    pgvector: 'Postgres',
    timescaledb: 'TimescaleDB',
    mysql: 'MySQL',
    mariadb: 'MariaDB',
    mongodb: 'MongoDB',
    redis: 'Redis',
    valkey: 'Valkey',
    minio: 'MinIO',
    soketi: 'Soketi',
    clickhouse: 'ClickHouse',
};

export const kindLabels: Record<NodeKind, string> = {
    app: 'App',
    database: 'Database',
    cache: 'Cache',
    storage: 'Storage',
    websockets: 'WebSockets',
};

export const statusColours: Record<DashStatus, string> = {
    live: 'var(--dash-live)',
    deploying: 'var(--dash-deploying)',
    pending: 'var(--dash-pending)',
    down: 'var(--dash-down)',
    stopped: 'var(--dash-stopped)',
};

export function softwareName(
    software: Software | null,
    template: string | null = null,
): string | null {
    const slug = software?.slug ?? template;
    if (slug && shortNames[slug]) return shortNames[slug];

    return software?.name ?? template;
}

export function stackLabel(item: StackItem): string {
    return (
        (item.template ? shortNames[item.template] : null) ??
        item.label ??
        item.template ??
        item.name
    );
}

export function logoName(
    software: Software | null,
    template: string | null,
    image: string | null,
    fallback = '',
): string {
    const text = [software?.slug ?? template, software?.name, image]
        .filter(Boolean)
        .join(' ')
        .trim();

    return text || fallback;
}

export function nodeLogo(node: ProjectNode): string {
    return logoName(node.software, node.template, node.image, node.name);
}

export function version(image: string | null): string | null {
    if (!image || image.startsWith('serveros/')) return null;
    const tag = image.split(':')[1] ?? '';
    const match = tag.match(/^v?(\d+(?:\.\d+)?)(?:[.-]|$)/);

    return match ? match[1] : null;
}

export function nodeSoftware(node: ProjectNode): string {
    const name = softwareName(node.software, node.template);
    if (!name) return trans(kindLabels[node.kind]);
    const v = node.kind === 'app' ? null : version(node.image);

    return v ? `${name} ${v}` : name;
}

export function listSentence(items: string[]): string {
    if (items.length <= 1) return items[0] ?? '';

    return trans(':items and :last', {
        items: items.slice(0, -1).join(', '),
        last: items[items.length - 1],
    });
}

export function ago(iso: string | null): string {
    if (!iso) return '';
    const ms = Date.now() - new Date(iso).getTime();
    const { minutes, hours, days } = timeUnits(ms);
    if (minutes < 1) return trans('Just now');
    if (minutes < 60)
        return trans(':count min ago', { count: String(minutes) });
    if (hours < 24) return trans(':counth ago', { count: String(hours) });
    if (days < 2) return trans('Yesterday');
    if (days < 7) return trans(':countd ago', { count: String(days) });
    if (days < 60)
        return trans(':countw ago', { count: String(Math.floor(days / 7)) });

    return new Date(iso).toLocaleDateString(undefined, {
        day: 'numeric',
        month: 'short',
    });
}

export const linkSettings: Record<string, string> = {
    postgres: 'DATABASE_URL',
    mysql: 'DATABASE_URL',
    mariadb: 'DATABASE_URL',
    mongodb: 'MONGODB_URI',
    redis: 'REDIS_URL',
    valkey: 'REDIS_URL',
    minio: 'AWS_ENDPOINT',
    soketi: 'PUSHER_HOST',
};
