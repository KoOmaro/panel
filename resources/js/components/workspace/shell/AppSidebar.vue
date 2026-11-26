<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import serverosMark from '@/components/dash/assets/serveros-mark.svg?raw';
import SectionLabel from '@/components/dash/SectionLabel.vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import AccountMenu from '@/components/workspace/AccountMenu.vue';
import SidebarItem from '@/components/workspace/shell/SidebarItem.vue';
import SidebarSubItem from '@/components/workspace/shell/SidebarSubItem.vue';
import SidebarUpgrade from '@/components/workspace/shell/SidebarUpgrade.vue';
import {
    openGroupFor,
    useWorkspaceNav,
} from '@/components/workspace/workspaceNav';
import type {
    WorkspaceNavGroupKey,
    WorkspaceNavItem,
} from '@/components/workspace/workspaceNav';

const {
    collapsed = false,
    collapsible = false,
    closable = false,
} = defineProps<{
    collapsed?: boolean;
    collapsible?: boolean;
    closable?: boolean;
}>();

const emit = defineEmits<{ toggle: []; close: [] }>();

const page = usePage();
const items = useWorkspaceNav();

const menu = computed(() =>
    items.value.filter((item) => item.section === 'menu'),
);
const other = computed(() =>
    items.value.filter((item) => item.section === 'other'),
);

const openGroup = ref<WorkspaceNavGroupKey | null>(openGroupFor(items.value));

watch(
    () => page.component,
    () => (openGroup.value = openGroupFor(items.value)),
);

function isGroup(item: WorkspaceNavItem): item is WorkspaceNavItem & {
    key: WorkspaceNavGroupKey;
} {
    return item.key === 'machines' || item.key === 'developers';
}

function groupState(item: WorkspaceNavItem): 'open' | 'closed' | null {
    if (!isGroup(item) || !item.children?.length) {
        return null;
    }

    return openGroup.value === item.key ? 'open' : 'closed';
}

function toggleGroup(key: WorkspaceNavGroupKey): void {
    openGroup.value = openGroup.value === key ? null : key;
}

const focusRing =
    'focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-(--ds-ring)';
</script>

<template>
    <div
        class="flex h-full flex-col overflow-hidden bg-surface-sidebar font-ui text-fg-primary"
    >
        <div
            class="flex h-14 shrink-0 items-center border-b-[0.5px] border-edge"
            :class="collapsed ? 'justify-center' : 'gap-3 pr-3 pl-[23px]'"
        >
            <template v-if="!collapsed">
                <a
                    :href="page.props.appUrl"
                    class="flex min-w-0 items-center gap-3 rounded-[5px]"
                    :class="focusRing"
                >
                    <span
                        class="flex h-[18px] w-[15.93px] shrink-0 text-fg-primary"
                        aria-hidden="true"
                        v-html="serverosMark"
                    />
                    <span
                        class="truncate text-[14px]/5 font-medium tracking-[-0.015em] text-fg-primary"
                        >{{ page.props.name }}</span
                    >
                </a>
                <span class="flex-1" />
                <button
                    v-if="collapsible"
                    type="button"
                    class="flex size-7 shrink-0 cursor-pointer items-center justify-center rounded-[6px] text-fg-tertiary transition-colors hover:bg-surface-hover hover:text-fg-secondary"
                    :class="focusRing"
                    :aria-label="$t('Collapse sidebar')"
                    aria-expanded="true"
                    @click="emit('toggle')"
                >
                    <FilledIcon name="sidebar" />
                </button>
                <button
                    v-if="closable"
                    type="button"
                    class="flex size-7 shrink-0 cursor-pointer items-center justify-center rounded-[6px] text-fg-tertiary transition-colors hover:bg-surface-hover hover:text-fg-secondary"
                    :class="focusRing"
                    :aria-label="$t('Close navigation')"
                    @click="emit('close')"
                >
                    <X class="size-4" :stroke-width="1.6" />
                </button>
            </template>
            <button
                v-else
                type="button"
                class="group flex size-8 cursor-pointer items-center justify-center rounded-[8px] text-fg-primary transition-colors hover:bg-surface-hover"
                :class="focusRing"
                :aria-label="$t('Expand sidebar')"
                aria-expanded="false"
                @click="emit('toggle')"
            >
                <span
                    class="flex h-[18px] w-[15.93px] group-hover:hidden group-focus-visible:hidden"
                    aria-hidden="true"
                    v-html="serverosMark"
                />
                <span
                    class="hidden text-fg-tertiary group-hover:flex group-focus-visible:flex"
                >
                    <FilledIcon name="sidebar" />
                </span>
            </button>
        </div>

        <nav
            class="flex min-h-0 grow [scrollbar-width:thin] flex-col overflow-x-hidden overflow-y-auto"
            :aria-label="$t('Workspace')"
        >
            <div
                class="flex flex-col gap-1 pt-2.5 pb-3.5"
                :class="collapsed ? 'items-center px-3' : 'px-3'"
            >
                <SectionLabel v-if="!collapsed">{{ $t('Menu') }}</SectionLabel>
                <template v-for="item in menu" :key="item.key">
                    <SidebarItem
                        :label="item.label"
                        :icon="item.icon"
                        :href="item.href"
                        :external="item.external"
                        :current="item.current"
                        :bright="item.bright"
                        :badge="item.badge"
                        :group="groupState(item)"
                        :collapsed="collapsed"
                        :class="{ 'w-8!': collapsed }"
                        @toggle="isGroup(item) && toggleGroup(item.key)"
                    />
                    <div
                        v-if="
                            !collapsed &&
                            groupState(item) === 'open' &&
                            item.children?.length
                        "
                        class="flex w-full flex-col"
                    >
                        <SidebarSubItem
                            v-for="(child, index) in item.children"
                            :key="child.key"
                            :label="child.label"
                            :href="child.href"
                            :current="child.current"
                            :last="index === item.children.length - 1"
                        />
                    </div>
                </template>
            </div>

            <div class="h-[0.5px] shrink-0 bg-edge" aria-hidden="true" />

            <div
                class="flex flex-col gap-1 pt-2.5"
                :class="collapsed ? 'items-center px-3 pb-3' : 'px-3'"
            >
                <SectionLabel v-if="!collapsed">{{ $t('Other') }}</SectionLabel>
                <SidebarItem
                    v-for="item in other"
                    :key="item.key"
                    :label="item.label"
                    :icon="item.icon"
                    :href="item.href"
                    :external="item.external"
                    :current="item.current"
                    :collapsed="collapsed"
                    :class="{ 'w-8!': collapsed }"
                />
            </div>

            <div class="min-h-4 grow" />

            <SidebarUpgrade v-if="!collapsed" />
        </nav>

        <div
            class="shrink-0 border-t-[0.5px] border-edge"
            :class="{ 'flex justify-center py-2': collapsed }"
        >
            <AccountMenu
                :display="collapsed ? 'avatar' : 'sidebar'"
                side="right"
                align="end"
            />
        </div>
    </div>
</template>
