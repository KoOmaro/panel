<script setup lang="ts">
import { computed } from 'vue';
import type { DiffLine } from '@/components/code/yaml';

const { file, lines } = defineProps<{
    file: string;
    lines: DiffLine[];
}>();

const added = computed(() => lines.filter((line) => line.sign === '+').length);
const removed = computed(
    () => lines.filter((line) => line.sign === '-').length,
);
</script>

<template>
    <div
        class="overflow-hidden rounded-lg border-[0.5px] border-edge-row bg-surface-field font-ui-mono shadow-[inset_0_1px_2px_rgb(0_0_0/0.4)]"
    >
        <div
            class="flex items-center gap-2 border-b-[0.5px] border-edge-tabs px-3 py-[7px] text-[11px]/4"
        >
            <span class="text-fg-secondary">{{ file }}</span>
            <span class="grow" />
            <span v-if="added" class="text-status-live">+{{ added }}</span>
            <span v-if="removed" class="text-status-down">−{{ removed }}</span>
        </div>
        <div class="overflow-x-auto py-1.5">
            <div class="flex min-w-max flex-col">
                <div
                    v-for="(line, index) in lines"
                    :key="index"
                    class="flex h-5 items-center pr-3"
                    :class="{
                        'bg-[rgb(240_105_90/0.09)]': line.sign === '-',
                        'bg-[rgb(62_196_109/0.09)]': line.sign === '+',
                    }"
                >
                    <span
                        class="flex w-9 shrink-0 justify-end pr-2.5 text-[11px]/4 text-fg-faint select-none"
                        aria-hidden="true"
                        >{{ line.no }}</span
                    >
                    <span
                        class="flex w-4 shrink-0 justify-center text-[12px]/4 select-none"
                        :class="
                            line.sign === '+'
                                ? 'text-status-live'
                                : line.sign === '-'
                                  ? 'text-status-down'
                                  : 'text-fg-faint'
                        "
                        >{{ line.sign === '-' ? '−' : line.sign }}</span
                    >
                    <span
                        class="text-[12px]/4 whitespace-pre"
                        :class="
                            line.sign === ' '
                                ? 'text-fg-tertiary'
                                : 'text-fg-primary'
                        "
                        >{{ line.text }}</span
                    >
                </div>
            </div>
        </div>
    </div>
</template>
