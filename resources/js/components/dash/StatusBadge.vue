<script setup lang="ts">
import {
    CircleCheck,
    CircleDashed,
    CirclePause,
    CircleX,
    TriangleAlert,
} from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';

export type DashStatus = 'live' | 'deploying' | 'pending' | 'down' | 'stopped';

const { status, label } = defineProps<{
    status: DashStatus;
    label?: string;
}>();

const look = {
    live: { icon: CircleCheck, colour: 'var(--dash-live)', text: 'Live' },
    deploying: {
        icon: CircleDashed,
        colour: 'var(--dash-deploying)',
        text: 'Deploying',
    },
    pending: {
        icon: TriangleAlert,
        colour: 'var(--dash-pending)',
        text: 'Pending',
    },
    down: { icon: CircleX, colour: 'var(--dash-down)', text: 'Down' },
    stopped: {
        icon: CirclePause,
        colour: 'var(--dash-stopped)',
        text: 'Stopped',
    },
} as const;

const current = computed(() => look[status]);
</script>

<template>
    <span
        class="inline-flex h-6 shrink-0 items-center gap-[5px] rounded-[7px] border border-[color-mix(in_srgb,var(--badge)_16%,transparent)] bg-[color-mix(in_srgb,var(--badge)_12%,transparent)] pr-2 pl-1.5 text-[12.5px]/4 font-medium tracking-[-0.005em] whitespace-nowrap text-(--badge) shadow-badge"
        :style="{ '--badge': current.colour }"
    >
        <component
            :is="current.icon"
            class="size-3.5 shrink-0"
            :class="{
                'animate-[spin_4s_linear_infinite] motion-reduce:animate-none':
                    status === 'deploying',
            }"
            :stroke-width="2.3"
            aria-hidden="true"
        />
        {{ label ?? trans(current.text) }}
    </span>
</template>
