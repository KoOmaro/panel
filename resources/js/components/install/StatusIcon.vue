<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';
import type { Status } from './types';

const { status } = defineProps<{ status: Status }>();

const tone = computed(
    () =>
        ({
            ok: { color: 'text-status-live', label: trans('Good') },
            warn: {
                color: 'text-status-pending',
                label: trans('Worth a look'),
            },
            fail: { color: 'text-status-down', label: trans('Needs fixing') },
        })[status],
);
</script>

<template>
    <svg
        viewBox="0 0 16 16"
        fill="none"
        class="size-4 shrink-0"
        :class="tone.color"
        role="img"
        :aria-label="tone.label"
    >
        <rect
            width="16"
            height="16"
            rx="8"
            fill="currentColor"
            fill-opacity="0.16"
        />
        <rect
            x="0.25"
            y="0.25"
            width="15.5"
            height="15.5"
            rx="7.75"
            stroke="currentColor"
            stroke-opacity="0.45"
            stroke-width="0.5"
        />
        <path
            v-if="status === 'ok'"
            d="M4.5 8.1L6.9 10.5L11.5 5.5"
            stroke="currentColor"
            stroke-width="1.5"
            stroke-linecap="round"
            stroke-linejoin="round"
        />
        <path
            v-else-if="status === 'warn'"
            d="M8 4.85V8.25M8 11.05V11.15"
            stroke="currentColor"
            stroke-width="1.5"
            stroke-linecap="round"
            stroke-linejoin="round"
        />
        <path
            v-else
            d="M5.5 5.5L10.5 10.5M10.5 5.5L5.5 10.5"
            stroke="currentColor"
            stroke-width="1.5"
            stroke-linecap="round"
            stroke-linejoin="round"
        />
    </svg>
</template>
