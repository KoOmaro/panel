<script setup lang="ts">
import FilledIcon from '@/components/icons/FilledIcon.vue';
import type { FilledIconName } from '@/components/icons/filledIcons';

const {
    icon,
    title,
    amber = false,
} = defineProps<{
    icon: FilledIconName;
    title: string;
    amber?: boolean;
}>();

defineSlots<{ default?: () => unknown }>();
</script>

<template>
    <div class="flex items-start gap-2.5">
        <span
            class="flex size-5 shrink-0 items-center justify-center rounded-[9px]"
            :class="
                amber
                    ? 'bg-[rgb(245_168_51/0.14)] text-[#c27c12] dark:text-[#f5a833]'
                    : 'bg-edge-row text-fg-secondary'
            "
        >
            <FilledIcon :name="icon" :size="12" />
        </span>
        <div class="flex min-w-0 flex-1 flex-col gap-px">
            <p class="text-[13px]/5 text-fg-primary">{{ title }}</p>
            <p class="text-[12px]/[18px] text-fg-tertiary">
                <slot />
            </p>
        </div>
    </div>
</template>
