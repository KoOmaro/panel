<script setup lang="ts">
import { computed } from 'vue';
import BrandLogo from '@/components/dash/BrandLogo.vue';

const { names, max = 3 } = defineProps<{
    names: (string | null | undefined)[];
    max?: number;
}>();

const shown = computed(() => names.filter(Boolean).slice(0, max) as string[]);
</script>

<template>
    <span v-if="shown.length" class="flex shrink-0 items-center">
        <span
            v-for="(name, index) in shown"
            :key="`${name}-${index}`"
            class="flex size-[22px] shrink-0 items-center justify-center overflow-clip rounded-full border-[1.5px] border-surface-main bg-[#f0f0f0] not-last:-mr-1.5 dark:bg-[#1f1f1f]"
            :style="{ zIndex: shown.length - index }"
        >
            <BrandLogo :name="name" :size="12.8" />
        </span>
    </span>
</template>
