<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';
import TimeSeriesChart from '@/components/machines/TimeSeriesChart.vue';
import type { UsagePoint } from '@/components/service/serviceTypes';
import { size } from '@/components/service/serviceTypes';
import { themeColour } from '@/lib/themeColour';

const props = defineProps<{
    history: UsagePoint[];
    range: string;
    network: boolean;
}>();

defineEmits<{ range: [range: string] }>();

const ranges = ['15m', '1h', '6h', '24h'];
const timestamps = computed(() => props.history.map((p) => p.ts));
const percent = (v: number) => `${v < 10 ? v.toFixed(1) : Math.round(v)}%`;
const rate = (v: number) => `${size(v)}/s`;
</script>

<template>
    <section
        class="dash-card flex shrink-0 flex-col gap-3 border-edge-strong! px-[18px] pt-3 pb-3.5"
        :aria-label="$t('Usage over time')"
    >
        <div class="flex items-center gap-2">
            <h2
                class="min-w-0 flex-1 text-[13px]/5 font-medium tracking-[-0.005em] text-fg-primary"
            >
                {{ $t('Usage over time') }}
            </h2>
            <div
                class="flex gap-0.5 rounded-[7px] border-[0.5px] border-edge-row bg-surface-well p-0.5"
                role="group"
                :aria-label="$t('Time range')"
            >
                <button
                    v-for="option in ranges"
                    :key="option"
                    type="button"
                    class="flex h-[22px] cursor-pointer items-center rounded-[5px] px-[9px] text-[12px]/4 font-medium tracking-[-0.01em] focus-visible:outline-2 focus-visible:outline-(--ds-ring)"
                    :class="
                        range === option
                            ? 'border-[0.5px] border-edge-control bg-surface-pill text-fg-primary'
                            : 'text-fg-muted hover:text-fg-secondary'
                    "
                    :aria-pressed="range === option"
                    @click="$emit('range', option)"
                >
                    {{ option }}
                </button>
            </div>
        </div>
        <div class="grid gap-3 lg:grid-cols-2">
            <TimeSeriesChart
                :title="trans('CPU')"
                :timestamps="timestamps"
                :series="[
                    {
                        label: trans('CPU'),
                        color: themeColour('--ds-chart', '#60A5FA'),
                        values: history.map((p) => p.cpu),
                    },
                ]"
                :format="percent"
                :min-max="1"
                :max="100"
            />
            <TimeSeriesChart
                :title="trans('Memory')"
                :timestamps="timestamps"
                :series="[
                    {
                        label: trans('Memory'),
                        color: '#34D399',
                        values: history.map((p) => p.mem),
                    },
                ]"
                :format="size"
                :min-max="1024 * 1024"
            />
            <TimeSeriesChart
                v-if="network"
                class="lg:col-span-2"
                :title="trans('Network')"
                :timestamps="timestamps"
                :series="[
                    {
                        label: trans('In'),
                        color: '#A78BFA',
                        values: history.map((p) => p.rx),
                    },
                    {
                        label: trans('Out'),
                        color: '#FBBF24',
                        values: history.map((p) => p.tx),
                    },
                ]"
                :format="rate"
                :min-max="1024"
            />
        </div>
    </section>
</template>
