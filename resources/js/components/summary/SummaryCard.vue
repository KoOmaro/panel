<script setup lang="ts">
const {
    title,
    note,
    as = 'div',
} = defineProps<{
    title: string;
    note?: string | null;
    as?: string;
}>();

defineSlots<{ default?: () => unknown; note?: () => unknown }>();
</script>

<template>
    <component
        :is="as"
        class="dash-card flex min-w-0 flex-col items-stretch gap-2.5 px-[18px] py-3 text-left"
    >
        <div class="flex min-w-0 items-center gap-2 whitespace-nowrap">
            <component
                :is="as === 'button' ? 'span' : 'h2'"
                class="shrink-0 text-[14px]/5 font-medium tracking-[-0.01em] text-fg-primary"
            >
                {{ title }}
            </component>
            <span
                v-if="note || $slots.note"
                class="min-w-0 truncate text-[13px]/5 tracking-[-0.005em] text-fg-tertiary"
            >
                <slot name="note">{{ note }}</slot>
            </span>
        </div>
        <slot />
    </component>
</template>
