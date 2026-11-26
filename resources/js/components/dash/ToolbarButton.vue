<script setup lang="ts">
import chevron from '@/components/dash/assets/chevron-small.svg?raw';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import type { FilledIconName } from '@/components/icons/filledIcons';

const {
    icon,
    label,
    menu = false,
    active = false,
} = defineProps<{
    icon?: FilledIconName;
    label?: string;
    menu?: boolean;
    active?: boolean;
}>();

defineSlots<{ default?: () => unknown }>();
</script>

<template>
    <button
        type="button"
        class="flex h-8 shrink-0 cursor-pointer items-center gap-[7px] rounded-[8px] border-[0.5px] border-edge-control bg-surface-control pr-3 pl-2.5 font-ui text-[13px]/5 font-medium tracking-[-0.01em] whitespace-nowrap transition-colors duration-150 hover:text-fg-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring) data-[state=open]:text-fg-primary"
        :class="active ? 'text-fg-primary' : 'text-fg-secondary'"
    >
        <FilledIcon v-if="icon" :name="icon" class="text-fg-muted" />
        <slot>{{ label }}</slot>
        <span
            v-if="menu"
            class="flex size-[13px] shrink-0 text-fg-muted"
            aria-hidden="true"
            v-html="chevron"
        />
    </button>
</template>
