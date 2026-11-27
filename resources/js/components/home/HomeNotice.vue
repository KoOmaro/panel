<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import FilledIcon from '@/components/icons/FilledIcon.vue';

const {
    href,
    title,
    detail,
    action,
    tone = 'blue',
} = defineProps<{
    href: string;
    title: string;
    detail?: string | null;
    action: string;
    tone?: 'blue' | 'amber';
}>();
</script>

<template>
    <Link
        :href="href"
        class="dash-card flex items-center gap-3 px-[18px] py-3 transition-colors hover:bg-surface-hover focus-visible:outline-2 focus-visible:outline-(--ds-ring)"
    >
        <span
            class="flex size-5 shrink-0 items-center justify-center"
            aria-hidden="true"
        >
            <span
                class="size-3.5 rounded-full border-2"
                :class="
                    tone === 'amber'
                        ? 'border-status-pending'
                        : 'border-dash-blue'
                "
            />
        </span>
        <span class="min-w-0 grow truncate text-[13px]/5">
            <span class="font-medium text-fg-primary">{{ title }}</span>
            <span v-if="detail" class="text-fg-tertiary"> · {{ detail }}</span>
        </span>
        <span
            class="flex shrink-0 items-center gap-1 text-[12px]/4 font-medium text-fg-tertiary"
        >
            {{ action }}
            <FilledIcon name="chevron-right" :size="14" />
        </span>
    </Link>
</template>
