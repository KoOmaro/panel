<script setup lang="ts">
import { computed } from 'vue';
import { yamlParts } from '@/components/code/yaml';
import type { YamlTone } from '@/components/code/yaml';

const { lines, start = 1 } = defineProps<{
    lines: string[];
    start?: number;
}>();

const tones: Record<YamlTone, string> = {
    comment: 'text-fg-faint',
    key: 'text-fg-tertiary',
    value: 'text-fg-primary',
    ref: 'text-[#3d5ee0] dark:text-[#87a3ff]',
};

const rows = computed(() =>
    lines.map((line, index) => ({
        no: start + index,
        parts: yamlParts(line),
    })),
);
</script>

<template>
    <div class="flex min-w-max flex-col py-2.5 font-ui-mono">
        <div
            v-for="row in rows"
            :key="row.no"
            class="flex h-5 items-center pr-4"
        >
            <span
                class="flex w-12 shrink-0 justify-end pr-4 text-[11px]/4 text-fg-faint select-none"
                aria-hidden="true"
                v-text="row.no"
            />
            <span class="flex text-[12px]/4 whitespace-pre">
                <span
                    v-for="(part, index) in row.parts"
                    :key="index"
                    :class="tones[part.tone]"
                    v-text="part.text"
                />
            </span>
        </div>
    </div>
</template>
