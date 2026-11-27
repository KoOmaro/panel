<script setup lang="ts">
const { state, number } = defineProps<{
    state: 'done' | 'next' | 'todo';
    number: number;
}>();
</script>

<template>
    <svg
        v-if="state === 'done'"
        width="20"
        height="20"
        viewBox="0 0 20 20"
        fill="none"
        class="shrink-0 text-status-live"
        role="img"
        :aria-label="$t('Done')"
    >
        <path
            d="M10 19.75C15.3848 19.75 19.75 15.3848 19.75 10C19.75 4.61522 15.3848 0.25 10 0.25C4.61522 0.25 0.25 4.61522 0.25 10C0.25 15.3848 4.61522 19.75 10 19.75Z"
            fill="currentColor"
            fill-opacity="0.14"
            stroke="currentColor"
            stroke-opacity="0.35"
            stroke-width="0.5"
        />
        <path
            d="M6.4 10.2L8.8 12.5L13.6 7.5"
            stroke="currentColor"
            stroke-width="1.6"
            stroke-linecap="round"
            stroke-linejoin="round"
        />
    </svg>
    <span
        v-else
        class="flex size-5 shrink-0 items-center justify-center rounded-[10px] text-center text-[11px]/3 font-medium tabular-nums"
        :class="
            state === 'next'
                ? 'bg-fg-primary text-surface-field shadow-card'
                : 'border border-edge-tile text-fg-muted'
        "
        >{{ number }}</span
    >
</template>
