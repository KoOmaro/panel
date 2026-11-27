<script setup lang="ts">
const { kind } = defineProps<{ kind: 'start' | 'middle' | 'last' }>();
</script>

<template>
    <svg
        v-if="kind === 'start'"
        width="1"
        height="12"
        viewBox="0 0 1 12"
        fill="none"
        class="pointer-events-none absolute top-[36.5px] left-[50.5px] text-[#d4d4d4] dark:text-[#424242]"
        aria-hidden="true"
    >
        <path d="M0.5 0.5V11.5" stroke="currentColor" stroke-linecap="round" />
    </svg>
    <svg
        v-else-if="kind === 'middle'"
        width="15"
        height="53"
        viewBox="0 0 15 53"
        fill="none"
        class="pointer-events-none absolute top-[-0.5px] left-[50.5px] text-[#d4d4d4] dark:text-[#424242]"
        aria-hidden="true"
    >
        <path
            d="M0.5 0.5V52.5M0.5 26.5H14.5"
            stroke="currentColor"
            stroke-linecap="round"
        />
    </svg>
    <svg
        v-else
        width="15"
        height="27"
        viewBox="0 0 15 27"
        fill="none"
        class="pointer-events-none absolute top-[-0.5px] left-[50.5px] text-[#d4d4d4] dark:text-[#424242]"
        aria-hidden="true"
    >
        <path
            d="M0.5 0.5V20.5C0.5 24.5 2.5 26.5 6.5 26.5H14.5"
            stroke="currentColor"
            stroke-linecap="round"
        />
    </svg>
</template>
