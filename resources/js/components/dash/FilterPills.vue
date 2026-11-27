<script setup lang="ts" generic="T extends string | number">
export type FilterPill<V> = {
    value: V;
    label: string;
    count?: number | string;
};

const value = defineModel<T>({ required: true });

const { options, label } = defineProps<{
    options: FilterPill<T>[];
    label?: string;
}>();
</script>

<template>
    <div
        role="radiogroup"
        :aria-label="label"
        class="flex h-[31px] shrink-0 items-center gap-0.5 rounded-[8px] border-[0.5px] border-edge-row bg-surface-well p-0.5 shadow-[inset_0_1px_2px_rgb(0_0_0/0.4)]"
    >
        <button
            v-for="option in options"
            :key="String(option.value)"
            type="button"
            role="radio"
            :aria-checked="option.value === value"
            class="flex h-[26px] cursor-pointer items-center gap-1.5 rounded-[6px] px-2.5 font-ui text-[12.5px]/4 font-medium tracking-[-0.005em] whitespace-nowrap transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-(--ds-ring)"
            :class="
                option.value === value
                    ? 'bg-surface-pill text-fg-primary shadow-[0_1px_1.5px_rgb(0_0_0/0.5),inset_0_1px_0_rgb(255_255_255/0.05)]'
                    : 'text-fg-tertiary hover:text-fg-secondary'
            "
            @click="value = option.value"
        >
            {{ option.label }}
            <span
                v-if="option.count !== undefined"
                class="font-ui-mono text-[11.5px]/4 font-normal"
                :class="
                    option.value === value
                        ? 'text-fg-secondary'
                        : 'text-fg-faint'
                "
                >{{ option.count }}</span
            >
        </button>
    </div>
</template>
