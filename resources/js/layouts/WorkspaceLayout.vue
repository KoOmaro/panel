<script setup lang="ts">
import SubscriptionBanner from '@/components/workspace/SubscriptionBanner.vue';
import { useSubscriptionNotice } from '@/composables/useSubscriptionNotice';
import { usePage } from '@inertiajs/vue3';
import {
    computed,
    defineAsyncComponent,
    onBeforeUnmount,
    onMounted,
    ref,
    useTemplateRef,
    watch,
} from 'vue';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetTitle,
} from '@/components/ui/sheet';
import NotchToaster from '@/components/workspace/NotchToaster.vue';
import type AssistantPanelComponent from '@/components/workspace/AssistantPanel.vue';
import WorkspacePageSkeleton from '@/components/workspace/WorkspacePageSkeleton.vue';
import AgentsSidebar from '@/components/agents/AgentsSidebar.vue';
import AppHeader from '@/components/workspace/shell/AppHeader.vue';
import AppSidebar from '@/components/workspace/shell/AppSidebar.vue';
import ThemeDraftBar from '@/components/workspace/ThemeDraftBar.vue';
import { useAssistant } from '@/composables/useAssistant';
import { useCommandPalette } from '@/composables/useCommandPalette';
import { useDashShell } from '@/composables/useDashShell';
import { useWorkspaceSwitch } from '@/composables/useWorkspaceSwitch';
import { usePanelUpdates } from '@/composables/usePanelUpdates';

const AssistantPanel = defineAsyncComponent(
    () => import('@/components/workspace/AssistantPanel.vue'),
);
const CommandPalette = defineAsyncComponent(
    () => import('@/components/workspace/CommandPalette.vue'),
);
const WhatsNewDialog = defineAsyncComponent(
    () => import('@/components/workspace/WhatsNewDialog.vue'),
);

const subscriptionNotice = useSubscriptionNotice();
usePanelUpdates();

const NO_SIDEBAR = ['workspace/WorkflowEditor'];
const OWN_HEADER = ['workspace/AgentTaskShow', 'workspace/Agents'];

const page = usePage();
const shell = useDashShell();
const { switchingTo } = useWorkspaceSwitch();
const { open: searching } = useCommandPalette();
const mobileOpen = ref(false);

const inAgents = computed(() => page.component.startsWith('workspace/Agent'));

const sidebarMode = computed(
    () =>
        shell.sidebar.value ??
        (NO_SIDEBAR.includes(page.component) ? 'none' : 'app'),
);

const ownHeader = computed(() => OWN_HEADER.includes(page.component));

const COLLAPSED_KEY = 'workspace-sidebar-collapsed';
const collapsed = ref(false);

function toggleCollapsed(): void {
    collapsed.value = !collapsed.value;

    try {
        window.localStorage.setItem(COLLAPSED_KEY, collapsed.value ? '1' : '0');
    } catch {}
}

const {
    open: assistantOpen,
    expanded: assistantExpanded,
    toggle: toggleAssistant,
    activity: assistantActivity,
} = useAssistant();

const isDesktop = ref(false);
let desktopQuery: MediaQueryList | undefined;
function syncDesktop(): void {
    isDesktop.value = desktopQuery?.matches ?? false;
}
const assistantPanel =
    useTemplateRef<InstanceType<typeof AssistantPanelComponent>>(
        'assistantPanel',
    );
const assistantSheet =
    useTemplateRef<InstanceType<typeof AssistantPanelComponent>>(
        'assistantSheet',
    );

function onShortcut(event: KeyboardEvent): void {
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'j') {
        event.preventDefault();
        toggleAssistant();
    }
}

watch(assistantOpen, (open) => {
    if (open) {
        mobileOpen.value = false;
        setTimeout(() => {
            assistantPanel.value?.focus();
            assistantSheet.value?.focus();
        }, 220);
    }
});

watch(searching, (open) => {
    if (open) {
        mobileOpen.value = false;
    }
});

onMounted(() => {
    window.addEventListener('keydown', onShortcut);
    desktopQuery = window.matchMedia('(min-width: 64rem)');
    syncDesktop();
    desktopQuery.addEventListener('change', syncDesktop);

    try {
        collapsed.value = window.localStorage.getItem(COLLAPSED_KEY) === '1';
    } catch {}
});
onBeforeUnmount(() => {
    window.removeEventListener('keydown', onShortcut);
    desktopQuery?.removeEventListener('change', syncDesktop);
});

watch(
    () => page.url,
    () => {
        mobileOpen.value = false;
    },
);
</script>

<template>
    <div
        class="ds-root flex h-dvh flex-col overflow-hidden"
        :class="
            subscriptionNotice?.tone === 'urgent'
                ? 'bg-(--ds-amber-bg)'
                : 'bg-surface-sidebar'
        "
    >
        <SubscriptionBanner />
        <div
            class="relative flex min-h-0 flex-1 overflow-hidden bg-surface-main font-ui text-fg-primary antialiased"
        >
            <a
                href="#main"
                class="sr-only z-50 rounded-[6px] bg-surface-raised px-3 py-1.5 text-xs focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:outline-2 focus:outline-(--ds-ring)"
            >
                {{ ' ' + $t('Skip to content') + ' ' }}
            </a>

            <aside
                v-show="sidebarMode !== 'none'"
                data-theme-part="sidebar"
                class="relative hidden shrink-0 border-r-[0.5px] border-edge bg-surface-sidebar transition-[width] duration-200 ease-out motion-reduce:transition-none lg:block"
                :class="
                    collapsed && sidebarMode === 'app' ? 'w-14' : 'w-[224px]'
                "
            >
                <div
                    v-show="sidebarMode === 'custom'"
                    id="dash-sidebar-custom"
                    class="h-full overflow-hidden"
                />
                <div
                    v-if="sidebarMode === 'app'"
                    class="relative h-full overflow-hidden"
                >
                    <Transition
                        :name="inAgents ? 'side-to-agents' : 'side-to-servers'"
                    >
                        <AgentsSidebar
                            v-if="inAgents"
                            key="agents"
                            class="absolute inset-0"
                            :collapsed="collapsed"
                            collapsible
                            @toggle="toggleCollapsed"
                        />
                        <AppSidebar
                            v-else
                            key="servers"
                            class="absolute inset-0"
                            :collapsed="collapsed"
                            collapsible
                            @toggle="toggleCollapsed"
                        />
                    </Transition>
                </div>
            </aside>

            <div
                data-theme-part="content"
                class="relative isolate flex min-h-0 min-w-0 flex-1 flex-col bg-surface-main"
            >
                <AppHeader
                    data-theme-part="topbar"
                    :class="{ 'lg:hidden': ownHeader }"
                    @menu="mobileOpen = true"
                />

                <main
                    id="main"
                    data-theme-part="main"
                    class="relative isolate flex min-h-0 min-w-0 grow flex-col overflow-hidden"
                >
                    <div
                        class="assistant-page-glow pointer-events-none absolute inset-0 z-20 rounded-[inherit]"
                        :class="{
                            'is-on':
                                assistantActivity === 'working' ||
                                assistantActivity === 'waiting',
                        }"
                        :style="{
                            '--assistant-page-glow':
                                assistantActivity === 'waiting'
                                    ? '245 158 11'
                                    : '0 229 214',
                        }"
                        aria-hidden="true"
                    />
                    <div
                        class="min-h-0 grow overflow-y-auto overscroll-contain"
                    >
                        <WorkspacePageSkeleton v-if="switchingTo" />
                        <slot v-else />
                    </div>
                </main>
            </div>

            <div
                v-if="isDesktop"
                id="assistant-panel"
                data-theme-part="assistant"
                class="hidden shrink-0 overflow-hidden transition-[width,opacity] duration-300 ease-[cubic-bezier(0.32,0.72,0,1)] motion-reduce:transition-none lg:block"
                :class="
                    assistantOpen
                        ? 'opacity-100'
                        : 'pointer-events-none opacity-0'
                "
                :style="{
                    width: assistantOpen
                        ? assistantExpanded
                            ? '50%'
                            : '400px'
                        : '0px',
                }"
                :inert="!assistantOpen"
                @keydown.esc="assistantOpen = false"
            >
                <div
                    class="h-full overflow-hidden border-l-[0.5px] border-edge bg-surface-main"
                    :class="
                        assistantExpanded ? 'w-full min-w-[400px]' : 'w-[400px]'
                    "
                >
                    <AssistantPanel
                        ref="assistantPanel"
                        @close="assistantOpen = false"
                    />
                </div>
            </div>

            <Sheet v-model:open="mobileOpen">
                <SheetContent
                    side="left"
                    class="w-[264px] gap-0 border-edge bg-surface-sidebar p-0 pt-[env(safe-area-inset-top)] pb-[env(safe-area-inset-bottom)] font-ui text-fg-primary [&>button]:hidden"
                >
                    <SheetTitle class="sr-only">{{
                        $t('Navigation')
                    }}</SheetTitle>
                    <SheetDescription class="sr-only">
                        {{
                            ' ' +
                            $t('Workspace navigation and account menu') +
                            ' '
                        }}
                    </SheetDescription>
                    <AgentsSidebar
                        v-if="inAgents"
                        closable
                        @close="mobileOpen = false"
                    />
                    <AppSidebar v-else closable @close="mobileOpen = false" />
                </SheetContent>
            </Sheet>

            <Sheet
                :open="assistantOpen && !isDesktop"
                @update:open="(value) => (assistantOpen = value)"
            >
                <SheetContent
                    side="right"
                    data-assistant-sheet
                    class="w-full gap-0 border-edge bg-surface-main p-0 sm:max-w-[420px] [&>button]:hidden"
                >
                    <SheetTitle class="sr-only">{{ $t('Ask AI') }}</SheetTitle>
                    <SheetDescription class="sr-only">
                        {{
                            ' ' +
                            $t(
                                'Ask questions and get things done in your workspace',
                            ) +
                            ' '
                        }}
                    </SheetDescription>
                    <AssistantPanel
                        ref="assistantSheet"
                        @close="assistantOpen = false"
                    />
                </SheetContent>
            </Sheet>

            <CommandPalette v-model:open="searching" />
            <WhatsNewDialog />
            <NotchToaster />
            <ThemeDraftBar />
        </div>
    </div>
</template>

<style scoped>
.side-to-agents-enter-active,
.side-to-agents-leave-active,
.side-to-servers-enter-active,
.side-to-servers-leave-active {
    transition:
        transform 320ms cubic-bezier(0.32, 0.72, 0, 1),
        opacity 220ms ease;
}
.side-to-agents-enter-from,
.side-to-servers-leave-to {
    transform: translateX(28%);
    opacity: 0;
}
.side-to-agents-leave-to,
.side-to-servers-enter-from {
    transform: translateX(-28%);
    opacity: 0;
}
@media (prefers-reduced-motion: reduce) {
    .side-to-agents-enter-active,
    .side-to-agents-leave-active,
    .side-to-servers-enter-active,
    .side-to-servers-leave-active {
        transition: opacity 150ms ease;
    }
    .side-to-agents-enter-from,
    .side-to-agents-leave-to,
    .side-to-servers-enter-from,
    .side-to-servers-leave-to {
        transform: none;
    }
}
</style>
