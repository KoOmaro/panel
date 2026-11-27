<script setup lang="ts">
import { ref } from 'vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';

const props = defineProps<{ command: string }>();

const copied = ref(false);
let reset: ReturnType<typeof setTimeout> | undefined;

async function copy(): Promise<void> {
    clearTimeout(reset);
    copied.value = true;
    try {
        await navigator.clipboard.writeText(props.command);
        reset = setTimeout(() => (copied.value = false), 1500);
    } catch {
        copied.value = false;
    }
}
</script>

<template>
    <div
        class="flex h-8 items-center gap-2 rounded-[7px] border-[0.5px] border-edge-strong bg-surface-field pr-1 pl-[11px]"
    >
        <code
            class="min-w-0 flex-1 truncate font-ui-mono text-[12px]/4 text-fg-secondary select-all"
            :title="command"
            >{{ command }}</code
        >
        <button
            type="button"
            class="inline-flex size-6 shrink-0 cursor-pointer items-center justify-center rounded-[5px] text-fg-secondary transition-colors hover:bg-surface-hover hover:text-fg-primary focus-visible:outline-2 focus-visible:outline-(--ds-ring)"
            :aria-label="copied ? $t('Copied') : $t('Copy')"
            @click="copy"
        >
            <FilledIcon :name="copied ? 'check' : 'copy'" :size="13" />
        </button>
    </div>
</template>
