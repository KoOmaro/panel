<script setup lang="ts">
const { selected = false, clickable = false } = defineProps<{
    selected?: boolean;
    clickable?: boolean;
}>();

defineSlots<{ default?: () => unknown }>();
</script>

<template>
    <tr
        class="group/row transition-colors duration-100 hover:bg-surface-hover"
        :class="{
            'bg-surface-hover': selected,
            'cursor-pointer': clickable,
        }"
        :aria-selected="selected || undefined"
    >
        <slot />
    </tr>
</template>
