import { trans } from 'laravel-vue-i18n';
import type { FilledIconName } from '@/components/icons/filledIcons';
import { brandMark } from '@/lib/brandLogos';
import { EVENTS, FROM_TRIGGER, STEPS, environmentName } from './kinds';
import type { Options, WorkflowNode } from './kinds';

export type NodeVisual = {
    icon: FilledIconName;
    logo: string | null;
    logoSrc: string | null;
};

const EVENT_ICONS: Record<string, FilledIconName> = {
    manual: 'user-fill',
    schedule: 'history',
    webhook: 'globe-fill',
    'git.push': 'branch-fill',
    'deploy.finished': 'update-fill',
    'service.down': 'layers-fill',
    'service.crash_loop': 'layers-fill',
    'monitor.down': 'monitors-fill',
    'monitor.up': 'monitors-fill',
    'machine.offline': 'machines-fill',
    'machine.disk_full': 'database-fill',
    'backup.failed': 'archive',
};

const KIND_ICONS: Record<WorkflowNode['kind'], FilledIconName> = {
    trigger: 'monitors-fill',
    redeploy: 'update-fill',
    release: 'update-fill',
    restart: 'layers-fill',
    start: 'layers-fill',
    stop: 'layers-fill',
    command: 'terminal-fill',
    agent: 'sparkle-fill',
    backup: 'archive',
    copy_data: 'database-fill',
    promote: 'update-fill',
    check_monitor: 'globe-fill',
    http: 'globe-fill',
    n8n: 'update-fill',
    alert: 'user-fill',
    wait: 'history',
    branch: 'branch-fill',
};

function machineOf(options: Options, node: WorkflowNode) {
    return options.machines.find(
        (m) => String(m.id) === String(node.rules.machine_id),
    );
}

function serviceOf(options: Options, node: WorkflowNode) {
    const machine = machineOf(options, node);
    const services = machine
        ? machine.services
        : options.machines.flatMap((m) => m.services);

    return services.find((s) => s.key === node.rules.service);
}

function urlHost(url: unknown): string | null {
    try {
        return new URL(String(url)).host;
    } catch {
        return null;
    }
}

const HOOK_BRANDS: [RegExp, string, string][] = [
    [/discord(app)?\.com$/, 'discord', 'Discord'],
    [/slack\.com$/, 'slack', 'Slack'],
    [/telegram\.org$/, 'telegram', 'Telegram'],
];

function hookBrand(url: unknown): [string, string] | null {
    const host = urlHost(url);
    if (!host) return null;
    const match = HOOK_BRANDS.find(([pattern]) => pattern.test(host));

    return match ? [match[1], match[2]] : null;
}

export function nodeVisual(node: WorkflowNode, options: Options): NodeVisual {
    const r = node.rules;
    const base: NodeVisual = {
        icon: KIND_ICONS[node.kind],
        logo: null,
        logoSrc: null,
    };

    switch (node.kind) {
        case 'trigger':
            return {
                ...base,
                icon: EVENT_ICONS[String(r.event ?? 'manual')] ?? base.icon,
                logo: r.event === 'git.push' ? 'github' : null,
            };
        case 'restart':
        case 'start':
        case 'stop':
        case 'redeploy':
        case 'release': {
            const service = serviceOf(options, node);
            const text = `${service?.label ?? service?.name ?? ''} ${service?.image ?? ''}`;
            if (service?.icon)
                return { ...base, logo: text, logoSrc: service.icon };

            return brandMark(text) ? { ...base, logo: text } : base;
        }
        case 'agent':
            return {
                ...base,
                logo: (r.agent ?? 'claude') === 'claude' ? 'claude' : null,
                icon: 'terminal-fill',
            };
        case 'http': {
            const brand = hookBrand(r.url);

            return brand ? { ...base, logo: brand[0] } : base;
        }
        case 'n8n':
            return { ...base, logo: 'n8n' };
    }

    return base;
}

export function nodeSubtitle(node: WorkflowNode, options: Options): string {
    const r = node.rules;
    const machine =
        r.machine_id === FROM_TRIGGER
            ? trans('the trigger’s machine')
            : machineOf(options, node)?.name;
    const service =
        r.service === FROM_TRIGGER
            ? trans('the trigger’s service')
            : (serviceOf(options, node)?.label ??
              serviceOf(options, node)?.name ??
              (r.service ? String(r.service) : null));
    const monitor = options.monitors.find(
        (m) => String(m.id) === String(r.monitor_id),
    );
    const fallback = node.description || STEPS[node.kind].description;

    switch (node.kind) {
        case 'trigger': {
            if (
                (r.event === 'monitor.down' || r.event === 'monitor.up') &&
                monitor
            ) {
                return monitor.target ?? monitor.name;
            }
            if (r.event === 'schedule' && r.every) {
                return r.every === 'hour'
                    ? trans('Every hour')
                    : trans(
                          r.every === 'week'
                              ? 'Every week at :time UTC'
                              : 'Every day at :time UTC',
                          { time: String(r.at ?? '03:00') },
                      );
            }
            if (r.event === 'git.push' && r.repo) {
                return `${r.repo}${r.branch ? ` · ${r.branch}` : ''}`;
            }

            return (
                EVENTS.find((e) => e.id === r.event)?.description ?? fallback
            );
        }
        case 'redeploy':
        case 'release':
        case 'restart':
        case 'start':
        case 'stop':
            return service && machine
                ? trans(':service on :machine', { service, machine })
                : fallback;
        case 'command':
            return r.command ? `$ ${r.command}` : fallback;
        case 'backup':
            return machine ?? fallback;
        case 'copy_data': {
            const into = environmentName(options, r.project, r.environment);

            return into ? trans('Into :name', { name: into }) : fallback;
        }
        case 'promote': {
            const from = environmentName(options, r.project, r.from);
            const to = environmentName(options, r.project, r.to);

            return from && to ? `${from} → ${to}` : fallback;
        }
        case 'check_monitor':
            return trans('for up to :count minutes', {
                count: String(r.timeout ?? 5),
            });
        case 'http': {
            const brand = hookBrand(r.url);
            if (brand) return trans(':brand · Call a URL', { brand: brand[1] });
            const host = urlHost(r.url);

            return host ? `${r.method ?? 'POST'} ${host}` : fallback;
        }
        case 'n8n': {
            const name = r.workflow_name ? String(r.workflow_name) : null;
            if (!name) return fallback;

            return r.wait === 'answer'
                ? trans(':name, waits for its answer', { name })
                : r.wait === 'callback'
                  ? trans(':name, waits for it to call back', { name })
                  : name;
        }
        case 'agent':
            return r.agent === 'codex'
                ? trans('Codex looks into it')
                : r.agent === 'opencode'
                  ? trans('OpenCode looks into it')
                  : trans('Claude Code looks into it');
        case 'alert':
            return r.subject ? String(r.subject) : fallback;
        case 'wait':
            return trans('for :count min', { count: String(r.minutes ?? 2) });
        case 'branch':
            return fallback;
    }

    return fallback;
}
