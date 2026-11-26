<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';
import RidgeChart from '@/components/charts/RidgeChart.vue';
import FilterPills from '@/components/dash/FilterPills.vue';
import { onWorkspace } from '@/lib/workspaceRoute';
import backupsRoutes from '@/routes/workspace/backups';
import machinesRoutes from '@/routes/workspace/machines';
import servicesRoutes from '@/routes/workspace/services';
import type { WorkspaceStats } from '@/types';

const { stats, attention, lastBackup } = defineProps<{
    stats: WorkspaceStats;
    attention: number;
    lastBackup: string | null;
}>();

const page = usePage<{ workspace: { slug: string } }>();
const slug = computed(() => page.props.workspace.slug);

const BUSY_CPU = 80;

type Kpi = {
    label: string;
    value: string;
    note: string;
    tone: string;
    href?: string;
};

const kpis = computed<Kpi[]>(() => {
    const { machines, services, running, averageCpu, busy, backedUp } = stats;
    const unprotected = machines - backedUp;

    return [
        {
            label: trans('Machines'),
            value: String(machines),
            note:
                attention > 0
                    ? trans(':count need attention', {
                          count: String(attention),
                      })
                    : trans('healthy'),
            tone: attention > 0 ? 'text-status-pending' : 'text-fg-muted',
            href: onWorkspace(machinesRoutes.index(slug.value)).url,
        },
        {
            label: trans('Services'),
            value: String(services),
            note:
                running === services
                    ? trans('running')
                    : trans(':count running', { count: String(running) }),
            tone: 'text-fg-muted',
            href: onWorkspace(servicesRoutes.index(slug.value)).url,
        },
        {
            label: trans('Average CPU'),
            value: averageCpu === null ? '–' : `${averageCpu}%`,
            note:
                busy > 0
                    ? trans(':count busy', { count: String(busy) })
                    : trans('normal'),
            tone: busy > 0 ? 'text-status-pending' : 'text-fg-muted',
        },
        {
            label: trans('Backups'),
            value: `${backedUp}/${machines}`,
            note:
                unprotected > 0
                    ? trans(':count unprotected', {
                          count: String(unprotected),
                      })
                    : (lastBackup ?? ''),
            tone: unprotected > 0 ? 'text-status-down' : 'text-fg-muted',
            href: onWorkspace(backupsRoutes.index(slug.value)).url,
        },
    ];
});

type CpuRange = WorkspaceStats['cpuRange'];

const cpuRanges: {
    key: CpuRange;
    step: number;
    span: string;
    ticks: string[];
}[] = [
    {
        key: '1h',
        step: 60_000,
        span: trans('last hour'),
        ticks: ['-60m', '-45m', '-30m', '-15m'],
    },
    {
        key: '24h',
        step: 900_000,
        span: trans('last 24 hours'),
        ticks: ['-24h', '-18h', '-12h', '-6h'],
    },
    {
        key: '7d',
        step: 7_200_000,
        span: trans('last 7 days'),
        ticks: ['-7d', '-5d', '-3.5d', '-2d'],
    },
    {
        key: '30d',
        step: 28_800_000,
        span: trans('last 30 days'),
        ticks: ['-30d', '-22d', '-15d', '-7d'],
    },
];

const cpuRange = computed(
    () =>
        cpuRanges.find((range) => range.key === stats.cpuRange) ?? cpuRanges[1],
);

const picked = ref<CpuRange>(stats.cpuRange);
const loading = ref(false);

const range = computed<CpuRange>({
    get: () => (loading.value ? picked.value : stats.cpuRange),
    set: (key) => {
        if (key === stats.cpuRange) return;
        picked.value = key;
        loading.value = true;
        router.reload({
            data: { range: key },
            only: ['stats'],
            onFinish: () => (loading.value = false),
        });
    },
});

const rangeOptions = computed(() =>
    cpuRanges.map((option) => ({ value: option.key, label: option.key })),
);

const cpuLabels = computed(() => {
    const now = Date.now();
    const count = stats.cpuTimeline.length;
    const { step } = cpuRange.value;
    const short = step < 3_600_000;

    return stats.cpuTimeline.map((_, index) =>
        new Date(now - (count - 1 - index) * step).toLocaleString(
            [],
            short
                ? { hour: '2-digit', minute: '2-digit' }
                : {
                      month: 'short',
                      day: 'numeric',
                      hour: '2-digit',
                      minute: '2-digit',
                  },
        ),
    );
});

const cpuTicks = computed(() => [
    ...cpuRange.value.ticks.map((label, index) => ({ at: index / 4, label })),
    { at: 1, label: trans('now') },
]);
</script>

<template>
    <section class="dash-card" :aria-label="$t('Status')">
        <dl
            class="grid border-b-[0.5px] border-edge-tabs sm:grid-cols-2 xl:grid-cols-4"
        >
            <div
                v-for="kpi in kpis"
                :key="kpi.label"
                class="relative flex min-w-0 flex-col gap-1 border-edge-tabs px-[18px] py-3.5 not-first:max-sm:border-t-[0.5px] sm:even:border-l-[0.5px] xl:not-first:border-l-[0.5px] sm:[&:nth-child(n+3)]:max-xl:border-t-[0.5px]"
                :class="kpi.href && 'transition-colors hover:bg-surface-hover'"
            >
                <dt class="text-[12px]/4 text-fg-tertiary">
                    <Link
                        v-if="kpi.href"
                        :href="kpi.href"
                        class="after:absolute after:inset-0 focus-visible:outline-none focus-visible:after:outline-2 focus-visible:after:outline-(--ds-ring)"
                        >{{ kpi.label }}</Link
                    >
                    <template v-else>{{ kpi.label }}</template>
                </dt>
                <dd class="flex min-w-0 items-baseline gap-2">
                    <span
                        class="text-[20px]/7 font-medium tracking-[-0.02em] text-fg-primary tabular-nums"
                        >{{ kpi.value }}</span
                    >
                    <span class="truncate text-[12px]/4" :class="kpi.tone">{{
                        kpi.note
                    }}</span>
                </dd>
            </div>
        </dl>
        <div class="px-[18px] pt-3 pb-3">
            <div class="flex flex-wrap items-center gap-x-2 gap-y-2">
                <h2 class="text-[14px]/5 font-medium text-fg-primary">
                    {{ $t('CPU') }}
                </h2>
                <span class="truncate text-[13px]/5 text-fg-tertiary">{{
                    $t(':count machines, :span', {
                        count: String(stats.machines),
                        span: cpuRange.span,
                    })
                }}</span>
                <FilterPills
                    v-model="range"
                    class="ml-auto"
                    :class="loading && 'animate-pulse'"
                    :label="$t('Time range')"
                    :options="rangeOptions"
                />
            </div>
            <div class="mt-3 h-56">
                <RidgeChart
                    :values="stats.cpuTimeline"
                    :labels="cpuLabels"
                    :ticks="cpuTicks"
                    :threshold="{
                        value: BUSY_CPU,
                        label: `${BUSY_CPU}%`,
                        note: $t('busy line'),
                    }"
                    :label="
                        $t('Average CPU over the :span', {
                            span: cpuRange.span,
                        })
                    "
                />
            </div>
        </div>
    </section>
</template>
