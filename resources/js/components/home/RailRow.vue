<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import BrandLogo from '@/components/dash/BrandLogo.vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import type { FilledIconName } from '@/components/icons/filledIcons';

const {
    title,
    description,
    icon,
    logo,
    href,
    external = false,
    kbd,
    dialog = false,
} = defineProps<{
    title: string;
    description?: string | null;
    icon?: FilledIconName;
    logo?: string;
    href?: string;
    external?: boolean;
    kbd?: string;
    dialog?: boolean;
}>();

defineSlots<{ tile?: () => unknown; trail?: () => unknown }>();

const tag = computed(() => (href ? (external ? 'a' : Link) : 'button'));
const attrs = computed(() =>
    href
        ? external
            ? { href, target: '_blank', rel: 'noopener' }
            : { href }
        : {
              type: 'button',
              'aria-haspopup': dialog ? ('dialog' as const) : undefined,
          },
);
</script>

<template>
    <component
        :is="tag"
        v-bind="attrs"
        class="group flex w-full cursor-pointer items-center gap-2.5 rounded-[8px] py-1.5 pr-2 pl-1.5 text-left transition-colors duration-100 hover:bg-surface-raised focus-visible:outline-2 focus-visible:outline-(--ds-ring)"
    >
        <span
            class="flex size-7 shrink-0 items-center justify-center rounded-[7px] border-[0.5px] border-edge-control bg-surface-raised shadow-[0_1px_1.5px_rgb(0_0_0/0.55),inset_0_1px_0_rgb(255_255_255/0.05)]"
            aria-hidden="true"
        >
            <slot name="tile">
                <BrandLogo v-if="logo" :name="logo" :size="14" />
                <FilledIcon
                    v-else-if="icon"
                    :name="icon"
                    class="text-fg-muted"
                />
            </slot>
        </span>
        <span class="flex min-w-0 grow flex-col">
            <span
                class="truncate text-[13px]/[18px] font-medium text-fg-primary"
                >{{ title }}</span
            >
            <span
                v-if="description"
                class="truncate text-[12px]/4 text-fg-tertiary"
                >{{ description }}</span
            >
        </span>
        <slot name="trail">
            <kbd
                v-if="kbd"
                class="flex shrink-0 items-center rounded-[4px] border-[0.5px] border-edge-strong bg-surface-avatar px-[5px] py-px font-ui-mono text-[11px]/4 text-fg-muted pointer-coarse:hidden"
                >{{ kbd }}</kbd
            >
            <FilledIcon v-else name="chevron-right" class="text-fg-secondary" />
        </slot>
    </component>
</template>
