<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import separator from '@/components/dash/assets/crumb-separator.svg?raw';
import BrandLogo from '@/components/dash/BrandLogo.vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import type { DashCrumb } from '@/composables/useDashShell';

const { crumbs, leading = true } = defineProps<{
    crumbs: DashCrumb[];
    leading?: boolean;
}>();
</script>

<template>
    <ol class="flex min-w-0 items-center gap-2.5">
        <li
            v-for="(crumb, index) in crumbs"
            :key="`${index}-${crumb.label}`"
            class="flex min-w-0 items-center gap-2.5"
            :class="index === crumbs.length - 1 ? 'shrink' : 'shrink-0'"
        >
            <span
                v-if="leading || index > 0"
                class="flex h-[9px] w-1 shrink-0 overflow-visible text-fg-faint"
                aria-hidden="true"
                v-html="separator"
            />
            <component
                :is="crumb.href && index < crumbs.length - 1 ? Link : 'span'"
                :href="
                    crumb.href && index < crumbs.length - 1
                        ? crumb.href
                        : undefined
                "
                class="flex min-w-0 items-center gap-1.5 rounded-[5px] text-[13px]/5 font-medium tracking-[-0.01em] whitespace-nowrap focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring)"
                :class="
                    index === crumbs.length - 1
                        ? 'text-fg-primary'
                        : 'text-fg-secondary transition-colors hover:text-fg-primary'
                "
                :aria-current="index === crumbs.length - 1 ? 'page' : undefined"
            >
                <img
                    v-if="crumb.image"
                    :src="crumb.image"
                    alt=""
                    class="size-4 shrink-0 rounded-[4px] object-cover"
                />
                <BrandLogo
                    v-else-if="crumb.logo"
                    :name="crumb.logo"
                    :fallback="false"
                />
                <FilledIcon
                    v-else-if="crumb.icon"
                    :name="crumb.icon"
                    class="text-fg-muted"
                />
                <span class="truncate">{{ crumb.label }}</span>
            </component>
        </li>
    </ol>
</template>
