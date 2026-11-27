<script setup lang="ts">
import { computed } from 'vue';

const { percent, bars = 22 } = defineProps<{
    percent: number;
    bars?: number;
}>();

const lit = computed(() =>
    percent <= 0
        ? 0
        : Math.max(1, Math.round((Math.min(100, percent) / 100) * bars)),
);
</script>

<template>
    <span
        class="flex items-center gap-1"
        role="meter"
        :aria-valuenow="Math.round(percent)"
        aria-valuemin="0"
        aria-valuemax="100"
    >
        <span
            v-for="index in bars"
            :key="index"
            class="h-[22px] w-[5px] shrink-0 rounded-[2px]"
            :class="
                index <= lit
                    ? 'bg-linear-to-b from-[#8aa4ff] to-[#3d63ff] shadow-[0_0_6px_0_rgb(61_99_255/0.35)]'
                    : 'bg-[#e6e6e6] dark:bg-[#262626]'
            "
        />
    </span>
</template>
