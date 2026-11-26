<script setup lang="ts">
import { provide, useId } from 'vue';
import { SETTINGS_SECTIONS } from '@/components/workspace/settings/sections';

const {
    title,
    description,
    danger = false,
    padded = true,
    wide = false,
} = defineProps<{
    title?: string;
    description?: string;
    danger?: boolean;
    padded?: boolean;
    wide?: boolean;
}>();

defineSlots<{
    default?: () => unknown;
    head?: () => unknown;
    actions?: () => unknown;
    footer?: () => unknown;
}>();

provide(SETTINGS_SECTIONS, false);

const headingId = useId();
</script>

<template>
    <section
        class="settings-section flex min-w-0 scroll-mt-6 flex-col gap-4 border-t-[0.5px] border-edge-tabs py-7"
        :class="{ 'lg:flex-row lg:items-start lg:gap-12': !wide }"
        :aria-labelledby="title ? headingId : undefined"
    >
        <div
            class="flex w-full min-w-0 shrink-0 flex-col pt-0.5"
            :class="{ 'lg:w-60': !wide }"
        >
            <slot name="head">
                <h2
                    :id="headingId"
                    class="text-[14px]/5 font-medium tracking-[-0.01em] text-fg-primary"
                >
                    {{ title }}
                </h2>
                <p
                    v-if="description"
                    class="mt-1 text-[13px]/5 tracking-[-0.005em] text-pretty text-fg-tertiary"
                >
                    {{ description }}
                </p>
            </slot>
            <div
                v-if="$slots.actions"
                class="mt-3 flex flex-wrap items-center gap-2"
            >
                <slot name="actions" />
            </div>
        </div>

        <div
            class="dash-card flex min-w-0 flex-1 flex-col self-stretch"
            :class="{ 'border-status-down/35!': danger }"
        >
            <div
                class="flex grow flex-col"
                :class="{ 'px-[18px] py-4': padded }"
            >
                <slot />
            </div>
            <div v-if="$slots.footer" class="dash-card-footer flex-wrap">
                <slot name="footer" />
            </div>
        </div>
    </section>
</template>
