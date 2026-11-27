<script setup lang="ts">
import { useId } from 'vue';

const { size = 16 } = defineProps<{ size?: number }>();

const id = useId();
</script>

<template>
    <svg
        viewBox="0 0 16 16"
        fill="none"
        class="shrink-0 animate-spin [animation-duration:1.1s] motion-reduce:animate-none"
        :style="{ width: `${size}px`, height: `${size}px` }"
        aria-hidden="true"
    >
        <defs>
            <linearGradient
                :id="`${id}-arc`"
                x1="8"
                y1="0"
                x2="8"
                y2="16"
                gradientUnits="userSpaceOnUse"
            >
                <stop stop-color="#3b9cff" />
                <stop offset="1" stop-color="#3b9cff" stop-opacity="0" />
            </linearGradient>
        </defs>
        <circle
            cx="8"
            cy="8"
            r="6.88"
            stroke="currentColor"
            stroke-width="2.24"
            class="text-edge-row"
        />
        <path
            d="M8 1.12a6.88 6.88 0 0 1 0 13.76"
            :stroke="`url(#${id}-arc)`"
            stroke-width="2.24"
            stroke-linecap="round"
        />
    </svg>
</template>
