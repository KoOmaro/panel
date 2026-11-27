<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';
import HomeCard from '@/components/home/HomeCard.vue';
import type { ServiceStat } from '@/components/machines/serviceFormat';
import type { ServiceUsage } from '@/components/service/serviceTypes';
import { cpus, size } from '@/components/service/serviceTypes';

const props = defineProps<{
    stats: ServiceStat | null;
    usage: ServiceUsage;
    traffic: boolean;
    memLimit: number | null;
    cpuLimit: number | null;
    machineMemory: number | null;
}>();

type Figure = {
    key: string;
    label: string;
    value: string;
    sub: string;
    meter?: number | null;
};

const percent = (v: number) => `${v < 10 ? v.toFixed(1) : Math.round(v)}%`;
const number = (v: number) => v.toLocaleString();
const ms = (v: number) => `${Math.round(v).toLocaleString()} ms`;

const figures = computed<Figure[]>(() => {
    const stats = props.stats;
    const cpu = stats?.cpu ?? null;
    const mem = stats?.memUsed ?? null;
    const memOf = props.memLimit ?? props.machineMemory;
    const list: Figure[] = [
        {
            key: 'cpu',
            label: trans('CPU'),
            value: cpu === null ? '–' : percent(cpu),
            sub: props.cpuLimit
                ? trans('limit :value', { value: cpus(props.cpuLimit) })
                : trans('of the whole machine'),
            meter: cpu === null ? null : Math.min(100, cpu),
        },
        {
            key: 'memory',
            label: trans('Memory'),
            value: size(mem),
            sub: props.memLimit
                ? trans('limit :value', { value: size(props.memLimit) })
                : trans('no limit set'),
            meter:
                mem === null || !memOf
                    ? null
                    : Math.min(100, (mem / memOf) * 100),
        },
    ];

    if (props.traffic) {
        const usage = props.usage;
        list.push(
            {
                key: 'requests',
                label: trans('Requests'),
                value: usage ? number(usage.requests) : '–',
                sub: usage ? trans('a minute') : trans('none counted yet'),
            },
            {
                key: 'response',
                label: trans('Response'),
                value: usage?.p50 != null ? ms(usage.p50) : '–',
                sub:
                    usage?.p95 != null
                        ? trans(':value at p95', { value: ms(usage.p95) })
                        : trans('none counted yet'),
            },
        );
    } else {
        list.push(
            {
                key: 'processes',
                label: trans('Processes'),
                value: stats?.pids != null ? number(stats.pids) : '–',
                sub: trans('threads and processes'),
            },
            {
                key: 'network',
                label: trans('Network'),
                value:
                    stats?.netRx != null || stats?.netTx != null
                        ? size((stats?.netRx ?? 0) + (stats?.netTx ?? 0))
                        : '–',
                sub: trans('in and out since it started'),
            },
        );
    }

    return list;
});
</script>

<template>
    <HomeCard
        icon="monitors-fill"
        :title="$t('Usage')"
        heading-id="service-usage"
        class="shrink-0"
    >
        <ul class="grid grid-cols-2 md:h-[100px] md:grid-cols-4">
            <li
                v-for="(figure, index) in figures"
                :key="figure.key"
                class="flex min-w-0 flex-col gap-1 overflow-hidden border-edge-tabs px-[18px] py-3.5"
                :class="[
                    index < figures.length - 1 && 'md:border-r-[0.5px]',
                    index % 2 === 0 && 'max-md:border-r-[0.5px]',
                    index < 2 && 'max-md:border-b-[0.5px]',
                ]"
            >
                <span
                    class="text-[13px]/5 tracking-[-0.005em] whitespace-nowrap text-fg-tertiary"
                    >{{ figure.label }}</span
                >
                <span
                    class="truncate text-[20px]/7 font-medium tracking-[-0.015em] text-fg-primary tabular-nums"
                    >{{ figure.value }}</span
                >
                <span class="flex min-w-0 items-center gap-2">
                    <span
                        v-if="figure.meter !== undefined"
                        class="relative h-1 w-9 shrink-0 overflow-hidden rounded-[2px] bg-[#e4e4e4] dark:bg-[#2e2e2e]"
                        aria-hidden="true"
                    >
                        <span
                            class="absolute inset-y-0 left-0 rounded-[2px] bg-[#8c8c8c] dark:bg-[#9e9e9e]"
                            :style="{ width: `${figure.meter ?? 0}%` }"
                        />
                    </span>
                    <span
                        class="truncate text-[12px]/4 tracking-[-0.005em] text-fg-muted"
                        >{{ figure.sub }}</span
                    >
                </span>
            </li>
        </ul>
    </HomeCard>
</template>
