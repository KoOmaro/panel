<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import DashButton from '@/components/dash/DashButton.vue';
import { toast } from '@/lib/toast';

const {
    text,
    label = '',
    wrap = false,
} = defineProps<{
    text: string;
    label?: string;
    wrap?: boolean;
}>();

async function copy(): Promise<void> {
    try {
        await navigator.clipboard.writeText(text);
        toast.success(trans('Copied.'));
    } catch {
        toast.error(trans('Couldn’t copy. Select it and copy it yourself.'));
    }
}
</script>

<template>
    <div
        class="relative min-w-0 rounded-[8px] border-[0.5px] border-edge-field bg-surface-field"
    >
        <pre
            class="max-h-[260px] overflow-auto py-2.5 pr-11 pl-3 font-ui-mono text-[12px]/[19px] text-fg-primary select-all"
            :class="wrap ? 'break-all whitespace-pre-wrap' : 'whitespace-pre'"
            >{{ text }}</pre>
        <div class="absolute top-1.5 right-1.5">
            <DashButton
                size="sm"
                icon="copy"
                :aria-label="label || $t('Copy')"
                :title="label || $t('Copy')"
                @click="copy"
            />
        </div>
    </div>
</template>
