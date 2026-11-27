<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import treeLast from '@/components/dash/assets/tree-last.svg?raw';
import treeMiddle from '@/components/dash/assets/tree-middle.svg?raw';

const {
    label,
    href,
    current = false,
    last = false,
} = defineProps<{
    label: string;
    href: string;
    current?: boolean;
    last?: boolean;
}>();
</script>

<template>
    <Link
        :href="href"
        class="group/sub relative flex h-[30px] w-full shrink-0 items-center pr-2.5 pl-[39px] focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-(--ds-ring)"
        :class="
            current
                ? 'rounded-[6px] bg-surface-raised'
                : 'rounded-[8px] hover:bg-surface-hover'
        "
        :aria-current="current ? 'page' : undefined"
    >
        <span
            class="pointer-events-none absolute top-[-10px] left-0 flex size-10 text-(--dash-tree)"
            aria-hidden="true"
            v-html="last ? treeLast : treeMiddle"
        />
        <span
            class="truncate text-[13px]/5 tracking-[-0.005em]"
            :class="
                current
                    ? 'text-fg-primary'
                    : 'text-fg-secondary transition-colors group-hover/sub:text-fg-primary'
            "
            >{{ label }}</span
        >
    </Link>
</template>
