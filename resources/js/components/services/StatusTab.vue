<script setup lang="ts">
import { computed } from 'vue';
import managedFill from '@/components/services/icons/managed-fill.svg?raw';
import runningFill from '@/components/services/icons/running-fill.svg?raw';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import type { FilledIconName } from '@/components/icons/filledIcons';

export type StatusTabIcon = FilledIconName | 'managed-fill' | 'running-fill';

const {
    label,
    icon,
    count,
    active = false,
} = defineProps<{
    label: string;
    icon: StatusTabIcon;
    count: number;
    active?: boolean;
}>();

defineEmits<{ click: [event: MouseEvent] }>();

const local: Record<string, string> = {
    'managed-fill': managedFill,
    'running-fill': runningFill,
};

const raw = computed(() => local[icon] ?? null);
</script>

<template>
    <button
        type="button"
        class="group relative flex h-11 shrink-0 cursor-pointer flex-col items-center justify-center focus-visible:outline-none"
        :aria-pressed="active"
        @click="$emit('click', $event)"
    >
        <span
            class="flex h-7.5 items-center gap-[7px] rounded-[7px] pr-[11px] pl-2.5 text-[13px]/5 font-medium tracking-[-0.01em] whitespace-nowrap transition-colors duration-150 group-focus-visible:outline-2 group-focus-visible:outline-(--ds-ring)"
            :class="
                active
                    ? 'border-[0.5px] border-edge-control bg-surface-raised text-fg-primary'
                    : 'text-fg-tertiary group-hover:text-fg-secondary'
            "
        >
            <span
                v-if="raw"
                class="inline-flex size-4 shrink-0 [&>svg]:size-full"
                aria-hidden="true"
                v-html="raw"
            />
            <FilledIcon v-else :name="icon as FilledIconName" />
            <span>
                {{ label }}
                <span class="text-fg-faint tabular-nums dark:text-[#6b6b6b]">{{
                    count
                }}</span>
            </span>
        </span>
        <span
            v-if="active"
            class="absolute right-[-1px] bottom-0 left-1.5 h-0.5 rounded-[1px] bg-(--dash-underline)"
            aria-hidden="true"
        />
    </button>
</template>
