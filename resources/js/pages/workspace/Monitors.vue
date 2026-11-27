<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { CirclePause, CircleX, Ellipsis, Lock, Plus } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed, onBeforeUnmount, onMounted, ref, useTemplateRef } from 'vue';
import BrandLogo from '@/components/dash/BrandLogo.vue';
import DashButton from '@/components/dash/DashButton.vue';
import DashCheckbox from '@/components/dash/DashCheckbox.vue';
import DashHeader from '@/components/dash/DashHeader.vue';
import DashTable from '@/components/dash/DashTable.vue';
import DashTd from '@/components/dash/DashTd.vue';
import DashTh from '@/components/dash/DashTh.vue';
import DashTr from '@/components/dash/DashTr.vue';
import DashTray from '@/components/dash/DashTray.vue';
import FilterPills from '@/components/dash/FilterPills.vue';
import GaugeDial from '@/components/dash/GaugeDial.vue';
import SearchWell from '@/components/dash/SearchWell.vue';
import StatusBadge from '@/components/dash/StatusBadge.vue';
import type { DashStatus } from '@/components/dash/StatusBadge.vue';
import ToolbarButton from '@/components/dash/ToolbarButton.vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import AlertsDialog from '@/components/monitors/AlertsDialog.vue';
import MonitorDialog from '@/components/monitors/MonitorDialog.vue';
import MonitorTabs from '@/components/monitors/MonitorTabs.vue';
import {
    address,
    byUrgency,
    dayBar,
    daysUntil,
    every,
    host,
    lasting,
    ms,
    percent,
    relative,
    shortError,
    uptimeTone,
} from '@/components/monitors/monitors';
import type {
    MachineOption,
    MonitorRow,
    MonitorStatus,
} from '@/components/monitors/monitors';
import IncidentDialog from '@/components/status/IncidentDialog.vue';
import type { Incident } from '@/components/status/IncidentDialog.vue';
import CardLine from '@/components/summary/CardLine.vue';
import StatGrid from '@/components/summary/StatGrid.vue';
import type { Stat } from '@/components/summary/StatGrid.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useSelfHosted } from '@/composables/useSelfHosted';
import { brandMark } from '@/lib/brandLogos';
import { onWorkspace, workspacePath } from '@/lib/workspaceRoute';
import monitorsRoutes from '@/routes/workspace/monitors';
import settingsRoutes from '@/routes/workspace/settings';
import type { Workspace, WorkspaceAccess } from '@/types';

const props = defineProps<{
    workspace: Workspace;
    monitors: MonitorRow[];
    uptime: number | null;
    machines: MachineOption[];
    limit: number;
    minInterval: number;
    alerts: {
        email: boolean;
        webhook: string | null;
        webhookSet: boolean;
        telegram: { chat: string | null; set: boolean };
        muted: string[];
    };
    incidents: Incident[];
    statusPage: {
        available: boolean;
        set: boolean;
        live: boolean;
        url: string | null;
        shown: number;
    };
}>();

const selfHosted = useSelfHosted();
const page = usePage<{ workspaceAccess: WorkspaceAccess | null }>();
const canManage = computed(
    () =>
        page.props.workspaceAccess?.permissions.includes('manage-machines') ??
        false,
);

const tab = ref<'monitors' | 'incidents'>('monitors');
type Filter = 'all' | MonitorStatus;
const filter = ref<Filter>('all');
const search = ref('');
const machineFilter = ref<number | null>(null);
const picked = ref<Record<number, boolean>>({});
const creating = ref(false);
const editing = ref<MonitorRow | null>(null);
const editOpen = ref(false);
const removing = ref<MonitorRow | null>(null);
const alertsOpen = ref(false);
const incidentOpen = ref(false);
const incidentShown = ref<Incident | null>(null);
const bulkBusy = ref(false);
const now = ref(Date.now());
const searchWell =
    useTemplateRef<InstanceType<typeof SearchWell>>('searchWell');

const full = computed(() => props.monitors.length >= props.limit);
const counts = computed(() => ({
    all: props.monitors.length,
    up: props.monitors.filter((m) => m.status === 'up').length,
    down: props.monitors.filter((m) => m.status === 'down').length,
    paused: props.monitors.filter((m) => m.status === 'paused').length,
    pending: props.monitors.filter((m) => m.status === 'pending').length,
}));

const pills = computed(() =>
    (
        [
            { value: 'all', label: trans('All'), count: counts.value.all },
            { value: 'down', label: trans('Down'), count: counts.value.down },
            { value: 'up', label: trans('Up'), count: counts.value.up },
            {
                value: 'paused',
                label: trans('Paused'),
                count: counts.value.paused,
            },
            {
                value: 'pending',
                label: trans('Checking'),
                count: counts.value.pending,
            },
        ] as { value: Filter; label: string; count: number }[]
    ).filter((pill) => pill.value === 'all' || pill.count > 0),
);

const monitorMachines = computed(() => {
    const seen = new Map<number, NonNullable<MonitorRow['machine']>>();
    for (const monitor of props.monitors) {
        if (monitor.machine) seen.set(monitor.machine.id, monitor.machine);
    }

    return [...seen.values()].sort((a, b) => a.name.localeCompare(b.name));
});

const machineLabel = computed(
    () =>
        monitorMachines.value.find((m) => m.id === machineFilter.value)?.name ??
        trans('All machines'),
);

const shown = computed(() => {
    const needle = search.value.trim().toLowerCase();

    return props.monitors
        .filter(
            (m) =>
                (filter.value === 'all' || m.status === filter.value) &&
                (machineFilter.value === null ||
                    m.machine?.id === machineFilter.value) &&
                (!needle ||
                    `${m.name} ${m.target} ${m.machine?.name ?? ''}`
                        .toLowerCase()
                        .includes(needle)),
        )
        .sort(byUrgency);
});

const selected = computed(() =>
    props.monitors.filter((m) => picked.value[m.id]),
);
const allPicked = computed({
    get: () =>
        shown.value.length > 0 && shown.value.every((m) => picked.value[m.id]),
    set: (on: boolean) => {
        const next = { ...picked.value };
        for (const m of shown.value) next[m.id] = on;
        picked.value = next;
    },
});
const somePicked = computed(
    () => selected.value.length > 0 && !allPicked.value,
);

const responseAverage = computed(() => {
    const values = props.monitors
        .filter((m) => m.status === 'up' && m.responseMs !== null)
        .map((m) => m.responseMs as number);

    return values.length
        ? Math.round(values.reduce((a, b) => a + b, 0) / values.length)
        : null;
});

const openIncidents = computed(
    () => props.incidents.filter((i) => !i.resolvedAt).length,
);

const stats = computed<[Stat, Stat, Stat, Stat]>(() => [
    { label: trans('Monitors'), value: counts.value.all },
    {
        label: trans('Down'),
        value: counts.value.down,
        unit: counts.value.down ? trans('now') : undefined,
        tone: counts.value.down ? 'text-status-down' : undefined,
    },
    { label: trans('Paused'), value: counts.value.paused },
    { label: trans('Incidents'), value: openIncidents.value },
]);

type Look = {
    key: string;
    kind: 'down' | 'cert' | 'paused';
    title: string;
    line: string;
    faint: string | null;
    monitor: MonitorRow;
};

const needsLook = computed<Look[]>(() => {
    const out: Look[] = [];
    for (const m of props.monitors.filter((m) => m.status === 'down')) {
        const error = m.error?.replace(/\.$/, '') ?? null;
        out.push({
            key: `down-${m.id}`,
            kind: 'down',
            title: m.name,
            line: m.since
                ? trans('Down for :time,', {
                      time: lasting(m.since, now.value),
                  })
                : trans('Down'),
            faint: error
                ? error.charAt(0).toLowerCase() + error.slice(1)
                : null,
            monitor: m,
        });
    }
    for (const m of props.monitors) {
        const days = daysUntil(m.sslExpiresAt, now.value);
        if (m.status === 'paused' || days === null || days > 14) continue;
        out.push({
            key: `cert-${m.id}`,
            kind: 'cert',
            title: host(m),
            line:
                days < 0
                    ? trans('Certificate expired')
                    : trans('Certificate expires'),
            faint:
                days < 0
                    ? null
                    : days === 0
                      ? trans('today')
                      : trans(days === 1 ? 'in :days day' : 'in :days days', {
                            days: String(days),
                        }),
            monitor: m,
        });
    }
    for (const m of props.monitors.filter((m) => m.status === 'paused')) {
        const who = m.pausedBy?.name?.split(' ')[0];
        out.push({
            key: `paused-${m.id}`,
            kind: 'paused',
            title: m.name,
            line: who
                ? trans('Paused by :name', { name: who })
                : trans('Paused'),
            faint: m.pausedBy ? relative(m.pausedBy.at, now.value) : null,
            monitor: m,
        });
    }

    return out;
});

const statusPageAddress = computed(() =>
    props.statusPage.url?.replace(/^https?:\/\//, '').replace(/\/$/, ''),
);
const statusPageHref = computed(
    () => onWorkspace(settingsRoutes.statusPage(props.workspace.slug)).url,
);
const recentIncidents = computed(() =>
    [...props.incidents]
        .sort(
            (a, b) =>
                Number(a.resolvedAt !== null) - Number(b.resolvedAt !== null) ||
                b.startedAt.localeCompare(a.startedAt),
        )
        .slice(0, 4),
);

const alertChannels = computed(() =>
    [
        props.alerts.email ? trans('Email') : null,
        props.alerts.webhookSet ? trans('Webhook') : null,
        props.alerts.telegram?.set ? trans('Telegram') : null,
    ]
        .filter(Boolean)
        .join(', '),
);

const planLine = computed(() =>
    selfHosted.value
        ? trans(':length of :limit monitors on this install.', {
              length: String(props.monitors.length),
              limit: String(props.limit),
          })
        : trans(':length of :limit monitors on your plan.', {
              length: String(props.monitors.length),
              limit: String(props.limit),
          }),
);

const fullTitle = computed(() =>
    full.value
        ? selfHosted.value
            ? trans('This install allows :limit monitors', {
                  limit: String(props.limit),
              })
            : trans('Your plan includes :limit monitors', {
                  limit: String(props.limit),
              })
        : undefined,
);

function badge(m: MonitorRow): { status: DashStatus; label: string } {
    switch (m.status) {
        case 'up':
            return { status: 'live', label: trans('Up') };
        case 'down':
            return { status: 'down', label: trans('Down') };
        case 'paused':
            return { status: 'stopped', label: trans('Paused') };
        default:
            return { status: 'pending', label: trans('Checking') };
    }
}

function statusDetail(m: MonitorRow): string {
    if (m.status === 'down') {
        const error = shortError(m.error);

        return [m.since ? lasting(m.since, now.value) : null, error]
            .filter(Boolean)
            .join(', ');
    }
    if (m.status === 'pending')
        return m.error ? (shortError(m.error) ?? '') : '';

    return m.since ? lasting(m.since, now.value) : '';
}

function hasLogo(m: MonitorRow): boolean {
    return (
        Boolean(m.service?.icon) ||
        brandMark(
            `${m.name} ${m.service?.name ?? ''} ${m.service?.image ?? ''}`,
        ) !== null
    );
}

function showHref(m: MonitorRow): string {
    return onWorkspace(
        monitorsRoutes.show({ workspace: props.workspace.slug, monitor: m.id }),
    ).url;
}

function open(m: MonitorRow): void {
    router.visit(showHref(m));
}

function checkNow(m: MonitorRow, after?: () => void): void {
    router.post(
        workspacePath(`/monitors/${m.id}/check`),
        {},
        { preserveScroll: true, onFinish: () => after?.() },
    );
}

function setPaused(m: MonitorRow, paused: boolean, after?: () => void): void {
    router.patch(
        workspacePath(`/monitors/${m.id}/pause`),
        { paused },
        { preserveScroll: true, onFinish: () => after?.() },
    );
}

function mute(m: MonitorRow, minutes: number): void {
    router.post(
        workspacePath(`/monitors/${m.id}/mute`),
        { minutes },
        { preserveScroll: true },
    );
}

function unmute(m: MonitorRow): void {
    router.delete(workspacePath(`/monitors/${m.id}/mute`), {
        preserveScroll: true,
    });
}

function edit(m: MonitorRow): void {
    editing.value = m;
    editOpen.value = true;
}

function remove(): void {
    const m = removing.value;
    if (!m) return;
    router.delete(workspacePath(`/monitors/${m.id}`), {
        preserveScroll: true,
        onFinish: () => (removing.value = null),
    });
}

function bulk(action: 'check' | 'pause' | 'resume'): void {
    const queue = [...selected.value];
    bulkBusy.value = true;
    const next = (): void => {
        const m = queue.shift();
        if (!m) {
            bulkBusy.value = false;
            picked.value = {};

            return;
        }
        if (action === 'check') checkNow(m, next);
        else setPaused(m, action === 'pause', next);
    };
    next();
}

function showIncident(incident: Incident | null): void {
    incidentShown.value = incident;
    incidentOpen.value = true;
}

function selectTab(next: 'monitors' | 'incidents'): void {
    tab.value = next;
    const url = new URL(window.location.href);
    if (next === 'incidents') url.searchParams.set('tab', 'incidents');
    else url.searchParams.delete('tab');
    window.history.replaceState(window.history.state, '', url);
}

const impactDot: Record<Incident['impact'], string> = {
    degraded: 'bg-status-pending',
    partial: 'bg-status-pending',
    major: 'bg-status-down',
};
const incidentStates: Record<Incident['state'], string> = {
    investigating: trans('Investigating'),
    identified: trans('Identified'),
    monitoring: trans('Monitoring'),
    resolved: trans('Resolved'),
};

let tick: ReturnType<typeof setInterval> | undefined;
let reload: ReturnType<typeof setInterval> | undefined;

function onKey(event: KeyboardEvent): void {
    const target = event.target as HTMLElement | null;
    if (
        event.key === '/' &&
        !['INPUT', 'TEXTAREA', 'SELECT'].includes(target?.tagName ?? '') &&
        !target?.isContentEditable
    ) {
        event.preventDefault();
        searchWell.value?.focus();
    }
}

onMounted(() => {
    const params = new URLSearchParams(window.location.search);
    if (params.get('tab') === 'incidents') tab.value = 'incidents';
    if (params.has('new') && canManage.value && !full.value) {
        creating.value = true;
    }
    tick = setInterval(() => (now.value = Date.now()), 5000);
    reload = setInterval(() => {
        if (
            document.visibilityState === 'visible' &&
            !creating.value &&
            !editOpen.value &&
            !bulkBusy.value
        ) {
            router.reload({ only: ['monitors', 'uptime', 'incidents'] });
        }
    }, 15000);
    window.addEventListener('keydown', onKey);
});

onBeforeUnmount(() => {
    clearInterval(tick);
    clearInterval(reload);
    window.removeEventListener('keydown', onKey);
});
</script>

<template>
    <Head :title="$t('Monitors')" />

    <DashHeader :crumbs="[{ label: $t('Monitors') }]">
        <template v-if="canManage" #actions>
            <DashButton
                :label="$t('Alerts')"
                :title="
                    alertChannels
                        ? $t('Alerts go to :channels', {
                              channels: alertChannels,
                          })
                        : $t('Alerts are off')
                "
                @click="alertsOpen = true"
            />
            <DashButton
                variant="primary"
                :icon="Plus"
                :label="$t('New monitor')"
                :disabled="full || limit === 0"
                :title="fullTitle"
                @click="creating = true"
            />
        </template>
    </DashHeader>

    <div class="flex min-h-full flex-col">
        <MonitorTabs :active="tab" local @select="selectTab" />

        <template v-if="tab === 'monitors'">
            <div
                v-if="monitors.length === 0"
                class="flex flex-1 flex-col items-center justify-center px-6 py-20 text-center"
            >
                <span
                    class="flex size-10 items-center justify-center rounded-[10px] border-[0.5px] border-edge-control bg-surface-raised text-fg-tertiary shadow-tile"
                    aria-hidden="true"
                >
                    <FilledIcon name="monitors-fill" :size="20" />
                </span>
                <h2
                    class="mt-4 text-[15px]/6 font-medium tracking-[-0.01em] text-fg-primary"
                >
                    {{ $t('No monitors yet') }}
                </h2>
                <p class="mt-1 max-w-sm text-[13px]/5 text-fg-tertiary">
                    {{
                        $t(
                            'Add a URL or a port. ServerOS checks it every :value and emails you, or posts to Slack, when it stops answering.',
                            { value: every(Math.max(60, minInterval)) },
                        )
                    }}
                </p>
                <DashButton
                    v-if="canManage && limit > 0"
                    class="mt-5"
                    variant="primary"
                    :icon="Plus"
                    :label="$t('New monitor')"
                    @click="creating = true"
                />
                <p
                    v-else-if="limit === 0"
                    class="mt-4 text-[13px]/5 text-fg-muted"
                >
                    {{ $t('Monitors come with every plan.') }}
                </p>
            </div>

            <template v-else>
                <div
                    class="grid grid-cols-1 gap-3 px-5 pt-3.5 pb-1 md:grid-cols-2 xl:grid-cols-[480px_minmax(0,1fr)_minmax(0,1fr)] xl:[&>*]:min-h-[214px]"
                >
                    <DashTray
                        icon="monitors-fill"
                        :title="$t('Uptime')"
                        class="md:col-span-2 xl:col-span-1"
                        panel-class="px-[18px] py-3"
                    >
                        <template #meta>
                            <span class="text-fg-tertiary">{{
                                $t('last 30 days')
                            }}</span>
                        </template>
                        <div class="flex items-center gap-[18px]">
                            <GaugeDial
                                :value="uptime ?? 0"
                                :readout="percent(uptime)"
                                :label="$t('Uptime')"
                                :tone="
                                    uptime === null || uptime >= 99.5
                                        ? 'green'
                                        : uptime >= 95
                                          ? 'amber'
                                          : 'red'
                                "
                                class="shrink-0"
                            />
                            <GaugeDial
                                :value="
                                    Math.min(
                                        100,
                                        ((responseAverage ?? 0) / 600) * 100,
                                    )
                                "
                                :readout="ms(responseAverage)"
                                :label="$t('Response')"
                                tone="sky"
                                class="shrink-0 max-sm:hidden"
                            />
                            <StatGrid :stats="stats" />
                        </div>
                    </DashTray>

                    <DashTray
                        icon="info-fill"
                        :title="$t('Needs a look')"
                        panel-class="gap-2.5 px-[18px] py-3"
                    >
                        <template v-if="needsLook.length" #meta>
                            <span class="text-fg-tertiary">{{
                                needsLook.length === 1
                                    ? $t('1 monitor')
                                    : $t(':count monitors', {
                                          count: String(needsLook.length),
                                      })
                            }}</span>
                        </template>
                        <p
                            v-if="needsLook.length === 0"
                            class="flex items-center gap-2.5 text-[13px]/5 text-fg-tertiary"
                        >
                            <FilledIcon name="check" class="text-status-live" />
                            {{ $t('Nothing needs a look.') }}
                        </p>
                        <a
                            v-for="item in needsLook.slice(0, 3)"
                            :key="item.key"
                            :href="showHref(item.monitor)"
                            class="-mx-1.5 flex items-start gap-3 rounded-[6px] px-1.5 text-[13px] hover:bg-surface-hover"
                            @click.prevent="open(item.monitor)"
                        >
                            <span class="flex pt-px">
                                <CircleX
                                    v-if="item.kind === 'down'"
                                    class="size-4 text-status-down"
                                    :stroke-width="2"
                                />
                                <Lock
                                    v-else-if="item.kind === 'cert'"
                                    class="size-4 text-status-pending"
                                    :stroke-width="2"
                                />
                                <CirclePause
                                    v-else
                                    class="size-4 text-status-stopped"
                                    :stroke-width="2"
                                />
                            </span>
                            <span class="flex min-w-0 flex-col">
                                <span
                                    class="truncate leading-[18px] font-medium tracking-[-0.01em] text-fg-primary"
                                    >{{ item.title }}</span
                                >
                                <span
                                    class="flex gap-1 leading-5 tracking-[-0.005em] whitespace-nowrap"
                                >
                                    <span class="text-fg-secondary">{{
                                        item.line
                                    }}</span>
                                    <span
                                        v-if="item.faint"
                                        class="truncate text-fg-faint"
                                        >{{ item.faint }}</span
                                    >
                                </span>
                            </span>
                        </a>
                    </DashTray>

                    <DashTray
                        icon="monitors-fill"
                        :title="$t('Status page')"
                        panel-class="gap-2.5 px-[18px] py-3"
                    >
                        <template
                            v-if="statusPage.url && statusPage.live"
                            #meta
                        >
                            <a
                                :href="statusPage.url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="text-fg-tertiary hover:text-fg-secondary"
                                >{{ statusPageAddress }}</a
                            >
                        </template>
                        <button
                            v-for="incident in recentIncidents"
                            :key="incident.id"
                            type="button"
                            class="-mx-1.5 cursor-pointer rounded-[6px] px-1.5 text-left hover:bg-surface-hover"
                            @click="showIncident(incident)"
                        >
                            <CardLine
                                as="span"
                                :state="
                                    incident.resolvedAt ? 'done' : 'running'
                                "
                                :label="incident.title"
                                :value="
                                    incident.resolvedAt ? $t('resolved') : null
                                "
                            />
                        </button>
                        <p
                            v-if="!statusPage.set"
                            class="text-[13px]/5 text-fg-tertiary"
                        >
                            {{
                                $t(
                                    'One public page for your machines and these monitors.',
                                )
                            }}
                        </p>
                        <a
                            :href="statusPageHref"
                            class="-mx-1.5 rounded-[6px] px-1.5 hover:bg-surface-hover"
                            @click.prevent="router.visit(statusPageHref)"
                        >
                            <CardLine
                                as="span"
                                state="waiting"
                                :label="
                                    statusPage.set
                                        ? statusPage.shown === 1
                                            ? $t('1 monitor shown')
                                            : $t(':count monitors shown', {
                                                  count: String(
                                                      statusPage.shown,
                                                  ),
                                              })
                                        : $t('No status page yet')
                                "
                                :value="
                                    statusPage.live ? $t('live') : $t('off')
                                "
                            />
                        </a>
                    </DashTray>
                </div>

                <div
                    class="flex min-h-14 flex-wrap items-center gap-2 px-5 py-3"
                >
                    <SearchWell
                        ref="searchWell"
                        v-model="search"
                        :placeholder="$t('Search monitors')"
                        :width="300"
                        class="max-sm:w-full!"
                    />
                    <FilterPills
                        v-model="filter"
                        :label="$t('Status')"
                        :options="pills"
                    />
                    <DropdownMenu v-if="monitorMachines.length > 1">
                        <DropdownMenuTrigger as-child>
                            <ToolbarButton
                                icon="filter-fill"
                                :label="machineLabel"
                                :active="machineFilter !== null"
                            />
                        </DropdownMenuTrigger>
                        <DropdownMenuContent
                            class="max-h-80 min-w-44 overflow-y-auto font-ui"
                            align="start"
                        >
                            <DropdownMenuItem @select="machineFilter = null">
                                {{ $t('All machines') }}
                            </DropdownMenuItem>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                                v-for="machine in monitorMachines"
                                :key="machine.id"
                                @select="machineFilter = machine.id"
                            >
                                <BrandLogo :name="machine.provider" />
                                {{ machine.name }}
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                    <span class="min-w-0 flex-1" />
                    <template v-if="selected.length && canManage">
                        <span class="text-[12px]/4 text-fg-muted">{{
                            $t(':count selected', {
                                count: String(selected.length),
                            })
                        }}</span>
                        <DashButton
                            size="sm"
                            :label="$t('Check now')"
                            :loading="bulkBusy"
                            @click="bulk('check')"
                        />
                        <DashButton
                            size="sm"
                            :label="
                                selected.every((m) => m.paused)
                                    ? $t('Resume')
                                    : $t('Pause')
                            "
                            :disabled="bulkBusy"
                            @click="
                                bulk(
                                    selected.every((m) => m.paused)
                                        ? 'resume'
                                        : 'pause',
                                )
                            "
                        />
                    </template>
                    <span v-else class="text-[12px]/4 text-fg-muted">{{
                        planLine
                    }}</span>
                </div>

                <DashTable class="border-t-[0.5px] border-edge-tabs">
                    <template #head>
                        <DashTh icon="monitors-fill" :width="262" first>
                            <template v-if="canManage" #lead>
                                <DashCheckbox
                                    v-model="allPicked"
                                    :indeterminate="somePicked"
                                    :label="$t('Select all')"
                                />
                            </template>
                            {{ $t('Monitor') }}
                        </DashTh>
                        <DashTh icon="activity-fill" :width="176">{{
                            $t('Status')
                        }}</DashTh>
                        <DashTh icon="table-fill" :width="212">{{
                            $t('Last 30 days')
                        }}</DashTh>
                        <DashTh icon="setup-fill" :width="94">{{
                            $t('Uptime')
                        }}</DashTh>
                        <DashTh icon="sort-fill" :width="110">{{
                            $t('Response')
                        }}</DashTh>
                        <DashTh icon="machines-fill" :width="128">{{
                            $t('Machine')
                        }}</DashTh>
                        <DashTh icon="activity-fill" fill>{{
                            $t('Checked')
                        }}</DashTh>
                    </template>

                    <DashTr
                        v-for="monitor in shown"
                        :key="monitor.id"
                        clickable
                        :selected="picked[monitor.id]"
                        @click="open(monitor)"
                    >
                        <DashTd first>
                            <template v-if="canManage" #lead>
                                <span @click.stop>
                                    <DashCheckbox
                                        v-model="picked[monitor.id]"
                                        :label="
                                            $t('Select :name', {
                                                name: monitor.name,
                                            })
                                        "
                                    />
                                </span>
                            </template>
                            <span
                                class="flex max-w-[200px] min-w-0 items-center gap-2"
                            >
                                <BrandLogo
                                    v-if="hasLogo(monitor)"
                                    :name="`${monitor.name} ${monitor.service?.name ?? ''} ${monitor.service?.image ?? ''}`"
                                    :src="monitor.service?.icon"
                                />
                                <FilledIcon
                                    v-else
                                    name="globe-fill"
                                    class="text-fg-muted"
                                />
                                <a
                                    :href="showHref(monitor)"
                                    class="shrink-0 font-medium tracking-[-0.01em] text-fg-primary"
                                    @click.prevent
                                    >{{ monitor.name }}</a
                                >
                                <span
                                    class="truncate font-ui-mono text-[11.5px]/4 text-fg-faint"
                                    >{{ address(monitor) }}</span
                                >
                            </span>
                        </DashTd>
                        <DashTd>
                            <StatusBadge v-bind="badge(monitor)" />
                            <span
                                class="max-w-[72px] truncate text-[12px]/4 tracking-[-0.005em]"
                                :class="
                                    monitor.status === 'down'
                                        ? 'text-status-down'
                                        : 'text-fg-tertiary'
                                "
                                >{{ statusDetail(monitor) }}</span
                            >
                        </DashTd>
                        <DashTd>
                            <span
                                class="flex items-center gap-0.5"
                                role="img"
                                :aria-label="
                                    $t(':value over 30 days', {
                                        value: percent(monitor.uptime30),
                                    })
                                "
                            >
                                <span
                                    v-for="(day, i) in monitor.days"
                                    :key="i"
                                    class="h-[18px] w-1 shrink-0 rounded-[1.5px]"
                                    :class="dayBar(day)"
                                />
                            </span>
                        </DashTd>
                        <DashTd>
                            <span
                                class="font-ui-mono text-[12px]/4"
                                :class="uptimeTone(monitor.uptime30)"
                                >{{ percent(monitor.uptime30) }}</span
                            >
                        </DashTd>
                        <DashTd>
                            <span
                                v-if="
                                    monitor.status === 'up' &&
                                    monitor.responseMs !== null
                                "
                                class="font-ui-mono text-[12px]/4 text-fg-secondary"
                                >{{ ms(monitor.responseMs) }}</span
                            >
                            <span
                                v-else
                                class="text-[12px]/4 font-medium text-fg-faint"
                                >–</span
                            >
                        </DashTd>
                        <DashTd>
                            <template v-if="monitor.machine">
                                <BrandLogo :name="monitor.machine.provider" />
                                <span
                                    class="truncate font-medium tracking-[-0.01em]"
                                    >{{ monitor.machine.name }}</span
                                >
                            </template>
                            <span v-else class="font-medium text-fg-faint"
                                >–</span
                            >
                        </DashTd>
                        <DashTd>
                            <span
                                class="text-[12px]/4 tracking-[-0.005em] text-fg-tertiary"
                            >
                                {{
                                    $t('every :value', {
                                        value: every(monitor.interval),
                                    })
                                }}
                            </span>
                            <span
                                v-if="monitor.mutedUntil"
                                class="text-[12px]/4 text-fg-faint"
                                >{{ $t('muted') }}</span
                            >
                            <span class="min-w-0 flex-1" />
                            <DropdownMenu v-if="canManage">
                                <DropdownMenuTrigger as-child>
                                    <button
                                        type="button"
                                        class="flex size-6 shrink-0 cursor-pointer items-center justify-center rounded-[6px] text-fg-muted hover:bg-surface-raised hover:text-fg-primary focus-visible:outline-2 focus-visible:outline-(--ds-ring) data-[state=open]:bg-surface-raised"
                                        :aria-label="
                                            $t('More for :name', {
                                                name: monitor.name,
                                            })
                                        "
                                        @click.stop
                                    >
                                        <Ellipsis class="size-4" />
                                    </button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent
                                    class="min-w-48 font-ui"
                                    align="end"
                                    @click.stop
                                >
                                    <DropdownMenuItem
                                        :disabled="monitor.paused"
                                        @select="checkNow(monitor)"
                                    >
                                        {{ $t('Check now') }}
                                    </DropdownMenuItem>
                                    <DropdownMenuItem @select="edit(monitor)">
                                        {{ $t('Edit monitor') }}
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        @select="
                                            setPaused(monitor, !monitor.paused)
                                        "
                                    >
                                        {{
                                            monitor.paused
                                                ? $t('Resume')
                                                : $t('Pause')
                                        }}
                                    </DropdownMenuItem>
                                    <DropdownMenuSeparator />
                                    <DropdownMenuItem
                                        v-if="monitor.mutedUntil"
                                        @select="unmute(monitor)"
                                    >
                                        {{ $t('Unmute alerts') }}
                                    </DropdownMenuItem>
                                    <template v-else>
                                        <DropdownMenuItem
                                            v-for="[minutes, label] in [
                                                [60, $t('1 hour')],
                                                [240, $t('4 hours')],
                                                [1440, $t('1 day')],
                                            ] as const"
                                            :key="minutes"
                                            @select="mute(monitor, minutes)"
                                        >
                                            {{
                                                $t('Mute alerts for :label', {
                                                    label,
                                                })
                                            }}
                                        </DropdownMenuItem>
                                    </template>
                                    <DropdownMenuSeparator />
                                    <DropdownMenuItem
                                        class="text-status-down focus:text-status-down"
                                        @select="removing = monitor"
                                    >
                                        {{ $t('Delete monitor') }}
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </DashTd>
                    </DashTr>
                </DashTable>
                <p
                    v-if="shown.length === 0"
                    class="px-5 py-10 text-center text-[13px]/5 text-fg-muted"
                >
                    {{ $t('Nothing matches.') }}
                </p>
            </template>
        </template>

        <template v-else>
            <div class="flex min-h-14 flex-wrap items-center gap-2 px-5 py-3">
                <span class="text-[12px]/4 text-fg-muted">{{
                    $t(
                        'Incidents show on your status page and tell visitors what’s going on.',
                    )
                }}</span>
                <span class="min-w-0 flex-1" />
                <DashButton
                    v-if="canManage"
                    :icon="Plus"
                    :label="$t('Post an incident')"
                    @click="showIncident(null)"
                />
            </div>
            <DashTable
                v-if="incidents.length"
                class="border-t-[0.5px] border-edge-tabs"
            >
                <template #head>
                    <DashTh icon="activity-fill" :width="380" first>{{
                        $t('Incident')
                    }}</DashTh>
                    <DashTh icon="setup-fill" :width="160">{{
                        $t('Status')
                    }}</DashTh>
                    <DashTh icon="machines-fill" :width="180">{{
                        $t('Shown on')
                    }}</DashTh>
                    <DashTh icon="history" fill>{{ $t('Started') }}</DashTh>
                </template>
                <DashTr
                    v-for="incident in incidents"
                    :key="incident.id"
                    clickable
                    @click="showIncident(incident)"
                >
                    <DashTd first>
                        <span
                            class="size-2 shrink-0 rounded-full"
                            :class="
                                incident.resolvedAt
                                    ? 'bg-status-live'
                                    : impactDot[incident.impact]
                            "
                            aria-hidden="true"
                        />
                        <span
                            class="truncate font-medium tracking-[-0.01em] text-fg-primary"
                            >{{ incident.title }}</span
                        >
                    </DashTd>
                    <DashTd>{{ incidentStates[incident.state] }}</DashTd>
                    <DashTd>
                        <span class="truncate">{{
                            incident.machine ?? $t('Workspace page')
                        }}</span>
                    </DashTd>
                    <DashTd>
                        <span class="text-fg-muted">{{
                            relative(incident.startedAt, now)
                        }}</span>
                    </DashTd>
                </DashTr>
            </DashTable>
            <p
                v-else
                class="border-t-[0.5px] border-edge-tabs px-5 py-16 text-center text-[13px]/5 text-fg-muted"
            >
                {{ $t('No incidents in the last 90 days.') }}
            </p>
        </template>
    </div>

    <MonitorDialog
        v-model:open="creating"
        :machines="machines"
        :min-interval="minInterval"
    />
    <MonitorDialog
        v-model:open="editOpen"
        :monitor="editing"
        :machines="machines"
        :min-interval="minInterval"
    />
    <AlertsDialog v-model:open="alertsOpen" :alerts="alerts" />
    <IncidentDialog
        v-model:open="incidentOpen"
        :incident="incidentShown"
        :machines="machines"
        :can-manage="canManage"
    />
    <Dialog
        :open="removing !== null"
        @update:open="(value) => !value && (removing = null)"
    >
        <DialogContent class="gap-0 p-0 font-ui sm:max-w-md">
            <div class="flex flex-col gap-1 p-5">
                <DialogTitle
                    class="text-[15px]/6 font-medium text-fg-primary"
                    >{{
                        $t('Delete :name?', { name: removing?.name ?? '' })
                    }}</DialogTitle
                >
                <DialogDescription class="text-[13px]/5 text-fg-tertiary">
                    {{
                        $t(
                            'Its checks and uptime history go with it. Pause it instead to keep them.',
                        )
                    }}
                </DialogDescription>
            </div>
            <div class="dash-card-footer gap-2">
                <DashButton :label="$t('Cancel')" @click="removing = null" />
                <DashButton
                    variant="danger"
                    :label="$t('Delete monitor')"
                    @click="remove"
                />
            </div>
        </DialogContent>
    </Dialog>
</template>
