<script setup lang="ts">
import { computed } from 'vue';

const { value, colour } = defineProps<{ value: number; colour: string }>();

const RADIUS = 7.56;
const CIRCUMFERENCE = 2 * Math.PI * RADIUS;

const dash = computed(() => {
    const share = Math.min(1, Math.max(0, value));

    return `${(share * CIRCUMFERENCE).toFixed(3)} ${CIRCUMFERENCE.toFixed(3)}`;
});
</script>

<template>
    <svg
        viewBox="0 0 18 18"
        fill="none"
        class="size-[18px] shrink-0"
        aria-hidden="true"
    >
        <circle
            cx="9"
            cy="9"
            :r="RADIUS"
            stroke="currentColor"
            stroke-width="2.88"
            class="text-[#e6e6e6] dark:text-[#2a2a2a]"
        />
        <circle
            v-if="value > 0"
            cx="9"
            cy="9"
            :r="RADIUS"
            :stroke="colour"
            stroke-width="2.88"
            :stroke-dasharray="dash"
            transform="rotate(-90 9 9)"
        />
    </svg>
</template>
