<script setup lang="ts">
import { Check, CircleMinus, CircleX } from '@lucide/vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import SpinnerArc from '@/components/summary/SpinnerArc.vue';

export type CardLineState =
    | 'running'
    | 'done'
    | 'failed'
    | 'skipped'
    | 'waiting';

const { state, label, value } = defineProps<{
    state: CardLineState;
    label: string;
    value?: string | null;
}>();
</script>

<template>
    <div class="flex h-5 min-w-0 items-center gap-2.5">
        <SpinnerArc v-if="state === 'running'" />
        <Check
            v-else-if="state === 'done'"
            class="size-4 shrink-0 text-status-live"
            :stroke-width="2.1"
            aria-hidden="true"
        />
        <CircleX
            v-else-if="state === 'failed'"
            class="size-4 shrink-0 text-status-down"
            :stroke-width="2.1"
            aria-hidden="true"
        />
        <CircleMinus
            v-else-if="state === 'skipped'"
            class="size-4 shrink-0 text-fg-muted"
            :stroke-width="2.1"
            aria-hidden="true"
        />
        <FilledIcon v-else name="activity-fill" class="text-fg-tertiary" />
        <span
            class="min-w-0 flex-1 truncate text-[13px]/5 tracking-[-0.005em]"
            :class="
                state === 'running' ? 'text-fg-tertiary' : 'text-fg-secondary'
            "
            >{{ label }}</span
        >
        <span
            v-if="value"
            class="shrink-0 font-ui-mono text-[12px]/4 whitespace-nowrap"
            :class="
                state === 'failed' ? 'text-status-down' : 'text-fg-tertiary'
            "
            >{{ value }}</span
        >
    </div>
</template>
