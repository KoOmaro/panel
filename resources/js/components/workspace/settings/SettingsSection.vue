<script setup lang="ts">
import type { Component } from 'vue';
import { computed, provide } from 'vue';
import BrandLogo from '@/components/dash/BrandLogo.vue';
import DashTray from '@/components/dash/DashTray.vue';
import type { FilledIconName } from '@/components/icons/filledIcons';
import { SETTINGS_SECTIONS } from '@/components/workspace/settings/sections';
import { trayIcon } from '@/components/workspace/settings/trayIcons';

defineOptions({ inheritAttrs: false });

const {
    title,
    description,
    icon = undefined,
    logo = undefined,
    danger = false,
    padded = true,
} = defineProps<{
    title?: string;
    description?: string;
    icon?: Component | FilledIconName;
    logo?: string;
    danger?: boolean;
    padded?: boolean;
    wide?: boolean;
}>();

defineSlots<{
    default?: () => unknown;
    head?: () => unknown;
    actions?: () => unknown;
    footer?: () => unknown;
}>();

provide(SETTINGS_SECTIONS, false);

const resolved = computed(() => trayIcon(icon));
const filled = computed(() =>
    typeof resolved.value === 'string' ? resolved.value : undefined,
);
const component = computed(() =>
    typeof resolved.value === 'string' ? undefined : resolved.value,
);
const panelClass = computed(
    () =>
        'pt-3 [--tile-bg:var(--dash-bg-tray-panel)] [--tile-edge:var(--dash-edge-row)] [--tile-head:var(--dash-bg-tray-panel)] [--tile-inner:var(--dash-bg-card-footer)] [--tile-rule:var(--dash-edge-row)]' +
        (danger ? ' border-status-down/60!' : ''),
);
</script>

<template>
    <section
        class="settings-section flex min-w-0 flex-col border-t-[0.5px] border-edge-tabs py-7"
    >
        <DashTray
            v-bind="$attrs"
            class="scroll-mt-6"
            :title="title ?? ''"
            :icon="filled"
            :panel-class="panelClass"
        >
            <template v-if="logo || component" #icon>
                <BrandLogo v-if="logo" :name="logo" :size="15" />
                <component
                    :is="component"
                    v-else
                    class="size-[15px] shrink-0 text-fg-muted"
                    :stroke-width="1.75"
                />
            </template>
            <template v-if="$slots.head" #title>
                <slot name="head" />
            </template>
            <template v-if="description" #meta>
                <span class="hidden whitespace-normal sm:inline">{{
                    description
                }}</span>
            </template>
            <template v-if="$slots.actions" #actions>
                <div
                    class="flex shrink-0 flex-wrap items-center justify-end gap-2"
                >
                    <slot name="actions" />
                </div>
            </template>

            <p
                v-if="description"
                class="px-[18px] pt-1 text-[12.5px]/[18px] tracking-[-0.005em] text-pretty text-fg-tertiary sm:hidden"
            >
                {{ description }}
            </p>
            <div
                class="flex grow flex-col"
                :class="{ 'px-[18px] py-4': padded }"
            >
                <slot />
            </div>
            <div v-if="$slots.footer" class="dash-card-footer flex-wrap">
                <slot name="footer" />
            </div>
        </DashTray>
    </section>
</template>
