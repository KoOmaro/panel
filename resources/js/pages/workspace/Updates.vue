<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { Head, router, usePage } from '@inertiajs/vue3';
import { Check } from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import BrandLogo from '@/components/dash/BrandLogo.vue';
import DashButton from '@/components/dash/DashButton.vue';
import DashHeader from '@/components/dash/DashHeader.vue';
import DashTab from '@/components/dash/DashTab.vue';
import DashTabs from '@/components/dash/DashTabs.vue';
import EmptyArt from '@/components/dash/EmptyArt.vue';
import FilterPills from '@/components/dash/FilterPills.vue';
import SearchWell from '@/components/dash/SearchWell.vue';
import ToolbarButton from '@/components/dash/ToolbarButton.vue';
import UpdatesDialog from '@/components/machines/UpdatesDialog.vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import ContainerUpdates from '@/components/updates/ContainerUpdates.vue';
import type { ContainerRow } from '@/components/updates/ContainerUpdates.vue';
import MachinesTable from '@/components/updates/MachinesTable.vue';
import PackagesTable from '@/components/updates/PackagesTable.vue';
import RolloutDetailDialog from '@/components/updates/RolloutDetailDialog.vue';
import RolloutDialog from '@/components/updates/RolloutDialog.vue';
import RolloutsTable from '@/components/updates/RolloutsTable.vue';
import UpdatesCards from '@/components/updates/UpdatesCards.vue';
import { sendJson, shortAgo } from '@/components/updates/updates';
import type {
    MachineGroupChoice,
    Rollout,
    RolloutDetail,
    SecuritySummary,
    UpdatesMachine,
    UpdatesPackage,
    UpdatesSummary,
} from '@/components/updates/updates';
import { useWhenVisible } from '@/composables/useWhenVisible';
import { errorMessage, jobReason } from '@/lib/errors';
import { toast } from '@/lib/toast';
import { onWorkspace, workspacePath } from '@/lib/workspaceRoute';
import machinesRoutes from '@/routes/workspace/machines';
import updatesRoutes from '@/routes/workspace/updates';
import type { Workspace, WorkspaceAccess } from '@/types';

type View = 'packages' | 'machines' | 'containers' | 'rollouts';
type Filter = 'all' | 'security' | 'restart' | 'held';

const props = defineProps<{
    workspace: Workspace;
    summary: UpdatesSummary;
    security: SecuritySummary;
    machines: UpdatesMachine[];
    packages: UpdatesPackage[];
    checking: number[];
    rollouts: Rollout[];
    rollout: RolloutDetail | null;
    groups: MachineGroupChoice[];
    containers: ContainerRow[];
    commandsEnabled: boolean;
    view: View | null;
}>();

const page = usePage<{ workspaceAccess: WorkspaceAccess | null }>();
const canManage = computed(
    () =>
        (page.props.workspaceAccess?.permissions.includes('manage-machines') ??
            false) &&
        props.commandsEnabled,
);

const view = ref<View>(props.view ?? 'packages');
const search = ref('');
const filter = ref<Filter>('all');
const machineId = ref<number | null>(null);
const selected = ref<string[]>([]);

const byId = computed(
    () => new Map(props.machines.map((machine) => [machine.id, machine])),
);

const tabs = computed(() => [
    {
        value: 'packages' as const,
        label: trans('By package'),
        icon: 'package-fill' as const,
    },
    {
        value: 'machines' as const,
        label: trans('By machine'),
        icon: 'machines-fill' as const,
    },
    {
        value: 'containers' as const,
        label: trans('Containers'),
        icon: 'layers-fill' as const,
    },
    {
        value: 'rollouts' as const,
        label: trans('Rollouts'),
        icon: 'update-fill' as const,
    },
]);

const packageTests: Record<Filter, (p: UpdatesPackage) => boolean> = {
    all: () => true,
    security: (p) => p.security,
    restart: (p) => p.restart,
    held: (p) => p.heldOn.length > 0,
};

const machineTests: Record<Filter, (m: UpdatesMachine) => boolean> = {
    all: () => true,
    security: (m) => m.security > 0,
    restart: (m) => m.rebootRequired,
    held: (m) => m.held.length > 0,
};

const onMachine = computed(() =>
    machineId.value === null
        ? props.packages
        : props.packages.filter(
              (p) =>
                  p.machineIds.includes(machineId.value as number) ||
                  p.heldOn.includes(machineId.value as number),
          ),
);

const pills = computed(() => {
    const count = (key: Filter) =>
        view.value === 'machines'
            ? props.machines.filter(machineTests[key]).length
            : onMachine.value.filter(packageTests[key]).length;

    return [
        { value: 'all' as const, label: trans('All'), count: count('all') },
        {
            value: 'security' as const,
            label: trans('Security'),
            count: count('security'),
        },
        {
            value: 'restart' as const,
            label: trans('Restart needed'),
            count: count('restart'),
        },
        { value: 'held' as const, label: trans('Held'), count: count('held') },
    ];
});

const visiblePackages = computed(() => {
    const q = search.value.trim().toLowerCase();

    return onMachine.value
        .filter(packageTests[filter.value])
        .filter((p) => !q || p.name.toLowerCase().includes(q));
});

const visibleMachines = computed(() => {
    const q = search.value.trim().toLowerCase();

    return props.machines
        .filter(machineTests[filter.value])
        .filter(
            (m) =>
                !q ||
                `${m.name} ${m.os ?? ''} ${m.ip ?? ''}`
                    .toLowerCase()
                    .includes(q),
        );
});

const selectedPackages = computed(() =>
    props.packages.filter((p) => selected.value.includes(p.name)),
);
const rollingOut = computed(() =>
    selectedPackages.value.filter((p) => p.machineIds.length > 0),
);
const securityPackages = computed(() =>
    props.packages.filter((p) => p.security && p.machineIds.length > 0),
);

watch(
    () => props.packages,
    (list) => {
        selected.value = selected.value.filter((name) =>
            list.some((p) => p.name === name),
        );
    },
);

const machineLabel = computed(() =>
    machineId.value === null
        ? trans('All machines')
        : (byId.value.get(machineId.value)?.name ?? trans('All machines')),
);

const whenVisible = useWhenVisible();
let pollTimer: ReturnType<typeof setTimeout> | undefined;
const busy = computed(
    () =>
        props.checking.length > 0 ||
        props.rollouts.some(
            (r) =>
                r.state === 'running' ||
                (r.state === 'pending' && !r.scheduledFor),
        ),
);

function reload(): void {
    router.reload({
        only: [
            'summary',
            'security',
            'machines',
            'packages',
            'checking',
            'rollouts',
            'rollout',
        ],
    });
}

watch(
    busy,
    (value) => {
        clearTimeout(pollTimer);
        if (!value) return;
        const tick = () => {
            whenVisible(reload);
            pollTimer = setTimeout(tick, 4000);
        };
        pollTimer = setTimeout(tick, 4000);
    },
    { immediate: true },
);
onBeforeUnmount(() => clearTimeout(pollTimer));

const checkingAll = ref(false);
async function checkAll(): Promise<void> {
    if (checkingAll.value) return;
    checkingAll.value = true;
    try {
        const { queued } = await sendJson<{ queued: number }>(
            'POST',
            onWorkspace(updatesRoutes.check(props.workspace.slug)).url,
        );
        toast.success(
            queued === 1
                ? trans('Checking 1 machine. This can take a minute.')
                : trans('Checking :count machines. This can take a minute.', {
                      count: String(queued),
                  }),
        );
        reload();
    } catch (cause) {
        toast.error(errorMessage(cause));
    } finally {
        checkingAll.value = false;
    }
}

const rolloutOpenDialog = ref(false);
const rolloutPackages = ref<UpdatesPackage[]>([]);

const rollOutLabel = computed(() => {
    if (rollingOut.value.length) {
        return rollingOut.value.length === 1
            ? trans('Roll out 1 package')
            : trans('Roll out :count packages', {
                  count: String(rollingOut.value.length),
              });
    }

    return securityPackages.value.length
        ? trans('Roll out security updates')
        : trans('Roll out');
});

function startRollout(): void {
    rolloutPackages.value = rollingOut.value.length
        ? rollingOut.value
        : securityPackages.value;
    if (!rolloutPackages.value.length) return;
    rolloutOpenDialog.value = true;
}

const holding = ref(false);
async function hold(on: boolean): Promise<void> {
    const packages = selectedPackages.value;
    const machineIds = [
        ...new Set(packages.flatMap((p) => (on ? p.machineIds : p.heldOn))),
    ];
    if (!machineIds.length || holding.value) return;
    holding.value = true;
    try {
        const result = await sendJson<{
            queued: { machineId: number; machine: string; jobId: string }[];
            skipped: { machine: string; reason: string }[];
        }>('POST', onWorkspace(updatesRoutes.holds(props.workspace.slug)).url, {
            packages: packages.map((p) => p.name),
            machines: machineIds,
            hold: on,
        });
        toast.success(
            on
                ? trans('Holding on :count machines.', {
                      count: String(result.queued.length),
                  })
                : trans('Letting go on :count machines.', {
                      count: String(result.queued.length),
                  }),
        );
        for (const skip of result.skipped) {
            toast.warning(`${skip.machine}: ${skip.reason}`);
        }
        void followHolds(result.queued);
    } catch (cause) {
        toast.error(errorMessage(cause));
    } finally {
        holding.value = false;
    }
}

async function followHolds(
    jobs: { machineId: number; machine: string; jobId: string }[],
): Promise<void> {
    let waiting = [...jobs];
    for (let round = 0; round < 20 && waiting.length; round++) {
        await new Promise((resolve) => setTimeout(resolve, 2000));
        const next: typeof waiting = [];
        for (const job of waiting) {
            try {
                const status = await sendJson<{
                    state: string;
                    error: { message?: string; next_step?: string } | null;
                }>(
                    'GET',
                    onWorkspace(
                        machinesRoutes.updates.show({
                            workspace: props.workspace.slug,
                            machine: job.machineId,
                            job: job.jobId,
                        }),
                    ).url,
                );
                if (['queued', 'running'].includes(status.state)) {
                    next.push(job);
                } else if (status.state !== 'succeeded') {
                    toast.error(
                        `${job.machine}: ${jobReason(status.error, trans('That didn’t finish.'))}`,
                    );
                }
            } catch {
                next.push(job);
            }
        }
        waiting = next;
    }
    reload();
}

const machineDialog = ref<UpdatesMachine | null>(null);
const machineDialogOpen = ref(false);
function openMachine(machine: UpdatesMachine): void {
    if (!canManage.value) {
        router.visit(workspacePath(`/machines/${machine.id}`));

        return;
    }
    machineDialog.value = machine;
    machineDialogOpen.value = true;
}
watch(machineDialogOpen, (value) => {
    if (!value) reload();
});

const rolloutId = ref<number | null>(null);
const rolloutDetailOpen = ref(false);
function openRollout(id: number): void {
    rolloutId.value = id;
    rolloutDetailOpen.value = true;
}
function rolloutCreated(rollout: Rollout): void {
    selected.value = [];
    view.value = 'rollouts';
    reload();
    openRollout(rollout.id);
}

const checkedLine = computed(() => {
    if (props.checking.length) {
        return props.checking.length === 1
            ? trans('Checking 1 machine…')
            : trans('Checking :count machines…', {
                  count: String(props.checking.length),
              });
    }

    return props.summary.newestCheck
        ? trans('Checked :when', { when: shortAgo(props.summary.newestCheck) })
        : trans('Not checked yet');
});

const machinesHref = computed(
    () => onWorkspace(machinesRoutes.index(props.workspace.slug)).url,
);
</script>

<template>
    <Head :title="$t('Updates')" />

    <DashHeader :crumbs="[{ label: $t('Updates') }]">
        <template v-if="machines.length && canManage" #actions>
            <DashButton
                v-if="view !== 'containers'"
                icon="update-fill"
                :label="$t('Check all now')"
                :loading="checkingAll"
                @click="checkAll"
            />
            <DashButton
                variant="primary"
                :label="rollOutLabel"
                :disabled="!rollingOut.length && !securityPackages.length"
                @click="startRollout"
            />
        </template>
    </DashHeader>

    <div
        v-if="!machines.length"
        class="flex min-h-full flex-col items-center justify-center gap-5 px-6 py-16 text-center"
    >
        <EmptyArt kind="project-graph" />
        <div class="flex max-w-sm flex-col gap-1">
            <h1 class="text-[15px]/6 font-medium text-fg-primary">
                {{ $t('No machines to update yet') }}
            </h1>
            <p class="text-[13px]/5 text-pretty text-fg-tertiary">
                {{
                    $t(
                        'Connect a machine and its operating system updates show up here, checked about once a day.',
                    )
                }}
            </p>
        </div>
        <DashButton
            icon="machines-fill"
            :label="$t('Go to Machines')"
            :href="machinesHref"
        />
    </div>

    <div v-else class="flex min-h-full flex-col">
        <DashTabs>
            <DashTab
                v-for="tab in tabs"
                :key="tab.value"
                :label="tab.label"
                :icon="tab.icon"
                :active="view === tab.value"
                @click="
                    view = tab.value;
                    filter = 'all';
                "
            />
        </DashTabs>

        <UpdatesCards
            :summary="summary"
            :security="security"
            :rollout="rollout"
            :checking="checking.length"
            @open-rollout="openRollout"
        />

        <ContainerUpdates
            v-if="view === 'containers'"
            :containers="containers"
            :can-manage="canManage"
        />

        <template v-else-if="view === 'rollouts'">
            <div class="flex h-14 items-center gap-2 px-5">
                <p class="text-[13px]/5 text-fg-tertiary">
                    {{
                        $t(
                            'Updates installed on several machines at once, or a batch at a time.',
                        )
                    }}
                </p>
            </div>
            <RolloutsTable
                v-if="rollouts.length"
                class="border-t-[0.5px] border-edge"
                :rollouts="rollouts"
                @open="openRollout"
            />
            <p
                v-else
                class="border-t-[0.5px] border-edge px-5 py-10 text-center text-[13px]/5 text-fg-muted"
            >
                {{
                    canManage
                        ? $t(
                              'No rollouts yet. Pick packages under By package, then Roll out.',
                          )
                        : $t('No rollouts yet.')
                }}
            </p>
        </template>

        <template v-else>
            <div
                class="flex min-h-14 flex-wrap items-center gap-2 px-5 py-3 lg:flex-nowrap lg:py-0"
            >
                <SearchWell
                    v-model="search"
                    :placeholder="
                        view === 'packages'
                            ? $t('Search packages')
                            : $t('Search machines')
                    "
                    :width="300"
                    class="max-sm:w-full!"
                />
                <FilterPills
                    v-model="filter"
                    :label="$t('Show')"
                    :options="pills"
                />
                <DropdownMenu v-if="view === 'packages'">
                    <DropdownMenuTrigger as-child>
                        <ToolbarButton
                            icon="sliders"
                            :label="machineLabel"
                            :active="machineId !== null"
                        />
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="start" class="min-w-52 font-ui">
                        <DropdownMenuItem @select="machineId = null">
                            <span class="flex-1">{{ $t('All machines') }}</span>
                            <Check
                                v-if="machineId === null"
                                class="size-4 text-fg-secondary"
                            />
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            v-for="machine in machines"
                            :key="machine.id"
                            @select="machineId = machine.id"
                        >
                            <BrandLogo :name="machine.provider" :size="14" />
                            <span class="flex-1 truncate">{{
                                machine.name
                            }}</span>
                            <span
                                class="font-ui-mono text-[11.5px] text-fg-faint"
                                >{{ machine.updates }}</span
                            >
                            <Check
                                v-if="machineId === machine.id"
                                class="size-4 text-fg-secondary"
                            />
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
                <div class="flex-1 max-lg:hidden" />
                <div
                    v-if="canManage && view === 'packages' && selected.length"
                    class="flex items-center gap-2"
                >
                    <span
                        class="text-[12.5px]/4 font-medium tracking-[-0.005em] whitespace-nowrap text-fg-secondary"
                        >{{
                            $t(':count selected', {
                                count: String(selected.length),
                            })
                        }}</span
                    >
                    <DashButton
                        v-if="rollingOut.length"
                        :label="$t('Hold')"
                        :disabled="holding"
                        @click="hold(true)"
                    />
                    <DashButton
                        v-if="selectedPackages.some((p) => p.heldOn.length)"
                        :label="$t('Let go')"
                        :disabled="holding"
                        @click="hold(false)"
                    />
                    <DashButton :label="$t('Clear')" @click="selected = []" />
                </div>
                <span
                    v-if="canManage && view === 'packages' && selected.length"
                    class="h-[18px] w-[0.5px] shrink-0 bg-edge-control max-lg:hidden"
                    aria-hidden="true"
                />
                <span
                    class="shrink-0 text-[12px]/4 whitespace-nowrap"
                    :class="
                        checking.length
                            ? 'text-status-deploying'
                            : 'text-fg-muted'
                    "
                    role="status"
                    >{{ checkedLine }}</span
                >
            </div>

            <template v-if="view === 'packages'">
                <PackagesTable
                    v-if="visiblePackages.length"
                    v-model:selected="selected"
                    class="border-t-[0.5px] border-edge"
                    :packages="visiblePackages"
                    :machines="machines"
                    :can-manage="canManage"
                />
                <p
                    v-else
                    class="border-t-[0.5px] border-edge px-5 py-10 text-center text-[13px]/5 text-fg-muted"
                >
                    {{
                        !packages.length
                            ? summary.checkedAt
                                ? $t(
                                      'Nothing to update. Every machine checked is up to date.',
                                  )
                                : $t(
                                      'No machine has been checked yet. Checks run on their own about once a day, or check all now.',
                                  )
                            : $t('No packages match.')
                    }}
                </p>
                <p
                    v-if="machines.some((m) => !m.complete && m.updates)"
                    class="px-5 py-4 text-[12px]/4 text-fg-muted"
                >
                    {{
                        $t(
                            'Some machines run an older daemon that only lists the first few dozen updates. Update it with sudo serverosd update to see them all.',
                        )
                    }}
                </p>
            </template>

            <template v-else>
                <MachinesTable
                    v-if="visibleMachines.length"
                    class="border-t-[0.5px] border-edge"
                    :machines="visibleMachines"
                    :checking="checking"
                    @open="openMachine"
                />
                <p
                    v-else
                    class="border-t-[0.5px] border-edge px-5 py-10 text-center text-[13px]/5 text-fg-muted"
                >
                    {{ $t('No machines match.') }}
                </p>
            </template>
        </template>
    </div>

    <RolloutDialog
        v-model:open="rolloutOpenDialog"
        :packages="rolloutPackages"
        :machines="machines"
        :groups="groups"
        @created="rolloutCreated"
    />
    <RolloutDetailDialog
        v-model:open="rolloutDetailOpen"
        :rollout-id="rolloutId"
        :can-manage="canManage"
        @changed="reload"
    />
    <UpdatesDialog
        v-if="machineDialog"
        v-model:open="machineDialogOpen"
        :machine-id="machineDialog.id"
        :machine-name="machineDialog.name"
    />
</template>
