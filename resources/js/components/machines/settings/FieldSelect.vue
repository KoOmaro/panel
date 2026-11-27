<script setup lang="ts" generic="V extends string | number | null">
import { Check } from '@lucide/vue';
import { computed } from 'vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

export type FieldOption<T> = {
    value: T;
    label: string;
    hint?: string;
    disabled?: boolean;
    separated?: boolean;
    danger?: boolean;
};

const props = withDefaults(
    defineProps<{
        modelValue: V;
        options: FieldOption<V>[];
        label: string;
        width?: number;
        disabled?: boolean;
        display?: string;
        tone?: 'field' | 'plain';
        align?: 'start' | 'end';
    }>(),
    {
        width: 232,
        disabled: false,
        display: undefined,
        tone: 'field',
        align: 'end',
    },
);

const emit = defineEmits<{ 'update:modelValue': [value: V] }>();

defineSlots<{
    lead?: () => unknown;
    option?: (props: { option: FieldOption<V> }) => unknown;
}>();

const current = computed(
    () =>
        props.options.find((option) => option.value === props.modelValue) ??
        null,
);
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child :disabled="disabled">
            <button
                type="button"
                :aria-label="label"
                :disabled="disabled"
                class="group/field flex shrink-0 cursor-pointer items-center gap-2 text-left focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-(--ds-ring) disabled:cursor-default"
                :class="
                    tone === 'field'
                        ? 'dash-field max-w-full pr-[9px] disabled:opacity-60 data-[state=open]:border-edge-checkbox-hover'
                        : 'h-5 rounded-sm text-[13px]/5 tracking-[-0.005em] text-fg-secondary hover:text-fg-primary data-[state=open]:text-fg-primary'
                "
                :style="tone === 'field' ? { width: `${width}px` } : undefined"
            >
                <slot name="lead" />
                <span
                    class="min-w-0 truncate"
                    :class="tone === 'field' ? 'flex-1' : ''"
                >
                    {{ display ?? current?.label ?? '' }}
                </span>
                <FilledIcon
                    v-if="tone === 'field'"
                    name="chevron-down"
                    :size="13"
                    class="text-fg-muted"
                />
            </button>
        </DropdownMenuTrigger>
        <DropdownMenuContent
            class="ds-modal max-w-80 min-w-(--reka-dropdown-menu-trigger-width) font-ui"
            :align="align"
        >
            <template v-for="option in options" :key="String(option.value)">
                <DropdownMenuSeparator v-if="option.separated" />
                <DropdownMenuItem
                    :disabled="option.disabled"
                    class="items-start text-[13px]/5"
                    :class="option.danger ? 'text-status-down!' : ''"
                    @select="emit('update:modelValue', option.value)"
                >
                    <span class="flex min-w-0 grow flex-col">
                        <slot name="option" :option="option">
                            <span class="truncate">{{ option.label }}</span>
                        </slot>
                        <span
                            v-if="option.hint"
                            class="text-[12px]/4 text-fg-muted"
                            >{{ option.hint }}</span
                        >
                    </span>
                    <Check
                        v-if="option.value === modelValue && !option.danger"
                        class="mt-0.5 size-3.5 shrink-0"
                        :stroke-width="2"
                    />
                </DropdownMenuItem>
            </template>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
