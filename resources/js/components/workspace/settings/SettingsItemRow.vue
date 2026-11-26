<script setup lang="ts">
const { title, description } = defineProps<{
    title?: string;
    description?: string | null;
}>();

defineSlots<{
    icon?: () => unknown;
    lead?: () => unknown;
    title?: () => unknown;
    description?: () => unknown;
    actions?: () => unknown;
    default?: () => unknown;
}>();
</script>

<template>
    <div
        class="flex min-w-0 flex-wrap items-center gap-x-3.5 gap-y-3 border-b-[0.5px] border-edge-row px-[18px] py-3.5 last:border-b-0 sm:flex-nowrap"
    >
        <slot name="lead" />
        <span
            v-if="$slots.icon"
            class="flex size-[30px] shrink-0 items-center justify-center overflow-hidden rounded-[7px] bg-surface-badge shadow-[0_1px_1.5px_rgb(0_0_0/0.5),inset_0_1px_0_rgb(255_255_255/0.05)]"
            aria-hidden="true"
        >
            <slot name="icon" />
        </span>
        <div class="flex min-w-0 flex-1 basis-40 flex-col gap-px">
            <span
                class="truncate text-[13px]/5 font-medium tracking-[-0.01em] text-fg-primary"
            >
                <slot name="title">{{ title }}</slot>
            </span>
            <span
                v-if="description || $slots.description"
                class="text-[12.5px]/[18px] tracking-[-0.005em] text-pretty text-fg-tertiary"
            >
                <slot name="description">{{ description }}</slot>
            </span>
            <slot />
        </div>
        <div
            v-if="$slots.actions"
            class="flex shrink-0 flex-wrap items-center gap-3.5"
        >
            <slot name="actions" />
        </div>
    </div>
</template>
