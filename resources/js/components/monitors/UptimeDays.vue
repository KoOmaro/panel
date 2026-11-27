<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';
import DashTray from '@/components/dash/DashTray.vue';
import { percent, spelled } from '@/components/monitors/monitors';
import type { MonitorDay, OutageTone } from '@/components/monitors/monitors';

const { days, uptime } = defineProps<{
    days: MonitorDay[];
    uptime: number | null;
}>();

const hovered = ref<number | null>(null);

const tones: Record<OutageTone, string> = {
    none: 'bg-[#ececec] dark:bg-[#242424]',
    up: 'bg-[#d4d4d4] dark:bg-[#424242]',
    short: 'bg-[#f5a833]',
    long: 'bg-status-down',
    ongoing: 'bg-status-down',
};

function dateOf(day: string): string {
    return new Date(`${day}T00:00:00`).toLocaleDateString(undefined, {
        day: 'numeric',
        month: 'short',
    });
}

const detail = computed(() => {
    if (hovered.value === null) {
        return uptime === null
            ? trans('No checks yet')
            : trans(':value up', { value: percent(uptime) });
    }
    const day = days[hovered.value];
    const parts = [dateOf(day.day)];
    parts.push(
        day.percent === null
            ? trans('no checks')
            : trans(':value up', { value: percent(day.percent) }),
    );
    if (day.down > 0) {
        parts.push(trans('down :time', { time: spelled(day.down) }));
    }

    return parts.join(' · ');
});
</script>

<template>
    <DashTray icon="activity-fill" :title="$t('Last 90 days')">
        <template #actions>
            <span
                class="truncate text-right text-[13px]/5 tracking-[-0.005em] text-fg-muted tabular-nums"
                aria-live="polite"
                >{{ detail }}</span
            >
        </template>
        <div class="flex flex-col gap-2 px-4 pt-3 pb-3">
            <div
                class="flex h-7 gap-0.5"
                role="img"
                :aria-label="
                    $t('Uptime by day over the last 90 days, :value up', {
                        value: percent(uptime),
                    })
                "
                @mouseleave="hovered = null"
            >
                <span
                    v-for="(day, i) in days"
                    :key="day.day"
                    class="min-w-0 flex-1 rounded-[2px]"
                    :class="[
                        tones[day.tone],
                        {
                            'max-sm:hidden': i < days.length - 30,
                            'brightness-125': hovered === i,
                        },
                    ]"
                    @mouseenter="hovered = i"
                />
            </div>
            <div
                class="flex gap-2 text-[11px]/[14px] text-fg-faint"
                aria-hidden="true"
            >
                <span class="min-w-0 flex-1 max-sm:hidden">{{
                    $t('90 days ago')
                }}</span>
                <span class="min-w-0 flex-1 sm:hidden">{{
                    $t('30 days ago')
                }}</span>
                <span class="shrink-0">{{ $t('Today') }}</span>
            </div>
        </div>
    </DashTray>
</template>
