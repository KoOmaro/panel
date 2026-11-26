<script setup lang="ts">
import { computed } from 'vue';

const { percent, color } = defineProps<{
    percent: number | null;
    color: string;
}>();

const RADIUS = 7.56;
const LENGTH = 2 * Math.PI * RADIUS;

const dash = computed(() => {
    const share = Math.min(1, Math.max(0, (percent ?? 0) / 100));

    return `${(share * LENGTH).toFixed(2)} ${LENGTH.toFixed(2)}`;
});
</script>

<template>
    <svg
        viewBox="0 0 18 18"
        class="size-[18px] shrink-0 -rotate-90"
        aria-hidden="true"
    >
        <circle
            cx="9"
            cy="9"
            :r="RADIUS"
            fill="none"
            stroke-width="2.88"
            class="stroke-[#e4e4e4] dark:stroke-[#2a2a2a]"
        />
        <circle
            v-if="percent"
            cx="9"
            cy="9"
            :r="RADIUS"
            fill="none"
            stroke-width="2.88"
            :stroke="color"
            :stroke-dasharray="dash"
            class="transition-[stroke-dasharray] duration-500 motion-reduce:transition-none"
        />
    </svg>
</template>
