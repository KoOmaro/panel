<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { Component } from 'vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import type { FilledIconName } from '@/components/icons/filledIcons';

const {
    label,
    icon,
    href,
    external = false,
    current = false,
    bright = false,
    badge = null,
    group = null,
    collapsed = false,
} = defineProps<{
    label: string;
    icon: FilledIconName | Component;
    href: string;
    external?: boolean;
    current?: boolean;
    bright?: boolean;
    badge?: number | null;
    group?: 'open' | 'closed' | null;
    collapsed?: boolean;
}>();

const emit = defineEmits<{ toggle: [] }>();
</script>

<template>
    <div
        class="group/item relative flex h-8 w-full shrink-0 items-center rounded-[8px] transition-colors duration-150"
        :class="
            current
                ? 'bg-surface-raised'
                : 'hover:bg-surface-hover has-focus-visible:bg-surface-hover'
        "
    >
        <component
            :is="external ? 'a' : Link"
            :href="href"
            :target="external ? '_blank' : undefined"
            :rel="external ? 'noopener' : undefined"
            class="flex h-full min-w-0 flex-1 items-center gap-[11px] rounded-[8px] focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-(--ds-ring)"
            :class="collapsed ? 'justify-center px-0' : 'px-2.5'"
            :aria-current="current ? 'page' : undefined"
            :aria-label="collapsed ? label : undefined"
            :title="collapsed ? label : undefined"
        >
            <FilledIcon
                v-if="typeof icon === 'string'"
                :name="icon"
                :size="18"
                :class="bright ? 'text-fg-primary' : 'text-fg-tertiary'"
            />
            <component
                :is="icon"
                v-else
                class="size-[18px] shrink-0 text-fg-tertiary"
                :stroke-width="1.6"
            />
            <span
                v-if="!collapsed"
                class="min-w-0 flex-1 truncate text-[13px]/5 tracking-[-0.01em]"
                :class="
                    current
                        ? 'font-medium text-fg-primary'
                        : 'text-fg-tertiary transition-colors group-hover/item:text-fg-secondary'
                "
                >{{ label }}</span
            >
            <span
                v-if="badge && !collapsed"
                class="flex h-[18px] min-w-5 shrink-0 items-center justify-center rounded-[5px] border-[0.5px] border-edge-strong bg-surface-badge px-1 text-[11px]/3.5 font-medium text-fg-secondary tabular-nums"
                :aria-label="$t(':count left', { count: String(badge) })"
                >{{ badge }}</span
            >
        </component>
        <button
            v-if="group && !collapsed"
            type="button"
            class="mr-2 flex size-5 shrink-0 cursor-pointer items-center justify-center rounded-[5px] text-fg-tertiary transition-colors hover:bg-surface-raised hover:text-fg-secondary focus-visible:outline-2 focus-visible:outline-(--ds-ring)"
            :aria-expanded="group === 'open'"
            :aria-label="
                group === 'open'
                    ? $t('Hide :name', { name: label })
                    : $t('Show :name', { name: label })
            "
            @click="emit('toggle')"
        >
            <FilledIcon
                :name="group === 'open' ? 'chevron-up' : 'chevron-down'"
            />
        </button>
    </div>
</template>
