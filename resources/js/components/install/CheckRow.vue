<script setup lang="ts">
import StatusIcon from './StatusIcon.vue';
import type { Status } from './types';

defineProps<{ status: Status }>();
</script>

<template>
    <li
        class="flex gap-3 border-t-[0.5px] border-edge-tray-panel py-[11px] text-[13px]/5"
    >
        <StatusIcon :status="status" class="mt-0.5" />
        <div class="flex min-w-0 flex-1 flex-col gap-1.5">
            <slot />
        </div>
    </li>
</template>
