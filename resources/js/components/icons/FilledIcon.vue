<script setup lang="ts">
import { computed } from 'vue';
import { filledIcons } from '@/components/icons/filledIcons';
import type { FilledIconName } from '@/components/icons/filledIcons';

const { name, size = 16 } = defineProps<{
    name: FilledIconName;
    size?: number;
}>();

const svg = computed(() => filledIcons[name] ?? '');
</script>

<template>
    <span
        class="inline-flex shrink-0 [&>svg]:size-full"
        :style="{ width: `${size}px`, height: `${size}px` }"
        aria-hidden="true"
        v-html="svg"
    />
</template>
