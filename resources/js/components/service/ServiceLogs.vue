<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    useTemplateRef,
    watch,
} from 'vue';
import chevron from '@/components/dash/assets/chevron-small.svg?raw';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import type { LogSummary } from '@/components/service/serviceTypes';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { jobReason } from '@/lib/errors';
import { onWorkspace } from '@/lib/workspaceRoute';
import machinesRoutes from '@/routes/workspace/machines';

type Line = {
    id: number;
    at: number;
    level: string;
    stream: string;
    message: string;
};

type MachineJob = {
    state: string;
    result: Record<string, unknown> | null;
    error: { message?: string; next_step?: string } | null;
} | null;

const props = defineProps<{
    workspaceSlug: string;
    machineId: number;
    serviceKey: string;
    summary: LogSummary;
    managed: boolean;
    canFetch: boolean;
    machineJob: MachineJob;
    historyUrl: string;
    planLabel: string | null;
}>();

const emit = defineEmits<{ fetch: [lines: number] }>();

const LINE_OPTIONS = [100, 300, 1000] as const;
const RANGES = [
    { value: '15m', label: trans('Last 15 minutes') },
    { value: '1h', label: trans('Last hour') },
    { value: '6h', label: trans('Last 6 hours') },
    { value: '24h', label: trans('Last 24 hours') },
    { value: '7d', label: trans('Last 7 days') },
    { value: 'all', label: trans('Everything kept') },
] as const;

const fromMachine = ref(!props.summary.stored);
const fromStore = computed(() => !fromMachine.value);
const count = ref<(typeof LINE_OPTIONS)[number]>(300);
const range = ref<(typeof RANGES)[number]['value']>('1h');
const query = ref('');
const live = ref(true);
const lines = ref<Line[]>([]);
const today = ref<number | null>(props.summary.today);
const loading = ref(false);
const failed = ref<string | null>(null);
const viewer = useTemplateRef<HTMLElement>('viewer');

const rangeLabel = computed(
    () => RANGES.find((option) => option.value === range.value)?.label ?? '',
);

function url(extra: Record<string, string | number>): string {
    const params: Record<string, string | number> = {
        lines: count.value,
        ...(range.value !== 'all' ? { range: range.value } : {}),
        ...(query.value.trim() ? { q: query.value.trim() } : {}),
        ...extra,
    };

    return onWorkspace(
        machinesRoutes.services.logLines(
            {
                workspace: props.workspaceSlug,
                machine: props.machineId,
                service: props.serviceKey,
            },
            { query: params },
        ),
    ).url;
}

function atBottom(): boolean {
    const box = viewer.value;

    return !box || box.scrollHeight - box.scrollTop - box.clientHeight < 40;
}

function toBottom(): void {
    void nextTick(() => {
        if (viewer.value) viewer.value.scrollTop = viewer.value.scrollHeight;
    });
}

let asked = 0;

async function load(): Promise<void> {
    if (!fromStore.value) return;
    const ticket = ++asked;
    loading.value = true;
    failed.value = null;
    try {
        const response = await fetch(url({}), {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });
        const body = await response.json().catch(() => ({}));
        if (ticket !== asked) return;
        if (!response.ok) {
            failed.value =
                body.message ?? trans('The lines couldn’t be loaded.');

            return;
        }
        lines.value = body.lines;
        today.value = body.today ?? today.value;
        toBottom();
    } catch {
        if (ticket === asked) {
            failed.value = trans('The lines couldn’t be loaded.');
        }
    } finally {
        if (ticket === asked) loading.value = false;
    }
}

async function follow(): Promise<void> {
    if (!fromStore.value || !live.value || loading.value) return;
    if (document.visibilityState !== 'visible') return;
    const last = lines.value[lines.value.length - 1]?.id ?? 0;
    const ticket = asked;
    try {
        const response = await fetch(url({ after: last, lines: 1000 }), {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });
        if (!response.ok || ticket !== asked) return;
        const body = (await response.json()) as {
            lines: Line[];
            today: number | null;
        };
        today.value = body.today ?? today.value;
        if (!body.lines.length) return;
        const stick = atBottom();
        lines.value = [...lines.value, ...body.lines].slice(-count.value);
        if (stick) toBottom();
    } catch {
        // The next tick tries again.
    }
}

let timer: ReturnType<typeof setInterval> | undefined;
let typing: ReturnType<typeof setTimeout> | undefined;

onMounted(() => {
    if (fromStore.value) void load();
    else if (!props.machineJob) emit('fetch', count.value);
    timer = setInterval(() => void follow(), 3000);
});

onBeforeUnmount(() => {
    clearInterval(timer);
    clearTimeout(typing);
});

watch([count, range], () => {
    if (fromStore.value) void load();
    else emit('fetch', count.value);
});

watch(query, () => {
    if (!fromStore.value) return;
    clearTimeout(typing);
    typing = setTimeout(() => void load(), 300);
});

function readMachine(): void {
    fromMachine.value = true;
    emit('fetch', count.value);
}

function readStore(): void {
    fromMachine.value = false;
    void load();
}

const machineLines = computed(() => {
    const raw = (props.machineJob?.result?.lines as string[] | undefined) ?? [];
    const needle = query.value.trim().toLowerCase();

    return needle
        ? raw.filter((line) => line.toLowerCase().includes(needle))
        : raw;
});

watch(
    () => machineLines.value.length,
    () => toBottom(),
);

const machineBusy = computed(
    () =>
        !!props.machineJob &&
        ['queued', 'running'].includes(props.machineJob.state),
);

const time = new Intl.DateTimeFormat(undefined, {
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    hour12: false,
});

function clock(at: number): string {
    return time.format(new Date(at));
}

const LEVEL = 'error|err|fatal|warn(?:ing)?|info|notice|debug|trace';
const MARKER = new RegExp(
    `^\\s*(?:\\[\\s*(?:${LEVEL})\\s*\\]|(?:${LEVEL})\\s*[-:|])\\s*`,
    'i',
);

function message(line: Line): string {
    return line.message.replace(MARKER, '');
}

const tone: Record<string, { row: string; level: string; text: string }> = {
    error: {
        row: 'bg-status-down/8',
        level: 'text-status-down',
        text: 'text-fg-primary',
    },
    warn: {
        row: 'bg-[#f5a833]/5',
        level: 'text-[#c77d12] dark:text-[#f5a833]',
        text: 'text-fg-primary',
    },
    info: { row: '', level: 'text-fg-muted', text: 'text-fg-secondary' },
    debug: { row: '', level: 'text-fg-faint', text: 'text-fg-tertiary' },
};

const footer = computed(() => {
    if (!fromStore.value) {
        if (props.summary.stored) {
            return trans('These lines come straight from the machine.');
        }

        return props.summary.shipping
            ? trans(
                  'Nothing from this service is kept yet, so these lines come straight from the machine.',
              )
            : trans(
                  'This service’s logs aren’t kept, so these lines come straight from the machine.',
              );
    }
    const count = lines.value.length;
    const shown =
        count === 0
            ? today.value
                ? trans(':today lines today.', {
                      today: today.value.toLocaleString(),
                  })
                : trans('No lines today.')
            : today.value !== null && today.value >= count
              ? trans('Showing :shown of :today lines today.', {
                    shown: count.toLocaleString(),
                    today: today.value.toLocaleString(),
                })
              : trans('Showing :shown lines.', {
                    shown: count.toLocaleString(),
                });
    const days = props.summary.retentionDays;
    const kept = props.planLabel
        ? days === 1
            ? trans(':plan keeps logs for 1 day.', { plan: props.planLabel })
            : trans(':plan keeps logs for :days days.', {
                  plan: props.planLabel,
                  days: String(days),
              })
        : days === 1
          ? trans('Logs are kept for 1 day.')
          : trans('Logs are kept for :days days.', { days: String(days) });

    return `${shown} ${kept}`;
});

const field =
    'flex h-8 w-[280px] shrink-0 cursor-text items-center gap-2 rounded-[8px] border-[0.5px] border-edge-card bg-surface-field px-2.5 shadow-field focus-within:border-edge-checkbox-hover max-sm:w-full';
const select =
    'flex h-8 shrink-0 cursor-pointer items-center gap-2 rounded-[8px] border-[0.5px] border-edge-control bg-[#f7f7f7] pr-2.5 pl-3 text-[13px]/5 font-medium tracking-[-0.005em] whitespace-nowrap text-fg-secondary shadow-card hover:text-fg-primary focus-visible:outline-2 focus-visible:outline-(--ds-ring) data-[state=open]:text-fg-primary dark:bg-[#1c1c1c]';
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col gap-3 px-6 pt-4 pb-[34px]">
        <div class="flex flex-wrap items-center gap-2">
            <label :class="field">
                <FilledIcon
                    name="search-fill"
                    :size="14"
                    class="shrink-0 text-fg-muted"
                />
                <span class="sr-only">{{ $t('Filter log lines') }}</span>
                <input
                    v-model="query"
                    type="search"
                    :placeholder="$t('Filter lines')"
                    class="h-full min-w-0 grow bg-transparent text-[13px]/5 tracking-[-0.005em] text-fg-primary outline-none placeholder:text-fg-faint [&::-webkit-search-cancel-button]:hidden"
                />
            </label>
            <DropdownMenu>
                <DropdownMenuTrigger :class="select">
                    {{
                        $t('Last :count lines', {
                            count: count.toLocaleString(),
                        })
                    }}
                    <span
                        class="flex size-[13px] shrink-0 text-fg-muted"
                        aria-hidden="true"
                        v-html="chevron"
                    />
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    class="ds-modal min-w-40 font-ui"
                    align="start"
                >
                    <DropdownMenuItem
                        v-for="option in LINE_OPTIONS"
                        :key="option"
                        @select="count = option"
                    >
                        {{
                            $t('Last :count lines', {
                                count: option.toLocaleString(),
                            })
                        }}
                        <FilledIcon
                            v-if="count === option"
                            name="check"
                            class="ml-auto text-fg-muted"
                        />
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
            <DropdownMenu v-if="fromStore">
                <DropdownMenuTrigger :class="select">
                    {{ rangeLabel }}
                    <span
                        class="flex size-[13px] shrink-0 text-fg-muted"
                        aria-hidden="true"
                        v-html="chevron"
                    />
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    class="ds-modal min-w-44 font-ui"
                    align="start"
                >
                    <DropdownMenuItem
                        v-for="option in RANGES"
                        :key="option.value"
                        @select="range = option.value"
                    >
                        {{ option.label }}
                        <FilledIcon
                            v-if="range === option.value"
                            name="check"
                            class="ml-auto text-fg-muted"
                        />
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
            <span class="grow" />
            <button
                v-if="fromStore"
                type="button"
                class="flex h-5 cursor-pointer items-center gap-1.5 rounded-md text-[13px]/5 tracking-[-0.005em] text-fg-secondary hover:text-fg-primary focus-visible:outline-2 focus-visible:outline-(--ds-ring)"
                :aria-pressed="live"
                :title="live ? $t('Pause') : $t('Follow new lines')"
                @click="live = !live"
            >
                <span
                    class="size-1.5 rounded-full"
                    :class="live ? 'bg-status-live' : 'bg-status-stopped'"
                    aria-hidden="true"
                />
                {{ live ? $t('Live') : $t('Paused') }}
            </button>
            <button
                v-else
                type="button"
                class="flex h-5 cursor-pointer items-center gap-1.5 rounded-md text-[13px]/5 tracking-[-0.005em] text-fg-secondary hover:text-fg-primary focus-visible:outline-2 focus-visible:outline-(--ds-ring) disabled:cursor-default disabled:opacity-50"
                :disabled="!canFetch || machineBusy"
                @click="emit('fetch', count)"
            >
                <FilledIcon name="history" :size="14" class="text-fg-muted" />
                {{ machineBusy ? $t('Fetching…') : $t('Refresh') }}
            </button>
        </div>

        <div
            ref="viewer"
            class="relative min-h-80 flex-1 basis-0 overflow-auto rounded-[10px] border-[0.5px] border-edge-card bg-surface-field py-3 font-ui-mono text-[12px]/[18px] shadow-field"
            role="log"
            :aria-label="$t('Service logs')"
            aria-live="off"
        >
            <template v-if="fromStore">
                <p v-if="failed" class="px-4 text-status-down">
                    {{ failed }}
                </p>
                <p
                    v-else-if="loading && !lines.length"
                    class="px-4 text-fg-faint"
                >
                    {{ $t('Loading lines…') }}
                </p>
                <div
                    v-else-if="!lines.length"
                    class="flex flex-col items-start gap-2 px-4"
                >
                    <p class="text-fg-faint">
                        {{
                            query.trim()
                                ? $t('No lines match.')
                                : range === 'all'
                                  ? $t('No lines kept yet.')
                                  : $t('No lines in this time.')
                        }}
                    </p>
                    <span class="flex flex-wrap gap-3 font-ui text-[12px]/4">
                        <button
                            v-if="range !== 'all'"
                            type="button"
                            class="cursor-pointer text-fg-tertiary underline-offset-4 hover:text-fg-secondary hover:underline"
                            @click="range = 'all'"
                        >
                            {{ $t('Show everything kept') }}
                        </button>
                        <button
                            v-if="canFetch"
                            type="button"
                            class="cursor-pointer text-fg-tertiary underline-offset-4 hover:text-fg-secondary hover:underline"
                            @click="readMachine"
                        >
                            {{ $t('Read the last lines from the machine') }}
                        </button>
                    </span>
                </div>
                <div
                    v-for="line in lines"
                    :key="line.id"
                    class="flex min-h-[22px] items-start gap-3.5 px-4 py-0.5"
                    :class="tone[line.level]?.row"
                >
                    <time
                        class="shrink-0 whitespace-nowrap text-fg-faint"
                        :datetime="new Date(line.at).toISOString()"
                        :title="new Date(line.at).toLocaleString()"
                        >{{ clock(line.at) }}</time
                    >
                    <span
                        class="w-10 shrink-0"
                        :class="tone[line.level]?.level ?? 'text-fg-muted'"
                        >{{ line.level }}</span
                    >
                    <span
                        class="min-w-0 flex-1 break-words whitespace-pre-wrap"
                        :class="tone[line.level]?.text ?? 'text-fg-secondary'"
                        >{{ message(line) }}</span
                    >
                </div>
                <div
                    v-if="live && !failed"
                    class="flex items-center gap-2 px-4 pt-2.5"
                >
                    <span
                        class="size-1.5 animate-pulse rounded-full bg-status-live motion-reduce:animate-none"
                        aria-hidden="true"
                    />
                    <span class="text-fg-faint">{{
                        $t('Waiting for new lines')
                    }}</span>
                </div>
            </template>
            <template v-else>
                <p v-if="!managed" class="px-4 text-fg-faint">
                    {{ $t('Adopt the service to read its logs.') }}
                </p>
                <p
                    v-else-if="
                        !machineJob ||
                        (machineBusy && machineLines.length === 0)
                    "
                    class="px-4 text-fg-faint"
                >
                    {{ $t('Fetching logs…') }}
                </p>
                <p v-else-if="machineJob.error" class="px-4 text-status-down">
                    {{ jobReason(machineJob.error, $t('That didn’t finish.')) }}
                </p>
                <p
                    v-else-if="machineLines.length === 0"
                    class="px-4 text-fg-faint"
                >
                    {{ query ? $t('No lines match.') : $t('No log output.') }}
                </p>
                <div
                    v-for="(line, index) in machineLines"
                    :key="index"
                    class="px-4 py-0.5 break-words whitespace-pre-wrap text-fg-secondary"
                >
                    {{ line }}
                </div>
            </template>
        </div>

        <p class="text-[12px]/4 tracking-[-0.005em] text-fg-muted">
            {{ footer }}
            <button
                v-if="fromMachine && summary.stored"
                type="button"
                class="cursor-pointer text-fg-tertiary underline-offset-4 hover:text-fg-secondary hover:underline"
                @click="readStore"
            >
                {{ $t('Back to kept lines') }}
            </button>
            <Link
                :href="historyUrl"
                class="text-fg-tertiary underline-offset-4 hover:text-fg-secondary hover:underline"
                >{{ $t('Search every log') }}</Link
            >
        </p>
    </div>
</template>
