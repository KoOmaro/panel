<script setup lang="ts">
import { Menu, Search } from '@lucide/vue';
import { computed } from 'vue';
import DashBreadcrumb from '@/components/dash/DashBreadcrumb.vue';
import WorkspaceMenu from '@/components/workspace/WorkspaceMenu.vue';
import { useAutoCrumbs } from '@/components/workspace/shell/useAutoCrumbs';
import { useCommandPalette } from '@/composables/useCommandPalette';
import { useDashShell } from '@/composables/useDashShell';

const { menu = true } = defineProps<{ menu?: boolean }>();

const emit = defineEmits<{ menu: [] }>();

const shell = useDashShell();
const auto = useAutoCrumbs();
const palette = useCommandPalette();

const crumbs = computed(() => shell.crumbs.value ?? auto.value);

const iconButton =
    'flex size-8 shrink-0 cursor-pointer items-center justify-center rounded-[8px] text-fg-tertiary transition-colors hover:bg-surface-hover hover:text-fg-secondary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring)';
</script>

<template>
    <header
        class="flex h-14 shrink-0 items-center gap-2.5 border-b-[0.5px] border-edge bg-surface-main pr-4 pl-5 font-ui max-lg:pl-3"
    >
        <button
            v-if="menu"
            type="button"
            class="-ml-1 lg:hidden"
            :class="iconButton"
            :aria-label="$t('Open navigation')"
            @click="emit('menu')"
        >
            <Menu class="size-[18px]" :stroke-width="1.6" />
        </button>
        <nav
            :aria-label="$t('Breadcrumb')"
            class="flex min-w-0 items-center gap-2.5 overflow-hidden"
        >
            <WorkspaceMenu variant="crumb" class="shrink-0" />
            <DashBreadcrumb :crumbs="crumbs" class="max-sm:hidden" />
        </nav>
        <div
            id="dash-header-meta"
            class="flex min-w-0 items-center gap-2 empty:hidden max-md:hidden"
        />
        <span class="min-w-0 flex-1" />
        <div
            id="dash-header-actions"
            class="flex shrink-0 items-center gap-2 empty:hidden max-sm:min-w-0 max-sm:shrink max-sm:[scrollbar-width:none] max-sm:overflow-x-auto"
        />
        <button
            type="button"
            class="md:hidden"
            :class="iconButton"
            :aria-label="$t('Search')"
            aria-haspopup="dialog"
            @click="palette.show()"
        >
            <Search class="size-4" :stroke-width="1.6" />
        </button>
    </header>
</template>
