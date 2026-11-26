<script setup lang="ts">
import { CircleDashed, CircleMinus, CircleX } from '@lucide/vue';

const { state } = defineProps<{
    state: 'success' | 'failure' | 'pending' | 'neutral';
}>();
</script>

<template>
    <svg
        v-if="state === 'success'"
        viewBox="0 0 18 18"
        class="mt-px size-[18px] shrink-0 text-status-live"
        aria-hidden="true"
    >
        <circle
            cx="9"
            cy="9"
            r="8.75"
            fill="currentColor"
            fill-opacity="0.14"
            stroke="currentColor"
            stroke-opacity="0.35"
            stroke-width="0.5"
        />
        <path
            d="M5.8 9.2L7.9 11.2L12.2 6.8"
            fill="none"
            stroke="currentColor"
            stroke-width="1.5"
            stroke-linecap="round"
            stroke-linejoin="round"
        />
    </svg>
    <CircleX
        v-else-if="state === 'failure'"
        class="mt-px size-[18px] shrink-0 text-status-down"
        :stroke-width="1.5"
        aria-hidden="true"
    />
    <CircleDashed
        v-else-if="state === 'pending'"
        class="mt-px size-[18px] shrink-0 text-fg-muted"
        :stroke-width="1.5"
        aria-hidden="true"
    />
    <CircleMinus
        v-else
        class="mt-px size-[18px] shrink-0 text-fg-muted"
        :stroke-width="1.5"
        aria-hidden="true"
    />
</template>
