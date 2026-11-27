<script setup lang="ts">
import { registerDashShell } from '@/composables/useDashShell';

defineSlots<{ default?: () => unknown }>();

registerDashShell(() => ({ sidebar: 'custom' }));
</script>

<template>
    <Teleport defer to="#dash-sidebar-custom">
        <slot />
    </Teleport>
</template>
