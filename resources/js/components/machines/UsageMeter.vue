<script setup lang="ts">
import { computed } from 'vue';
import { loadOf } from '@/components/machines/machineFormat';

const { percent, quiet = false } = defineProps<{
    percent: number;
    quiet?: boolean;
}>();

defineSlots<{ default?: () => unknown }>();

const load = computed(() => loadOf(percent));
const width = computed(() =>
    percent <= 0
        ? 0
        : Math.max(2, Math.round((Math.min(100, percent) / 100) * 36)),
);
const fill = computed(() =>
    quiet
        ? 'bg-[#a3a3a3] dark:bg-[#6b6b6b]'
        : {
              calm: 'bg-[#a8a8a8] dark:bg-[#9e9e9e]',
              busy: 'bg-[#f5a833]',
              high: 'bg-[#f0695a]',
          }[load.value],
);
</script>

<template>
    <span class="flex items-center gap-2.5">
        <span
            class="relative h-1 w-9 shrink-0 overflow-hidden rounded-[2px] bg-[#e6e6e6] dark:bg-[#2e2e2e]"
            aria-hidden="true"
        >
            <span
                class="absolute inset-y-0 left-0 rounded-[2px] transition-[width] duration-500 motion-reduce:transition-none"
                :class="fill"
                :style="{ width: `${width}px` }"
            />
        </span>
        <span
            class="text-[13px]/5 whitespace-nowrap tabular-nums"
            :class="
                quiet
                    ? 'text-fg-muted'
                    : load === 'high'
                      ? 'text-fg-primary'
                      : 'text-fg-secondary'
            "
        >
            <slot />
        </span>
    </span>
</template>
