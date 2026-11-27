<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';
import {
    cadence,
    dayAndTime,
    ms,
    shortDay,
} from '@/components/monitors/monitors';
import DashTray from '@/components/dash/DashTray.vue';
import type { MonitorChart } from '@/components/monitors/monitors';

type Range = '24h' | '7d';

const { chart, now = Date.now() } = defineProps<{
    chart: MonitorChart;
    now?: number;
}>();

const range = defineModel<Range>('range', { required: true });

const hovered = ref<number | null>(null);

const ranges: { value: Range; label: string }[] = [
    { value: '24h', label: trans('24 hours') },
    { value: '7d', label: trans('7 days') },
];

const count = computed(() => chart.bars.length);

const slowest = computed<number | null>(() => {
    let index: number | null = null;
    let max = -1;
    for (let i = 0; i < chart.bars.length; i++) {
        const value = chart.bars[i].ms;
        if (value !== null && value > max) {
            max = value;
            index = i;
        }
    }

    return index;
});

const top = computed(() => {
    const max = slowest.value === null ? 0 : chart.bars[slowest.value].ms;

    return max ? max / 0.914 : 0;
});

const hasData = computed(() =>
    chart.bars.some((bar) => bar.ms !== null || bar.failed > 0),
);

function height(value: number | null, failed: number): string {
    if (value === null) return failed > 0 ? '12px' : '0px';
    if (!top.value) return '2px';

    return `max(2px, ${((value / top.value) * 100).toFixed(2)}%)`;
}

const averageAt = computed(() =>
    chart.averageMs !== null && top.value
        ? `${((chart.averageMs / top.value) * 100).toFixed(2)}%`
        : null,
);

const shown = computed(() => hovered.value ?? slowest.value);

const labelHidden = computed(
    () =>
        shown.value !== null &&
        shown.value >= count.value - Math.ceil(count.value * 0.08),
);

function tone(i: number): string {
    const bar = chart.bars[i];
    if (bar.failed > 0) {
        return hovered.value === i ? 'bg-[#f48b7f]' : 'bg-status-down';
    }
    if (i === slowest.value) return 'bg-[#151515] dark:bg-[#d9d9d9]';
    if (i === hovered.value) return 'bg-[#8c8c8c] dark:bg-[#6b6b6b]';

    return 'bg-[#d6d6d6] dark:bg-[#3d3d3d]';
}

const tooltip = computed(() => {
    const i = shown.value;
    if (i === null) return null;
    const bar = chart.bars[i];
    if (bar.ms === null && bar.failed === 0) return null;
    const ratio = bar.ms !== null && top.value ? bar.ms / top.value : 0;
    const flip = i > count.value * 0.82;

    return {
        value: bar.ms === null ? trans('No response') : ms(bar.ms),
        failed:
            bar.failed === 0
                ? null
                : trans(
                      bar.failed === 1
                          ? '1 failed check'
                          : ':count failed checks',
                      { count: String(bar.failed) },
                  ),
        when: dayAndTime(bar.at, now),
        style: {
            top: `min(${((1 - ratio) * 100).toFixed(2)}%, calc(100% - 52px))`,
            ...(flip
                ? {
                      right: `calc(${((count.value - i) / count.value) * 100}% + 8px)`,
                  }
                : { left: `calc(${((i + 1) / count.value) * 100}% + 8px)` }),
        },
    };
});

const axis = computed(() => {
    if (range.value === '24h') {
        return [trans('24 hours ago'), trans('12 hours ago'), trans('Now')];
    }
    const middle = chart.bars[Math.floor(count.value / 2)];

    return [
        trans('7 days ago'),
        middle ? shortDay(middle.at) : '',
        trans('Now'),
    ];
});

const summary = computed(() => {
    const i = slowest.value;

    return trans('Response time, :average average, slowest :slowest', {
        average: ms(chart.averageMs),
        slowest: i === null ? ms(null) : ms(chart.bars[i].ms),
    });
});
</script>

<template>
    <DashTray
        icon="monitors-fill"
        :title="$t('Response time')"
        :meta="cadence(chart.step)"
    >
        <template #actions>
            <div
                role="radiogroup"
                :aria-label="$t('Range')"
                class="flex shrink-0 items-center gap-0.5 rounded-[8px] border-[0.5px] border-edge-row bg-[#ededed] p-0.5 shadow-[inset_0_1px_2px_rgb(0_0_0/0.12)] dark:bg-[#101010] dark:shadow-[inset_0_1px_2px_rgb(0_0_0/0.5)]"
            >
                <button
                    v-for="option in ranges"
                    :key="option.value"
                    type="button"
                    role="radio"
                    :aria-checked="range === option.value"
                    class="flex h-[30px] cursor-pointer items-center rounded-[6px] border-[0.5px] px-3 font-ui text-[12.5px]/[18px] font-medium whitespace-nowrap transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-(--ds-ring)"
                    :class="
                        range === option.value
                            ? 'border-[#d4d4d4] bg-white text-fg-primary shadow-[0_1px_2px_rgb(0_0_0/0.12)] dark:border-[#404040] dark:bg-[#2b2b2b] dark:shadow-[0_1px_2px_rgb(0_0_0/0.5)]'
                            : 'border-transparent text-fg-muted hover:text-fg-secondary'
                    "
                    @click="range = option.value"
                >
                    {{ option.label }}
                </button>
            </div>
        </template>

        <div class="flex flex-col gap-2 px-4 pt-7 pb-3">
            <div
                class="relative h-[150px]"
                role="img"
                :aria-label="summary"
                @mouseleave="hovered = null"
            >
                <template v-if="hasData">
                    <div class="absolute inset-0 flex items-end gap-0.5">
                        <span
                            v-for="(bar, i) in chart.bars"
                            :key="bar.at"
                            class="relative h-full min-w-0 flex-1"
                            @mouseenter="hovered = i"
                        >
                            <span
                                class="absolute inset-x-0 bottom-0 rounded-[1.5px] transition-colors duration-100"
                                :class="tone(i)"
                                :style="{ height: height(bar.ms, bar.failed) }"
                            />
                        </span>
                    </div>
                    <template v-if="averageAt">
                        <span
                            class="pointer-events-none absolute inset-x-0 h-px bg-black/15 dark:bg-white/18"
                            :style="{ bottom: averageAt }"
                            aria-hidden="true"
                        />
                        <span
                            v-if="!labelHidden"
                            class="pointer-events-none absolute right-0 text-[11px]/[14px] whitespace-nowrap text-fg-muted"
                            :style="{ bottom: `calc(${averageAt} + 4px)` }"
                            aria-hidden="true"
                            >{{
                                $t(':value average', {
                                    value: ms(chart.averageMs),
                                })
                            }}</span
                        >
                    </template>
                    <div
                        v-if="tooltip"
                        class="pointer-events-none absolute z-10 flex flex-col rounded-[7px] border-[0.5px] border-[#dcdcdc] bg-white px-2 py-1.5 text-[12px]/4 tracking-[-0.005em] whitespace-nowrap shadow-[0_4px_8px_-4px_rgb(0_0_0/0.25),0_1px_2px_rgb(0_0_0/0.15)] dark:border-[#3d3d3d] dark:bg-[#292929] dark:shadow-[0_4px_8px_-4px_rgb(0_0_0/0.4),0_1px_2px_rgb(0_0_0/0.5)]"
                        :style="tooltip.style"
                        aria-hidden="true"
                    >
                        <span class="font-medium text-fg-primary">{{
                            tooltip.value
                        }}</span>
                        <span v-if="tooltip.failed" class="text-status-down">{{
                            tooltip.failed
                        }}</span>
                        <span class="text-fg-muted">{{ tooltip.when }}</span>
                    </div>
                </template>
                <p
                    v-else
                    class="absolute inset-0 flex items-center justify-center text-[13px]/5 text-fg-muted"
                >
                    {{ $t('No checks in this range yet.') }}
                </p>
            </div>
            <div
                class="flex gap-2 text-[11px]/[14px] text-fg-faint"
                aria-hidden="true"
            >
                <span class="min-w-0 flex-1">{{ axis[0] }}</span>
                <span class="min-w-0 flex-1 text-center">{{ axis[1] }}</span>
                <span class="min-w-0 flex-1 text-right">{{ axis[2] }}</span>
            </div>
        </div>
    </DashTray>
</template>
