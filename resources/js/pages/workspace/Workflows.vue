<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed, onMounted, ref } from 'vue';
import DashButton from '@/components/dash/DashButton.vue';
import DashHeader from '@/components/dash/DashHeader.vue';
import DashTable from '@/components/dash/DashTable.vue';
import DashTd from '@/components/dash/DashTd.vue';
import DashTh from '@/components/dash/DashTh.vue';
import DashTr from '@/components/dash/DashTr.vue';
import FilterPills from '@/components/dash/FilterPills.vue';
import SearchWell from '@/components/dash/SearchWell.vue';
import StatusBadge from '@/components/dash/StatusBadge.vue';
import type { DashStatus } from '@/components/dash/StatusBadge.vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import type { FilledIconName } from '@/components/icons/filledIcons';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { EVENTS } from '@/components/workflows/kinds';
import type { Graph, Options } from '@/components/workflows/kinds';
import TemplatePicker from '@/components/workflows/TemplatePicker.vue';
import type { Template } from '@/components/workflows/TemplatePicker.vue';
import WorkflowPreview from '@/components/workflows/WorkflowPreview.vue';
import PlanLock from '@/components/workspace/PlanLock.vue';
import { toast } from '@/lib/toast';
import { onWorkspace } from '@/lib/workspaceRoute';
import workflowsRoutes from '@/routes/workspace/workflows';
import type { Workspace, WorkspaceAccess } from '@/types';

type WorkflowRow = {
    id: number;
    name: string;
    description: string | null;
    enabled: boolean;
    trigger: string;
    rules: Record<string, string | number | null>;
    steps: number;
    lastRun: { state: string; at: string } | null;
    lastRunAt: string | null;
    runs7d: number;
    failed7d: number;
    nextRunAt: string | null;
};

const props = defineProps<{
    workspace: Workspace;
    workflows: WorkflowRow[];
    templates: Template[];
    planAllows: boolean;
    preview: { graph: Graph; options: Options } | null;
}>();

const page = usePage<{ workspaceAccess: WorkspaceAccess | null }>();
const canManage = computed(
    () =>
        page.props.workspaceAccess?.permissions.includes('manage-machines') ??
        false,
);

const picking = ref(false);
const creating = ref<string | null>(null);
const search = ref('');

type Group = 'all' | 'deploys' | 'incidents' | 'scheduled' | 'manual';
const filter = ref<Group>('all');

onMounted(() => {
    if (
        new URLSearchParams(window.location.search).has('new') &&
        canManage.value &&
        props.planAllows &&
        props.workflows.length
    ) {
        picking.value = true;
    }
});

function create(key: string): void {
    creating.value = key;
    router.post(
        onWorkspace(workflowsRoutes.store(props.workspace.slug)).url,
        { template: key },
        {
            onError: (errors) =>
                toast.error(
                    Object.values(errors)[0] ?? trans('Couldn’t create it.'),
                ),
            onFinish: () => (creating.value = null),
        },
    );
}

function showHref(row: WorkflowRow): string {
    return onWorkspace(
        workflowsRoutes.show({
            workspace: props.workspace.slug,
            workflow: row.id,
        }),
    ).url;
}

const groups: {
    key: Exclude<Group, 'all'>;
    label: string;
    triggers: string[];
}[] = [
    {
        key: 'deploys',
        label: trans('Deploys'),
        triggers: ['git.push', 'deploy.finished', 'webhook'],
    },
    {
        key: 'incidents',
        label: trans('Incidents'),
        triggers: [
            'service.down',
            'service.crash_loop',
            'monitor.down',
            'monitor.up',
            'machine.offline',
            'machine.disk_full',
            'backup.failed',
        ],
    },
    { key: 'scheduled', label: trans('Scheduled'), triggers: ['schedule'] },
    { key: 'manual', label: trans('By hand'), triggers: ['manual'] },
];

function groupOf(row: WorkflowRow): Exclude<Group, 'all'> {
    return (
        groups.find((g) => g.triggers.includes(row.trigger))?.key ?? 'manual'
    );
}

const pills = computed(() => [
    {
        value: 'all' as Group,
        label: trans('All'),
        count: props.workflows.length,
    },
    ...groups
        .map((g) => ({
            value: g.key as Group,
            label: g.label,
            count: props.workflows.filter((w) => groupOf(w) === g.key).length,
        }))
        .filter((pill) => pill.count > 0),
]);

const shown = computed(() => {
    const needle = search.value.trim().toLowerCase();

    return props.workflows.filter(
        (w) =>
            (filter.value === 'all' || groupOf(w) === filter.value) &&
            (!needle ||
                `${w.name} ${w.description ?? ''}`
                    .toLowerCase()
                    .includes(needle)),
    );
});

const triggerIcons: Record<string, FilledIconName> = {
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

function triggerLabel(row: WorkflowRow): string {
    if (row.trigger === 'schedule') {
        const every = row.rules.every;

        return every
            ? every === 'hour'
                ? trans('Every hour')
                : trans(
                      every === 'week'
                          ? 'Every week at :time UTC'
                          : 'Every day at :time UTC',
                      { time: String(row.rules.at ?? '03:00') },
                  )
            : trans('On a schedule');
    }

    return EVENTS.find((e) => e.id === row.trigger)?.label ?? row.trigger;
}

function ago(iso: string | null): string {
    if (!iso) return trans('Never');
    const s = Math.max(
        0,
        Math.round((Date.now() - new Date(iso).getTime()) / 1000),
    );
    if (s < 60) return trans('just now');
    if (s < 3600)
        return trans(':count min ago', { count: String(Math.floor(s / 60)) });
    if (s < 86400)
        return trans(':count h ago', { count: String(Math.floor(s / 3600)) });

    return trans(':count days ago', { count: String(Math.floor(s / 86400)) });
}

const runLook: Record<string, { status: DashStatus; label: string }> = {
    running: { status: 'deploying', label: trans('Running') },
    succeeded: { status: 'live', label: trans('Succeeded') },
    failed: { status: 'down', label: trans('Failed') },
    cancelled: { status: 'stopped', label: trans('Cancelled') },
};
</script>

<template>
    <Head :title="$t('Workflows')" />

    <DashHeader :crumbs="[{ label: $t('Workflows') }]">
        <template v-if="canManage && planAllows && workflows.length" #actions>
            <DashButton
                variant="primary"
                :icon="Plus"
                :label="$t('New workflow')"
                @click="picking = true"
            />
        </template>
    </DashHeader>

    <div class="flex min-h-full flex-col">
        <div v-if="preview" class="p-5">
            <PlanLock
                :locked="!planAllows"
                feature="Workflows"
                plan="pro"
                :description="
                    $t(
                        'Deploy when CI passes, restart what falls over and tell the team, drawn as steps and run for you.',
                    )
                "
            >
                <WorkflowPreview
                    :graph="preview.graph"
                    :options="preview.options"
                />
            </PlanLock>
        </div>

        <div
            v-else-if="!workflows.length"
            class="mx-auto flex w-full max-w-4xl flex-col gap-6 px-5 py-12"
        >
            <div class="flex flex-col items-center text-center">
                <span
                    class="flex size-10 items-center justify-center rounded-[10px] border-[0.5px] border-edge-control bg-surface-raised text-fg-tertiary shadow-tile"
                    aria-hidden="true"
                >
                    <FilledIcon name="branch-fill" :size="20" />
                </span>
                <h2
                    class="mt-4 text-[15px]/6 font-medium tracking-[-0.01em] text-fg-primary"
                >
                    {{ $t('Automate what you do by hand') }}
                </h2>
                <p class="mt-1 max-w-md text-[13px]/5 text-fg-tertiary">
                    {{
                        $t(
                            'Pick a starting point for how you work. Each one opens ready to use with your machines filled in; change anything you like, and nothing runs until you turn it on.',
                        )
                    }}
                </p>
            </div>
            <TemplatePicker
                :templates="templates"
                :disabled="!canManage || !planAllows"
                :creating="creating"
                @pick="create"
            />
        </div>

        <template v-else>
            <div class="flex min-h-14 flex-wrap items-center gap-2 px-5 py-3">
                <SearchWell
                    v-model="search"
                    :placeholder="$t('Search workflows')"
                    :width="300"
                    class="max-sm:w-full!"
                />
                <FilterPills
                    v-model="filter"
                    :label="$t('Show')"
                    :options="pills"
                />
                <span class="min-w-0 flex-1" />
                <span class="text-[12px]/4 text-fg-muted">{{
                    $t(
                        'Deploys, restarts, checks and alerts, run for you when something happens.',
                    )
                }}</span>
            </div>

            <DashTable class="border-t-[0.5px] border-edge-tabs">
                <template #head>
                    <DashTh icon="branch-fill" :width="300" first>{{
                        $t('Workflow')
                    }}</DashTh>
                    <DashTh icon="activity-fill" :width="220">{{
                        $t('Starts when')
                    }}</DashTh>
                    <DashTh icon="layers-fill" :width="80">{{
                        $t('Steps')
                    }}</DashTh>
                    <DashTh icon="history" :width="210">{{
                        $t('Last run')
                    }}</DashTh>
                    <DashTh icon="table-fill" :width="120">{{
                        $t('Last 7 days')
                    }}</DashTh>
                    <DashTh icon="setup-fill" fill>{{ $t('State') }}</DashTh>
                </template>
                <DashTr
                    v-for="w in shown"
                    :key="w.id"
                    clickable
                    @click="router.visit(showHref(w))"
                >
                    <DashTd first>
                        <span class="flex max-w-[164px] min-w-0 flex-col">
                            <a
                                :href="showHref(w)"
                                class="truncate font-medium tracking-[-0.01em] text-fg-primary"
                                @click.prevent
                                >{{ w.name }}</a
                            >
                        </span>
                        <span
                            v-if="w.description"
                            class="max-w-[96px] truncate text-[12px]/4 text-fg-faint"
                            :title="w.description"
                            >{{ w.description }}</span
                        >
                    </DashTd>
                    <DashTd>
                        <FilledIcon
                            :name="triggerIcons[w.trigger] ?? 'activity-fill'"
                            class="text-fg-muted"
                        />
                        <span class="truncate">{{ triggerLabel(w) }}</span>
                    </DashTd>
                    <DashTd>
                        <span class="font-ui-mono text-[12px]/4">{{
                            w.steps
                        }}</span>
                    </DashTd>
                    <DashTd>
                        <template v-if="w.lastRun && runLook[w.lastRun.state]">
                            <StatusBadge
                                :status="runLook[w.lastRun.state].status"
                                :label="runLook[w.lastRun.state].label"
                            />
                            <span class="text-[12px]/4 text-fg-tertiary">{{
                                ago(w.lastRun.at)
                            }}</span>
                        </template>
                        <span v-else class="text-[12px]/4 text-fg-faint">{{
                            w.lastRunAt ? ago(w.lastRunAt) : $t('Never run')
                        }}</span>
                    </DashTd>
                    <DashTd>
                        <span class="font-ui-mono text-[12px]/4">{{
                            w.runs7d
                        }}</span>
                        <span
                            v-if="w.failed7d"
                            class="text-[12px]/4 text-status-down"
                            >{{
                                $t(':count failed', {
                                    count: String(w.failed7d),
                                })
                            }}</span
                        >
                    </DashTd>
                    <DashTd>
                        <span
                            class="inline-flex h-[22px] items-center gap-1.5 rounded-[6px] border px-2 text-[12px]/4 font-medium"
                            :class="
                                w.enabled
                                    ? 'border-[rgb(62_196_109/0.22)] bg-[rgb(62_196_109/0.1)] text-[#23a050] dark:text-[#5fd88a]'
                                    : 'border-edge-control bg-surface-control text-fg-tertiary'
                            "
                        >
                            <span
                                class="size-1.5 rounded-full"
                                :class="
                                    w.enabled
                                        ? 'bg-status-live'
                                        : 'bg-status-stopped'
                                "
                            />
                            {{ w.enabled ? $t('Active') : $t('Off') }}
                        </span>
                        <span
                            v-if="w.enabled && w.nextRunAt"
                            class="text-[12px]/4 text-fg-faint"
                            >{{
                                $t('next :when', {
                                    when: new Date(
                                        w.nextRunAt,
                                    ).toLocaleTimeString(undefined, {
                                        hour: '2-digit',
                                        minute: '2-digit',
                                    }),
                                })
                            }}</span
                        >
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
    </div>

    <Dialog v-model:open="picking">
        <DialogContent
            class="flex max-h-[min(86vh,760px)] flex-col gap-0 overflow-hidden p-0 font-ui sm:max-w-2xl"
        >
            <div class="flex shrink-0 flex-col gap-1 px-5 pt-5 pb-3">
                <DialogTitle
                    class="text-[15px]/6 font-medium text-fg-primary"
                    >{{ $t('New workflow') }}</DialogTitle
                >
                <DialogDescription class="text-[13px]/5 text-fg-tertiary">
                    {{
                        $t(
                            'Start from one of these and change anything you like. It stays off until you turn it on.',
                        )
                    }}
                </DialogDescription>
            </div>
            <div class="flex min-h-0 flex-1 flex-col px-5 pb-5">
                <TemplatePicker
                    :templates="templates"
                    :creating="creating"
                    compact
                    @pick="create"
                />
            </div>
        </DialogContent>
    </Dialog>
</template>
