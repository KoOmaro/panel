<script setup lang="ts">
import { useTemplateRef } from 'vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';

const query = defineModel<string>({ default: '' });

const { placeholder, label, width, shortcut } = defineProps<{
    placeholder?: string;
    label?: string;
    width?: number;
    shortcut?: string;
}>();

const input = useTemplateRef<HTMLInputElement>('input');

defineExpose({ focus: () => input.value?.focus() });
</script>

<template>
    <label
        class="flex h-8 min-w-0 cursor-text items-center gap-2 rounded-[8px] border-[0.5px] border-edge-field bg-surface-well px-2.5 focus-within:border-edge-checkbox-hover"
        :style="width ? { width: `${width}px` } : undefined"
    >
        <FilledIcon name="search-fill" class="text-fg-muted" />
        <span class="sr-only">{{ label ?? placeholder }}</span>
        <input
            ref="input"
            v-model="query"
            type="search"
            :placeholder="placeholder"
            class="h-full min-w-0 grow bg-transparent font-ui text-[13px]/5 tracking-[-0.005em] text-fg-primary outline-none placeholder:text-fg-faint [&::-webkit-search-cancel-button]:hidden"
        />
        <kbd
            v-if="shortcut"
            class="flex size-[18px] shrink-0 items-center justify-center rounded-[4px] border-[0.5px] border-edge-control font-ui text-[11px]/3.5 text-fg-muted"
            aria-hidden="true"
            >{{ shortcut }}</kbd
        >
    </label>
</template>
