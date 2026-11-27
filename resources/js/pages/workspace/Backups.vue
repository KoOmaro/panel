<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { Head, router, usePage } from '@inertiajs/vue3';
import { ArrowRight, Check, Plus } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import BackupDestinationDialog from '@/components/workspace/BackupDestinationDialog.vue';
import BackupMachinesTable from '@/components/backups/BackupMachinesTable.vue';
import BackupsCards from '@/components/backups/BackupsCards.vue';
import DestinationsTable from '@/components/backups/DestinationsTable.vue';
import HistoryTable from '@/components/backups/HistoryTable.vue';
import {
    destinationKind,
    firstError,
    post,
    when,
} from '@/components/backups/backups';
import type {
    BackupMachine,
    BackupRow,
    BackupService,
    BackupsSummary,
    Destination,
    Restorable,
    Schedule,
} from '@/components/backups/backups';
import BrandLogo from '@/components/dash/BrandLogo.vue';
import DashButton from '@/components/dash/DashButton.vue';
import DashHeader from '@/components/dash/DashHeader.vue';
import DashTab from '@/components/dash/DashTab.vue';
import DashTabs from '@/components/dash/DashTabs.vue';
import EmptyArt from '@/components/dash/EmptyArt.vue';
import FilterPills from '@/components/dash/FilterPills.vue';
import SearchWell from '@/components/dash/SearchWell.vue';
import ToolbarButton from '@/components/dash/ToolbarButton.vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import InputError from '@/components/InputError.vue';
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
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import ConnectMachineButton from '@/components/workspace/ConnectMachineButton.vue';
import { usePathTab } from '@/composables/usePathTab';
import { useWhenVisible } from '@/composables/useWhenVisible';
import { toast } from '@/lib/toast';
import { onWorkspace, workspacePath } from '@/lib/workspaceRoute';
import backupsRoutes from '@/routes/workspace/backups';
import machinesRoutes from '@/routes/workspace/machines';
import type { StorageShortcut } from '@/components/integrations/storage';
import type { Workspace, WorkspaceAccess } from '@/types';

type Filter = 'all' | 'covered' | 'uncovered' | 'failed';

const props = defineProps<{
    workspace: Workspace;
    machines: BackupMachine[];
    destinations: Destination[];
    summary: BackupsSummary;
    history: BackupRow[];
    storage?: StorageShortcut[];
}>();

const page = usePage<{ workspaceAccess: WorkspaceAccess | null }>();
const canManage = computed(
    () =>
        page.props.workspaceAccess?.permissions.includes('manage-machines') ??
        false,
);

const tab = usePathTab(
    workspacePath('/backups'),
    ['machines', 'destinations', 'history'] as const,
    'machines',
);

const tabs = computed(() => [
    {
        value: 'machines' as const,
        label: trans('Machines'),
        icon: 'machines-fill' as const,
    },
    {
        value: 'destinations' as const,
        label: trans('Destinations'),
        icon: 'database-fill' as const,
    },
    {
        value: 'history' as const,
        label: trans('History'),
        icon: 'activity-fill' as const,
    },
]);

const search = ref('');
const filter = ref<Filter>('all');
const destinationFilter = ref<number | null>(null);
const selected = ref<number[]>([]);

const isCovered = (m: BackupMachine) =>
    m.destinationId !== null && m.schedule !== 'off';

const tests: Record<Filter, (m: BackupMachine) => boolean> = {
    all: () => true,
    covered: isCovered,
    uncovered: (m) => !isCovered(m),
    failed: (m) => m.lastRun?.state === 'failed',
};

const pills = computed(() => [
    {
        value: 'all' as const,
        label: trans('All'),
        count: props.machines.length,
    },
    {
        value: 'covered' as const,
        label: trans('Covered'),
        count: props.machines.filter(tests.covered).length,
    },
    {
        value: 'uncovered' as const,
        label: trans('Not covered'),
        count: props.machines.filter(tests.uncovered).length,
    },
    {
        value: 'failed' as const,
        label: trans('Failed'),
        count: props.machines.filter(tests.failed).length,
    },
]);

const visibleMachines = computed(() => {
    const q = search.value.trim().toLowerCase();

    return props.machines
        .filter(tests[filter.value])
        .filter(
            (m) =>
                destinationFilter.value === null ||
                m.destinationId === destinationFilter.value,
        )
        .filter(
            (m) =>
                !q ||
                `${m.name} ${m.ip} ${m.provider ?? ''}`
                    .toLowerCase()
                    .includes(q),
        );
});

const destinationLabel = computed(
    () =>
        props.destinations.find((d) => d.id === destinationFilter.value)
            ?.name ?? trans('All destinations'),
);

const coveredLine = computed(() =>
    trans(':covered of :total machines are covered.', {
        covered: String(props.summary.covered),
        total: String(props.summary.machines),
    }),
);

const saving = ref<number | null>(null);

function saveMachine(
    machine: BackupMachine,
    change: { destinationId?: number | null; schedule?: Schedule },
): void {
    const destinationId =
        change.destinationId !== undefined
            ? change.destinationId
            : machine.destinationId;
    const schedule =
        change.schedule ??
        (destinationId && machine.schedule === 'off' && !machine.destinationId
            ? 'daily'
            : destinationId
              ? machine.schedule
              : 'off');

    router.put(
        workspacePath(`/machines/${machine.id}/backups`),
        { destination_id: destinationId, schedule },
        {
            preserveScroll: true,
            preserveState: true,
            only: ['machines', 'destinations', 'summary'],
            onStart: () => (saving.value = machine.id),
            onFinish: () => (saving.value = null),
            onError: (bag) =>
                toast.error(
                    `${machine.name}: ${Object.values(bag)[0] ?? trans('That didn’t save.')}`,
                ),
        },
    );
}

const running = ref<number[]>([]);

async function runOne(machine: BackupMachine): Promise<number | string> {
    if (machine.unavailable) return machine.unavailable;
    running.value = [...running.value, machine.id];
    try {
        const { ok, data } = await post(
            onWorkspace(
                machinesRoutes.backups.run({
                    workspace: props.workspace.slug,
                    machine: machine.id,
                }),
            ).url,
        );

        return ok
            ? Number(data.started ?? 0)
            : firstError(data, trans('The backup could not start.'));
    } finally {
        running.value = running.value.filter((id) => id !== machine.id);
    }
}

async function backUp(machine: BackupMachine): Promise<void> {
    const result = await runOne(machine);
    if (typeof result === 'string') {
        toast.error(`${machine.name}: ${result}`);

        return;
    }
    toast.success(
        result === 1
            ? trans('Backing up 1 service on :name.', { name: machine.name })
            : trans('Backing up :count services on :name.', {
                  count: String(result),
                  name: machine.name,
              }),
    );
    refresh();
}

const runningAll = ref(false);
const runTargets = computed(() =>
    selected.value.length
        ? props.machines.filter((m) => selected.value.includes(m.id))
        : props.machines.filter((m) => m.destinationId !== null),
);

async function backUpAll(): Promise<void> {
    if (runningAll.value) return;
    const targets = runTargets.value;
    if (!targets.length) {
        toast.error(trans('Pick a destination for a machine first.'));

        return;
    }
    runningAll.value = true;
    let started = 0;
    const problems: string[] = [];
    try {
        for (const machine of targets) {
            const result = await runOne(machine);
            if (typeof result === 'string') {
                problems.push(`${machine.name}: ${result}`);
            } else {
                started++;
            }
        }
    } finally {
        runningAll.value = false;
    }
    if (started) {
        toast.success(
            started === 1
                ? trans('Backing up 1 machine.')
                : trans('Backing up :count machines.', {
                      count: String(started),
                  }),
        );
    }
    for (const problem of problems.slice(0, 3)) toast.error(problem);
    if (problems.length > 3) {
        toast.error(
            trans(':count more couldn’t start.', {
                count: String(problems.length - 3),
            }),
        );
    }
    selected.value = [];
    refresh();
}

const backUpLabel = computed(() =>
    selected.value.length > 1
        ? trans('Back up :count machines', {
              count: String(selected.value.length),
          })
        : trans('Back up now'),
);

const dialogOpen = ref(false);
const editing = ref<Destination | null>(null);
const initialType = ref<'s3' | 'machine'>('s3');

function addDestination(type: 's3' | 'machine' = 's3'): void {
    editing.value = null;
    initialType.value = type;
    dialogOpen.value = true;
}

function editDestination(destination: Destination): void {
    editing.value = destination;
    dialogOpen.value = true;
}

const removing = ref<Destination | null>(null);
const confirmingRemove = ref(false);
const removeProcessing = ref(false);

function askRemove(destination: Destination): void {
    removing.value = destination;
    confirmingRemove.value = true;
}

function removeDestination(): void {
    if (!removing.value) return;

    router.delete(workspacePath(`/backup-destinations/${removing.value.id}`), {
        preserveScroll: true,
        onStart: () => (removeProcessing.value = true),
        onFinish: () => (removeProcessing.value = false),
        onSuccess: () => (confirmingRemove.value = false),
    });
}

function retry(destination: Destination): void {
    router.post(
        workspacePath(`/backup-destinations/${destination.id}/retry`),
        {},
        {
            preserveScroll: true,
            preserveState: true,
            onError: (bag) =>
                toast.error(
                    Object.values(bag)[0] ?? trans('That didn’t start.'),
                ),
        },
    );
}

const dialogMachines = computed(() =>
    props.machines.map((m) => ({
        id: m.id,
        name: m.name,
        online: ['online', 'degraded'].includes(m.connection),
    })),
);

const restoring = ref<Restorable | null>(null);
const restoreOpen = ref(false);
const restoreConfirm = ref('');
const restoreBusy = ref(false);
const restoreError = ref<string | null>(null);

function openRestore(target: Restorable, cannot: string | null): void {
    if (cannot) {
        toast.error(cannot);

        return;
    }
    restoring.value = target;
    restoreConfirm.value = '';
    restoreError.value = null;
    restoreOpen.value = true;
}

function restoreService(machine: BackupMachine, service: BackupService): void {
    if (!service.last) return;
    openRestore(
        {
            id: service.last.id,
            serviceName: service.last.serviceName,
            label: service.name,
            machine: machine.name,
            createdAt: service.last.createdAt ?? '',
            undo: service.last.trigger === 'pre-restore',
        },
        service.last.cannotRestore,
    );
}

function restoreBackup(backup: BackupRow): void {
    openRestore(
        {
            id: backup.id,
            serviceName: backup.serviceName,
            label: backup.serviceLabel,
            machine: backup.machine.name,
            createdAt: backup.createdAt,
            undo: backup.trigger === 'pre-restore',
        },
        backup.cannotRestore,
    );
}

async function restore(): Promise<void> {
    const target = restoring.value;
    if (!target || restoreConfirm.value !== target.serviceName) return;
    restoreBusy.value = true;
    restoreError.value = null;
    try {
        const { ok, data } = await post(
            onWorkspace(
                backupsRoutes.restore({
                    workspace: props.workspace.slug,
                    backup: target.id,
                }),
            ).url,
            { confirm: restoreConfirm.value },
        );
        if (!ok) {
            restoreError.value = firstError(
                data,
                trans('The restore could not start.'),
            );

            return;
        }
        restoreOpen.value = false;
        toast.success(
            trans('Restoring :name on :machine.', {
                name: target.label,
                machine: target.machine,
            }),
        );
        refresh();
    } finally {
        restoreBusy.value = false;
    }
}

const busy = computed(
    () =>
        props.history.some(
            (b) =>
                ['queued', 'running'].includes(b.state) ||
                ['queued', 'running'].includes(b.restore?.state ?? ''),
        ) || props.destinations.some((d) => d.status === 'setting_up'),
);

let timer: ReturnType<typeof setTimeout> | undefined;
const whenVisible = useWhenVisible();

function refresh(): void {
    router.reload({
        only: ['machines', 'destinations', 'history', 'summary'],
    });
}

function poll(): void {
    clearTimeout(timer);
    timer = setTimeout(
        () =>
            whenVisible(() =>
                router.reload({
                    only: ['machines', 'destinations', 'history', 'summary'],
                    onFinish: poll,
                }),
            ),
        busy.value ? 3000 : 15000,
    );
}

onMounted(poll);
onBeforeUnmount(() => clearTimeout(timer));

const storageStarters = computed(() =>
    (props.storage ?? []).map((shortcut) => ({
        key: shortcut.key,
        logo: shortcut.logo,
        title: trans(':provider, set up for you', { provider: shortcut.name }),
        description: shortcut.accounts.length
            ? trans(
                  'ServerOS makes a private bucket and a key for it in :account',
                  { account: shortcut.accounts[0].label },
              )
            : trans(
                  'Connect an account and ServerOS makes the bucket and its key',
              ),
        href: shortcut.accounts[0]?.href ?? shortcut.connect,
    })),
);

const starterKinds = computed(() => [
    {
        kind: 's3' as const,
        icon: 'database-fill' as const,
        title: trans('S3 bucket'),
        description: trans(
            'Cloudflare R2, Backblaze B2, AWS, Hetzner or any S3-compatible storage',
        ),
        logos: ['cloudflare', 'backblaze', 'hetzner', 'digitalocean', 'minio'],
    },
    {
        kind: 'machine' as const,
        icon: 'machines-fill' as const,
        title: trans('Another machine'),
        description: trans('A machine you’ve connected, over SFTP'),
        logos: [],
    },
]);
</script>

<template>
    <Head :title="$t('Backups')" />

    <DashHeader :crumbs="[{ label: $t('Backups') }]">
        <template v-if="machines.length && canManage" #actions>
            <DashButton
                :icon="Plus"
                :label="$t('Add destination')"
                @click="addDestination()"
            />
            <DashButton
                variant="primary"
                :label="backUpLabel"
                :loading="runningAll"
                :disabled="!runTargets.length"
                @click="backUpAll"
            />
        </template>
    </DashHeader>

    <div
        v-if="!machines.length"
        class="flex min-h-full flex-col items-center justify-center gap-5 px-6 py-16 text-center"
    >
        <EmptyArt kind="drop" />
        <div class="flex max-w-sm flex-col gap-1">
            <h1 class="text-[15px]/6 font-medium text-fg-primary">
                {{ $t('Nothing to back up yet') }}
            </h1>
            <p class="text-[13px]/5 text-pretty text-fg-tertiary">
                {{
                    $t(
                        'Connect a machine first. Its apps, sites and databases can then be backed up every day.',
                    )
                }}
            </p>
        </div>
        <ConnectMachineButton />
    </div>

    <div v-else class="flex min-h-full flex-col">
        <DashTabs>
            <DashTab
                v-for="option in tabs"
                :key="option.value"
                :label="option.label"
                :icon="option.icon"
                :active="tab === option.value"
                @click="tab = option.value"
            />
        </DashTabs>

        <BackupsCards
            :summary="summary"
            :destinations="destinations"
            :can-manage="canManage"
            @add-destination="addDestination()"
            @open-destinations="tab = 'destinations'"
        />

        <template v-if="tab === 'machines'">
            <div
                class="flex min-h-14 flex-wrap items-center gap-2 px-5 py-3 lg:flex-nowrap lg:py-0"
            >
                <SearchWell
                    v-model="search"
                    :placeholder="$t('Search machines')"
                    :width="300"
                    class="max-sm:w-full!"
                />
                <FilterPills
                    v-model="filter"
                    :label="$t('Show')"
                    :options="pills"
                />
                <DropdownMenu v-if="destinations.length">
                    <DropdownMenuTrigger as-child>
                        <ToolbarButton
                            icon="sliders"
                            :label="destinationLabel"
                            :active="destinationFilter !== null"
                        />
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="start" class="min-w-52 font-ui">
                        <DropdownMenuItem @select="destinationFilter = null">
                            <span class="flex-1">{{
                                $t('All destinations')
                            }}</span>
                            <Check
                                v-if="destinationFilter === null"
                                class="size-4"
                            />
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            v-for="destination in destinations"
                            :key="destination.id"
                            @select="destinationFilter = destination.id"
                        >
                            <BrandLogo
                                v-if="destinationKind(destination).logo"
                                :name="destinationKind(destination).logo"
                                :size="14"
                            />
                            <FilledIcon
                                v-else
                                name="database-fill"
                                :size="14"
                                class="text-fg-muted"
                            />
                            <span class="flex-1 truncate">{{
                                destination.name
                            }}</span>
                            <Check
                                v-if="destinationFilter === destination.id"
                                class="size-4"
                            />
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
                <div class="flex-1 max-lg:hidden" />
                <template v-if="selected.length">
                    <span
                        class="text-[12.5px]/4 font-medium tracking-[-0.005em] whitespace-nowrap text-fg-secondary"
                        >{{
                            $t(':count selected', {
                                count: String(selected.length),
                            })
                        }}</span
                    >
                    <DashButton :label="$t('Clear')" @click="selected = []" />
                    <span
                        class="h-[18px] w-[0.5px] shrink-0 bg-edge-control max-lg:hidden"
                        aria-hidden="true"
                    />
                </template>
                <span
                    class="shrink-0 text-[12px]/4 whitespace-nowrap text-fg-muted"
                    >{{ coveredLine }}</span
                >
            </div>

            <section
                v-if="!destinations.length"
                class="flex flex-col border-t-[0.5px] border-edge"
                aria-labelledby="destination-heading"
            >
                <div class="flex flex-col gap-0.5 px-5 py-4">
                    <h2
                        id="destination-heading"
                        class="text-[14px]/5 font-medium tracking-[-0.01em] text-fg-primary"
                    >
                        {{ $t('Where should backups go?') }}
                    </h2>
                    <p class="text-[13px]/5 text-pretty text-fg-tertiary">
                        {{
                            $t(
                                'Somewhere off the machine, so a dead disk or a bad deploy doesn’t take them with it. Then pick a schedule for each machine.',
                            )
                        }}
                    </p>
                </div>
                <button
                    v-for="option in starterKinds"
                    :key="option.kind"
                    type="button"
                    class="group flex h-14 w-full items-center gap-3 border-t-[0.5px] border-edge px-5 text-left transition-colors enabled:cursor-pointer enabled:hover:bg-surface-hover disabled:opacity-60"
                    :disabled="!canManage"
                    @click="addDestination(option.kind)"
                >
                    <FilledIcon :name="option.icon" class="text-fg-muted" />
                    <span class="flex min-w-0 grow flex-col">
                        <span
                            class="text-[13px]/5 font-medium tracking-[-0.01em] text-fg-primary"
                            >{{ option.title }}</span
                        >
                        <span class="truncate text-[12px]/4 text-fg-tertiary">{{
                            option.description
                        }}</span>
                    </span>
                    <span
                        v-if="option.logos.length"
                        class="hidden items-center gap-2.5 sm:flex"
                        aria-hidden="true"
                    >
                        <BrandLogo
                            v-for="logo in option.logos"
                            :key="logo"
                            :name="logo"
                            :size="14"
                        />
                    </span>
                    <ArrowRight
                        v-if="canManage"
                        class="size-3.5 shrink-0 text-fg-faint transition-colors group-hover:text-fg-secondary"
                        :stroke-width="2"
                        aria-hidden="true"
                    />
                </button>
                <button
                    v-for="option in storageStarters"
                    :key="option.key"
                    type="button"
                    class="group flex h-14 w-full cursor-pointer items-center gap-3 border-t-[0.5px] border-edge px-5 text-left transition-colors hover:bg-surface-hover"
                    @click="router.visit(workspacePath(option.href))"
                >
                    <BrandLogo :name="option.logo" :size="16" />
                    <span class="flex min-w-0 grow flex-col">
                        <span
                            class="text-[13px]/5 font-medium tracking-[-0.01em] text-fg-primary"
                            >{{ option.title }}</span
                        >
                        <span class="truncate text-[12px]/4 text-fg-tertiary">{{
                            option.description
                        }}</span>
                    </span>
                    <ArrowRight
                        class="size-3.5 shrink-0 text-fg-faint transition-colors group-hover:text-fg-secondary"
                        :stroke-width="2"
                        aria-hidden="true"
                    />
                </button>
                <p
                    v-if="!canManage"
                    class="border-t-[0.5px] border-edge px-5 py-3 text-[13px]/5 text-fg-tertiary"
                >
                    {{ $t('Ask someone who manages machines to add one.') }}
                </p>
            </section>

            <template v-else>
                <BackupMachinesTable
                    v-if="visibleMachines.length"
                    v-model:selected="selected"
                    class="border-t-[0.5px] border-edge"
                    :machines="visibleMachines"
                    :destinations="destinations"
                    :can-manage="canManage"
                    :saving="saving"
                    :running="running"
                    @save="saveMachine"
                    @run="backUp"
                    @restore="restoreService"
                    @add-destination="addDestination()"
                />
                <p
                    v-else
                    class="border-t-[0.5px] border-edge px-5 py-10 text-center text-[13px]/5 text-fg-muted"
                >
                    {{ $t('No machines match.') }}
                </p>
            </template>
        </template>

        <template v-else-if="tab === 'destinations'">
            <div class="flex h-14 items-center gap-2 px-5">
                <p class="text-[13px]/5 text-fg-tertiary">
                    {{
                        $t(
                            'Copies of your apps, sites and databases kept off the machine, in an S3 bucket or on another of your machines.',
                        )
                    }}
                </p>
            </div>
            <DestinationsTable
                v-if="destinations.length"
                class="border-t-[0.5px] border-edge"
                :destinations="destinations"
                :can-manage="canManage"
                @edit="editDestination"
                @remove="askRemove"
                @retry="retry"
            />
            <div
                v-else
                class="flex flex-col items-center gap-3 border-t-[0.5px] border-edge px-5 py-10 text-center"
            >
                <p class="text-[13px]/5 text-fg-muted">
                    {{ $t('No destinations yet.') }}
                </p>
                <DashButton
                    v-if="canManage"
                    :icon="Plus"
                    :label="$t('Add destination')"
                    @click="addDestination()"
                />
            </div>
        </template>

        <template v-else>
            <div class="flex h-14 items-center gap-2 px-5">
                <p class="text-[13px]/5 text-fg-tertiary">
                    {{ $t('The last 100 backups and restores.') }}
                </p>
            </div>
            <HistoryTable
                v-if="history.length"
                class="border-t-[0.5px] border-edge"
                :history="history"
                :can-manage="canManage"
                @restore="restoreBackup"
            />
            <p
                v-else
                class="border-t-[0.5px] border-edge px-5 py-10 text-center text-[13px]/5 text-fg-muted"
            >
                {{
                    $t(
                        'No backups have run yet. Pick a destination for a machine, then press Back up now or wait for its schedule.',
                    )
                }}
            </p>
        </template>
    </div>

    <BackupDestinationDialog
        v-model:open="dialogOpen"
        :machines="dialogMachines"
        :editing="editing"
        :initial-type="initialType"
        :storage="storage ?? []"
    />

    <Dialog v-model:open="restoreOpen">
        <DialogContent
            class="workspace-panel ds-modal font-ui text-foreground sm:max-w-md"
        >
            <DialogHeader>
                <DialogTitle>
                    {{
                        restoring?.undo
                            ? $t('Undo the restore of :serviceName?', {
                                  serviceName: String(restoring?.label),
                              })
                            : $t('Restore :serviceName?', {
                                  serviceName: String(restoring?.label),
                              })
                    }}
                </DialogTitle>
                <DialogDescription>
                    {{
                        $t(
                            'Its data on :name goes back to how it was :value. Anything written since is replaced. ServerOS takes a snapshot first, so you can undo this from the history.',
                            {
                                name: String(restoring?.machine ?? ''),
                                value: restoring
                                    ? when(restoring.createdAt, true)
                                    : '',
                            },
                        )
                    }}
                </DialogDescription>
            </DialogHeader>
            <form class="flex flex-col gap-4" @submit.prevent="restore">
                <div class="grid gap-2">
                    <label for="restore-confirm" class="text-[13px]/5">
                        {{ $t('Type') }}
                        <span class="font-ui-mono text-fg-primary">{{
                            restoring?.serviceName
                        }}</span>
                        {{ $t('to confirm.') }}
                    </label>
                    <Input
                        id="restore-confirm"
                        v-model="restoreConfirm"
                        autocomplete="off"
                        spellcheck="false"
                        class="font-ui-mono"
                    />
                </div>
                <InputError :message="restoreError ?? undefined" />
                <div class="flex justify-end gap-2">
                    <DashButton
                        :label="$t('Cancel')"
                        @click="restoreOpen = false"
                    />
                    <DashButton
                        type="submit"
                        variant="danger"
                        :label="restoreBusy ? $t('Starting…') : $t('Restore')"
                        :loading="restoreBusy"
                        :disabled="
                            !restoreBusy &&
                            restoreConfirm !== restoring?.serviceName
                        "
                    />
                </div>
            </form>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="confirmingRemove">
        <DialogContent
            class="workspace-panel ds-modal font-ui text-foreground sm:max-w-md"
        >
            <DialogHeader>
                <DialogTitle>{{
                    $t('Remove :name?', { name: String(removing?.name ?? '') })
                }}</DialogTitle>
                <DialogDescription>
                    {{
                        $t(
                            'Machines that send backups here stop until you pick another destination for them. Backups already there are left where they are.',
                        )
                    }}
                </DialogDescription>
            </DialogHeader>
            <form
                class="flex justify-end gap-2"
                @submit.prevent="removeDestination"
            >
                <DashButton
                    :label="$t('Cancel')"
                    @click="confirmingRemove = false"
                />
                <DashButton
                    type="submit"
                    variant="danger"
                    :label="$t('Remove destination')"
                    :loading="removeProcessing"
                />
            </form>
        </DialogContent>
    </Dialog>
</template>
