<script setup lang="ts">
const { first = false, height = 44 } = defineProps<{
    first?: boolean;
    height?: number;
}>();

defineSlots<{ default?: () => unknown; lead?: () => unknown }>();
</script>

<template>
    <td
        class="border-r-[0.5px] border-b-[0.5px] border-r-edge-cell border-b-edge p-0 align-middle"
    >
        <div
            class="flex min-w-0 items-center pr-3 text-[13px]/5 whitespace-nowrap text-fg-secondary"
            :class="first ? 'gap-3 pl-5' : 'gap-2 pl-3.5'"
            :style="{ height: `${height}px` }"
        >
            <slot name="lead" />
            <slot />
        </div>
    </td>
</template>
