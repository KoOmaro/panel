<script setup lang="ts">
import FilledIcon from '@/components/icons/FilledIcon.vue';
import type { FilledIconName } from '@/components/icons/filledIcons';

const {
    icon,
    width,
    first = false,
    fill = false,
} = defineProps<{
    icon?: FilledIconName;
    width?: number;
    first?: boolean;
    fill?: boolean;
}>();

defineSlots<{
    default?: () => unknown;
    lead?: () => unknown;
    trail?: () => unknown;
}>();
</script>

<template>
    <th
        scope="col"
        class="h-10 border-r-[0.5px] border-b-[0.5px] border-r-edge-cell border-b-edge p-0 align-middle font-normal"
        :style="
            width && !fill
                ? { width: `${width}px`, minWidth: `${width}px` }
                : undefined
        "
    >
        <div
            class="flex h-10 items-center pr-3"
            :class="first ? 'gap-3 pl-5' : 'gap-2 pl-3.5'"
        >
            <slot name="lead" />
            <FilledIcon v-if="icon" :name="icon" class="text-fg-muted" />
            <span
                class="truncate text-[13px]/5 font-medium tracking-[-0.01em] whitespace-nowrap text-fg-secondary"
            >
                <slot />
            </span>
            <span v-if="$slots.trail" class="ml-auto flex items-center">
                <slot name="trail" />
            </span>
        </div>
    </th>
</template>
