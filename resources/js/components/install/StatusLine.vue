<script setup lang="ts">
import StatusIcon from './StatusIcon.vue';
import type { Status } from './types';

defineProps<{ status: Status }>();
</script>

<template>
    <div class="flex gap-3 text-[13px]/5">
        <StatusIcon :status="status" class="mt-0.5" />
        <div class="min-w-0 flex-1 break-words">
            <slot />
        </div>
    </div>
</template>
