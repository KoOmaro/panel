<script setup lang="ts">
import { registerDashShell } from '@/composables/useDashShell';
import type { DashCrumb } from '@/composables/useDashShell';

const props = defineProps<{
    crumbs?: DashCrumb[];
    noSidebar?: boolean;
}>();

defineSlots<{ actions?: () => unknown; meta?: () => unknown }>();

registerDashShell(() => ({
    crumbs: props.crumbs ?? null,
    sidebar: props.noSidebar ? 'none' : undefined,
}));
</script>

<template>
    <Teleport v-if="$slots.meta" defer to="#dash-header-meta">
        <slot name="meta" />
    </Teleport>
    <Teleport v-if="$slots.actions" defer to="#dash-header-actions">
        <slot name="actions" />
    </Teleport>
</template>
