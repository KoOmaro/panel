<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { Check, Plug, Plus } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref, useTemplateRef } from 'vue';
import DashButton from '@/components/dash/DashButton.vue';
import DashHeader from '@/components/dash/DashHeader.vue';
import HomeEmpty from '@/components/home/HomeEmpty.vue';
import HomeFleet from '@/components/home/HomeFleet.vue';
import HomeIssues from '@/components/home/HomeIssues.vue';
import HomeMachines from '@/components/home/HomeMachines.vue';
import HomeNotice from '@/components/home/HomeNotice.vue';
import HomeStats from '@/components/home/HomeStats.vue';
import MachineConnector from '@/components/home/MachineConnector.vue';
import NewProjectDialog from '@/components/home/NewProjectDialog.vue';
import RailRow from '@/components/home/RailRow.vue';
import RailSection from '@/components/home/RailSection.vue';
import type { Machine } from '@/components/machines/machines';
import SupportAsk from '@/components/workspace/SupportAsk.vue';
import { useAssistant } from '@/composables/useAssistant';
import type { HealthIssue } from '@/lib/healthIssues';
import { toast } from '@/lib/toast';
import { onWorkspace, workspaceAddress } from '@/lib/workspaceRoute';
import backupsRoutes from '@/routes/workspace/backups';
import fixesRoutes from '@/routes/workspace/fixes';
import servicesRoutes from '@/routes/workspace/services';
import settingsRoutes from '@/routes/workspace/settings';
import workflowsRoutes from '@/routes/workspace/workflows';
import type { Workspace, WorkspaceAccess, WorkspaceStats } from '@/types';

type Props = {
    workspace: Workspace;
    fixesWaiting: { id: number; title: string | null }[];
    stats: WorkspaceStats;
    providers: { provider: string; machines: number }[];
    issues: HealthIssue[];
    machines: Machine[];
    memberCount: number;
    lastBackup: string | null;
    panelUpdate: { version: string; current: string } | null;
    supportAsk: { donateUrl: string; licenseUrl: string } | null;
    licenseMachines?: {
        allowed: number;
        connected: number;
        over: number;
    } | null;
};

const props = defineProps<Props>();
const page = usePage<{ workspaceAccess: WorkspaceAccess | null }>();
const assistant = useAssistant();

const canManage = computed(
    () =>
        page.props.workspaceAccess?.permissions.includes('manage-machines') ??
        false,
);
const hasMachines = computed(() => props.stats.machines > 0);
const attention = computed(
    () => props.issues.filter((issue) => issue.severity !== 'notice').length,
);

const firstName = computed(
    () => page.props.auth.user.name.trim().split(/\s+/)[0] ?? '',
);

const connector =
    useTemplateRef<InstanceType<typeof MachineConnector>>('connector');
const creating = ref(false);

function connect(): void {
    connector.value?.open();
}

const slug = computed(() => props.workspace.slug);

const updateHref = computed(
    () => `${onWorkspace(settingsRoutes.general(slug.value)).url}#updates`,
);

const fixHref = computed(() =>
    props.fixesWaiting.length
        ? onWorkspace(
              fixesRoutes.index(slug.value, {
                  query: { fix: props.fixesWaiting[0].id },
              }),
          ).url
        : '',
);

const hasNotices = computed(
    () =>
        props.panelUpdate !== null ||
        props.supportAsk !== null ||
        props.fixesWaiting.length > 0 ||
        props.issues.length > 0,
);

const copied = ref(false);
let copiedTimer: ReturnType<typeof setTimeout> | undefined;

async function copyAddress(): Promise<void> {
    try {
        await navigator.clipboard.writeText(props.workspace.url);
        copied.value = true;
        clearTimeout(copiedTimer);
        copiedTimer = setTimeout(() => (copied.value = false), 1600);
        toast.success(trans('Copied'));
    } catch {
        toast.error(trans('Couldn’t copy the URL'));
    }
}

const shortcuts = computed(() => [
    {
        key: 'deploy',
        title: trans('Deploy a service'),
        description: trans('From a template or a Git repo'),
        icon: 'package-fill' as const,
        href: onWorkspace(servicesRoutes.index(slug.value)).url,
    },
    {
        key: 'workflows',
        title: trans('Workflows'),
        description: trans('Automate the routine work'),
        icon: 'branch-fill' as const,
        href: onWorkspace(workflowsRoutes.index(slug.value)).url,
    },
    {
        key: 'backups',
        title: trans('Backups'),
        description: props.lastBackup
            ? trans('Last backup :time', { time: props.lastBackup })
            : trans('Nothing backed up yet'),
        icon: 'zip-fill' as const,
        href: onWorkspace(backupsRoutes.index(slug.value)).url,
    },
]);
</script>

<template>
    <Head :title="$t('Home')" />

    <DashHeader :crumbs="[{ label: $t('Home') }]">
        <template v-if="canManage" #actions>
            <DashButton
                :icon="Plug"
                :label="$t('Connect machine')"
                aria-haspopup="dialog"
                class="max-sm:hidden!"
                @click="connect"
            />
            <DashButton
                variant="primary"
                :icon="Plus"
                :label="$t('New project')"
                aria-haspopup="dialog"
                @click="creating = true"
            />
        </template>
    </DashHeader>

    <div v-if="!hasMachines" class="flex min-h-full flex-col">
        <div v-if="hasNotices" class="flex flex-col gap-3 px-6 pt-6">
            <HomeNotice
                v-if="panelUpdate"
                :href="updateHref"
                :title="$t('Update available')"
                :detail="
                    $t('ServerOS :version is available. You’re on :current.', {
                        version: panelUpdate.version,
                        current: panelUpdate.current,
                    })
                "
                :action="$t('Update')"
            />
            <SupportAsk
                v-if="supportAsk"
                :ask="supportAsk"
                :workspace-id="workspace.id"
            />
            <HomeIssues
                v-if="issues.length"
                :issues="issues"
                :can-dismiss="canManage"
            />
        </div>
        <div
            class="flex grow flex-col items-center justify-center px-4 pb-[120px]"
        >
            <HomeEmpty
                :can-manage="canManage"
                @connect="connect"
                @create="creating = true"
            />
        </div>
    </div>

    <div v-else class="grid min-h-full xl:grid-cols-[minmax(0,1fr)_316px]">
        <div class="flex min-w-0 flex-col gap-3 px-6 pt-7 pb-8">
            <div class="flex flex-wrap items-start gap-x-4 gap-y-3 pb-2">
                <div class="flex min-w-0 grow flex-col gap-1">
                    <h1
                        class="text-[20px]/7 font-medium tracking-[-0.015em] text-fg-primary"
                    >
                        {{
                            $t('Hey :firstName 👋', {
                                firstName: String(firstName),
                            })
                        }}
                    </h1>
                    <p class="text-[13px]/5 text-fg-tertiary">
                        {{
                            $t('Here’s how :name is running right now.', {
                                name: workspace.name,
                            })
                        }}
                    </p>
                </div>
                <DashButton
                    size="sm"
                    :icon="copied ? Check : 'copy'"
                    class="max-w-64 min-w-0"
                    :title="$t('Copy URL')"
                    :aria-label="
                        $t('Copy :url', { url: String(workspace.url) })
                    "
                    @click="copyAddress"
                >
                    <span class="truncate">{{
                        workspaceAddress(workspace.url)
                    }}</span>
                </DashButton>
            </div>

            <HomeNotice
                v-if="panelUpdate"
                :href="updateHref"
                :title="$t('Update available')"
                :detail="
                    $t('ServerOS :version is available. You’re on :current.', {
                        version: panelUpdate.version,
                        current: panelUpdate.current,
                    })
                "
                :action="$t('Update')"
            />
            <SupportAsk
                v-if="supportAsk"
                :ask="supportAsk"
                :workspace-id="workspace.id"
            />
            <HomeNotice
                v-if="fixesWaiting.length"
                :href="fixHref"
                tone="amber"
                :title="
                    fixesWaiting.length === 1
                        ? $t('A fix needs your approval')
                        : $t(':count fixes need your approval', {
                              count: String(fixesWaiting.length),
                          })
                "
                :detail="fixesWaiting[0].title"
                :action="$t('Review')"
            />
            <HomeIssues
                v-if="issues.length"
                :issues="issues"
                :can-dismiss="canManage"
            />
            <HomeStats
                :stats="stats"
                :attention="attention"
                :last-backup="lastBackup"
            />
            <HomeMachines
                :machines="machines"
                :stats="stats"
                :member-count="memberCount"
            />
        </div>

        <aside
            class="grid min-w-0 content-start gap-[26px] border-edge-tabs px-5 py-7 max-xl:border-t-[0.5px] md:max-xl:grid-cols-2 xl:border-l-[0.5px]"
            :aria-label="$t('Shortcuts')"
        >
            <HomeFleet
                :workspace="workspace"
                :stats="stats"
                :providers="providers"
                :license-machines="licenseMachines"
            />
            <RailSection :title="$t('Shortcuts')">
                <RailRow
                    v-for="shortcut in shortcuts"
                    :key="shortcut.key"
                    :title="shortcut.title"
                    :description="shortcut.description"
                    :icon="shortcut.icon"
                    :href="shortcut.href"
                />
                <RailRow
                    :title="$t('Ask AI')"
                    :description="
                        $t('About anything in :name', { name: workspace.name })
                    "
                    icon="sparkle-fill"
                    kbd="⌘J"
                    aria-controls="assistant-panel"
                    :aria-expanded="assistant.open.value"
                    @click="assistant.toggle()"
                />
            </RailSection>
        </aside>
    </div>

    <MachineConnector v-if="canManage" ref="connector" />
    <NewProjectDialog v-if="canManage" v-model:open="creating" />
</template>
