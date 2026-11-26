<script setup lang="ts">
defineSlots<{ default?: () => unknown; after?: () => unknown }>();
</script>

<template>
    <nav
        class="flex h-11 shrink-0 [scrollbar-width:none] items-center gap-0.5 overflow-x-auto border-b-[0.5px] border-edge-tabs pl-3.5 [&::-webkit-scrollbar]:hidden"
    >
        <slot />
        <div v-if="$slots.after" class="flex shrink-0 items-center pl-2">
            <slot name="after" />
        </div>
    </nav>
</template>
