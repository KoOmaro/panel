<script setup lang="ts">
import { Check, Minus } from '@lucide/vue';

const checked = defineModel<boolean>({ default: false });

const { indeterminate = false, label } = defineProps<{
    indeterminate?: boolean;
    label?: string;
}>();
</script>

<template>
    <button
        type="button"
        role="checkbox"
        :aria-checked="indeterminate ? 'mixed' : checked"
        :aria-label="label"
        class="group/check flex size-[15px] shrink-0 cursor-pointer items-center justify-center rounded-[4px] border-[0.5px] transition-colors duration-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring)"
        :class="
            checked || indeterminate
                ? 'border-transparent bg-dash-blue text-white'
                : 'border-edge-checkbox bg-surface-checkbox group-hover/row:border-edge-checkbox-hover hover:border-edge-checkbox-hover'
        "
        @click.stop="checked = !checked"
    >
        <Minus v-if="indeterminate" class="size-2.5" :stroke-width="3" />
        <Check v-else-if="checked" class="size-2.5" :stroke-width="3" />
    </button>
</template>
