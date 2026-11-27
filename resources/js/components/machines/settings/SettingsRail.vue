<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, ref, watch } from 'vue';
import DashButton from '@/components/dash/DashButton.vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import ClearDataDialog from '@/components/machines/ClearDataDialog.vue';
import type { DataKind } from '@/components/machines/ClearDataDialog.vue';
import type { Machine } from '@/components/machines/machines';
import FieldSelect from '@/components/machines/settings/FieldSelect.vue';
import type { FieldOption } from '@/components/machines/settings/FieldSelect.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { fieldLabel } from '@/lib/machinePolicies';
import type {
    MachinePolicy,
    PolicyChoice,
    PolicyRule,
} from '@/lib/machinePolicies';
import { onWorkspace } from '@/lib/workspaceRoute';
import machinesRoutes from '@/routes/workspace/machines';
import settingsRoutes from '@/routes/workspace/settings';

export type PolicyProps = {
    current: MachinePolicy;
    from: 'machine' | 'group' | 'rule' | 'default';
    group: { id: number; name: string } | null;
    rule: PolicyRule | null;
    choice: number | null;
    options: PolicyChoice[];
    pending: boolean;
    supported: boolean;
    since: string;
    daemon: {
        version: string | null;
        available: string | null;
        notesUrl: string | null;
        compareUrl: string | null;
        pinned: string | null;
        online: boolean;
    };
};

export type CollectedData = {
    logs: { lines: number | null; since: string | null; unavailable: boolean };
    metrics: { readings: number; since: string | null };
    services: { discovered: number; kept: number; scannedAt: string | null };
    status: {
        state: 'queued' | 'running' | 'done' | 'failed';
        kinds: DataKind[];
        counts: Partial<Record<DataKind, number>>;
        at: string;
    } | null;
};

const props = defineProps<{
    machine: Machine;
    workspaceSlug: string;
    workspaceName: string;
    policy: PolicyProps;
    statusPage: { live: boolean; public: boolean };
    planAllows: boolean;
    collectedData?: CollectedData;
    canManage: boolean;
}>();

const emit = defineEmits<{ status: []; cleared: [] }>();

const args = computed(() => ({
    workspace: props.workspaceSlug,
    machine: props.machine.id!,
}));

const connection = computed<{ dot: string; label: string }>(() => {
    const seen = props.machine.lastSeen ?? null;
    switch (props.machine.connection) {
        case 'online':
            return { dot: 'bg-status-live', label: seen ?? trans('Now') };
        case 'degraded':
            return {
                dot: 'bg-status-pending',
                label: seen ?? trans('Delayed'),
            };
        case 'connecting':
            return {
                dot: 'bg-status-deploying',
                label: trans('Not connected yet'),
            };
        case 'demo':
            return { dot: 'bg-fg-faint', label: trans('No daemon') };
        default:
            return {
                dot: 'bg-status-down',
                label: seen ?? trans('Offline'),
            };
    }
});

const policyBusy = ref(false);
const policyError = ref<string | null>(null);

const defaultName = computed(
    () => props.policy.options.find((option) => option.isDefault)?.name ?? '',
);

const policyOptions = computed<FieldOption<number | null>[]>(() => [
    {
        value: null,
        label:
            props.machine.groupId && props.policy.group
                ? trans('Follow its group, :group', {
                      group: props.policy.group.name,
                  })
                : trans('Follow the default, :name', {
                      name: defaultName.value,
                  }),
    },
    ...props.policy.options.map((option) => ({
        value: option.id,
        label: option.name,
        separated: false,
    })),
]);

const policyFollows = computed(() => {
    const name = props.policy.current.name;
    if (props.policy.from === 'machine') {
        return trans('Follows :name, picked for this machine.', { name });
    }
    if (props.policy.from === 'rule' && props.policy.rule) {
        return trans('Follows :name, because its :field is :value.', {
            name,
            field: fieldLabel(props.policy.rule.field).toLowerCase(),
            value: props.policy.rule.value,
        });
    }
    if (props.policy.from === 'group') {
        return trans('Follows :name, its group :group’s policy.', {
            name,
            group: props.policy.group?.name ?? '',
        });
    }

    return trans('Follows :name, the default policy.', { name });
});

function choosePolicy(value: number | null): void {
    router.put(
        onWorkspace(machinesRoutes.policy.update(args.value)).url,
        { policy: value },
        {
            preserveScroll: true,
            preserveState: true,
            onStart: () => {
                policyBusy.value = true;
                policyError.value = null;
            },
            onFinish: () => (policyBusy.value = false),
            onError: (bag) => (policyError.value = bag.policy ?? null),
        },
    );
}

const policiesUrl = computed(
    () => onWorkspace(settingsRoutes.machinePolicies(props.workspaceSlug)).url,
);

const updateBusy = ref(false);
const updateError = ref<string | null>(null);

function installUpdate(): void {
    router.post(
        onWorkspace(machinesRoutes.daemonUpdate(args.value)).url,
        {},
        {
            preserveScroll: true,
            preserveState: true,
            onStart: () => {
                updateBusy.value = true;
                updateError.value = null;
            },
            onFinish: () => (updateBusy.value = false),
            onError: (bag) => (updateError.value = bag.update ?? null),
        },
    );
}

const updateTitle = computed(() => {
    const version = (props.policy.daemon.available ?? '').replace(/^v/, '');

    return props.policy.daemon.pinned
        ? trans('Its policy keeps it on :version', { version })
        : trans(':version is out', { version });
});

const releaseUrl = computed(
    () => props.policy.daemon.notesUrl ?? props.policy.daemon.compareUrl,
);

const updateMode = computed(() =>
    props.policy.current.updates === 'pinned'
        ? trans('Stays on :version, even when a newer one is out.', {
              version: props.policy.daemon.pinned ?? '',
          })
        : props.policy.current.updates === 'approve'
          ? trans('Updates wait for you to install them.')
          : null,
);

function compact(n: number): string {
    if (n >= 1_000_000)
        return `${(n / 1_000_000).toFixed(1).replace(/\.0$/, '')}M`;
    if (n >= 10_000) return `${Math.round(n / 1_000)}K`;

    return n.toLocaleString();
}

const collected = computed<{ title: string; detail: string } | null>(() => {
    const data = props.collectedData;
    if (!data) return null;
    const lines = data.logs.lines ?? 0;
    const readings = data.metrics.readings;
    const found = data.services.discovered;
    const title = data.logs.unavailable
        ? trans('Logs aren’t answering')
        : lines
          ? trans(':count log lines', { count: compact(lines) })
          : trans('No log lines');
    const detail = [
        readings
            ? trans(':count readings', { count: compact(readings) })
            : trans('No usage history'),
        found === 1
            ? trans('1 found service')
            : trans(':count found services', { count: String(found) }),
    ].join(' · ');

    return { title, detail };
});

const dataKinds: DataKind[] = ['logs', 'metrics', 'services'];

const dataSizes = computed<Partial<Record<DataKind, string>> | undefined>(
    () => {
        const data = props.collectedData;
        if (!data) return undefined;

        return {
            logs: data.logs.unavailable
                ? trans('Not answering right now')
                : trans(':count lines', {
                      count: compact(data.logs.lines ?? 0),
                  }),
            metrics: trans(':count readings', {
                count: compact(data.metrics.readings),
            }),
            services: String(data.services.discovered),
        };
    },
);

const empty = computed(() => {
    const data = props.collectedData;
    if (!data) return true;

    return (
        !data.logs.lines &&
        !data.logs.unavailable &&
        !data.metrics.readings &&
        !data.services.discovered
    );
});

const clearing = computed(() =>
    ['queued', 'running'].includes(props.collectedData?.status?.state ?? ''),
);

const clearStatus = computed<string | null>(() => {
    const status = props.collectedData?.status;
    if (!status) return null;
    if (status.state === 'queued' || status.state === 'running') {
        return trans('Clearing in the background. You can leave this page.');
    }
    if (status.state === 'failed') {
        return trans('The last clear didn’t finish. Try again.');
    }

    return null;
});

const clearOpen = ref(false);

const removing = ref(false);
const confirmation = ref('');
const deleting = ref(false);

watch(removing, (open) => {
    if (!open) confirmation.value = '';
});

function removeMachine(): void {
    if (confirmation.value !== props.machine.name) return;
    router.delete(onWorkspace(machinesRoutes.destroy(args.value)).url, {
        onStart: () => (deleting.value = true),
        onFinish: () => (deleting.value = false),
    });
}

const sectionTitle =
    'text-[13px]/5 font-medium tracking-[-0.005em] text-fg-primary';
const rowLabel =
    'min-w-0 flex-1 text-[13px]/5 tracking-[-0.005em] text-fg-muted';
</script>

<template>
    <aside
        class="flex w-full shrink-0 flex-col gap-[22px] border-edge-tabs p-5 max-xl:border-t-[0.5px] xl:w-[316px] xl:border-l-[0.5px]"
    >
        <section class="flex flex-col gap-2.5" :aria-label="$t('Daemon')">
            <h2 :class="sectionTitle">{{ $t('Daemon') }}</h2>
            <div class="flex items-center gap-2">
                <span :class="rowLabel">{{ $t('Version') }}</span>
                <span
                    class="font-ui-mono text-[12px]/5 whitespace-nowrap text-fg-secondary"
                    >{{
                        machine.daemonVersion
                            ? `serverosd ${machine.daemonVersion}`
                            : '–'
                    }}</span
                >
            </div>
            <div class="flex items-center gap-2">
                <span :class="rowLabel">{{ $t('Connection') }}</span>
                <span
                    class="size-1.5 shrink-0 rounded-full"
                    :class="connection.dot"
                    aria-hidden="true"
                />
                <span
                    class="text-[13px]/5 tracking-[-0.005em] whitespace-nowrap text-fg-secondary"
                    >{{ connection.label }}</span
                >
            </div>
            <div class="flex items-center gap-2" :title="policyFollows">
                <span :class="rowLabel">{{ $t('Policy') }}</span>
                <FieldSelect
                    v-if="canManage && machine.id"
                    tone="plain"
                    :model-value="policy.choice"
                    :options="policyOptions"
                    :display="policy.current.name"
                    :label="$t('Policy')"
                    :disabled="policyBusy"
                    @update:model-value="choosePolicy"
                />
                <span
                    v-else
                    class="text-[13px]/5 tracking-[-0.005em] whitespace-nowrap text-fg-secondary"
                    >{{ policy.current.name }}</span
                >
            </div>
            <p v-if="policy.pending" class="text-[12px]/4 text-status-pending">
                {{
                    $t(
                        'It hasn’t reached the machine yet: it’s sent when the machine is next online.',
                    )
                }}
            </p>
            <p
                v-if="!policy.supported && policy.daemon.version"
                class="text-[12px]/4 text-fg-muted"
            >
                {{
                    $t(
                        'Its daemon is older than :version, so it can’t turn discovery or logs off at the source, or wait for update approval, yet. It updates itself. Logs the policy turns off are still dropped as they arrive.',
                        { version: policy.since },
                    )
                }}
            </p>
            <p v-if="updateMode" class="text-[12px]/4 text-fg-muted">
                {{ updateMode }}
            </p>
            <p
                v-if="policyError"
                class="text-[12px]/4 text-status-down"
                role="alert"
            >
                {{ policyError }}
            </p>

            <div
                v-if="policy.daemon.available && policy.daemon.version"
                class="flex flex-col gap-1.5 rounded-[8px] border-[0.5px] border-edge-control bg-surface-hover py-2.5 pr-2 pl-3"
            >
                <div class="flex items-center gap-2.5">
                    <div class="flex min-w-0 flex-1 flex-col">
                        <span
                            class="truncate text-[13px]/5 tracking-[-0.005em] text-fg-primary"
                            >{{ updateTitle }}</span
                        >
                        <a
                            v-if="releaseUrl"
                            :href="releaseUrl"
                            target="_blank"
                            rel="noopener"
                            class="w-fit text-[12px]/4 tracking-[-0.005em] text-fg-muted underline underline-offset-2 hover:text-fg-secondary"
                            >{{ $t('What’s in it') }}</a
                        >
                    </div>
                    <DashButton
                        v-if="canManage"
                        size="sm"
                        :label="$t('Install')"
                        :loading="updateBusy"
                        :disabled="!policy.daemon.online"
                        :title="
                            policy.daemon.online
                                ? undefined
                                : $t('Once it’s online')
                        "
                        @click="installUpdate"
                    />
                </div>
                <p
                    v-if="updateError"
                    class="text-[12px]/4 text-status-down"
                    role="alert"
                >
                    {{ updateError }}
                </p>
            </div>

            <Link
                :href="policiesUrl"
                class="w-fit text-[13px]/5 tracking-[-0.005em] text-fg-secondary underline underline-offset-2 hover:text-fg-primary"
            >
                {{ $t('Edit policies') }}
            </Link>
        </section>

        <section class="flex flex-col gap-2.5" :aria-label="$t('Status page')">
            <h2 :class="sectionTitle">{{ $t('Status page') }}</h2>
            <button
                type="button"
                class="flex w-full cursor-pointer items-center gap-2.5 rounded-[8px] bg-surface-hover py-2 pr-2 pl-2.5 text-left hover:bg-surface-raised focus-visible:outline-2 focus-visible:outline-(--ds-ring)"
                @click="emit('status')"
            >
                <FilledIcon
                    name="monitors-fill"
                    :size="15"
                    class="text-fg-muted"
                />
                <span class="flex min-w-0 flex-1 flex-col">
                    <span
                        class="truncate text-[13px]/5 tracking-[-0.005em] text-fg-primary"
                        >{{
                            !statusPage.live
                                ? $t('Off')
                                : statusPage.public
                                  ? $t('Live')
                                  : $t('Live for members')
                        }}</span
                    >
                    <span
                        class="truncate text-[12px]/4 tracking-[-0.005em] text-fg-muted"
                        >{{
                            planAllows
                                ? $t('A public page for :name’s uptime', {
                                      name: machine.name,
                                  })
                                : $t('Part of Pro and Scale')
                        }}</span
                    >
                </span>
                <FilledIcon
                    name="chevron-right"
                    :size="14"
                    class="text-fg-muted"
                />
            </button>
        </section>

        <section
            v-if="canManage && machine.id"
            class="flex flex-col gap-2.5"
            :aria-label="$t('Collected data')"
        >
            <h2 :class="sectionTitle">{{ $t('Collected data') }}</h2>
            <div class="flex items-center gap-2.5">
                <div class="flex min-w-0 flex-1 flex-col">
                    <span
                        class="truncate text-[13px]/5 tracking-[-0.005em] text-fg-primary"
                        >{{ collected?.title ?? $t('Counting…') }}</span
                    >
                    <span
                        class="text-[12px]/4 tracking-[-0.005em] text-fg-muted"
                        >{{
                            collected?.detail ?? $t('Metrics, logs and history')
                        }}</span
                    >
                </div>
                <DashButton
                    size="sm"
                    :label="$t('Clear')"
                    :disabled="!collectedData || clearing || empty"
                    @click="clearOpen = true"
                />
            </div>
            <p
                v-if="clearStatus"
                class="text-[12px]/4 text-fg-muted"
                role="status"
            >
                {{ clearStatus }}
            </p>
        </section>

        <section
            v-if="canManage && machine.id"
            class="flex flex-col gap-2.5"
            :aria-label="$t('Remove machine')"
        >
            <h2 :class="sectionTitle">{{ $t('Remove machine') }}</h2>
            <div class="flex items-center gap-2.5">
                <p
                    class="min-w-0 flex-1 text-[12px]/4 tracking-[-0.005em] text-fg-muted"
                >
                    {{
                        $t(
                            'Takes it out of :workspace. The server keeps running.',
                            {
                                workspace: workspaceName,
                            },
                        )
                    }}
                </p>
                <DashButton
                    size="sm"
                    class="text-status-down!"
                    :label="$t('Remove')"
                    @click="removing = true"
                />
            </div>
        </section>
    </aside>

    <ClearDataDialog
        v-if="machine.id"
        v-model:open="clearOpen"
        :machines="[{ id: machine.id, name: machine.name }]"
        :kinds="dataKinds"
        :sizes="dataSizes"
        @done="emit('cleared')"
    />

    <Dialog v-model:open="removing">
        <DialogContent
            class="workspace-panel ds-modal font-ui text-foreground sm:max-w-md"
        >
            <DialogHeader>
                <DialogTitle>{{
                    $t('Remove :name?', { name: machine.name })
                }}</DialogTitle>
                <DialogDescription>
                    {{
                        $t(
                            'It leaves :workspace with its deploy history, groups and status page. The server keeps running, and nothing on it is touched.',
                            { workspace: workspaceName },
                        )
                    }}
                </DialogDescription>
            </DialogHeader>
            <form class="flex flex-col gap-4" @submit.prevent="removeMachine">
                <div class="grid gap-2">
                    <label for="remove-machine" class="text-[13px]/5">
                        {{ ' ' + $t('Type') }}
                        <span class="font-ui-mono text-fg-primary">{{
                            machine.name
                        }}</span>
                        {{ $t('to confirm.') + ' ' }}
                    </label>
                    <div class="dash-field font-ui-mono">
                        <input
                            id="remove-machine"
                            v-model="confirmation"
                            autocomplete="off"
                            spellcheck="false"
                        />
                    </div>
                </div>
                <div class="flex justify-end gap-2">
                    <DashButton
                        :label="$t('Cancel')"
                        @click="removing = false"
                    />
                    <DashButton
                        type="submit"
                        variant="danger"
                        :label="$t('Remove machine')"
                        :loading="deleting"
                        :disabled="confirmation !== machine.name"
                    />
                </div>
            </form>
        </DialogContent>
    </Dialog>
</template>
