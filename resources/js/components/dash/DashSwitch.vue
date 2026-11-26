<script setup lang="ts">
const on = defineModel<boolean>({ default: false });

const {
    size = 'md',
    label,
    disabled = false,
} = defineProps<{
    size?: 'sm' | 'md';
    label?: string;
    disabled?: boolean;
}>();
</script>

<template>
    <button
        type="button"
        role="switch"
        :aria-checked="on"
        :aria-label="label"
        :disabled="disabled"
        class="relative shrink-0 cursor-pointer rounded-full transition-colors duration-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring) disabled:cursor-default disabled:opacity-50"
        :class="[
            size === 'sm' ? 'h-4 w-7' : 'h-[18px] w-8',
            on
                ? 'bg-dash-blue shadow-[0_1px_1.5px_rgb(0_0_0/0.4),inset_0_1px_0_rgb(255_255_255/0.2)]'
                : 'bg-edge-checkbox shadow-[inset_0_1px_2px_rgb(0_0_0/0.35)]',
        ]"
        @click="on = !on"
    >
        <span
            class="absolute top-0.5 left-0.5 rounded-full bg-white shadow-[0_1px_2px_rgb(0_0_0/0.5)] transition-transform duration-200 ease-[cubic-bezier(0.32,0.72,0,1)] motion-reduce:transition-none"
            :class="[
                size === 'sm' ? 'size-3' : 'size-3.5',
                on ? (size === 'sm' ? 'translate-x-3' : 'translate-x-3.5') : '',
            ]"
            aria-hidden="true"
        />
    </button>
</template>
