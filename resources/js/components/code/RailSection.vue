<script setup lang="ts">
const {
    title,
    first = false,
    quiet = false,
} = defineProps<{
    title: string;
    first?: boolean;
    quiet?: boolean;
}>();

defineSlots<{ default?: () => unknown; aside?: () => unknown }>();
</script>

<template>
    <section
        class="flex flex-col gap-2.5"
        :class="first ? 'pb-5' : 'border-t-[0.5px] border-edge-tabs py-5'"
    >
        <div class="flex h-6.5 items-center gap-2">
            <h2
                class="text-[13px]/5 font-medium"
                :class="quiet ? 'text-fg-tertiary' : 'text-fg-primary'"
            >
                {{ title }}
            </h2>
            <span class="grow" />
            <slot name="aside" />
        </div>
        <slot />
    </section>
</template>
