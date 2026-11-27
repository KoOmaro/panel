<script setup lang="ts">
import { computed, useId } from 'vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import type { FilledIconName } from '@/components/icons/filledIcons';

const {
    icon = undefined,
    title = '',
    meta = null,
    as = 'section',
    panelClass = '',
} = defineProps<{
    icon?: FilledIconName;
    title?: string;
    meta?: string | null;
    as?: string;
    panelClass?: string;
}>();

const slots = defineSlots<{
    default?: () => unknown;
    icon?: () => unknown;
    title?: () => unknown;
    meta?: () => unknown;
    actions?: () => unknown;
}>();

const headingId = useId();
const titled = computed(() => title !== '' || slots.title !== undefined);
</script>

<template>
    <component
        :is="as"
        class="flex min-w-0 flex-col rounded-[14px] border-[0.5px] border-edge-tray bg-surface-tray px-[5px] pb-[5px] shadow-card"
        :aria-labelledby="titled ? headingId : undefined"
    >
        <header
            class="flex min-h-[43px] min-w-0 items-center gap-[9px] pt-3 pr-[11px] pb-[11px] pl-[13px]"
        >
            <slot name="icon">
                <FilledIcon
                    v-if="icon"
                    :name="icon"
                    :size="15"
                    class="text-fg-muted"
                />
            </slot>
            <h2
                v-if="titled"
                :id="headingId"
                class="min-w-0 shrink-0 truncate text-[13px]/5 font-medium tracking-[-0.005em] text-fg-primary"
            >
                <slot name="title">{{ title }}</slot>
            </h2>
            <span
                v-if="$slots.meta || meta"
                class="min-w-0 truncate text-[13px]/5 tracking-[-0.005em] text-fg-muted"
            >
                <slot name="meta">{{ meta }}</slot>
            </span>
            <span class="grow" />
            <slot name="actions" />
        </header>
        <div
            class="flex min-h-0 grow flex-col overflow-hidden rounded-[10px] border-[0.5px] border-edge-tray-panel bg-surface-tray-panel shadow-[0_-0.5px_0_var(--dash-tray-panel-lip)]"
            :class="panelClass"
        >
            <slot />
        </div>
    </component>
</template>
