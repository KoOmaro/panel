<script setup lang="ts">
withDefaults(
    defineProps<{
        label?: string;
        hint?: string | null;
        mono?: boolean;
        error?: string | null;
        labelFor?: string;
        stack?: boolean;
    }>(),
    { label: undefined, hint: null, error: null, labelFor: undefined },
);
defineSlots<{
    default?: () => unknown;
    label?: () => unknown;
    below?: () => unknown;
}>();
</script>

<template>
    <div
        class="flex min-h-[52px] flex-col justify-center border-b-[0.5px] border-edge-tabs py-2 last:border-b-0"
    >
        <div
            class="flex min-h-8 gap-x-4 gap-y-2"
            :class="
                stack
                    ? 'flex-col items-stretch'
                    : 'flex-wrap items-center sm:flex-nowrap'
            "
        >
            <div class="flex min-w-0 flex-1 flex-col">
                <slot name="label">
                    <component
                        :is="labelFor ? 'label' : 'span'"
                        :for="labelFor"
                        class="truncate"
                        :class="
                            mono
                                ? 'font-ui-mono text-[12px]/[18px] text-fg-primary'
                                : 'text-[13px]/5 tracking-[-0.005em] text-fg-primary'
                        "
                        >{{ label }}</component
                    >
                    <span
                        v-if="hint"
                        class="text-[12px]/4 tracking-[-0.005em] text-fg-muted"
                        >{{ hint }}</span
                    >
                </slot>
            </div>
            <div
                class="flex min-w-0 items-center gap-x-4 gap-y-2"
                :class="stack ? '' : 'flex-wrap justify-end'"
            >
                <slot />
            </div>
        </div>
        <slot name="below" />
        <p
            v-if="error"
            class="pt-1.5 text-right text-[12px]/4 text-status-down"
            role="alert"
        >
            {{ error }}
        </p>
    </div>
</template>
