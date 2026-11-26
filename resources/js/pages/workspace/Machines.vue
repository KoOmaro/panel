<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Check, Ellipsis, Plus, X } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, useTemplateRef } from 'vue';
import BrandLogo from '@/components/dash/BrandLogo.vue';
import DashButton from '@/components/dash/DashButton.vue';
import DashCheckbox from '@/components/dash/DashCheckbox.vue';
import DashHeader from '@/components/dash/DashHeader.vue';
import DashTab from '@/components/dash/DashTab.vue';
import DashTable from '@/components/dash/DashTable.vue';
import DashTabs from '@/components/dash/DashTabs.vue';
import DashTd from '@/components/dash/DashTd.vue';
import DashTh from '@/components/dash/DashTh.vue';
import DashTr from '@/components/dash/DashTr.vue';
import EmptyArt from '@/components/dash/EmptyArt.vue';
import SearchWell from '@/components/dash/SearchWell.vue';
import StatusBadge from '@/components/dash/StatusBadge.vue';
import ToolbarButton from '@/components/dash/ToolbarButton.vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import type { FilledIconName } from '@/components/icons/filledIcons';
import InputError from '@/components/InputError.vue';
import ClearDataDialog from '@/components/machines/ClearDataDialog.vue';
import MachineLogoStack from '@/components/machines/MachineLogoStack.vue';
import ServiceLogo from '@/components/machines/ServiceLogo.vue';
import UsageMeter from '@/components/machines/UsageMeter.vue';
import {
    diskOf,
    memoryOf,
    reporting,
    sizePair,
} from '@/components/machines/machineFormat';
import { environmentLabels } from '@/components/machines/machines';
import type { Environment, Machine } from '@/components/machines/machines';
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
import { Input } from '@/components/ui/input';
import { SelectMenu } from '@/components/ui/select';
import type { SelectMenuOption } from '@/components/ui/select';
import AppButton from '@/components/workspace/AppButton.vue';
import ConnectMachineButton from '@/components/workspace/ConnectMachineButton.vue';
import DangerButton from '@/components/workspace/DangerButton.vue';
import GroupDialog from '@/components/workspace/GroupDialog.vue';
import type { GroupDialogItem } from '@/components/workspace/GroupDialog.vue';
import SubmitButton from '@/components/workspace/SubmitButton.vue';
import { useWhenVisible } from '@/composables/useWhenVisible';
import { fixed } from '@/lib/formatting';
import { fieldLabel } from '@/lib/machinePolicies';
import type { PolicyChoice } from '@/lib/machinePolicies';
import { toast } from '@/lib/toast';
import { onWorkspace } from '@/lib/workspaceRoute';
import machineGroupRoutes from '@/routes/workspace/machine-groups';
import machinesRoutes from '@/routes/workspace/machines';
import metadataRoutes from '@/routes/workspace/machines/metadata';
import settingsRoutes from '@/routes/workspace/settings';
import type { Workspace, WorkspaceAccess } from '@/types';

type MachineGroup = { id: number; name: string; policyId?: number | null };

type Props = {
    workspace: Workspace;
    machines: Machine[];
    machineGroups: MachineGroup[];
    machinePolicies: PolicyChoice[];
    fleetStatus: { live: boolean };
};

type EnvironmentFilter = Environment | 'all';
type SortKey = 'cpu' | 'memory' | 'name';

type Summary = {
    count: number;
    providers: number;
    online: number;
    cpu: number | null;
    memory: { used: number; total: number; percent: number } | null;
    disk: { used: number; total: number; percent: number } | null;
    services: number;
    never: number;
};

type GroupRow = {
    kind: 'group';
    key: string;
    id: number;
    name: string;
    editable: boolean;
    open: boolean;
    machines: Machine[];
    summary: Summary;
};
type MachineRowEntry = {
    kind: 'machine';
    key: string;
    machine: Machine;
    nested: boolean;
};

const props = defineProps<Props>();

const page = usePage<{ workspaceAccess: WorkspaceAccess | null }>();
const canManage = computed(
    () =>
        page.props.workspaceAccess?.permissions.includes('manage-machines') ??
        false,
);

const machines = computed(() => props.machines);
const slug = computed(() => props.workspace.slug);

function machineUrl(machine: Machine, tab = ''): string {
    const base = onWorkspace(
        machinesRoutes.show({ workspace: slug.value, machine: machine.id! }),
    ).url;

    return tab ? `${base}/${tab}` : base;
}

const statusPageUrl = computed(
    () => onWorkspace(settingsRoutes.statusPage(slug.value)).url,
);

const search = ref('');
const environment = ref<EnvironmentFilter>('all');
const detailFilters = ref<Record<string, string>>({});
const sort = ref<SortKey>('cpu');
const selected = ref<string[]>([]);
const groupBy = ref<string>('groups');
const searchWell =
    useTemplateRef<InstanceType<typeof SearchWell>>('searchWell');
const connectButton =
    useTemplateRef<InstanceType<typeof ConnectMachineButton>>('connectButton');
const firstConnectButton =
    useTemplateRef<InstanceType<typeof ConnectMachineButton>>(
        'firstConnectButton',
    );

const environmentIcons: Record<EnvironmentFilter, FilledIconName> = {
    all: 'machines-fill',
    production: 'layers-fill',
    staging: 'branch-fill',
    development: 'code-fill',
};

const environmentDots: Record<Environment, string> = {
    production: 'bg-dash-blue',
    staging: 'bg-[#f5a833]',
    development: 'bg-fg-muted',
};

const tabs = computed(() => [
    {
        value: 'all' as const,
        label: trans('All machines'),
        count: machines.value.length,
    },
    ...(Object.keys(environmentLabels) as Environment[]).map((value) => ({
        value,
        label: environmentLabels[value],
        count: machines.value.filter((machine) => machine.environment === value)
            .length,
    })),
]);

function detailValue(machine: Machine, field: string): string | null {
    const value = field.startsWith('label:')
        ? machine.labels?.[field.slice(6)]
        : field === 'provider'
          ? machine.provider
          : field === 'region'
            ? machine.region
            : field === 'tier'
              ? machine.tier
              : null;

    return value ? value : null;
}

const detailFields = computed(() => {
    const labelKeys = [
        ...new Set(
            machines.value.flatMap((machine) =>
                Object.keys(machine.labels ?? {}),
            ),
        ),
    ].sort((a, b) => a.localeCompare(b));

    return [
        'provider',
        'region',
        'tier',
        ...labelKeys.map((key) => `label:${key}`),
    ]
        .map((field) => ({
            field,
            values: [
                ...new Set(
                    machines.value
                        .map((machine) => detailValue(machine, field))
                        .filter((value): value is string => value !== null),
                ),
            ].sort((a, b) => a.localeCompare(b, undefined, { numeric: true })),
        }))
        .filter((entry) => entry.values.length > 0);
});

function toggleFilter(field: string, value: string): void {
    const next = { ...detailFilters.value };
    if (next[field] === value) delete next[field];
    else next[field] = value;
    detailFilters.value = next;
}

const filterCount = computed(() => Object.keys(detailFilters.value).length);
const filterLabel = computed(() => {
    const active = Object.values(detailFilters.value);
    if (active.length === 0) return trans('Filter');
    if (active.length === 1)
        return active[0] === 'Other' ? trans('Other') : active[0];

    return trans(':count filters', { count: String(active.length) });
});

const groupByLabel = computed(() =>
    groupBy.value === 'groups' ? trans('Groups') : fieldLabel(groupBy.value),
);

const sortOptions: { value: SortKey; label: string }[] = [
    { value: 'cpu', label: trans('CPU usage') },
    { value: 'memory', label: trans('Memory') },
    { value: 'name', label: trans('Name') },
];

const sortLabel = computed(
    () => sortOptions.find((option) => option.value === sort.value)?.label,
);

const comparators: Record<SortKey, (a: Machine, b: Machine) => number> = {
    cpu: (a, b) =>
        (b.cpu ?? -1) - (a.cpu ?? -1) || a.name.localeCompare(b.name),
    memory: (a, b) => (b.memoryMb ?? -1) - (a.memoryMb ?? -1),
    name: (a, b) => a.name.localeCompare(b.name),
};

function settingUp(machine: Machine): boolean {
    return !!machine.setup;
}

function sortKey(machine: Machine): number {
    if (settingUp(machine)) return 0;
    if (machine.connection === 'offline') return 2;

    return 1;
}

const visibleMachines = computed(() => {
    const query = search.value.trim().toLowerCase();

    return machines.value
        .filter(
            (machine) =>
                environment.value === 'all' ||
                machine.environment === environment.value,
        )
        .filter((machine) =>
            Object.entries(detailFilters.value).every(
                ([field, value]) => detailValue(machine, field) === value,
            ),
        )
        .filter(
            (machine) =>
                query === '' ||
                [
                    machine.name,
                    machine.ip,
                    machine.provider,
                    machine.region ?? '',
                    machine.tier ?? '',
                    ...Object.values(machine.labels ?? {}),
                ].some((value) => value.toLowerCase().includes(query)),
        )
        .toSorted(
            (a, b) => sortKey(a) - sortKey(b) || comparators[sort.value](a, b),
        );
});

const filtering = computed(
    () =>
        search.value.trim() !== '' ||
        environment.value !== 'all' ||
        filterCount.value > 0,
);

const storageKey = 'serveros:open-machine-groups';

function readOpen(): Set<number> {
    try {
        return new Set(JSON.parse(localStorage.getItem(storageKey) ?? '[]'));
    } catch {
        return new Set();
    }
}

const openGroups = ref<Set<number>>(
    typeof window === 'undefined' ? new Set() : readOpen(),
);

function toggleGroup(id: number): void {
    const next = new Set(openGroups.value);
    if (next.has(id)) next.delete(id);
    else next.add(id);
    openGroups.value = next;
    try {
        localStorage.setItem(storageKey, JSON.stringify([...next]));
    } catch {}
}

function hash(text: string): number {
    let h = 0;
    for (const char of text) h = (h * 31 + char.charCodeAt(0)) % 2_000_000_000;

    return h;
}

function hasBackup(machine: Machine): boolean {
    return !!machine.lastBackupAt;
}

function staleBackup(machine: Machine): boolean {
    if (!machine.lastBackupAt) return true;

    return Date.now() - Date.parse(machine.lastBackupAt) > 48 * 3600 * 1000;
}

function summarize(list: Machine[]): Summary {
    const live = list.filter((m) => reporting(m) && !settingUp(m));
    const cpus = live
        .map((m) => m.cpu)
        .filter((cpu): cpu is number => cpu !== null);
    const memories = live
        .map(memoryOf)
        .filter((m): m is NonNullable<typeof m> => m !== null);
    const disks = live
        .map(diskOf)
        .filter((d): d is NonNullable<typeof d> => d !== null);
    const memory = memories.length
        ? {
              used: memories.reduce((sum, m) => sum + m.used, 0),
              total: memories.reduce((sum, m) => sum + m.total, 0),
              percent: 0,
          }
        : null;
    const disk = disks.length
        ? {
              used: disks.reduce((sum, d) => sum + d.used, 0),
              total: disks.reduce((sum, d) => sum + d.total, 0),
              percent: 0,
          }
        : null;
    if (memory) memory.percent = (memory.used / memory.total) * 100;
    if (disk) disk.percent = (disk.used / disk.total) * 100;

    return {
        count: list.length,
        providers: new Set(list.map((m) => m.provider)).size,
        online: list.filter((m) => reporting(m)).length,
        cpu: cpus.length
            ? Math.round(cpus.reduce((sum, cpu) => sum + cpu, 0) / cpus.length)
            : null,
        memory,
        disk,
        services: list.reduce((sum, m) => sum + (m.services ?? 0), 0),
        never: list.filter((m) => !settingUp(m) && !hasBackup(m)).length,
    };
}

const totals = computed(() => summarize(visibleMachines.value));

const rows = computed<(GroupRow | MachineRowEntry)[]>(() => {
    const list = visibleMachines.value;
    const field = groupBy.value === 'groups' ? null : groupBy.value;
    const groups: {
        id: number;
        name: string;
        editable: boolean;
        machines: Machine[];
    }[] =
        field === null
            ? props.machineGroups.map((group) => ({
                  id: group.id,
                  name: group.name,
                  editable: true,
                  machines: list.filter((m) => m.groupId === group.id),
              }))
            : [
                  ...new Set(
                      list
                          .map((m) => detailValue(m, field))
                          .filter((v): v is string => v !== null),
                  ),
              ]
                  .sort((a, b) =>
                      a.localeCompare(b, undefined, { numeric: true }),
                  )
                  .map((name) => ({
                      id: -(hash(name) + 1),
                      name,
                      editable: false,
                      machines: list.filter(
                          (m) => detailValue(m, field) === name,
                      ),
                  }));
    const grouped = new Set(groups.flatMap((g) => g.machines));
    const out: (GroupRow | MachineRowEntry)[] = [];

    for (const group of groups) {
        if (group.machines.length === 0 && filtering.value) continue;
        const open =
            openGroups.value.has(group.id) ||
            (filtering.value && group.machines.length > 0);
        out.push({
            kind: 'group',
            key: `group-${group.id}`,
            ...group,
            open,
            summary: summarize(group.machines),
        });
        if (open) {
            for (const machine of group.machines) {
                out.push({
                    kind: 'machine',
                    key: `m-${machine.id ?? machine.name}`,
                    machine,
                    nested: true,
                });
            }
        }
    }

    for (const machine of list) {
        if (grouped.has(machine)) continue;
        out.push({
            kind: 'machine',
            key: `m-${machine.id ?? machine.name}`,
            machine,
            nested: false,
        });
    }

    return out;
});

const selectable = computed(() => visibleMachines.value.filter((m) => m.id));
const allSelected = computed({
    get: () =>
        selectable.value.length > 0 &&
        selectable.value.every((m) => selected.value.includes(m.name)),
    set: (value: boolean) => {
        selected.value = value ? selectable.value.map((m) => m.name) : [];
    },
});
const someSelected = computed(
    () => selected.value.length > 0 && !allSelected.value,
);

function isSelected(machine: Machine): boolean {
    return selected.value.includes(machine.name);
}

function setSelected(machine: Machine, value: boolean): void {
    selected.value = value
        ? [...new Set([...selected.value, machine.name])]
        : selected.value.filter((name) => name !== machine.name);
}

function open(machine: Machine, event?: MouseEvent): void {
    if (!machine.id) return;
    if (event && (event.metaKey || event.ctrlKey)) {
        window.open(machineUrl(machine), '_blank');

        return;
    }
    router.visit(machineUrl(machine));
}

function connectionLine(machine: Machine): { text: string; tone: string } {
    if (machine.maintenance)
        return { text: trans('In maintenance'), tone: 'text-[#f5a833]' };
    if (machine.connection === 'offline')
        return { text: trans('Daemon offline'), tone: 'text-[#f0695a]' };
    if (machine.connection === 'degraded')
        return { text: trans('Daemon delayed'), tone: 'text-[#f5a833]' };
    if (machine.connection === 'connecting')
        return { text: trans('Connecting daemon…'), tone: 'text-fg-faint' };

    return { text: machine.ip, tone: 'text-fg-faint' };
}

const setupLabels = {
    connecting: trans('Connecting'),
    discovering: trans('Discovering'),
    securing: trans('Securing'),
};

function logosOf(machine: Machine) {
    return (machine.inventory?.services ?? [])
        .filter((service) => !service.hidden)
        .map((service) => ({
            key: service.key,
            name: [service.label, service.app, service.name, service.found]
                .filter(Boolean)
                .join(' '),
            src: service.icon,
        }));
}

function pair(value: { used: number; total: number }) {
    return sizePair(value.used, value.total);
}

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

async function copyIp(machine: Machine): Promise<void> {
    try {
        await navigator.clipboard.writeText(machine.ip);
        toast.success(trans('IP address copied'));
    } catch {
        toast.error(trans('Couldn’t copy the IP address'));
    }
}

const deleting = ref<Machine | null>(null);
const confirmingDelete = ref(false);
const deleteProcessing = ref(false);

function askDelete(machine: Machine): void {
    deleting.value = machine;
    confirmingDelete.value = true;
}

function deleteMachine(): void {
    const machine = deleting.value;
    if (!machine?.id || deleteProcessing.value) return;

    router.delete(
        onWorkspace(
            machinesRoutes.destroy({
                workspace: slug.value,
                machine: machine.id,
            }),
        ).url,
        {
            preserveScroll: true,
            onStart: () => (deleteProcessing.value = true),
            onFinish: () => (deleteProcessing.value = false),
            onSuccess: () => {
                confirmingDelete.value = false;
                selected.value = selected.value.filter(
                    (name) => name !== machine.name,
                );
            },
        },
    );
}

const detailId = ref<number | null>(null);
const detailOpen = ref(false);
const detail = computed(
    () =>
        machines.value.find((machine) => machine.id === detailId.value) ?? null,
);

function showDetails(machine: Machine): void {
    detailId.value = machine.id ?? null;
    detailOpen.value = true;
}

const clearOpen = ref(false);
const clearTargets = computed(() =>
    machines.value
        .filter(
            (machine) => machine.id && selected.value.includes(machine.name),
        )
        .map((machine) => ({ id: machine.id!, name: machine.name })),
);

const detailsOpen = ref(false);
const detailsSaving = ref(false);
const detailsErrors = ref<Record<string, string>>({});
const detailsForm = ref({
    provider: '',
    region: '',
    tier: '',
    labels: [] as { key: string; value: string }[],
    removeLabels: '',
});

function editDetails(): void {
    detailsForm.value = {
        provider: '',
        region: '',
        tier: '',
        labels: [{ key: '', value: '' }],
        removeLabels: '',
    };
    detailsErrors.value = {};
    detailsOpen.value = true;
}

function saveDetails(): void {
    const form = detailsForm.value;
    const filled = (value: string) => (value.trim() === '' ? {} : null);
    const payload = {
        machines: clearTargets.value.map((machine) => machine.id),
        labels: form.labels.filter(
            (label) => label.key.trim() !== '' && label.value.trim() !== '',
        ),
        remove_labels: form.removeLabels
            .split(',')
            .map((key) => key.trim())
            .filter((key) => key !== ''),
        ...(filled(form.provider) ?? { provider: form.provider.trim() }),
        ...(filled(form.region) ?? { region: form.region.trim() }),
        ...(filled(form.tier) ?? { tier: form.tier.trim() }),
    };
    router.post(onWorkspace(metadataRoutes.bulk(slug.value)).url, payload, {
        preserveScroll: true,
        preserveState: true,
        onStart: () => (detailsSaving.value = true),
        onFinish: () => (detailsSaving.value = false),
        onError: (bag) => (detailsErrors.value = bag),
        onSuccess: () => {
            detailsOpen.value = false;
            selected.value = [];
        },
    });
}

const groupDialogOpen = ref(false);
const editingGroup = ref<MachineGroup | null>(null);
const groupInitialName = ref('');
const groupInitialSelected = ref<number[]>([]);
const groupSaving = ref(false);
const groupErrors = ref<Record<string, string>>({});
const groupPolicy = ref<number | null>(null);
const groupPolicyOptions = computed<SelectMenuOption<number | null>[]>(() => [
    {
        value: null,
        label: trans('The default, :name', {
            name:
                props.machinePolicies.find((p) => p.isDefault)?.name ??
                trans('Default'),
        }),
    },
    ...props.machinePolicies
        .filter((p) => !p.isDefault)
        .map((option) => ({ value: option.id, label: option.name })),
]);

const groupItems = computed<GroupDialogItem<number>[]>(() => {
    const names = new Map(
        props.machineGroups.map((group) => [group.id, group.name]),
    );

    return machines.value
        .filter((machine) => machine.id)
        .map((machine) => ({
            id: machine.id!,
            label: machine.name,
            detail: `${machine.ip} · ${machine.provider}`,
            groupName:
                machine.groupId && machine.groupId !== editingGroup.value?.id
                    ? (names.get(machine.groupId) ?? null)
                    : null,
        }));
});

function newGroup(): void {
    editingGroup.value = null;
    groupInitialName.value = '';
    groupPolicy.value = null;
    groupInitialSelected.value = clearTargets.value.map(
        (machine) => machine.id,
    );
    groupErrors.value = {};
    groupDialogOpen.value = true;
}

function editGroup(id: number): void {
    const group = props.machineGroups.find((g) => g.id === id);
    if (!group) return;
    editingGroup.value = group;
    groupInitialName.value = group.name;
    groupPolicy.value = group.policyId ?? null;
    groupInitialSelected.value = machines.value
        .filter((machine) => machine.id && machine.groupId === group.id)
        .map((machine) => machine.id!);
    groupErrors.value = {};
    groupDialogOpen.value = true;
}

function saveGroup({ name, ids }: { name: string; ids: number[] }): void {
    const group = editingGroup.value;
    const payload = { name, machines: ids, policy: groupPolicy.value };
    const options = {
        preserveScroll: true,
        preserveState: true,
        onStart: () => (groupSaving.value = true),
        onFinish: () => (groupSaving.value = false),
        onError: (bag: Record<string, string>) => (groupErrors.value = bag),
        onSuccess: () => {
            groupDialogOpen.value = false;
            selected.value = [];
        },
    };

    if (group) {
        router.put(
            onWorkspace(
                machineGroupRoutes.update({
                    workspace: slug.value,
                    group: group.id,
                }),
            ).url,
            payload,
            options,
        );
    } else {
        router.post(
            onWorkspace(machineGroupRoutes.store(slug.value)).url,
            payload,
            options,
        );
    }
}

function deleteGroup(id: number): void {
    router.delete(
        onWorkspace(
            machineGroupRoutes.destroy({ workspace: slug.value, group: id }),
        ).url,
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => (groupDialogOpen.value = false),
        },
    );
}

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

let setupTimer: ReturnType<typeof setTimeout> | undefined;
let clock: ReturnType<typeof setInterval> | undefined;
const whenVisible = useWhenVisible();

function pollSetup(): void {
    clearTimeout(setupTimer);
    const busy = props.machines.some(
        (m) => m.setup || m.connection === 'connecting',
    );
    setupTimer = setTimeout(
        () =>
            whenVisible(() =>
                router.reload({
                    only: ['machines'],
                    onSuccess: () => (updatedAt.value = Date.now()),
                    onFinish: pollSetup,
                }),
            ),
        busy ? 3000 : 15000,
    );
}

function connect(): void {
    (connectButton.value ?? firstConnectButton.value)?.connect();
}

onMounted(() => {
    window.addEventListener('keydown', onKeydown);
    pollSetup();
    clock = setInterval(() => (now.value = Date.now()), 1000);

    const url = new URL(window.location.href);
    if (url.searchParams.get('connect') === '1') {
        url.searchParams.delete('connect');
        window.history.replaceState(window.history.state, '', url);
        connect();
    }
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeydown);
    clearTimeout(setupTimer);
    clearInterval(clock);
});

function formatBytes(bytes: number | undefined): string {
    if (!bytes || bytes < 0) return trans('Unknown');

    return `${fixed(bytes / 1024 ** 3)} GiB`;
}
</script>

<template>
    <Head :title="$t('Machines')" />

    <DashHeader :crumbs="[{ label: $t('Machines') }]">
        <template v-if="machines.length" #actions>
            <DashButton
                icon="monitors-fill"
                :label="$t('Status page')"
                :href="statusPageUrl"
                :title="
                    fleetStatus.live
                        ? $t('Status page for every machine: live')
                        : $t('Status page for every machine: not set up yet')
                "
            />
            <ConnectMachineButton ref="connectButton" />
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
            {{ $t('No machines yet') }}
        </h2>
        <p class="mt-1 max-w-sm text-[13px]/5 text-pretty text-fg-tertiary">
            {{
                $t(
                    'Connect a server from any provider. One that already has things running is fine, nothing on it changes.',
                )
            }}
        </p>
        <ConnectMachineButton ref="firstConnectButton" class="mt-5" />
    </div>

    <template v-else>
        <DashTabs>
            <DashTab
                v-for="tab in tabs"
                :key="tab.value"
                :label="tab.label"
                :icon="environmentIcons[tab.value]"
                :active="environment === tab.value"
                @click="environment = tab.value"
            >
                <template #suffix>
                    <span class="ml-0.5 text-fg-faint tabular-nums">{{
                        tab.count
                    }}</span>
                </template>
            </DashTab>
        </DashTabs>

        <div class="flex min-h-14 flex-wrap items-center gap-2 px-5 py-3">
            <SearchWell
                ref="searchWell"
                v-model="search"
                :placeholder="$t('Search machines')"
                :width="260"
                shortcut="/"
                class="max-sm:w-full!"
            />

            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <ToolbarButton
                        icon="filter-fill"
                        :label="filterLabel"
                        :active="filterCount > 0"
                    />
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    class="ds-modal max-h-[60svh] min-w-48 overflow-y-auto font-ui"
                    align="start"
                >
                    <DropdownMenuItem
                        :disabled="filterCount === 0"
                        @select="detailFilters = {}"
                    >
                        {{ $t('All machines') }}
                        <Check
                            v-if="filterCount === 0"
                            class="ml-auto size-3.5"
                        />
                    </DropdownMenuItem>
                    <template v-for="entry in detailFields" :key="entry.field">
                        <DropdownMenuSeparator />
                        <DropdownMenuLabel
                            class="text-[11.5px]/4 font-medium text-fg-muted"
                        >
                            {{ fieldLabel(entry.field) }}
                        </DropdownMenuLabel>
                        <DropdownMenuItem
                            v-for="value in entry.values"
                            :key="value"
                            @select.prevent="toggleFilter(entry.field, value)"
                        >
                            {{ value === 'Other' ? $t('Other') : value }}
                            <Check
                                v-if="detailFilters[entry.field] === value"
                                class="ml-auto size-3.5"
                            />
                        </DropdownMenuItem>
                    </template>
                </DropdownMenuContent>
            </DropdownMenu>

            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <ToolbarButton
                        icon="folder-fill"
                        :label="groupByLabel"
                        :active="groupBy !== 'groups'"
                    />
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    class="ds-modal min-w-44 font-ui"
                    align="start"
                >
                    <DropdownMenuLabel
                        class="text-[11.5px]/4 font-medium text-fg-muted"
                    >
                        {{ $t('Group by') }}
                    </DropdownMenuLabel>
                    <DropdownMenuItem @select="groupBy = 'groups'">
                        {{ $t('Machine groups') }}
                        <Check
                            v-if="groupBy === 'groups'"
                            class="ml-auto size-3.5"
                        />
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        v-for="entry in detailFields"
                        :key="entry.field"
                        @select="groupBy = entry.field"
                    >
                        {{ fieldLabel(entry.field) }}
                        <Check
                            v-if="groupBy === entry.field"
                            class="ml-auto size-3.5"
                        />
                    </DropdownMenuItem>
                    <template v-if="canManage">
                        <DropdownMenuSeparator />
                        <DropdownMenuItem @select="newGroup">
                            <Plus class="size-4" :stroke-width="1.75" />
                            {{
                                selected.length
                                    ? $t('Group :selectedCount selected…', {
                                          selectedCount: String(
                                              selected.length,
                                          ),
                                      })
                                    : $t('New group…')
                            }}
                        </DropdownMenuItem>
                    </template>
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
                        v-for="option in sortOptions"
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

            <div
                v-if="canManage && clearTargets.length"
                class="flex items-center gap-2 pl-2"
            >
                <span class="text-[12px]/4 text-fg-muted tabular-nums">{{
                    $t(':count selected', {
                        count: String(clearTargets.length),
                    })
                }}</span>
                <ToolbarButton
                    icon="folder-fill"
                    :label="$t('Group')"
                    @click="newGroup"
                />
                <ToolbarButton
                    icon="sliders"
                    :label="$t('Edit details')"
                    @click="editDetails"
                />
                <ToolbarButton
                    icon="archive"
                    :label="$t('Clear data')"
                    @click="clearOpen = true"
                />
                <button
                    type="button"
                    class="flex size-7 cursor-pointer items-center justify-center rounded-md text-fg-muted hover:bg-surface-hover hover:text-fg-primary focus-visible:outline-2 focus-visible:outline-(--ds-ring)"
                    :aria-label="$t('Clear selection')"
                    @click="selected = []"
                >
                    <X class="size-3.5" :stroke-width="1.75" />
                </button>
            </div>

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

        <DashTable class="border-t-[0.5px] border-edge-tabs">
            <template #head>
                <DashTh :width="180" first>
                    <template #lead>
                        <span class="-mr-1 flex items-center gap-2">
                            <DashCheckbox
                                v-model="allSelected"
                                :indeterminate="someSelected"
                                :label="$t('Select all')"
                            />
                            <FilledIcon
                                name="machines-fill"
                                class="text-fg-muted"
                            />
                        </span>
                    </template>
                    {{ $t('Machine') }}
                </DashTh>
                <DashTh icon="branch-fill" :width="132">{{
                    $t('Environment')
                }}</DashTh>
                <DashTh icon="globe-fill" :width="140">{{
                    $t('Provider')
                }}</DashTh>
                <DashTh icon="monitors-fill" :width="104">{{
                    $t('CPU')
                }}</DashTh>
                <DashTh icon="package-fill" :width="150">{{
                    $t('Memory')
                }}</DashTh>
                <DashTh icon="database-fill" :width="158">{{
                    $t('Disk')
                }}</DashTh>
                <DashTh icon="layers-fill" :width="104">{{
                    $t('Services')
                }}</DashTh>
                <DashTh icon="upload-fill" :width="126">{{
                    $t('Last backup')
                }}</DashTh>
                <DashTh fill class="border-r-0!">
                    <span class="sr-only">{{ $t('Actions') }}</span>
                </DashTh>
            </template>

            <template v-for="row in rows" :key="row.key">
                <DashTr
                    v-if="row.kind === 'group'"
                    clickable
                    :aria-expanded="row.open"
                    @click="toggleGroup(row.id)"
                >
                    <DashTd first :height="51.5">
                        <span class="flex min-w-0 items-center gap-2">
                            <FilledIcon
                                :name="
                                    row.open ? 'chevron-down' : 'chevron-right'
                                "
                                :size="14"
                                class="text-fg-muted"
                            />
                            <FilledIcon
                                name="folder-fill"
                                class="text-fg-muted"
                            />
                            <span class="flex min-w-0 flex-col">
                                <span
                                    class="truncate font-medium tracking-[-0.01em] text-fg-primary"
                                    >{{ row.name }}</span
                                >
                                <span class="text-[11.5px]/4 text-fg-faint">{{
                                    row.machines.length === 1
                                        ? $t('1 machine')
                                        : $t(':count machines', {
                                              count: String(
                                                  row.machines.length,
                                              ),
                                          })
                                }}</span>
                            </span>
                        </span>
                    </DashTd>
                    <DashTd :height="51.5">
                        <span
                            class="size-1.5 shrink-0 rounded-full"
                            :class="
                                row.summary.count === 0
                                    ? 'bg-fg-faint'
                                    : row.summary.online === row.summary.count
                                      ? 'bg-status-live'
                                      : row.summary.online === 0
                                        ? 'bg-status-down'
                                        : 'bg-[#f5a833]'
                            "
                        />
                        {{
                            row.summary.count === 0
                                ? $t('Empty')
                                : row.summary.online === row.summary.count
                                  ? $t('All online')
                                  : $t(':online of :total online', {
                                        online: String(row.summary.online),
                                        total: String(row.summary.count),
                                    })
                        }}
                    </DashTd>
                    <DashTd :height="51.5">
                        <span class="text-fg-muted">{{
                            row.summary.providers === 1
                                ? $t('1 provider')
                                : $t(':count providers', {
                                      count: String(row.summary.providers),
                                  })
                        }}</span>
                    </DashTd>
                    <DashTd :height="51.5">
                        <UsageMeter
                            v-if="row.summary.cpu !== null"
                            :percent="row.summary.cpu"
                            quiet
                            >{{ row.summary.cpu }}%</UsageMeter
                        >
                        <span v-else class="text-fg-faint">–</span>
                    </DashTd>
                    <DashTd :height="51.5">
                        <UsageMeter
                            v-if="row.summary.memory"
                            :percent="row.summary.memory.percent"
                            quiet
                            >{{ pair(row.summary.memory).used
                            }}<span class="text-fg-faint">
                                / {{ pair(row.summary.memory).total }}</span
                            ></UsageMeter
                        >
                        <span v-else class="text-fg-faint">–</span>
                    </DashTd>
                    <DashTd :height="51.5">
                        <UsageMeter
                            v-if="row.summary.disk"
                            :percent="row.summary.disk.percent"
                            quiet
                            >{{ pair(row.summary.disk).used
                            }}<span class="text-fg-faint">
                                / {{ pair(row.summary.disk).total }}</span
                            ></UsageMeter
                        >
                        <span v-else class="text-fg-faint">–</span>
                    </DashTd>
                    <DashTd :height="51.5">
                        <span class="text-fg-muted tabular-nums">{{
                            row.summary.services
                        }}</span>
                    </DashTd>
                    <DashTd :height="51.5">
                        <span v-if="row.summary.never" class="text-[#f5a833]">{{
                            $t(':count never', {
                                count: String(row.summary.never),
                            })
                        }}</span>
                        <span v-else class="text-fg-muted">{{
                            row.summary.count ? $t('All backed up') : '–'
                        }}</span>
                    </DashTd>
                    <DashTd :height="51.5" class="border-r-0!">
                        <span class="ml-auto flex items-center">
                            <DropdownMenu v-if="canManage && row.editable">
                                <DropdownMenuTrigger as-child>
                                    <button
                                        type="button"
                                        class="flex size-7 cursor-pointer items-center justify-center rounded-md text-fg-muted opacity-0 group-hover/row:opacity-100 hover:bg-surface-raised hover:text-fg-primary focus-visible:opacity-100 focus-visible:outline-2 focus-visible:outline-(--ds-ring) data-[state=open]:opacity-100"
                                        :aria-label="
                                            $t('Actions for :name', {
                                                name: row.name,
                                            })
                                        "
                                        @click.stop
                                    >
                                        <Ellipsis class="size-4" />
                                    </button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent
                                    class="ds-modal min-w-44 font-ui"
                                    align="end"
                                    @click.stop
                                >
                                    <DropdownMenuItem
                                        @select="editGroup(row.id)"
                                    >
                                        {{ $t('Edit group') }}
                                    </DropdownMenuItem>
                                    <DropdownMenuSeparator />
                                    <DropdownMenuItem
                                        class="text-(--ds-red) focus:bg-(--ds-red)/10 focus:text-(--ds-red)"
                                        @select="deleteGroup(row.id)"
                                    >
                                        {{ $t('Delete group') }}
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </span>
                    </DashTd>
                </DashTr>

                <DashTr
                    v-else
                    :selected="isSelected(row.machine)"
                    :clickable="!!row.machine.id"
                    @click="open(row.machine, $event)"
                >
                    <DashTd first :height="51.5">
                        <template #lead>
                            <span
                                v-if="row.nested"
                                class="-mr-1 w-3"
                                aria-hidden="true"
                            />
                            <DashCheckbox
                                :model-value="isSelected(row.machine)"
                                :label="
                                    $t('Select :name', {
                                        name: row.machine.name,
                                    })
                                "
                                class="-mr-1"
                                @update:model-value="
                                    setSelected(row.machine, $event)
                                "
                            />
                        </template>
                        <span class="flex min-w-0 flex-col gap-px">
                            <Link
                                v-if="row.machine.id"
                                :href="machineUrl(row.machine)"
                                class="truncate font-medium tracking-[-0.01em] focus-visible:underline focus-visible:outline-none"
                                :class="
                                    row.machine.connection === 'offline'
                                        ? 'text-fg-tertiary'
                                        : 'text-fg-primary'
                                "
                                @click.stop
                                >{{ row.machine.name }}</Link
                            >
                            <span
                                v-else
                                class="truncate font-medium text-fg-primary"
                                >{{ row.machine.name }}</span
                            >
                            <span
                                class="font-ui-mono text-[11.5px]/4"
                                :class="connectionLine(row.machine).tone"
                                >{{ connectionLine(row.machine).text }}</span
                            >
                        </span>
                    </DashTd>
                    <DashTd :height="51.5">
                        <StatusBadge
                            v-if="row.machine.setup"
                            status="deploying"
                            :label="setupLabels[row.machine.setup]"
                        />
                        <StatusBadge
                            v-else-if="row.machine.setupFindings"
                            status="pending"
                            :label="
                                row.machine.setupFindings === 1
                                    ? $t('1 to check')
                                    : $t(':count to check', {
                                          count: String(
                                              row.machine.setupFindings,
                                          ),
                                      })
                            "
                        />
                        <template v-else>
                            <span
                                class="size-1.5 shrink-0 rounded-full"
                                :class="
                                    environmentDots[row.machine.environment]
                                "
                            />
                            {{ environmentLabels[row.machine.environment] }}
                        </template>
                    </DashTd>
                    <DashTd :height="51.5">
                        <BrandLogo :name="row.machine.provider" />
                        <span class="truncate">{{
                            row.machine.provider === 'Other'
                                ? $t('Other')
                                : row.machine.provider
                        }}</span>
                    </DashTd>
                    <DashTd :height="51.5">
                        <UsageMeter
                            v-if="
                                reporting(row.machine) &&
                                !row.machine.setup &&
                                row.machine.cpu !== null
                            "
                            :percent="row.machine.cpu"
                            >{{ row.machine.cpu }}%</UsageMeter
                        >
                        <span v-else class="text-fg-faint">–</span>
                    </DashTd>
                    <DashTd :height="51.5">
                        <UsageMeter
                            v-if="
                                reporting(row.machine) &&
                                !row.machine.setup &&
                                memoryOf(row.machine)
                            "
                            :percent="memoryOf(row.machine)!.percent"
                            >{{ pair(memoryOf(row.machine)!).used
                            }}<span class="text-fg-faint">
                                / {{ pair(memoryOf(row.machine)!).total }}</span
                            ></UsageMeter
                        >
                        <span
                            v-else-if="
                                reporting(row.machine) &&
                                !row.machine.setup &&
                                row.machine.memoryMb
                            "
                            class="tabular-nums"
                            >{{ row.machine.memory }}</span
                        >
                        <span v-else class="text-fg-faint">–</span>
                    </DashTd>
                    <DashTd :height="51.5">
                        <UsageMeter
                            v-if="
                                reporting(row.machine) &&
                                !row.machine.setup &&
                                diskOf(row.machine)
                            "
                            :percent="diskOf(row.machine)!.percent"
                            >{{ pair(diskOf(row.machine)!).used
                            }}<span class="text-fg-faint">
                                / {{ pair(diskOf(row.machine)!).total }}</span
                            ></UsageMeter
                        >
                        <UsageMeter
                            v-else-if="
                                reporting(row.machine) &&
                                !row.machine.setup &&
                                typeof row.machine.diskPercent === 'number'
                            "
                            :percent="row.machine.diskPercent"
                            >{{ row.machine.diskPercent }}%</UsageMeter
                        >
                        <span v-else class="text-fg-faint">–</span>
                    </DashTd>
                    <DashTd :height="51.5">
                        <span v-if="row.machine.setup" class="text-fg-faint"
                            >–</span
                        >
                        <MachineLogoStack
                            v-else-if="logosOf(row.machine).length"
                            :logos="logosOf(row.machine)"
                        />
                        <span
                            v-else
                            :class="
                                row.machine.services
                                    ? 'tabular-nums'
                                    : 'text-fg-faint'
                            "
                            >{{ row.machine.services || '–' }}</span
                        >
                    </DashTd>
                    <DashTd :height="51.5">
                        <span v-if="row.machine.setup" class="text-fg-faint"
                            >–</span
                        >
                        <template v-else>
                            <span
                                class="size-1.5 shrink-0 rounded-full"
                                :class="
                                    staleBackup(row.machine)
                                        ? 'bg-[#f5a833]'
                                        : 'bg-status-live'
                                "
                            />
                            <span
                                :class="
                                    staleBackup(row.machine)
                                        ? 'text-[#f5a833]'
                                        : ''
                                "
                                :title="
                                    row.machine.lastBackupAt
                                        ? new Date(
                                              row.machine.lastBackupAt,
                                          ).toLocaleString()
                                        : undefined
                                "
                                >{{ row.machine.lastBackup }}</span
                            >
                        </template>
                    </DashTd>
                    <DashTd :height="51.5" class="border-r-0!">
                        <span class="ml-auto flex items-center gap-0.5">
                            <DropdownMenu v-if="row.machine.id">
                                <DropdownMenuTrigger as-child>
                                    <button
                                        type="button"
                                        class="flex size-7 cursor-pointer items-center justify-center rounded-md text-fg-muted opacity-0 group-hover/row:opacity-100 hover:bg-surface-raised hover:text-fg-primary focus-visible:opacity-100 focus-visible:outline-2 focus-visible:outline-(--ds-ring) data-[state=open]:opacity-100"
                                        :aria-label="
                                            $t('Actions for :name', {
                                                name: row.machine.name,
                                            })
                                        "
                                        @click.stop
                                    >
                                        <Ellipsis class="size-4" />
                                    </button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent
                                    class="ds-modal min-w-48 font-ui"
                                    align="end"
                                    @click.stop
                                >
                                    <DropdownMenuItem
                                        @select="open(row.machine)"
                                    >
                                        <FilledIcon name="open-fill" />
                                        {{ $t('Open machine') }}
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        @select="showDetails(row.machine)"
                                    >
                                        <FilledIcon name="search-fill" />
                                        {{ $t('Discovery details') }}
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        @select="
                                            router.visit(
                                                machineUrl(
                                                    row.machine,
                                                    'terminal',
                                                ),
                                            )
                                        "
                                    >
                                        <FilledIcon name="terminal-fill" />
                                        {{ $t('Terminal') }}
                                    </DropdownMenuItem>
                                    <DropdownMenuSeparator />
                                    <DropdownMenuItem
                                        @select="copyIp(row.machine)"
                                    >
                                        <FilledIcon name="copy" />
                                        {{ $t('Copy IP address') }}
                                    </DropdownMenuItem>
                                    <template v-if="canManage">
                                        <DropdownMenuSeparator />
                                        <DropdownMenuItem
                                            class="text-(--ds-red) focus:bg-(--ds-red)/10 focus:text-(--ds-red)"
                                            @select="askDelete(row.machine)"
                                        >
                                            {{ $t('Delete machine') }}
                                        </DropdownMenuItem>
                                    </template>
                                </DropdownMenuContent>
                            </DropdownMenu>
                            <FilledIcon
                                name="chevron-right"
                                :size="14"
                                class="text-fg-secondary opacity-0 group-hover/row:opacity-100"
                            />
                        </span>
                    </DashTd>
                </DashTr>
            </template>

            <tr v-if="visibleMachines.length === 0">
                <td
                    colspan="9"
                    class="h-26 border-b-[0.5px] border-b-edge text-center text-[13px]/5 text-fg-muted"
                >
                    {{ $t('No machines match your filters.') }}
                </td>
            </tr>

            <tr v-else class="bg-[#f7f7f7] dark:bg-[#151515]">
                <DashTd first :height="43.5">
                    <span class="font-medium tracking-[-0.01em]">{{
                        totals.count === 1
                            ? $t('1 machine')
                            : $t(':count machines', {
                                  count: String(totals.count),
                              })
                    }}</span>
                </DashTd>
                <DashTd :height="43.5" />
                <DashTd :height="43.5">
                    <span class="text-fg-muted">{{
                        totals.providers === 1
                            ? $t('1 provider')
                            : $t(':count providers', {
                                  count: String(totals.providers),
                              })
                    }}</span>
                </DashTd>
                <DashTd :height="43.5">
                    <UsageMeter
                        v-if="totals.cpu !== null"
                        :percent="totals.cpu"
                        quiet
                        >{{ totals.cpu }}%</UsageMeter
                    >
                </DashTd>
                <DashTd :height="43.5">
                    <UsageMeter
                        v-if="totals.memory"
                        :percent="totals.memory.percent"
                        quiet
                        >{{ pair(totals.memory).used
                        }}<span class="text-fg-faint">
                            / {{ pair(totals.memory).total }}</span
                        ></UsageMeter
                    >
                </DashTd>
                <DashTd :height="43.5">
                    <UsageMeter
                        v-if="totals.disk"
                        :percent="totals.disk.percent"
                        quiet
                        >{{ pair(totals.disk).used
                        }}<span class="text-fg-faint">
                            / {{ pair(totals.disk).total }}</span
                        ></UsageMeter
                    >
                </DashTd>
                <DashTd :height="43.5">
                    <span class="text-fg-muted tabular-nums">{{
                        totals.services
                    }}</span>
                </DashTd>
                <DashTd :height="43.5">
                    <span v-if="totals.never" class="text-[#f5a833]">{{
                        $t(':count never', { count: String(totals.never) })
                    }}</span>
                    <span v-else class="text-fg-muted">{{
                        $t('All backed up')
                    }}</span>
                </DashTd>
                <DashTd :height="43.5" class="border-r-0!" />
            </tr>
        </DashTable>
    </template>

    <GroupDialog
        v-model:open="groupDialogOpen"
        noun="machine"
        :items="groupItems"
        :editing="!!editingGroup"
        :initial-name="groupInitialName"
        :initial-selected="groupInitialSelected"
        :processing="groupSaving"
        :errors="groupErrors"
        @save="saveGroup"
        @delete="editingGroup && deleteGroup(editingGroup.id)"
    >
        <template #extra>
            <div class="flex flex-col gap-1.5">
                <label
                    for="group-policy"
                    class="text-[13px]/5 font-medium text-(--ds-text-muted)"
                >
                    {{ $t('Policy') }}
                </label>
                <SelectMenu
                    id="group-policy"
                    v-model="groupPolicy"
                    :options="groupPolicyOptions"
                    class="ds-raised-button h-9 w-full text-[13px]"
                />
                <span class="text-[12.5px]/4.5 text-(--ds-text-muted)">
                    {{
                        $t(
                            'What its machines collect and send, unless one picked its own.',
                        )
                    }}
                </span>
                <InputError :message="groupErrors.policy" />
            </div>
        </template>
    </GroupDialog>

    <Dialog v-model:open="detailsOpen">
        <DialogContent
            class="workspace-panel ds-modal gap-0 overflow-hidden p-0 font-ui text-foreground sm:max-w-lg"
        >
            <form class="flex min-w-0 flex-col" @submit.prevent="saveDetails">
                <div class="flex flex-col gap-1 px-5 pt-5">
                    <DialogTitle class="text-base/6 font-semibold">
                        {{
                            clearTargets.length === 1
                                ? $t('Details of 1 machine')
                                : $t('Details of :count machines', {
                                      count: String(clearTargets.length),
                                  })
                        }}
                    </DialogTitle>
                    <DialogDescription
                        class="text-[13px]/5 text-(--ds-text-muted)"
                    >
                        {{
                            $t(
                                'What you fill in is set on every one of them. What you leave empty stays as each has it.',
                            )
                        }}
                    </DialogDescription>
                </div>
                <div class="flex flex-col gap-4 p-5">
                    <div class="grid gap-2 sm:grid-cols-3">
                        <label class="flex flex-col gap-1">
                            <span
                                class="text-[12px]/4 text-(--ds-text-muted)"
                                >{{ $t('Provider') }}</span
                            >
                            <Input
                                v-model="detailsForm.provider"
                                maxlength="60"
                                :placeholder="$t('OVH')"
                            />
                        </label>
                        <label class="flex flex-col gap-1">
                            <span
                                class="text-[12px]/4 text-(--ds-text-muted)"
                                >{{ $t('Region') }}</span
                            >
                            <Input
                                v-model="detailsForm.region"
                                maxlength="60"
                                :placeholder="$t('lon1')"
                            />
                        </label>
                        <label class="flex flex-col gap-1">
                            <span
                                class="text-[12px]/4 text-(--ds-text-muted)"
                                >{{ $t('Tier') }}</span
                            >
                            <Input
                                v-model="detailsForm.tier"
                                maxlength="60"
                                :placeholder="$t('performance')"
                            />
                        </label>
                    </div>
                    <div class="flex flex-col gap-2">
                        <span
                            class="text-[13px]/5 font-medium text-(--ds-text-muted)"
                            >{{ $t('Add labels') }}</span
                        >
                        <div
                            v-for="(label, index) in detailsForm.labels"
                            :key="index"
                            class="flex items-center gap-2"
                        >
                            <Input
                                v-model="label.key"
                                class="w-36 shrink-0"
                                maxlength="40"
                                :placeholder="$t('customer')"
                                :aria-label="$t('Label name')"
                            />
                            <Input
                                v-model="label.value"
                                class="grow"
                                maxlength="100"
                                :placeholder="$t('acme')"
                                :aria-label="$t('Label value')"
                            />
                            <AppButton
                                size="sm"
                                variant="quiet"
                                :aria-label="$t('Remove')"
                                @click="detailsForm.labels.splice(index, 1)"
                            >
                                <X class="size-3.5" />
                            </AppButton>
                        </div>
                        <div>
                            <AppButton
                                size="sm"
                                @click="
                                    detailsForm.labels.push({
                                        key: '',
                                        value: '',
                                    })
                                "
                            >
                                <Plus class="size-3.5" :stroke-width="2" />
                                {{ $t('Another label') }}
                            </AppButton>
                        </div>
                    </div>
                    <label class="flex flex-col gap-1">
                        <span
                            class="text-[13px]/5 font-medium text-(--ds-text-muted)"
                            >{{ $t('Take labels off') }}</span
                        >
                        <Input
                            v-model="detailsForm.removeLabels"
                            :placeholder="$t('customer, rack')"
                        />
                    </label>
                    <InputError :message="Object.values(detailsErrors)[0]" />
                </div>
                <div
                    class="flex items-center justify-end gap-2 border-t border-(--ds-border) px-4 py-3"
                >
                    <AppButton
                        size="sm"
                        variant="quiet"
                        @click="detailsOpen = false"
                    >
                        {{ $t('Cancel') }}
                    </AppButton>
                    <SubmitButton
                        :label="$t('Save')"
                        :busy-label="$t('Saving…')"
                        :processing="detailsSaving"
                    />
                </div>
            </form>
        </DialogContent>
    </Dialog>

    <ClearDataDialog
        v-model:open="clearOpen"
        :machines="clearTargets"
        :kinds="[]"
        @done="selected = []"
    />

    <Dialog v-model:open="confirmingDelete">
        <DialogContent
            class="workspace-panel ds-modal font-ui text-foreground sm:max-w-md"
        >
            <DialogHeader>
                <DialogTitle>{{
                    $t('Delete :name?', { name: String(deleting?.name ?? '') })
                }}</DialogTitle>
                <DialogDescription>
                    {{
                        $t(
                            'It disappears from ServerOS along with its deploy history. Nothing on the machine itself is touched, so you can connect it again later.',
                        )
                    }}
                </DialogDescription>
            </DialogHeader>
            <form
                class="flex justify-end gap-2"
                @submit.prevent="deleteMachine"
            >
                <AppButton size="sm" @click="confirmingDelete = false">
                    {{ $t('Cancel') }}
                </AppButton>
                <DangerButton
                    :label="$t('Delete machine')"
                    :processing="deleteProcessing"
                />
            </form>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="detailOpen">
        <DialogContent
            v-if="detail"
            class="workspace-panel ds-form ds-modal max-h-[85vh] overflow-y-auto font-ui text-foreground sm:max-w-2xl"
        >
            <DialogHeader>
                <DialogTitle>{{ detail.name }}</DialogTitle>
                <DialogDescription
                    >{{
                        $t('Daemon :connection', {
                            connection: String(detail.connection ?? ''),
                        })
                    }}<span v-if="detail.daemonVersion">
                        · {{ detail.daemonVersion }}</span
                    ><span v-if="detail.lastSeen">
                        {{
                            ' ' +
                            $t('· last seen :lastSeen', {
                                lastSeen: detail.lastSeen,
                            })
                        }}</span
                    ></DialogDescription
                >
            </DialogHeader>
            <section v-if="detail.facts" class="space-y-3 text-sm">
                <h3 class="font-medium text-(--ds-text)">
                    {{ $t('VPS details') }}
                </h3>
                <dl
                    class="grid grid-cols-2 gap-x-4 gap-y-2 rounded-lg border border-(--ds-border-strong) p-3 sm:grid-cols-3"
                >
                    <div>
                        <dt class="text-(--ds-text-muted)">
                            {{ $t('Provider') }}
                        </dt>
                        <dd>
                            {{
                                detail.facts.hosting?.provider ?? $t('Unknown')
                            }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-(--ds-text-muted)">
                            {{ $t('Region') }}
                        </dt>
                        <dd>
                            {{ detail.facts.hosting?.region ?? $t('Unknown') }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-(--ds-text-muted)">
                            {{ $t('Zone / datacenter') }}
                        </dt>
                        <dd>
                            {{ detail.facts.hosting?.zone ?? $t('Unknown') }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-(--ds-text-muted)">
                            {{ $t('VPS plan') }}
                        </dt>
                        <dd>
                            {{
                                detail.facts.hosting?.instance_type ??
                                $t('Unknown')
                            }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-(--ds-text-muted)">
                            {{ $t('Operating system') }}
                        </dt>
                        <dd>
                            {{ detail.facts.os || $t('Unknown') }}
                            {{ detail.facts.os_version }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-(--ds-text-muted)">
                            {{ $t('Kernel') }}
                        </dt>
                        <dd>{{ detail.facts.kernel || $t('Unknown') }}</dd>
                    </div>
                    <div>
                        <dt class="text-(--ds-text-muted)">
                            {{ $t('Architecture') }}
                        </dt>
                        <dd>{{ detail.facts.arch || $t('Unknown') }}</dd>
                    </div>
                    <div>
                        <dt class="text-(--ds-text-muted)">{{ $t('CPU') }}</dt>
                        <dd>
                            {{
                                $t(':valueValue vCPU · :value2', {
                                    valueValue: String(
                                        detail.facts.cpu_cores ?? $t('Unknown'),
                                    ),
                                    value2:
                                        detail.facts.cpu_model ||
                                        $t('Unknown model'),
                                })
                            }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-(--ds-text-muted)">
                            {{ $t('Memory') }}
                        </dt>
                        <dd>{{ formatBytes(detail.facts.memory_bytes) }}</dd>
                    </div>
                </dl>
                <div v-if="detail.facts.disks?.length">
                    <h4 class="mb-2 text-(--ds-text-muted)">
                        {{ $t('Disks') }}
                    </h4>
                    <div
                        v-for="disk in detail.facts.disks"
                        :key="`${disk.device}-${disk.mount}`"
                        class="flex justify-between gap-3 border-b border-(--ds-border-strong) py-1"
                    >
                        <span class="truncate"
                            >{{ disk.mount }} · {{ disk.device }} ·
                            {{ disk.fs_type }}</span
                        >
                        <span class="shrink-0">{{
                            formatBytes(disk.total_bytes)
                        }}</span>
                    </div>
                </div>
                <div v-if="detail.facts.interfaces?.length">
                    <h4 class="mb-2 text-(--ds-text-muted)">
                        {{ $t('Network interfaces') }}
                    </h4>
                    <div
                        v-for="network in detail.facts.interfaces"
                        :key="network.name"
                        class="border-b border-(--ds-border-strong) py-1"
                    >
                        {{ network.name }} ·
                        {{ network.addresses.join(', ') || $t('No address') }}
                    </div>
                </div>
                <p
                    v-if="!detail.facts.hosting?.provider"
                    class="text-xs text-(--ds-text-muted)"
                >
                    {{
                        $t(
                            'This VPS did not expose provider metadata to the daemon. Region and datacenter cannot be verified from its timezone.',
                        )
                    }}
                </p>
            </section>
            <div v-if="detail.inventory" class="space-y-5 text-sm">
                <p v-if="!detail.inventory.complete" class="text-(--ds-amber)">
                    {{ $t('The last discovery scan was incomplete.') }}
                </p>
                <section>
                    <h3 class="mb-2 font-medium text-(--ds-text)">
                        {{
                            $t('Discovered services (:length)', {
                                length: String(
                                    detail.inventory.services.length ?? '',
                                ),
                            })
                        }}
                    </h3>
                    <div
                        v-if="detail.inventory.services.length"
                        class="divide-y divide-(--ds-border-strong) rounded-lg border border-(--ds-border-strong)"
                    >
                        <div
                            v-for="service in detail.inventory.services"
                            :key="service.key"
                            class="flex flex-wrap items-center justify-between gap-2 px-3 py-2"
                        >
                            <div class="flex items-center gap-2.5">
                                <ServiceLogo
                                    :name="service.name"
                                    :kind="service.kind"
                                    :service-key="service.key"
                                    :icon-url="service.icon"
                                />
                                <div>
                                    <span class="text-(--ds-text)">{{
                                        service.label ??
                                        service.app ??
                                        service.name
                                    }}</span
                                    ><span
                                        class="ml-2 text-xs text-(--ds-text-muted)"
                                        >{{ service.kind }} ·
                                        {{ service.manager }} ·
                                        {{ service.status }}</span
                                    >
                                </div>
                            </div>
                            <span
                                class="font-ui-mono text-xs text-(--ds-text-secondary)"
                                >{{
                                    service.ports.length
                                        ? service.ports
                                              .map((port) => `:${port}`)
                                              .join(', ')
                                        : $t('No port')
                                }}</span
                            >
                        </div>
                    </div>
                    <p v-else class="text-(--ds-text-muted)">
                        {{ $t('No named services found.') }}
                    </p>
                </section>
                <section>
                    <h3 class="mb-2 font-medium text-(--ds-text)">
                        {{
                            $t('Listening ports (:length)', {
                                length: String(
                                    detail.inventory.listeners.length ?? '',
                                ),
                            })
                        }}
                    </h3>
                    <div
                        v-if="detail.inventory.listeners.length"
                        class="divide-y divide-(--ds-border-strong) rounded-lg border border-(--ds-border-strong)"
                    >
                        <div
                            v-for="(listener, index) in detail.inventory
                                .listeners"
                            :key="`${listener.proto}-${listener.address}-${listener.port}-${index}`"
                            class="flex flex-wrap items-center justify-between gap-2 px-3 py-2"
                        >
                            <span class="font-ui-mono text-xs text-(--ds-text)"
                                >{{ listener.address }}:{{ listener.port }}/{{
                                    listener.proto
                                }}</span
                            >
                            <span
                                class="max-w-full truncate text-xs text-(--ds-text-muted)"
                                >{{ listener.exe ?? $t('Unknown process')
                                }}<span v-if="listener.pid">
                                    {{
                                        ' ' +
                                        $t('· PID :pid', {
                                            pid: String(listener.pid ?? ''),
                                        })
                                    }}</span
                                ></span
                            >
                        </div>
                    </div>
                    <p v-else class="text-(--ds-text-muted)">
                        {{ $t('No listening ports found.') }}
                    </p>
                </section>
                <p
                    v-for="warning in detail.inventory.warnings ?? []"
                    :key="warning"
                    class="text-xs text-(--ds-amber)"
                >
                    {{ warning }}
                </p>
            </div>
            <p v-else class="text-sm text-(--ds-text-muted)">
                {{
                    $t(
                        "Waiting for the daemon's first read-only discovery scan.",
                    )
                }}
            </p>
        </DialogContent>
    </Dialog>
</template>
