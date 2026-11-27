<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { Head, router, usePage } from '@inertiajs/vue3';
import { Check, Plus } from '@lucide/vue';
import {
    computed,
    defineAsyncComponent,
    onBeforeUnmount,
    onMounted,
    ref,
    useTemplateRef,
    watch,
} from 'vue';
import DashButton from '@/components/dash/DashButton.vue';
import DashHeader from '@/components/dash/DashHeader.vue';
import DashTable from '@/components/dash/DashTable.vue';
import DashTh from '@/components/dash/DashTh.vue';
import EmptyArt from '@/components/dash/EmptyArt.vue';
import SearchWell from '@/components/dash/SearchWell.vue';
import ToolbarButton from '@/components/dash/ToolbarButton.vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import { buildingServices } from '@/components/machines/buildingServices';
import type { DeployJob } from '@/components/machines/buildingServices';
import {
    serviceSortOptions,
    statusTabs,
} from '@/components/machines/serviceFilters';
import type {
    ServiceSortKey,
    StatusFilter,
    WorkspaceService,
} from '@/components/machines/serviceFilters';
import { passes } from '@/components/machines/serviceFormat';
import type { ServiceGroup } from '@/components/machines/ServicesTable.vue';
import ServiceListBuildingRow from '@/components/services/ServiceListBuildingRow.vue';
import ServiceListGroupRow from '@/components/services/ServiceListGroupRow.vue';
import ServiceListRow from '@/components/services/ServiceListRow.vue';
import { displayName, imageLabel } from '@/components/services/serviceList';
import type { AdoptJob, ListMachine } from '@/components/services/serviceList';
import StatusTab from '@/components/services/StatusTab.vue';
import type { StatusTabIcon } from '@/components/services/StatusTab.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import ConnectMachineButton from '@/components/workspace/ConnectMachineButton.vue';
import type { ServiceTemplate } from '@/components/workspace/DeployServiceDialog.vue';
import { useWhenVisible } from '@/composables/useWhenVisible';
import { describe, errorMessage, jobReason } from '@/lib/errors';
import { toast } from '@/lib/toast';
import { onWorkspace } from '@/lib/workspaceRoute';
import machinesRoutes from '@/routes/workspace/machines';
import type { Workspace, WorkspaceAccess } from '@/types';

const loadDeployDialog = () =>
    import('@/components/workspace/DeployServiceDialog.vue');
const DeployServiceDialog = defineAsyncComponent(loadDeployDialog);

const props = defineProps<{
    workspace: Workspace;
    services: WorkspaceService[];
    groups: (ServiceGroup & { machineId: number })[];
    machines: ListMachine[];
    templates: ServiceTemplate[];
    deployEnabled: boolean;
    deploys: (DeployJob & { machineId: number })[];
    adopts: AdoptJob[];
}>();

const page = usePage<{ workspaceAccess: WorkspaceAccess | null }>();
const canManage = computed(
    () =>
        page.props.workspaceAccess?.permissions.includes('manage-machines') ??
        false,
);

const machineById = computed(
    () => new Map(props.machines.map((machine) => [machine.id, machine])),
);

function isOnline(machine: ListMachine): boolean {
    return ['online', 'degraded'].includes(machine.connection);
}

const deployTargets = computed(() =>
    canManage.value && props.deployEnabled
        ? props.machines.filter(isOnline)
        : [],
);

const waitingForScan = computed(() => props.machines.some((m) => !m.scanned));

const aboutOpen = ref(false);
const deployOpen = ref(false);
const deployMachine = ref<number | null>(null);

function deployTo(id: number): void {
    deployMachine.value = id;
    deployOpen.value = true;
}

const filter = ref<StatusFilter>('all');
const search = ref('');
const sort = ref<ServiceSortKey>('cpu');
const machineFilter = ref<number | null>(null);
const grouping = ref<'groups' | 'none'>('groups');
const searchWell =
    useTemplateRef<InstanceType<typeof SearchWell>>('searchWell');

const showHidden = ref(false);
const hiddenCount = computed(
    () => props.services.filter((service) => service.hidden).length,
);

watch(hiddenCount, (count) => {
    if (count === 0) showHidden.value = false;
});

const shown = computed(() =>
    showHidden.value
        ? props.services
        : props.services.filter((service) => !service.hidden),
);

const tabIcons: Record<StatusFilter, StatusTabIcon> = {
    all: 'layers-fill',
    managed: 'managed-fill',
    running: 'running-fill',
    stopped: 'activity-fill',
    problems: 'info-fill',
};

const tabs = computed(() => statusTabs(shown.value));

const sortLabel = computed(
    () => serviceSortOptions.find((o) => o.value === sort.value)?.label ?? '',
);

const machineOptions = computed(() =>
    props.machines.filter((m) =>
        props.services.some((s) => s.machine.id === m.id),
    ),
);

const filterLabel = computed(
    () =>
        props.machines.find((m) => m.id === machineFilter.value)?.name ??
        trans('Filter'),
);

const groupingLabels = computed(() => ({
    groups: trans('Groups'),
    none: trans('No groups'),
}));

function onKeydown(event: KeyboardEvent): void {
    const target = event.target as HTMLElement | null;
    const typing =
        target?.isContentEditable ||
        ['INPUT', 'TEXTAREA', 'SELECT'].includes(target?.tagName ?? '');

    if (event.key === '/' && !typing && !event.metaKey && !event.ctrlKey) {
        event.preventDefault();
        searchWell.value?.focus();
    }
}

const needle = computed(() => search.value.trim().toLowerCase());

const visible = computed(() => {
    const now = Date.now() / 1000;
    const value = (service: WorkspaceService): number => {
        if (machineById.value.get(service.machine.id)?.connection === 'offline')
            return -2;
        switch (sort.value) {
            case 'cpu':
                return service.stat?.cpu ?? -1;
            case 'memory':
                return service.stat?.memUsed ?? -1;
            case 'uptime':
                return service.stat?.startedAt
                    ? now - service.stat.startedAt
                    : -1;
            default:
                return 0;
        }
    };

    return shown.value
        .filter((s) => passes(s, filter.value))
        .filter(
            (s) =>
                machineFilter.value === null ||
                s.machine.id === machineFilter.value,
        )
        .filter(
            (s) =>
                !needle.value ||
                [
                    displayName(s),
                    s.name,
                    s.manager,
                    s.kind,
                    imageLabel(s.details?.image) ?? '',
                    s.ports.join(' '),
                    s.machine.name,
                ]
                    .join(' ')
                    .toLowerCase()
                    .includes(needle.value),
        )
        .sort((a, b) =>
            sort.value === 'name'
                ? displayName(a).localeCompare(displayName(b))
                : value(b) - value(a),
        );
});

const building = computed(() =>
    props.machines
        .filter(
            (m) => machineFilter.value === null || m.id === machineFilter.value,
        )
        .flatMap((machine) =>
            buildingServices(
                props.deploys.filter((job) => job.machineId === machine.id),
                props.services.filter((s) => s.machine.id === machine.id),
                machine.scannedAt,
            ).map((entry) => ({ ...entry, machine })),
        )
        .filter(
            (entry) =>
                !needle.value ||
                `${entry.name} ${entry.machine.name}`
                    .toLowerCase()
                    .includes(needle.value),
        ),
);

function openDeployLog(machineId: number, jobId: string): void {
    router.visit(
        onWorkspace(
            machinesRoutes.show(
                { workspace: props.workspace.slug, machine: machineId },
                { query: { job: jobId } },
            ),
        ).url,
    );
}

type GroupEntry = {
    id: string;
    kind: 'group' | 'stack';
    name: string;
    iconUrl: string | null;
    machineId: number;
    services: WorkspaceService[];
};

type Row =
    | { type: 'service'; id: string; service: WorkspaceService }
    | ({ type: 'group' } & GroupEntry);

const rows = computed<Row[]>(() => {
    if (grouping.value === 'none') {
        return visible.value.map((service) => ({
            type: 'service' as const,
            id: `${service.machine.id}:${service.key}`,
            service,
        }));
    }

    const owner = new Map<WorkspaceService, string>();
    const groups = new Map<string, GroupEntry>();

    for (const group of props.groups) {
        const id = `${group.machineId}:g:${group.id}`;
        const inside = visible.value.filter(
            (s) =>
                s.machine.id === group.machineId &&
                !owner.has(s) &&
                (group.services.includes(s.key) ||
                    (!!s.app && group.services.includes(`app:${s.app}`))),
        );
        inside.forEach((s) => owner.set(s, id));
        if (inside.length) {
            groups.set(id, {
                id,
                kind: 'group',
                name: group.name,
                iconUrl: group.iconUrl ?? null,
                machineId: group.machineId,
                services: inside,
            });
        }
    }

    const stacks = new Map<string, WorkspaceService[]>();
    for (const s of visible.value) {
        const project = s.details?.compose_project;
        if (!project || owner.has(s)) continue;
        const id = `${s.machine.id}:s:${project}`;
        stacks.set(id, [...(stacks.get(id) ?? []), s]);
    }
    for (const [id, inside] of stacks) {
        if (inside.length < 2) continue;
        inside.forEach((s) => owner.set(s, id));
        groups.set(id, {
            id,
            kind: 'stack',
            name: String(inside[0].details?.compose_project).replace(
                /^serveros-/,
                '',
            ),
            iconUrl: null,
            machineId: inside[0].machine.id,
            services: inside,
        });
    }

    const result: Row[] = [];
    const placed = new Set<string>();
    for (const service of visible.value) {
        const id = owner.get(service);
        if (id === undefined) {
            result.push({
                type: 'service',
                id: `${service.machine.id}:${service.key}`,
                service,
            });
        } else if (!placed.has(id)) {
            placed.add(id);
            result.push({ type: 'group', ...groups.get(id)! });
        }
    }

    return result;
});

const closedKey = 'serveros:services:closed-groups';

function readClosed(): Set<string> {
    try {
        return new Set(JSON.parse(localStorage.getItem(closedKey) ?? '[]'));
    } catch {
        return new Set();
    }
}

const closed = ref<Set<string>>(
    typeof window === 'undefined' ? new Set() : readClosed(),
);

watch(closed, (value) => {
    try {
        localStorage.setItem(closedKey, JSON.stringify([...value]));
    } catch {}
});

function isOpen(id: string): boolean {
    return !closed.value.has(id) || needle.value !== '';
}

function toggle(id: string): void {
    const next = new Set(closed.value);
    if (!next.delete(id)) next.add(id);
    closed.value = next;
}

function serviceArgs(service: WorkspaceService) {
    return {
        workspace: props.workspace.slug,
        machine: service.machine.id,
        service: service.app ? `app:${service.app}` : service.key,
    };
}

function serviceUrl(service: WorkspaceService): string {
    return onWorkspace(machinesRoutes.services.show(serviceArgs(service))).url;
}

function open(service: WorkspaceService, event: MouseEvent): void {
    if (event.metaKey || event.ctrlKey) {
        window.open(serviceUrl(service), '_blank');

        return;
    }
    router.visit(serviceUrl(service));
}

const reloadOnly = ['services', 'groups', 'machines', 'deploys', 'adopts'];
const updatedAt = ref(Date.now());
const now = ref(Date.now());
const updatedLabel = computed(() => {
    const seconds = Math.max(
        0,
        Math.round((now.value - updatedAt.value) / 1000),
    );

    return trans('Live · updated :ago ago', {
        ago: seconds < 60 ? `${seconds}s` : `${Math.round(seconds / 60)}m`,
    });
});

function refresh(): void {
    router.reload({
        only: reloadOnly,
        onSuccess: () => (updatedAt.value = Date.now()),
    });
}

function setHidden(service: WorkspaceService, hidden: boolean): void {
    const name = displayName(service);
    router.put(
        onWorkspace(
            machinesRoutes.services.hide({
                workspace: props.workspace.slug,
                machine: service.machine.id,
            }),
        ).url,
        { services: [service.key], hidden },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () =>
                toast.success(
                    hidden
                        ? trans(
                              ':displayName is hidden from the lists. It still runs as before.',
                              { displayName: name },
                          )
                        : trans(':displayName shows in the lists again.', {
                              displayName: name,
                          }),
                ),
        },
    );
}

type PendingAdopt = { jobId: string; toast: string | number; name: string };
const pendingAdopts = ref<Record<string, PendingAdopt>>({});
const sending = ref<string | null>(null);
const moving = ['queued', 'accepted', 'running'];

function adoptKey(machineId: number, key: string): string {
    return `${machineId}:${key}`;
}

function isAdopting(service: WorkspaceService): boolean {
    const key = adoptKey(service.machine.id, service.key);

    return (
        sending.value === key ||
        key in pendingAdopts.value ||
        props.adopts.some(
            (job) =>
                job.machineId === service.machine.id &&
                job.service === service.key &&
                moving.includes(job.state),
        )
    );
}

async function adopt(service: WorkspaceService): Promise<void> {
    const key = adoptKey(service.machine.id, service.key);
    if (isAdopting(service)) return;
    const name = displayName(service);
    sending.value = key;

    try {
        const response = await fetch(
            onWorkspace(
                machinesRoutes.services.action({
                    workspace: props.workspace.slug,
                    machine: service.machine.id,
                    service: service.key,
                }),
            ).url,
            {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN':
                        document.querySelector<HTMLMetaElement>(
                            'meta[name="csrf-token"]',
                        )?.content ?? '',
                },
                body: JSON.stringify({ action: 'adopt' }),
            },
        );
        const body = await response.json().catch(() => ({}));

        if (!response.ok) {
            toast.error(describe(response.status, body));

            return;
        }

        pendingAdopts.value = {
            ...pendingAdopts.value,
            [key]: {
                jobId: String(body.jobId ?? ''),
                toast: toast.loading(
                    trans(':verb :name…', {
                        verb: trans('Adopting'),
                        name,
                    }),
                ),
                name,
            },
        };
        refresh();
        poll();
    } catch (cause) {
        toast.error(errorMessage(cause));
    } finally {
        sending.value = null;
    }
}

watch(
    () => props.adopts,
    (jobs) => {
        const next = { ...pendingAdopts.value };
        for (const [key, pending] of Object.entries(next)) {
            const job = jobs.find((j) => j.id === pending.jobId);
            if (!job || moving.includes(job.state)) continue;

            if (job.state === 'succeeded') {
                toast.success(
                    trans(':verb :name.', {
                        verb: trans('Adopted'),
                        name: pending.name,
                    }),
                    { id: pending.toast },
                );
            } else {
                toast.error(
                    jobReason(
                        job.error,
                        trans('Couldn’t :action :displayName.', {
                            action: trans('adopt'),
                            displayName: pending.name,
                        }),
                    ),
                    { id: pending.toast },
                );
            }
            delete next[key];
        }
        pendingAdopts.value = next;
    },
);

let timer: ReturnType<typeof setTimeout> | undefined;
let clock: ReturnType<typeof setInterval> | undefined;
const whenVisible = useWhenVisible();

function poll(): void {
    clearTimeout(timer);
    const busy =
        waitingForScan.value ||
        Object.keys(pendingAdopts.value).length > 0 ||
        props.adopts.some((job) => moving.includes(job.state)) ||
        building.value.some((entry) => entry.state !== 'failed');
    timer = setTimeout(
        () =>
            whenVisible(() =>
                router.reload({
                    only: reloadOnly,
                    onSuccess: () => (updatedAt.value = Date.now()),
                    onFinish: poll,
                }),
            ),
        busy ? 5000 : 15000,
    );
}

onMounted(() => {
    window.addEventListener('keydown', onKeydown);
    poll();
    clock = setInterval(() => (now.value = Date.now()), 1000);
    void loadDeployDialog();
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeydown);
    clearTimeout(timer);
    clearInterval(clock);
});
</script>

<template>
    <Head :title="$t('Services')" />

    <DashHeader :crumbs="[{ label: $t('Services') }]">
        <template v-if="machines.length" #actions>
            <DropdownMenu v-if="deployTargets.length > 1">
                <DropdownMenuTrigger as-child>
                    <DashButton
                        variant="primary"
                        :icon="Plus"
                        :label="$t('Deploy service')"
                    />
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    class="ds-modal min-w-48 font-ui"
                    align="end"
                >
                    <DropdownMenuLabel
                        class="text-[11.5px]/4 font-medium text-fg-muted"
                    >
                        {{ $t('Deploy to') }}
                    </DropdownMenuLabel>
                    <DropdownMenuItem
                        v-for="machine in deployTargets"
                        :key="machine.id"
                        @select="deployTo(machine.id)"
                    >
                        <span class="truncate">{{ machine.name }}</span>
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
            <DashButton
                v-else
                variant="primary"
                :icon="Plus"
                :label="$t('Deploy service')"
                :disabled="deployTargets.length === 0"
                :title="
                    deployTargets.length === 0
                        ? $t('Deploying needs a machine that is online')
                        : undefined
                "
                @click="deployTargets[0] && deployTo(deployTargets[0].id)"
            />
        </template>
    </DashHeader>

    <div
        v-if="machines.length === 0"
        class="flex min-h-full flex-col items-center justify-center px-6 py-16 text-center"
    >
        <EmptyArt kind="drop" />
        <h2
            class="mt-6 text-[15px]/6 font-medium tracking-[-0.01em] text-fg-primary"
        >
            {{ $t('No services yet') }}
        </h2>
        <p class="mt-1 max-w-sm text-[13px]/5 text-pretty text-fg-tertiary">
            {{
                $t(
                    'Connect a machine and everything already running on it shows up here: apps, sites, databases, workers.',
                )
            }}
            <button
                type="button"
                class="cursor-pointer font-medium text-dash-blue hover:underline hover:underline-offset-4 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring)"
                @click="aboutOpen = true"
            >
                {{ $t('Learn more') }}
            </button>
        </p>
        <ConnectMachineButton class="mt-5" />
    </div>

    <div
        v-else-if="services.length === 0 && building.length === 0"
        class="flex min-h-full flex-col items-center justify-center px-6 py-16 text-center"
    >
        <EmptyArt kind="drop" />
        <h2
            class="mt-6 text-[15px]/6 font-medium tracking-[-0.01em] text-fg-primary"
        >
            {{
                waitingForScan
                    ? $t('Looking for services')
                    : $t('Nothing running yet')
            }}
        </h2>
        <p class="mt-1 max-w-sm text-[13px]/5 text-pretty text-fg-tertiary">
            {{
                waitingForScan
                    ? $t(
                          'The daemon is scanning for what already runs. Services appear here as soon as it reports back.',
                      )
                    : $t(
                          'Deploy an app, a site or a database from the library, and it shows up here.',
                      )
            }}
            <button
                type="button"
                class="cursor-pointer font-medium text-dash-blue hover:underline hover:underline-offset-4 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring)"
                @click="aboutOpen = true"
            >
                {{ $t('Learn more') }}
            </button>
        </p>
        <DashButton
            v-if="!waitingForScan && deployTargets.length"
            variant="primary"
            class="mt-5"
            :icon="Plus"
            :label="$t('Deploy service')"
            @click="deployTo(deployTargets[0].id)"
        />
    </div>

    <template v-else>
        <nav
            class="flex h-11 shrink-0 [scrollbar-width:none] items-center gap-0.5 overflow-x-auto border-b-[0.5px] border-edge-tabs pl-3.5 [&::-webkit-scrollbar]:hidden"
            :aria-label="$t('Filter by status')"
        >
            <StatusTab
                v-for="tab in tabs"
                :key="tab.value"
                :label="tab.label"
                :icon="tabIcons[tab.value]"
                :count="tab.count"
                :active="filter === tab.value"
                @click="filter = tab.value"
            />
        </nav>

        <div class="flex min-h-14 flex-wrap items-center gap-2 px-5 py-3">
            <SearchWell
                ref="searchWell"
                v-model="search"
                :placeholder="$t('Search services')"
                :width="260"
                shortcut="/"
                class="max-sm:w-full!"
            />

            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <ToolbarButton
                        icon="filter-fill"
                        :label="filterLabel"
                        :active="machineFilter !== null"
                    />
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    class="ds-modal max-h-[60svh] min-w-48 overflow-y-auto font-ui"
                    align="start"
                >
                    <DropdownMenuLabel
                        class="text-[11.5px]/4 font-medium text-fg-muted"
                    >
                        {{ $t('Machine') }}
                    </DropdownMenuLabel>
                    <DropdownMenuItem @select="machineFilter = null">
                        {{ $t('All machines') }}
                        <Check
                            v-if="machineFilter === null"
                            class="ml-auto size-3.5"
                        />
                    </DropdownMenuItem>
                    <DropdownMenuSeparator v-if="machineOptions.length" />
                    <DropdownMenuItem
                        v-for="option in machineOptions"
                        :key="option.id"
                        @select="machineFilter = option.id"
                    >
                        <span class="truncate">{{ option.name }}</span>
                        <Check
                            v-if="machineFilter === option.id"
                            class="ml-auto size-3.5"
                        />
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>

            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <ToolbarButton
                        icon="folder-fill"
                        :label="groupingLabels[grouping]"
                        :active="grouping !== 'groups'"
                    />
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    class="ds-modal min-w-52 font-ui"
                    align="start"
                >
                    <DropdownMenuLabel
                        class="text-[11.5px]/4 font-medium text-fg-muted"
                    >
                        {{ $t('Group by') }}
                    </DropdownMenuLabel>
                    <DropdownMenuItem @select="grouping = 'groups'">
                        {{ $t('Groups and Compose stacks') }}
                        <Check
                            v-if="grouping === 'groups'"
                            class="ml-auto size-3.5"
                        />
                    </DropdownMenuItem>
                    <DropdownMenuItem @select="grouping = 'none'">
                        {{ $t('No groups') }}
                        <Check
                            v-if="grouping === 'none'"
                            class="ml-auto size-3.5"
                        />
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuLabel
                        class="max-w-60 text-[11.5px]/4 font-normal text-fg-muted"
                    >
                        {{
                            $t(
                                'Make groups on a machine’s page. Compose stacks group themselves.',
                            )
                        }}
                    </DropdownMenuLabel>
                </DropdownMenuContent>
            </DropdownMenu>

            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <ToolbarButton icon="sort-fill" :label="sortLabel" />
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    class="ds-modal min-w-40 font-ui"
                    align="start"
                >
                    <DropdownMenuLabel
                        class="text-[11.5px]/4 font-medium text-fg-muted"
                    >
                        {{ $t('Sort by') }}
                    </DropdownMenuLabel>
                    <DropdownMenuItem
                        v-for="option in serviceSortOptions"
                        :key="option.value"
                        @select="sort = option.value"
                    >
                        {{ option.label }}
                        <Check
                            v-if="sort === option.value"
                            class="ml-auto size-3.5"
                        />
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>

            <ToolbarButton
                class="pl-3! disabled:cursor-default disabled:hover:text-fg-secondary"
                :active="showHidden"
                :aria-pressed="showHidden"
                :disabled="hiddenCount === 0"
                :title="
                    hiddenCount === 0
                        ? $t('Nothing is hidden. Hide a service from its menu.')
                        : undefined
                "
                @click="showHidden = !showHidden"
            >
                {{ showHidden ? $t('Leave out hidden') : $t('Show hidden') }}
                <span class="text-fg-faint tabular-nums dark:text-[#6b6b6b]">{{
                    hiddenCount
                }}</span>
            </ToolbarButton>

            <span class="grow" />
            <span
                class="flex items-center gap-2 text-[12px]/4 whitespace-nowrap text-fg-muted max-md:hidden"
            >
                <span
                    class="size-1.5 rounded-full bg-status-live"
                    aria-hidden="true"
                />
                {{ updatedLabel }}
            </span>
        </div>

        <DashTable>
            <template #head>
                <DashTh first fill>
                    <template #lead>
                        <FilledIcon
                            name="layers-fill"
                            class="-mr-1 text-fg-muted"
                        />
                    </template>
                    {{ $t('Service') }}
                </DashTh>
                <DashTh icon="monitors-fill" :width="168">{{
                    $t('Status')
                }}</DashTh>
                <DashTh icon="machines-fill" :width="124">{{
                    $t('Machine')
                }}</DashTh>
                <DashTh icon="monitors-fill" :width="112">{{
                    $t('CPU')
                }}</DashTh>
                <DashTh icon="package-fill" :width="150">{{
                    $t('Memory')
                }}</DashTh>
                <DashTh icon="globe-fill" :width="128">{{
                    $t('Ports')
                }}</DashTh>
                <DashTh :width="92" class="border-r-0!">
                    <span class="sr-only">{{ $t('Actions') }}</span>
                </DashTh>
            </template>

            <ServiceListBuildingRow
                v-for="entry in building"
                :key="`building-${entry.jobId}`"
                :entry="entry"
                :machine-name="entry.machine.name"
                @open="openDeployLog(entry.machine.id, entry.jobId)"
            />

            <template v-for="row in rows" :key="row.id">
                <ServiceListRow
                    v-if="row.type === 'service'"
                    :service="row.service"
                    :machine="machineById.get(row.service.machine.id)"
                    :href="serviceUrl(row.service)"
                    :can-manage="canManage"
                    :adopting="isAdopting(row.service)"
                    @open="open(row.service, $event)"
                    @adopt="adopt(row.service)"
                    @hide="setHidden(row.service, $event)"
                />
                <template v-else>
                    <ServiceListGroupRow
                        :name="row.name"
                        :kind="row.kind"
                        :icon-url="row.iconUrl"
                        :services="row.services"
                        :machine="machineById.get(row.machineId)"
                        :open="isOpen(row.id)"
                        @toggle="toggle(row.id)"
                    />
                    <template v-if="isOpen(row.id)">
                        <ServiceListRow
                            v-for="(service, index) in row.services"
                            :key="service.key"
                            :service="service"
                            :machine="machineById.get(service.machine.id)"
                            :href="serviceUrl(service)"
                            :guide="
                                index === row.services.length - 1
                                    ? 'last'
                                    : 'middle'
                            "
                            :can-manage="canManage"
                            :adopting="isAdopting(service)"
                            @open="open(service, $event)"
                            @adopt="adopt(service)"
                            @hide="setHidden(service, $event)"
                        />
                    </template>
                </template>
            </template>
        </DashTable>

        <p
            v-if="visible.length === 0 && building.length === 0"
            class="px-5 py-10 text-center text-[13px]/5 text-fg-muted"
        >
            {{ $t('No services match these filters.') }}
        </p>
    </template>

    <Dialog v-model:open="aboutOpen">
        <DialogContent
            class="workspace-panel ds-modal font-ui text-foreground sm:max-w-md"
        >
            <DialogHeader>
                <DialogTitle>{{ $t('About services') }}</DialogTitle>
                <DialogDescription>
                    {{
                        $t(
                            'A service is anything running on a machine: an app, a site, a database, a worker, a cache.',
                        )
                    }}
                </DialogDescription>
            </DialogHeader>
            <ul
                class="flex list-disc flex-col gap-2.5 pl-4 text-[13px]/5 text-fg-secondary marker:text-fg-faint"
            >
                <li>
                    <span class="font-medium text-fg-primary">{{
                        $t('Found for you.')
                    }}</span>
                    {{
                        ' ' +
                        $t(
                            'The daemon scans each machine for containers, systemd units and processes, every hour and after every deploy. Nothing you already run needs moving.',
                        )
                    }}
                </li>
                <li>
                    <span class="font-medium text-fg-primary">{{
                        $t('Adopt to control.')
                    }}</span>
                    {{
                        ' ' +
                        $t(
                            'Services you deploy here are managed from the start. Adopt one you set up yourself to start, stop, restart, read its logs and open a console.',
                        )
                    }}
                </li>
                <li>
                    <span class="font-medium text-fg-primary">{{
                        $t('Deploy from the library.')
                    }}</span>
                    {{
                        ' ' +
                        $t(
                            'Pick an app, a site or a database, choose a machine, and it shows up here once it starts.',
                        )
                    }}
                </li>
                <li>
                    <span class="font-medium text-fg-primary">{{
                        $t('Group them on a machine.')
                    }}</span>
                    {{
                        ' ' +
                        $t(
                            "On a machine's page, put services in groups. Compose stacks group themselves.",
                        )
                    }}
                </li>
            </ul>
        </DialogContent>
    </Dialog>

    <DeployServiceDialog
        v-if="deployMachine !== null"
        v-model:open="deployOpen"
        :templates="templates"
        :machine-id="deployMachine"
        @deployed="refresh"
    />
</template>
