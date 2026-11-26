<script setup lang="ts">
const { title } = defineProps<{ title: string }>();

defineSlots<{ default?: () => unknown; meta?: () => unknown }>();
</script>

<template>
    <section class="flex w-full flex-col gap-1.5" :aria-label="title">
        <div class="flex h-[26px] items-center gap-2">
            <h2 class="truncate text-[13px]/5 font-medium text-fg-tertiary">
                {{ title }}
            </h2>
            <span
                v-if="$slots.meta"
                class="ml-auto truncate text-[12px]/4 text-fg-muted"
            >
                <slot name="meta" />
            </span>
        </div>
        <div class="flex flex-col gap-0.5">
            <slot />
        </div>
    </section>
</template>
