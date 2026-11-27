<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { Plug, Plus } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed, onBeforeUnmount, onMounted, ref, useTemplateRef } from 'vue';
import DashButton from '@/components/dash/DashButton.vue';
import DashHeader from '@/components/dash/DashHeader.vue';
import HomeAttention from '@/components/home/HomeAttention.vue';
import HomeBackups from '@/components/home/HomeBackups.vue';
import HomeBusiest from '@/components/home/HomeBusiest.vue';
import HomeEmpty from '@/components/home/HomeEmpty.vue';
import HomeIssues from '@/components/home/HomeIssues.vue';
import HomeNotice from '@/components/home/HomeNotice.vue';
import HomeOverview from '@/components/home/HomeOverview.vue';
import HomeSecurity from '@/components/home/HomeSecurity.vue';
import HomeStartCard from '@/components/home/HomeStartCard.vue';
import IntegrationsPromo from '@/components/home/IntegrationsPromo.vue';
import type {
    HomeBackups as Backups,
    HomeBusy,
    HomeIssue,
    HomeOverview as Overview,
    HomeSecurity as Security,
} from '@/components/home/home';
import MachineConnector from '@/components/home/MachineConnector.vue';
import NewProjectDialog from '@/components/home/NewProjectDialog.vue';
import SupportAsk from '@/components/workspace/SupportAsk.vue';
import { useWhenVisible } from '@/composables/useWhenVisible';
import { onWorkspace } from '@/lib/workspaceRoute';
import settingsRoutes from '@/routes/workspace/settings';
import type { Workspace, WorkspaceAccess } from '@/types';

type Props = {
    workspace: Workspace;
    overview: Overview;
    issues: HomeIssue[];
    busiest: HomeBusy[];
    security: Security;
    backups: Backups;
    panelUpdate: { version: string; current: string } | null;
    supportAsk: { donateUrl: string; licenseUrl: string } | null;
    integrationsPromo: boolean;
    licenseMachines?: {
        allowed: number;
        connected: number;
        over: number;
    } | null;
};

const props = defineProps<Props>();
const page = usePage<{ workspaceAccess: WorkspaceAccess | null }>();

const canManage = computed(
    () =>
        page.props.workspaceAccess?.permissions.includes('manage-machines') ??
        false,
);
const hasMachines = computed(() => props.overview.machines > 0);

const firstName = computed(
    () => page.props.auth.user.name.trim().split(/\s+/)[0] ?? '',
);

const hour = ref(new Date().getHours());

const greeting = computed(() => {
    const name = String(firstName.value);
    if (hour.value >= 5 && hour.value < 12) {
        return trans('Good morning, :name', { name });
    }
    if (hour.value >= 12 && hour.value < 18) {
        return trans('Good afternoon, :name', { name });
    }

    return trans('Good evening, :name', { name });
});

const standing = computed(() => {
    const count = props.issues.length;
    const name = props.workspace.name;
    if (count === 0) {
        return trans('Here’s how :name is doing. Nothing needs a look.', {
            name,
        });
    }

    return count === 1
        ? trans('Here’s how :name is doing. 1 thing needs a look.', { name })
        : trans('Here’s how :name is doing. :count things need a look.', {
              name,
              count: String(count),
          });
});

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

const hasNotices = computed(
    () =>
        props.panelUpdate !== null ||
        props.supportAsk !== null ||
        props.issues.length > 0,
);

const POLL_MS = 30_000;
const whenVisible = useWhenVisible();
let pollTimer: ReturnType<typeof setTimeout> | undefined;
let polling = true;

function poll(): void {
    clearTimeout(pollTimer);
    if (!polling) return;
    pollTimer = setTimeout(
        () =>
            whenVisible(() => {
                hour.value = new Date().getHours();
                if (!hasMachines.value) {
                    poll();

                    return;
                }
                router.reload({
                    only: [
                        'overview',
                        'issues',
                        'busiest',
                        'security',
                        'backups',
                    ],
                    onFinish: poll,
                });
            }),
        POLL_MS,
    );
}

onMounted(poll);
onBeforeUnmount(() => {
    polling = false;
    clearTimeout(pollTimer);
});
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

    <div
        v-else
        class="@container flex min-w-0 flex-col gap-6 px-4 pt-7 pb-8 sm:px-8"
    >
        <div class="flex min-w-0 flex-col gap-1">
            <h1
                class="truncate text-[20px]/7 font-medium tracking-[-0.015em] text-fg-primary"
            >
                {{ greeting }}
            </h1>
            <p class="text-[13px]/5 tracking-[-0.005em] text-fg-tertiary">
                {{ standing }}
            </p>
        </div>

        <div class="flex min-w-0 flex-col gap-3">
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
            <HomeOverview
                :overview="overview"
                :workspace="workspace"
                :license-machines="licenseMachines"
            />
            <div class="flex min-w-0 flex-col gap-3 @4xl:flex-row">
                <HomeAttention
                    :issues="issues"
                    :can-dismiss="canManage"
                    class="@4xl:flex-1"
                />
                <HomeBusiest
                    :machines="busiest"
                    class="@4xl:w-[430px] @4xl:shrink-0"
                />
            </div>
            <div class="grid min-w-0 gap-3 @3xl:grid-cols-2">
                <HomeSecurity :security="security" />
                <HomeBackups :backups="backups" />
            </div>
        </div>
    </div>

    <IntegrationsPromo v-if="integrationsPromo && hasMachines" />
    <HomeStartCard v-else-if="!hasMachines && canManage" @connect="connect" />
    <MachineConnector v-if="canManage" ref="connector" />
    <NewProjectDialog v-if="canManage" v-model:open="creating" />
</template>
