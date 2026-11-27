<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Ellipsis } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import DashButton from '@/components/dash/DashButton.vue';
import DashHeader from '@/components/dash/DashHeader.vue';
import StatusBadge from '@/components/dash/StatusBadge.vue';
import type { DashStatus } from '@/components/dash/StatusBadge.vue';
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
import { SelectMenu } from '@/components/ui/select';
import type { Graph, Options, Run } from '@/components/workflows/kinds';
import {
    createWorkflowEditor,
    provideWorkflowEditor,
} from '@/components/workflows/useWorkflowEditor';
import WorkflowCanvas from '@/components/workflows/WorkflowCanvas.vue';
import WorkflowSimple from '@/components/workflows/WorkflowSimple.vue';
import { useUpgradeLink } from '@/composables/useUpgradeLink';
import { toast } from '@/lib/toast';
import { onWorkspace, workspacePath } from '@/lib/workspaceRoute';
import workflowsRoutes from '@/routes/workspace/workflows';
import type { Workspace } from '@/types';

type RunSummary = {
    id: number;
    state: Run['state'];
    trigger: string;
    kind: string;
    user: string | null;
    startedAt: string;
    seconds: number;
};

type FullRun = Run & { graph: Graph };

const props = defineProps<{
    workspace: Workspace;
    workflow: {
        id: number;
        name: string;
        description: string | null;
        enabled: boolean;
        trigger: string;
        graph: Graph;
        webhookUrl: string | null;
        nextRunAt: string | null;
        runAs: string | null;
        updatedAt: string | null;
    };
    problems: Record<string, string>;
    options: Options;
    runs: RunSummary[];
    run: FullRun | null;
    lastRun: FullRun | null;
    canManage: boolean;
    planAllows: boolean;
    twoFactor: boolean;
}>();

const upgrade = useUpgradeLink();
const page = usePage<{ errors: Record<string, string> }>();

const mode = ref<'edit' | 'runs'>(props.run ? 'runs' : 'edit');

const VIEW_KEY = 'serveros:workflow-view';
function storedView(): 'easy' | 'power' {
    try {
        return localStorage.getItem(VIEW_KEY) === 'easy' ? 'easy' : 'power';
    } catch {
        return 'power';
    }
}
const view = ref<'easy' | 'power'>(storedView());
const canvas = ref<InstanceType<typeof WorkflowCanvas> | null>(null);
watch(view, (v) => {
    try {
        localStorage.setItem(VIEW_KEY, v);
    } catch {
        return;
    }
    if (v === 'power') requestAnimationFrame(() => canvas.value?.fitView());
});

const readOnly = computed(() => mode.value === 'runs' || !props.canManage);
const editor = createWorkflowEditor(
    props.run?.graph ?? props.workflow.graph,
    readOnly,
);
provideWorkflowEditor(editor);
editor.run.value = props.run ?? props.lastRun;
if (props.run) editor.markSaved();

const name = ref(props.workflow.name);
const enabled = ref(props.workflow.enabled);
const saving = ref(false);
const renaming = ref(false);
const renameTo = ref('');
const confirmingDelete = ref(false);
const now = ref(Date.now());

let draft: Graph | null = null;

const showUrl = computed(
    () =>
        onWorkspace(
            workflowsRoutes.show({
                workspace: props.workspace.slug,
                workflow: props.workflow.id,
            }),
        ).url,
);
const indexUrl = computed(
    () => onWorkspace(workflowsRoutes.index(props.workspace.slug)).url,
);

const dirty = computed(
    () =>
        mode.value === 'edit' &&
        (editor.dirty.value || name.value.trim() !== props.workflow.name),
);
const problemCount = computed(() => Object.keys(props.problems).length);

function since(iso: string | null): string | null {
    if (!iso) return null;
    const seconds = Math.max(
        0,
        Math.round((now.value - new Date(iso).getTime()) / 1000),
    );
    if (seconds < 60) return trans('just now');
    if (seconds < 3600)
        return trans(':count min ago', {
            count: String(Math.floor(seconds / 60)),
        });
    if (seconds < 86400)
        return trans(':count h ago', {
            count: String(Math.floor(seconds / 3600)),
        });

    return trans(':count days ago', {
        count: String(Math.floor(seconds / 86400)),
    });
}

const savedLabel = computed(() => {
    if (mode.value === 'runs') return null;
    if (saving.value) return trans('Saving…');
    if (dirty.value) return trans('Unsaved changes');
    const ago = since(props.workflow.updatedAt);

    return ago ? trans('Saved :when', { when: ago }) : null;
});

const crumbs = computed(() => [
    { label: trans('Workflows'), href: indexUrl.value },
    { label: name.value.trim() || props.workflow.name },
]);

function save(options: { enabled?: boolean; after?: () => void } = {}): void {
    if (!props.canManage) return;
    saving.value = true;
    const graph = editor.serialise();
    router.put(
        workspacePath(`/workflows/${props.workflow.id}`),
        {
            name: name.value.trim() || trans('Untitled workflow'),
            description: props.workflow.description,
            enabled: options.enabled ?? enabled.value,
            graph,
        },
        {
            preserveState: true,
            preserveScroll: true,
            only: ['workflow', 'problems', 'errors', 'flash'],
            onSuccess: () => {
                editor.markSaved(graph);
                enabled.value = options.enabled ?? enabled.value;
                options.after?.();
            },
            onError: (errors) => {
                enabled.value = props.workflow.enabled;
                toast.error(
                    Object.values(errors)[0] ??
                        trans('Couldn’t save the workflow.'),
                );
            },
            onFinish: () => (saving.value = false),
        },
    );
}

function toggle(): void {
    const on = !enabled.value;
    enabled.value = on;
    save({
        enabled: on,
        after: () =>
            toast.success(
                on
                    ? trans(':name is on', { name: props.workflow.name })
                    : trans(':name is off', { name: props.workflow.name }),
            ),
    });
}

function runNow(): void {
    const go = () =>
        router.post(
            workspacePath(`/workflows/${props.workflow.id}/run`),
            {},
            {
                preserveScroll: true,
                onError: (errors) =>
                    toast.error(
                        Object.values(errors)[0] ?? trans('Couldn’t start it.'),
                    ),
            },
        );
    if (dirty.value) save({ after: go });
    else go();
}

function cancelRun(): void {
    if (!props.run) return;
    router.post(
        workspacePath(
            `/workflows/${props.workflow.id}/runs/${props.run.id}/cancel`,
        ),
        {},
        { preserveScroll: true },
    );
}

function rotateWebhook(): void {
    router.post(
        workspacePath(`/workflows/${props.workflow.id}/webhook`),
        {},
        {
            preserveScroll: true,
            preserveState: true,
            only: ['workflow'],
            onSuccess: () =>
                toast.success(
                    trans('New webhook URL; the old one no longer works.'),
                ),
        },
    );
}

function destroy(): void {
    router.delete(workspacePath(`/workflows/${props.workflow.id}`));
}

function startRename(): void {
    renameTo.value = name.value;
    renaming.value = true;
}

function finishRename(): void {
    const next = renameTo.value.trim();
    renaming.value = false;
    if (!next || next === name.value) return;
    name.value = next;
    save();
}

function showRuns(): void {
    if (mode.value === 'runs') return;
    draft = editor.snapshot();
    mode.value = 'runs';
    const latest = props.runs[0];
    if (latest && !props.run) openRun(latest.id);
}

function showEditor(): void {
    if (mode.value === 'edit') return;
    mode.value = 'edit';
    editor.run.value = props.lastRun;
    if (draft) {
        editor.replace(draft);
    } else {
        editor.replace(props.workflow.graph);
        editor.markSaved(props.workflow.graph);
    }
    draft = null;
    if (props.run)
        router.get(
            showUrl.value,
            {},
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: ['run', 'lastRun'],
            },
        );
}

function openRun(id: number): void {
    router.get(
        showUrl.value,
        { run: id },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['run', 'runs'],
        },
    );
}

watch(
    () => props.run,
    (run) => {
        if (!run) return;
        if (mode.value === 'edit') {
            draft = editor.snapshot();
            mode.value = 'runs';
        }
        const sameRun = editor.run.value?.id === run.id;
        editor.run.value = run;
        if (!sameRun) {
            editor.replace(run.graph);
            requestAnimationFrame(() => canvas.value?.fitView());
        }
    },
);

watch(
    () => props.lastRun,
    (run) => {
        if (mode.value === 'edit') editor.run.value = run;
    },
);

let poll: ReturnType<typeof setInterval> | undefined;
let tick: ReturnType<typeof setInterval> | undefined;

function guard(event: BeforeUnloadEvent): void {
    if (dirty.value) event.preventDefault();
}

function onKey(event: KeyboardEvent): void {
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 's') {
        event.preventDefault();
        if (dirty.value) save();
    }
}

onMounted(() => {
    poll = setInterval(() => {
        if (document.visibilityState !== 'visible') return;
        if (mode.value === 'runs' && props.run?.state === 'running') {
            router.reload({ only: ['run', 'runs'] });
        } else if (
            mode.value === 'edit' &&
            props.lastRun?.state === 'running'
        ) {
            router.reload({ only: ['lastRun', 'runs'] });
        }
    }, 2000);
    tick = setInterval(() => (now.value = Date.now()), 15000);
    window.addEventListener('beforeunload', guard);
    window.addEventListener('keydown', onKey);
});

onBeforeUnmount(() => {
    clearInterval(poll);
    clearInterval(tick);
    window.removeEventListener('beforeunload', guard);
    window.removeEventListener('keydown', onKey);
});

const errors = computed(() => page.props.errors ?? {});
watch(errors, (e) => {
    const message = e.run ?? e.graph;
    if (message) toast.error(message);
});

const stateLook: Record<
    Run['state'],
    { label: string; badge: DashStatus; dot: string }
> = {
    running: {
        label: trans('Running'),
        badge: 'deploying',
        dot: 'bg-status-deploying animate-pulse',
    },
    succeeded: {
        label: trans('Succeeded'),
        badge: 'live',
        dot: 'bg-status-live',
    },
    failed: { label: trans('Failed'), badge: 'down', dot: 'bg-status-down' },
    cancelled: {
        label: trans('Cancelled'),
        badge: 'stopped',
        dot: 'bg-status-stopped',
    },
};

function took(seconds: number): string {
    return seconds < 60
        ? trans(':count s', { count: String(seconds) })
        : `${Math.floor(seconds / 60)}m ${seconds % 60}s`;
}

function when(iso: string): string {
    const d = new Date(iso);
    const today = new Date().toDateString() === d.toDateString();

    return d.toLocaleString(
        undefined,
        today
            ? { hour: '2-digit', minute: '2-digit', second: '2-digit' }
            : {
                  month: 'short',
                  day: 'numeric',
                  hour: '2-digit',
                  minute: '2-digit',
              },
    );
}

const lastRunSteps = computed(() => {
    const run = props.lastRun;
    if (!run) return null;
    const ids = run.graph.nodes.map((n) => n.id);
    const states = ids.map((id) => run.steps[id]?.state ?? null);

    return {
        done: states.filter((s) => s === 'succeeded').length,
        total: ids.length,
        segments: [
            ...states.filter((s) => s === 'succeeded'),
            ...states.filter((s) => s === 'failed'),
            ...states.filter((s) => s === 'running' || s === 'waiting'),
            ...states.filter(
                (s) =>
                    s !== 'succeeded' &&
                    s !== 'failed' &&
                    s !== 'running' &&
                    s !== 'waiting',
            ),
        ],
    };
});

const segmentTone: Record<string, string> = {
    succeeded: 'bg-status-live shadow-[0_0_4px_rgb(62_196_109/0.35)]',
    failed: 'bg-status-down',
    running: 'bg-status-deploying animate-pulse',
    waiting: 'bg-status-pending',
};

const dockButton =
    'flex h-8 shrink-0 cursor-pointer items-center justify-center rounded-[8px] px-3 text-[13px]/5 font-medium tracking-[-0.01em] whitespace-nowrap focus-visible:outline-2 focus-visible:outline-(--ds-ring) disabled:cursor-default disabled:opacity-40';
const dockPrimary = `${dockButton} border-[0.5px] border-[#dcdcdc] bg-[#f4f4f4] text-fg-primary shadow-[0_1px_2px_rgb(0_0_0/0.15)] hover:bg-white dark:border-[#363636] dark:bg-[#242424] dark:shadow-[0_1px_2px_rgb(0_0_0/0.5)] dark:hover:bg-[#2a2a2a]`;
const dockQuiet = `${dockButton} text-fg-secondary hover:bg-black/[0.05] hover:text-fg-primary dark:hover:bg-[#242424]`;
</script>

<template>
    <Head :title="workflow.name" />

    <DashHeader no-sidebar :crumbs="crumbs">
        <template #meta>
            <span
                v-if="savedLabel"
                class="text-[12px]/4 tracking-[-0.005em] whitespace-nowrap"
                :class="dirty ? 'text-status-pending' : 'text-fg-faint'"
                :title="
                    workflow.runAs
                        ? $t('Runs as :name', { name: workflow.runAs })
                        : undefined
                "
                >{{ savedLabel }}</span
            >
            <span
                v-if="problemCount && mode === 'edit' && canManage"
                class="ml-2 rounded-full bg-status-pending/10 px-2 py-0.5 text-[12px]/4 font-medium whitespace-nowrap text-status-pending"
                :title="Object.values(problems).join('\n')"
            >
                {{
                    problemCount === 1
                        ? $t('1 step needs a look')
                        : $t(':count steps need a look', {
                              count: String(problemCount),
                          })
                }}
            </span>
        </template>
        <template #actions>
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <button
                        type="button"
                        class="dash-button dash-button--secondary dash-button--icon"
                        :aria-label="$t('More')"
                    >
                        <Ellipsis class="size-4" :stroke-width="1.6" />
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent class="min-w-52 font-ui" align="end">
                    <DropdownMenuItem v-if="canManage" @select="startRename">
                        {{ $t('Rename') }}
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        @select="view = view === 'easy' ? 'power' : 'easy'"
                    >
                        {{
                            view === 'easy'
                                ? $t('Show as a canvas')
                                : $t('Show as a list')
                        }}
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        v-if="canManage && workflow.trigger === 'webhook'"
                        @select="rotateWebhook"
                    >
                        {{ $t('New webhook URL') }}
                    </DropdownMenuItem>
                    <template v-if="canManage">
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            class="text-status-down focus:text-status-down"
                            @select="confirmingDelete = true"
                        >
                            {{ $t('Delete workflow') }}
                        </DropdownMenuItem>
                    </template>
                </DropdownMenuContent>
            </DropdownMenu>
            <button
                v-if="canManage"
                type="button"
                role="switch"
                :aria-checked="enabled"
                :disabled="
                    !planAllows || saving || (!enabled && problemCount > 0)
                "
                :title="
                    problemCount && !enabled
                        ? $t('Fix the marked steps to turn it on')
                        : enabled
                          ? $t('It runs when its trigger happens')
                          : $t('Off: it only runs when you press Run now')
                "
                class="flex h-8 shrink-0 cursor-pointer items-center gap-2 rounded-[8px] border pr-1.5 pl-2.5 text-[13px]/5 font-medium tracking-[-0.01em] transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring) disabled:cursor-default disabled:opacity-50"
                :class="
                    enabled
                        ? 'border-[rgb(62_196_109/0.22)] bg-[rgb(62_196_109/0.1)] text-[#23a050] dark:text-[#5fd88a]'
                        : 'border-edge-control bg-surface-control text-fg-secondary'
                "
                @click="toggle"
            >
                {{ enabled ? $t('Active') : $t('Off') }}
                <span
                    class="relative h-[18px] w-[30px] rounded-full transition-colors"
                    :class="
                        enabled
                            ? 'bg-[#2fb35f]'
                            : 'bg-edge-checkbox shadow-[inset_0_1px_2px_rgb(0_0_0/0.35)]'
                    "
                    aria-hidden="true"
                >
                    <span
                        class="absolute top-0.5 left-0.5 size-3.5 rounded-full bg-white shadow-[0_1px_2px_rgb(0_0_0/0.4)] transition-transform duration-200 ease-[cubic-bezier(0.32,0.72,0,1)] motion-reduce:transition-none"
                        :class="{ 'translate-x-3': enabled }"
                    />
                </span>
            </button>
            <DashButton
                icon="history"
                :label="mode === 'runs' ? $t('Editor') : $t('Runs')"
                :aria-pressed="mode === 'runs'"
                @click="mode === 'runs' ? showEditor() : showRuns()"
            />
            <DashButton
                v-if="canManage"
                variant="primary"
                :label="$t('Save')"
                :loading="saving"
                :disabled="!dirty"
                @click="save()"
            />
        </template>
    </DashHeader>

    <div class="flex h-full min-h-[560px] flex-col">
        <div
            v-if="!planAllows"
            class="border-b-[0.5px] border-edge bg-status-pending/8 px-5 py-2 text-[13px]/5 text-fg-secondary"
        >
            {{
                $t(
                    'Workflows come with Pro and Scale. You can look around and edit; turning one on or running it needs',
                ) + ' '
            }}
            <Link
                v-if="upgrade"
                :href="upgrade.href"
                class="font-medium text-dash-blue hover:underline"
                >{{ $t('a plan that includes them') }}</Link
            ><template v-else>{{ $t('a plan that includes them') }}</template>.
        </div>

        <template v-if="view === 'easy'">
            <div
                v-if="mode === 'runs'"
                class="flex flex-wrap items-center gap-3 border-b-[0.5px] border-edge px-5 py-2.5 text-[13px]/5"
            >
                <template v-if="runs.length">
                    <SelectMenu
                        class="h-8 max-w-full rounded-[8px] border-[0.5px] border-edge-field bg-surface-field px-2 text-[13px]/5"
                        :model-value="run?.id ?? ''"
                        :aria-label="$t('Run')"
                        :options="
                            runs.map((r) => ({
                                value: r.id,
                                label: `#${r.id} · ${stateLook[r.state].label} · ${when(r.startedAt)} · ${r.trigger}`,
                            }))
                        "
                        @update:model-value="(v) => openRun(Number(v))"
                    />
                    <StatusBadge
                        v-if="run"
                        :status="stateLook[run.state].badge"
                        :label="stateLook[run.state].label"
                    />
                    <span v-if="run" class="text-fg-muted">{{
                        $t('took :time', { time: took(run.seconds) })
                    }}</span>
                    <DashButton
                        v-if="run?.state === 'running' && canManage"
                        size="sm"
                        :label="$t('Cancel')"
                        @click="cancelRun"
                    />
                </template>
                <span v-else class="text-fg-muted">{{
                    $t(
                        'No runs yet. Press Run now to try it; runs from its trigger show up here too.',
                    )
                }}</span>
                <DashButton
                    size="sm"
                    class="ml-auto"
                    :label="$t('Back to editing')"
                    @click="showEditor"
                />
            </div>
            <div
                v-else-if="canManage"
                class="flex items-center gap-2 border-b-[0.5px] border-edge px-5 py-2.5"
            >
                <DashButton
                    size="sm"
                    :label="$t('Run now')"
                    :disabled="!planAllows || saving || problemCount > 0"
                    @click="runNow"
                />
            </div>
            <WorkflowSimple
                :options="options"
                :webhook-url="workflow.webhookUrl"
                :two-factor="twoFactor"
                :problems="mode === 'edit' ? problems : {}"
                @rotate-webhook="rotateWebhook"
            />
        </template>

        <WorkflowCanvas
            v-else
            ref="canvas"
            :options="options"
            :webhook-url="workflow.webhookUrl"
            :two-factor="twoFactor"
            :problems="mode === 'edit' ? problems : {}"
            :inset-left="mode === 'runs' ? 320 : 0"
            @rotate-webhook="rotateWebhook"
        >
            <template #dock>
                <template v-if="mode === 'runs'">
                    <button
                        v-if="run?.state === 'running' && canManage"
                        type="button"
                        :class="dockQuiet"
                        @click="cancelRun"
                    >
                        {{ $t('Cancel run') }}
                    </button>
                    <button
                        type="button"
                        :class="dockPrimary"
                        @click="showEditor"
                    >
                        {{ $t('Back to editing') }}
                    </button>
                </template>
                <button
                    v-else-if="canManage"
                    type="button"
                    :class="dockPrimary"
                    :disabled="!planAllows || saving || problemCount > 0"
                    :title="
                        problemCount
                            ? $t('Fix the steps marked with a warning first')
                            : $t('Run it now, by hand')
                    "
                    @click="runNow"
                >
                    {{ $t('Run now') }}
                </button>
            </template>

            <aside
                v-if="mode === 'runs'"
                :aria-label="$t('Runs')"
                data-canvas-overlay
                class="absolute top-5 left-5 z-20 flex max-h-[calc(100%-110px)] w-[min(300px,calc(100%-40px))] flex-col overflow-hidden rounded-[14px] border-[0.5px] border-edge-field bg-[#fafafa] shadow-[0_10px_24px_-6px_rgb(0_0_0/0.45),0_1px_2px_rgb(0_0_0/0.5)] dark:bg-[#171717]"
                @pointerdown.stop
            >
                <div
                    class="flex shrink-0 items-baseline justify-between px-4 pt-3.5 pb-2.5"
                >
                    <span
                        class="text-[14px]/5 font-medium tracking-[-0.01em] text-fg-primary"
                        >{{ $t('Runs') }}</span
                    >
                    <span class="text-[12px]/4 text-fg-faint">{{
                        $t('Last 30')
                    }}</span>
                </div>
                <ol
                    v-if="runs.length"
                    class="min-h-0 overflow-y-auto px-2 pb-2"
                >
                    <li v-for="r in runs" :key="r.id">
                        <button
                            type="button"
                            class="flex w-full cursor-pointer flex-col gap-0.5 rounded-[10px] px-2.5 py-2 text-left hover:bg-black/[0.04] dark:hover:bg-[#1f1f1f]"
                            :class="{
                                'bg-black/[0.04] dark:bg-[#1f1f1f]':
                                    run?.id === r.id,
                            }"
                            @click="openRun(r.id)"
                        >
                            <span class="flex items-center gap-2 text-[13px]/5">
                                <span
                                    class="size-1.5 shrink-0 rounded-full"
                                    :class="stateLook[r.state].dot"
                                />
                                <span class="font-medium text-fg-primary"
                                    >#{{ r.id }}</span
                                >
                                <span class="text-fg-secondary">{{
                                    stateLook[r.state].label
                                }}</span>
                                <span
                                    class="ml-auto text-[12px] text-fg-faint tabular-nums"
                                    >{{ when(r.startedAt) }}</span
                                >
                            </span>
                            <span
                                class="truncate pl-3.5 text-[12px]/4 text-fg-tertiary"
                            >
                                {{ r.trigger }} · {{ took(r.seconds) }}
                            </span>
                        </button>
                    </li>
                </ol>
                <p v-else class="px-4 pt-1 pb-5 text-[13px]/5 text-fg-tertiary">
                    {{
                        $t(
                            'No runs yet. Press Run now to try it; runs from its trigger show up here too.',
                        )
                    }}
                </p>
            </aside>

            <button
                v-if="mode === 'edit' && lastRun && lastRunSteps"
                type="button"
                data-canvas-overlay
                class="absolute bottom-5 left-5 z-20 flex w-[268px] cursor-pointer flex-col gap-2.5 rounded-[12px] border-[0.5px] border-edge-field bg-[#fafafa] px-3.5 pt-3 pb-3.5 text-left shadow-[0_10px_24px_-6px_rgb(0_0_0/0.45),0_1px_2px_rgb(0_0_0/0.5)] hover:border-edge-checkbox-hover focus-visible:outline-2 focus-visible:outline-(--ds-ring) max-md:hidden dark:bg-[#171717]"
                :title="$t('See what each step did')"
                @pointerdown.stop
                @click="openRun(lastRun.id)"
            >
                <span class="flex w-full items-center gap-2">
                    <span
                        class="flex-1 text-[13px]/5 font-medium tracking-[-0.01em] text-fg-primary"
                        >{{ $t('Last run') }}</span
                    >
                    <StatusBadge
                        :status="stateLook[lastRun.state].badge"
                        :label="stateLook[lastRun.state].label"
                    />
                </span>
                <span
                    class="text-[12px]/4 tracking-[-0.005em] whitespace-nowrap text-fg-tertiary"
                >
                    {{
                        $t(':when · took :time · :done of :total steps', {
                            when: since(lastRun.startedAt) ?? '',
                            time: took(lastRun.seconds),
                            done: String(lastRunSteps.done),
                            total: String(lastRunSteps.total),
                        })
                    }}
                </span>
                <span class="flex w-full gap-1" aria-hidden="true">
                    <span
                        v-for="(state, i) in lastRunSteps.segments"
                        :key="i"
                        class="h-1.5 min-w-0 flex-1 rounded-[3px]"
                        :class="segmentTone[state ?? ''] ?? 'bg-surface-tile'"
                    />
                </span>
            </button>
        </WorkflowCanvas>
    </div>

    <Dialog v-model:open="renaming">
        <DialogContent class="gap-0 p-0 font-ui sm:max-w-md">
            <form @submit.prevent="finishRename">
                <div class="flex flex-col gap-3 p-5">
                    <DialogTitle
                        class="text-[15px]/6 font-medium text-fg-primary"
                        >{{ $t('Rename workflow') }}</DialogTitle
                    >
                    <DialogDescription class="sr-only">{{
                        $t('Give the workflow a new name.')
                    }}</DialogDescription>
                    <label class="dash-field">
                        <span class="sr-only">{{ $t('Name') }}</span>
                        <input
                            v-model="renameTo"
                            maxlength="80"
                            class="h-full w-full bg-transparent px-2.5 text-[13px]/5 text-fg-primary outline-none"
                        />
                    </label>
                </div>
                <div class="dash-card-footer gap-2">
                    <DashButton
                        :label="$t('Cancel')"
                        @click="renaming = false"
                    />
                    <DashButton
                        type="submit"
                        variant="primary"
                        :label="$t('Rename')"
                    />
                </div>
            </form>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="confirmingDelete">
        <DialogContent class="gap-0 p-0 font-ui sm:max-w-md">
            <div class="flex flex-col gap-1 p-5">
                <DialogTitle
                    class="text-[15px]/6 font-medium text-fg-primary"
                    >{{
                        $t('Delete :name?', { name: workflow.name })
                    }}</DialogTitle
                >
                <DialogDescription class="text-[13px]/5 text-fg-tertiary">
                    {{
                        $t(
                            'Its runs go with it. Turn it off instead to keep them.',
                        )
                    }}
                </DialogDescription>
            </div>
            <div class="dash-card-footer gap-2">
                <DashButton
                    :label="$t('Cancel')"
                    @click="confirmingDelete = false"
                />
                <DashButton
                    variant="danger"
                    :label="$t('Delete workflow')"
                    @click="destroy"
                />
            </div>
        </DialogContent>
    </Dialog>
</template>
