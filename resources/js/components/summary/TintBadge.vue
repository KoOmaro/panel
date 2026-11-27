<script setup lang="ts">
import type { Component } from 'vue';

export type TintTone = 'green' | 'red' | 'blue' | 'amber' | 'grey';

const {
    tone,
    label,
    icon,
    spin = false,
} = defineProps<{
    tone: TintTone;
    label: string;
    icon?: Component;
    spin?: boolean;
}>();

const colours: Record<TintTone, string> = {
    green: 'var(--dash-live)',
    red: 'var(--dash-down)',
    blue: 'var(--dash-deploying)',
    amber: 'var(--dash-pending)',
    grey: 'var(--dash-stopped)',
};
</script>

<template>
    <span
        class="inline-flex h-[22px] shrink-0 items-center gap-[5px] rounded-[6px] border border-[color-mix(in_srgb,var(--tint)_17%,transparent)] bg-[color-mix(in_srgb,var(--tint)_12%,transparent)] pr-2 pl-1.5 text-[12px]/4 font-medium tracking-[-0.01em] whitespace-nowrap text-(--tint) shadow-[0_1px_1.5px_rgb(0_0_0/0.4)]"
        :style="{ '--tint': colours[tone] }"
    >
        <component
            :is="icon"
            v-if="icon"
            class="size-3 shrink-0"
            :class="{
                'animate-[spin_3s_linear_infinite] motion-reduce:animate-none':
                    spin,
            }"
            :stroke-width="2"
            aria-hidden="true"
        />
        {{ label }}
    </span>
</template>
