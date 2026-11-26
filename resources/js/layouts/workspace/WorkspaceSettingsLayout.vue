<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { router, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, provide } from 'vue';
import DashCustomSidebar from '@/components/dash/DashCustomSidebar.vue';
import DashHeader from '@/components/dash/DashHeader.vue';
import SettingsMobileNav from '@/components/workspace/settings/SettingsMobileNav.vue';
import SettingsSidebar from '@/components/workspace/settings/SettingsSidebar.vue';
import { SETTINGS_SECTIONS } from '@/components/workspace/settings/sections';
import { backHref, useSettingsNav } from '@/components/workspace/settingsNav';
import type { DashCrumb } from '@/composables/useDashShell';
import type { Workspace } from '@/types';

const page = usePage<{ workspace: Workspace }>();
const groups = useSettingsNav();

provide(SETTINGS_SECTIONS, true);

const current = computed(() => {
    for (const group of groups.value) {
        const item = group.items.find((entry) => entry.current);

        if (item) {
            return { group, item };
        }
    }

    return null;
});

const ownSidebar = computed(() => current.value?.group.inSidebar !== false);

const settingsHref = computed(() => groups.value[0]?.items[0]?.href);

const crumbs = computed<DashCrumb[] | undefined>(() => {
    if (!current.value) {
        return undefined;
    }

    const { group, item } = current.value;

    return [
        group.inSidebar
            ? { label: trans('Settings'), href: settingsHref.value }
            : { label: group.label, href: group.href },
        { label: item.label },
    ];
});

function revealHash(): void {
    const id = window.location.hash.slice(1);
    const target = id ? document.getElementById(id) : null;

    if (!target) {
        return;
    }

    target.scrollIntoView({ block: 'start', behavior: 'smooth' });
    target.classList.remove('settings-found');
    void target.offsetWidth;
    target.classList.add('settings-found');
}

let stopNavigate: (() => void) | undefined;

onMounted(() => {
    void nextTick(revealHash);
    stopNavigate = router.on('navigate', () => {
        void nextTick(revealHash);
    });
});

onBeforeUnmount(() => stopNavigate?.());
</script>

<template>
    <DashHeader :crumbs="crumbs" />

    <DashCustomSidebar v-if="ownSidebar">
        <SettingsSidebar
            :groups="groups"
            :back-href="backHref(page.props.workspace.slug)"
            :back-label="
                $t('Back to :name', { name: String(page.props.workspace.name) })
            "
        />
    </DashCustomSidebar>

    <div
        data-theme-part="page"
        class="workspace-panel mx-auto flex min-h-full w-full max-w-[940px] flex-col px-5 pt-5 pb-16 font-ui text-fg-secondary sm:px-10 lg:pt-9"
    >
        <SettingsMobileNav
            v-if="ownSidebar"
            class="mb-6 lg:hidden"
            :groups="groups"
        />

        <header
            v-if="current"
            data-theme-part="page-header"
            class="flex flex-col gap-1 pb-2"
        >
            <h1
                data-theme-part="page-title"
                class="text-[20px]/7 font-medium tracking-[-0.015em] text-fg-primary"
            >
                {{ current.item.label }}
            </h1>
            <p
                v-if="current.item.description"
                class="text-[13px]/5 tracking-[-0.005em] text-fg-tertiary"
            >
                {{ current.item.description }}
            </p>
        </header>

        <div class="settings-body flex min-w-0 flex-col">
            <slot />
        </div>
    </div>
</template>

<style>
:is(.settings-body, .settings-body > div, .settings-body > form)
    > section:not(.settings-section) {
    gap: 16px;
    padding-block: 28px;
    border-top: 0.5px solid var(--dash-edge-tabs);
    scroll-margin-top: 24px;
}

:is(.settings-body, .settings-body > div, .settings-body > form)
    > section:not(.settings-section)
    > :first-child
    h2 {
    color: var(--dash-fg-primary);
    font-size: 14px;
    font-weight: 500;
    line-height: 20px;
}

@media (width >= 64rem) {
    :is(.settings-body, .settings-body > div, .settings-body > form)
        > section:not(.settings-section, .settings-wide) {
        display: grid;
        grid-template-columns: 240px minmax(0, 1fr);
        align-items: start;
        column-gap: 48px;
        row-gap: 0;
    }

    :is(.settings-body, .settings-body > div, .settings-body > form)
        > section:not(.settings-section, .settings-wide)
        > :first-child {
        grid-row: 1 / span 8;
        grid-column: 1;
    }

    :is(.settings-body, .settings-body > div, .settings-body > form)
        > section:not(.settings-section, .settings-wide)
        > :not(:first-child) {
        grid-column: 2;
    }

    :is(.settings-body, .settings-body > div, .settings-body > form)
        > section:not(.settings-section, .settings-wide)
        > :nth-child(n + 3) {
        margin-top: 12px;
    }
}
</style>
