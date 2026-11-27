<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, ref, useTemplateRef } from 'vue';
import BrandLogo from '@/components/dash/BrandLogo.vue';
import DashButton from '@/components/dash/DashButton.vue';
import DashHeader from '@/components/dash/DashHeader.vue';
import DashTray from '@/components/dash/DashTray.vue';
import EmptyArt from '@/components/dash/EmptyArt.vue';
import MachineConnector from '@/components/home/MachineConnector.vue';
import RailRow from '@/components/home/RailRow.vue';
import RailSection from '@/components/home/RailSection.vue';
import SetupStep from '@/components/home/SetupStep.vue';
import type {
    SetupStepResult,
    SetupStepState,
} from '@/components/home/SetupStep.vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import type { FilledIconName } from '@/components/icons/filledIcons';
import McpDialog from '@/components/workspace/McpDialog.vue';
import { useAssistant } from '@/composables/useAssistant';
import { toast } from '@/lib/toast';
import {
    onWorkspace,
    workspaceAddress,
    workspacePath,
} from '@/lib/workspaceRoute';
import machinesRoutes from '@/routes/workspace/machines';
import onboardingRoutes from '@/routes/workspace/onboarding';
import settingsRoutes from '@/routes/workspace/settings';
import type { SetupGuide, Workspace, WorkspaceAccess } from '@/types';

const props = defineProps<{
    workspace: Workspace;
    guide: SetupGuide;
    hidden: boolean;
    results: Record<string, SetupStepResult>;
    discordUrl: string | null;
}>();

const page = usePage<{
    workspaceAccess: WorkspaceAccess | null;
    docsUrl: string;
}>();
const assistant = useAssistant();

const canManage = computed(
    () =>
        page.props.workspaceAccess?.permissions.includes('manage-machines') ??
        false,
);

const connector =
    useTemplateRef<InstanceType<typeof MachineConnector>>('connector');
const mcpOpen = ref(false);

function connect(): void {
    connector.value?.open();
}

const steps = computed(() =>
    props.guide.groups.flatMap((group) =>
        group.steps.map((step) => ({ ...step, locked: group.locked })),
    ),
);
const doneCount = computed(
    () => steps.value.filter((step) => step.done).length,
);
const complete = computed(
    () => steps.value.length > 0 && doneCount.value === steps.value.length,
);
const nextKey = computed(
    () => steps.value.find((step) => !step.done && !step.locked)?.key ?? null,
);

const groupIcons: Record<string, FilledIconName> = {
    connect: 'machines-fill',
    safe: 'monitors-fill',
    team: 'user-fill',
    plan: 'file-fill',
};

const numbers = computed(
    () => new Map(steps.value.map((step, index) => [step.key, index + 1])),
);

function state(
    step: { key: string; done: boolean },
    locked: string | null,
): SetupStepState {
    if (step.done) return 'done';
    if (locked) return 'locked';

    return step.key === nextKey.value ? 'next' : 'todo';
}

const counted = computed(() => {
    const words: Record<number, string> = {
        5: trans('Five steps for a new workspace. Do them in any order.'),
        6: trans('Six steps for a new workspace. Do them in any order.'),
        7: trans('Seven steps for a new workspace. Do them in any order.'),
    };

    return (
        words[steps.value.length] ??
        trans(':count steps for a new workspace. Do them in any order.', {
            count: String(steps.value.length),
        })
    );
});

const labels: Record<string, { next: string; todo: string }> = {
    machine: { next: trans('Connect a machine'), todo: trans('Connect') },
    service: { next: trans('Deploy a service'), todo: trans('Deploy') },
    monitor: { next: trans('Add a monitor'), todo: trans('Add') },
    backups: { next: trans('Set up backups'), todo: trans('Set up') },
    members: { next: trans('Invite a teammate'), todo: trans('Invite') },
    security: { next: trans('Turn on two-factor'), todo: trans('Turn on') },
    plan: { next: trans('See plans'), todo: trans('See plans') },
};

function label(key: string, kind: 'next' | 'todo'): string {
    return labels[key]?.[kind] ?? trans('Open');
}

const trialMeta = computed(() => {
    const days = props.workspace.billing.trialDaysLeft;
    if (!props.workspace.billing.onTrial || days === null) return null;

    return days === 1
        ? trans('1 day left on your trial')
        : trans(':count days left on your trial', { count: String(days) });
});

const destinations = computed(() => [
    { key: 'r2', label: trans('Cloudflare R2'), logo: 'cloudflare' },
    { key: 'b2', label: trans('Backblaze B2'), logo: 'backblaze' },
    { key: 'machine', label: trans('Another machine'), logo: null },
]);

const destinationsHref = computed(() => workspacePath('/backups/destinations'));

const toggling = ref(false);

function toggleGuide(): void {
    toggling.value = true;
    const options = {
        preserveScroll: true,
        onSuccess: () =>
            toast.success(
                props.hidden
                    ? trans('The setup guide is hidden.')
                    : trans('The setup guide is back.'),
            ),
        onFinish: () => (toggling.value = false),
    };

    if (props.hidden) {
        router.delete(
            onWorkspace(onboardingRoutes.resume(props.workspace.slug)).url,
            options,
        );
    } else {
        router.post(
            onWorkspace(onboardingRoutes.dismiss(props.workspace.slug)).url,
            {},
            options,
        );
    }
}

function soon(what: string): void {
    toast.info(trans(':what is coming soon.', { what }));
}

const machinesHref = computed(
    () => onWorkspace(machinesRoutes.index(props.workspace.slug)).url,
);
</script>

<template>
    <Head :title="$t('Setup guide')" />

    <DashHeader :crumbs="[{ label: $t('Setup guide') }]">
        <template v-if="!complete" #actions>
            <DashButton
                :label="hidden ? $t('Show the guide') : $t('Hide the guide')"
                :loading="toggling"
                @click="toggleGuide"
            />
        </template>
    </DashHeader>

    <div class="grid min-h-full xl:grid-cols-[minmax(0,1fr)_316px]">
        <div class="flex min-w-0 flex-col gap-3 px-6 pt-7 pb-8">
            <div class="flex flex-col gap-4 pb-2">
                <div class="flex flex-col gap-1">
                    <h1
                        class="text-[20px]/7 font-medium tracking-[-0.015em] text-fg-primary"
                    >
                        {{ $t('Setup guide') }}
                    </h1>
                    <p class="text-[13px]/5 text-fg-tertiary">
                        {{
                            complete
                                ? $t('All done. Your workspace is set up.')
                                : hidden
                                  ? $t(
                                        'The sidebar no longer counts these steps. Your progress is still here.',
                                    )
                                  : counted
                        }}
                    </p>
                </div>
                <div class="flex items-center gap-3">
                    <div
                        class="flex min-w-0 grow items-center gap-1"
                        role="progressbar"
                        :aria-valuemin="0"
                        :aria-valuemax="steps.length"
                        :aria-valuenow="doneCount"
                        :aria-label="$t('Setup progress')"
                    >
                        <span
                            v-for="step in steps"
                            :key="step.key"
                            class="h-1 min-w-px grow rounded-[2px] transition-colors duration-300"
                            :class="
                                step.done ? 'bg-status-live' : 'bg-edge-field'
                            "
                        />
                    </div>
                    <span
                        class="shrink-0 text-[13px]/5 font-medium text-fg-secondary tabular-nums"
                    >
                        {{
                            $t(':done of :total done', {
                                done: String(doneCount),
                                total: String(steps.length),
                            })
                        }}
                    </span>
                </div>
            </div>

            <DashTray
                v-for="group in guide.groups"
                :key="group.key"
                :icon="groupIcons[group.key] ?? 'setup-fill'"
                :title="group.title"
                panel-class="pt-3"
            >
                <template #meta>
                    <span class="text-fg-tertiary tabular-nums">{{
                        $t(':done of :total', {
                            done: String(
                                group.steps.filter((step) => step.done).length,
                            ),
                            total: String(group.steps.length),
                        })
                    }}</span>
                </template>
                <template
                    v-if="group.steps.every((step) => step.done)"
                    #actions
                >
                    <span
                        class="shrink-0 text-[12px]/4 font-medium text-status-live"
                        >{{ $t('Done') }}</span
                    >
                </template>
                <SetupStep
                    v-for="step in group.steps"
                    :key="step.key"
                    :number="numbers.get(step.key) ?? 0"
                    :state="state(step, group.locked)"
                    :title="step.title"
                    :body="step.body"
                    :href="
                        step.key === 'machine' && !canManage
                            ? machinesHref
                            : step.href
                    "
                    :result="results[step.key] ?? null"
                    :locked="group.locked"
                    :meta="step.key === 'plan' ? trialMeta : null"
                    :next-label="label(step.key, 'next')"
                    :todo-label="label(step.key, 'todo')"
                    :dialog="step.key === 'machine' && canManage"
                    @act="connect"
                >
                    <template v-if="step.key === 'backups'" #extra>
                        <div class="flex flex-wrap items-center gap-1.5 pt-2.5">
                            <Link
                                v-for="destination in destinations"
                                :key="destination.key"
                                :href="destinationsHref"
                                class="flex items-center gap-1.5 rounded-[6px] border-[0.5px] border-edge-strong bg-surface-badge py-1 pr-2 pl-[7px] text-[12px]/4 font-medium text-fg-secondary transition-colors hover:text-fg-primary focus-visible:outline-2 focus-visible:outline-(--ds-ring)"
                            >
                                <BrandLogo
                                    v-if="destination.logo"
                                    :name="destination.logo"
                                    :size="12"
                                />
                                <FilledIcon
                                    v-else
                                    name="machines-fill"
                                    :size="12"
                                    class="text-fg-muted"
                                />
                                {{ destination.label }}
                            </Link>
                        </div>
                    </template>
                    <template
                        v-if="step.key === 'backups' || step.key === 'machine'"
                        #art
                    >
                        <EmptyArt kind="upload" class="h-[130px]! w-[300px]!" />
                    </template>
                    <template v-else-if="step.key === 'service'" #art>
                        <EmptyArt
                            kind="project-graph-blue"
                            class="h-[120px]! w-[300px]!"
                        />
                    </template>
                </SetupStep>
            </DashTray>
        </div>

        <aside
            class="grid min-w-0 content-start gap-[26px] border-edge-tabs px-5 py-7 max-xl:border-t-[0.5px] md:max-xl:grid-cols-2 xl:border-l-[0.5px]"
            :aria-label="$t('Help with setup')"
        >
            <RailSection :title="$t('Get connected')">
                <RailRow
                    :title="$t('Install daemon')"
                    :description="$t('One-line install')"
                    icon="terminal-fill"
                    :href="canManage ? undefined : machinesHref"
                    :dialog="canManage"
                    @click="canManage && connect()"
                />
                <RailRow
                    :title="$t('SSH')"
                    :description="$t('Connect with a key')"
                    icon="key-fill"
                    @click="soon($t('Connecting over SSH'))"
                />
                <RailRow
                    :title="$t('Import')"
                    :description="$t('Bring existing machines')"
                    icon="upload-fill"
                    :href="canManage ? undefined : machinesHref"
                    :dialog="canManage"
                    @click="canManage && connect()"
                />
                <RailRow
                    :title="$t('Cloud providers')"
                    :description="$t('Hetzner, DigitalOcean…')"
                    logo="hetzner"
                    @click="soon($t('Connecting a cloud provider account'))"
                />
                <RailRow
                    :title="$t('API keys')"
                    :description="$t('Automate ServerOS')"
                    icon="code-fill"
                    :href="onWorkspace(settingsRoutes.api(workspace.slug)).url"
                />
                <RailRow
                    :title="$t('MCP')"
                    :description="$t('Connect your agent')"
                    logo="claude"
                    dialog
                    @click="mcpOpen = true"
                />
            </RailSection>

            <RailSection :title="$t('Need a hand?')">
                <RailRow
                    :title="$t('Docs')"
                    :description="workspaceAddress(page.props.docsUrl)"
                    icon="help-fill"
                    :href="page.props.docsUrl"
                    external
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
                <RailRow
                    v-if="discordUrl"
                    :title="$t('Discord')"
                    :description="workspaceAddress(discordUrl)"
                    logo="discord"
                    :href="discordUrl"
                    external
                />
            </RailSection>
        </aside>
    </div>

    <MachineConnector v-if="canManage" ref="connector" />
    <McpDialog v-model:open="mcpOpen" />
</template>
