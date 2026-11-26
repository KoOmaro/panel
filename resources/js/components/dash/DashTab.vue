<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import type { FilledIconName } from '@/components/icons/filledIcons';

const {
    label,
    icon,
    active = false,
    href,
} = defineProps<{
    label: string;
    icon?: FilledIconName;
    active?: boolean;
    href?: string;
}>();

defineEmits<{ click: [event: MouseEvent] }>();
defineSlots<{ suffix?: () => unknown }>();
</script>

<template>
    <component
        :is="href ? Link : 'button'"
        :href="href"
        :type="href ? undefined : 'button'"
        class="group relative flex h-11 shrink-0 cursor-pointer flex-col items-center justify-center focus-visible:outline-none"
        :aria-current="active ? 'page' : undefined"
        @click="$emit('click', $event)"
    >
        <span
            class="flex h-7.5 items-center gap-[7px] rounded-[7px] pr-[11px] pl-2.5 text-[13px]/5 font-medium tracking-[-0.01em] whitespace-nowrap transition-colors duration-150 group-focus-visible:outline-2 group-focus-visible:outline-(--ds-ring)"
            :class="
                active
                    ? 'border-[0.5px] border-edge-control bg-surface-raised text-fg-primary'
                    : 'text-fg-tertiary group-hover:text-fg-secondary'
            "
        >
            <FilledIcon v-if="icon" :name="icon" />
            {{ label }}
            <slot name="suffix" />
        </span>
        <span
            v-if="active"
            class="absolute right-[-1px] bottom-0 left-1.5 h-0.5 rounded-[1px] bg-(--dash-underline)"
            aria-hidden="true"
        />
    </component>
</template>
