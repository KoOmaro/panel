<script setup lang="ts">
import { useTemplateRef } from 'vue';
import ConnectMachineButton from '@/components/workspace/ConnectMachineButton.vue';

const host = useTemplateRef<HTMLSpanElement>('host');

function open(): void {
    host.value?.querySelector<HTMLElement>('button, a')?.click();
}

defineExpose({ open });
</script>

<template>
    <span ref="host" class="contents">
        <ConnectMachineButton
            class="hidden!"
            tabindex="-1"
            aria-hidden="true"
        />
    </span>
</template>
