<script setup lang="ts">
defineSlots<{ default?: () => unknown }>();
</script>

<template>
    <div class="flex h-7 shrink-0 items-end pb-1.5 pl-2.5">
        <span
            class="text-[11px]/3.5 font-medium tracking-[0.08em] whitespace-nowrap text-fg-muted uppercase"
        >
            <slot />
        </span>
    </div>
</template>
