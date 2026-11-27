<script setup lang="ts">
import { router, useForm, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, ref, watch } from 'vue';
import DashButton from '@/components/dash/DashButton.vue';
import DashTray from '@/components/dash/DashTray.vue';
import FilterPills from '@/components/dash/FilterPills.vue';
import StatusBadge from '@/components/dash/StatusBadge.vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import CopyBlock from '@/components/integrations/CopyBlock.vue';
import type { GrafanaAccount } from '@/components/integrations/grafana';
import { jsonRequest } from '@/components/integrations/request';
import { SelectMenu } from '@/components/ui/select';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { errorMessage } from '@/lib/errors';
import { timeAgo } from '@/lib/timeAgo';
import { toast } from '@/lib/toast';
import { onWorkspace } from '@/lib/workspaceRoute';
import grafanaRoutes from '@/routes/workspace/integrations/grafana';
import pushRoutes from '@/routes/workspace/integrations/grafana/push';
import tokenRoutes from '@/routes/workspace/integrations/grafana/tokens';
import type { Workspace } from '@/types';

const {
    account,
    integrationId,
    disabled = false,
} = defineProps<{
    account: GrafanaAccount | undefined;
    integrationId: number;
    disabled?: boolean;
}>();
const page = usePage<{ workspace: Workspace }>();
const args = computed(() => ({
    workspace: page.props.workspace.slug,
    integration: integrationId,
}));
const reload = ['account', 'usage', 'dependents', 'flash'];

type Mode = 'push' | 'scrape';
const mode = ref<Mode>('push');
watch(
    () => account,
    (value) => {
        if (value && !value.push && !value.cloud) mode.value = 'scrape';
    },
    { immediate: true },
);
const modes = computed(() => [
    { value: 'push' as const, label: trans('Grafana Cloud') },
    { value: 'scrape' as const, label: trans('Your own Prometheus') },
]);

const pushForm = useForm({ endpoint: '', instance: '', token: '' });
const editingPush = ref(false);
watch(
    () => account?.push,
    (push) => {
        pushForm.endpoint = push?.endpoint ?? '';
        pushForm.instance = push?.instance ?? '';
        pushForm.token = '';
    },
    { immediate: true },
);
const showPushForm = computed(() => !account?.push || editingPush.value);

function savePush(): void {
    pushForm.put(onWorkspace(grafanaRoutes.push(args.value)).url, {
        preserveScroll: true,
        only: reload,
        onSuccess: () => {
            editingPush.value = false;
            pushForm.token = '';
        },
    });
}

const stopping = ref(false);
function stopPush(): void {
    stopping.value = true;
    router.delete(onWorkspace(pushRoutes.destroy(args.value)).url, {
        preserveScroll: true,
        only: reload,
        onFinish: () => (stopping.value = false),
    });
}

const pushLine = computed(() => {
    const push = account?.push;
    if (!push) return null;
    if (push.failing || push.error) return push.error;
    if (push.lastAt)
        return trans(':count metrics every minute, last sent :when', {
            count: String(push.lastCount ?? 0),
            when: timeAgo(push.lastAt),
        });

    return trans('Sending every minute');
});

function host(url: string): string {
    try {
        return new URL(url).host;
    } catch {
        return url;
    }
}

const naming = ref(false);
const tokenName = ref('Prometheus');
const tokenError = ref<string | null>(null);
const making = ref(false);
const secret = ref<string | null>(null);

watch(naming, (open) => {
    if (!open) return;
    tokenError.value = null;
    secret.value = null;
    tokenName.value = 'Prometheus';
});

async function makeToken(): Promise<void> {
    if (making.value) return;
    making.value = true;
    tokenError.value = null;
    try {
        const made = await jsonRequest<{ secret: string }>(
            onWorkspace(tokenRoutes.store(args.value)).url,
            'POST',
            { name: tokenName.value },
        );
        secret.value = made.secret;
        router.reload({ only: ['account', 'usage', 'dependents'] });
    } catch (cause) {
        tokenError.value = errorMessage(cause);
    } finally {
        making.value = false;
    }
}

const scrapeConfig = computed(() =>
    account
        ? secret.value
            ? account.scrape.config.replace(/som_…/, secret.value)
            : account.scrape.config
        : '',
);

function revoke(id: number): void {
    router.delete(
        onWorkspace(tokenRoutes.destroy({ ...args.value, token: id })).url,
        { preserveScroll: true, only: reload },
    );
}

const dashboardForm = useForm({ datasource: '', name: '' });
watch(
    () => account,
    (value) => {
        if (!value) return;
        const current =
            value.dashboard?.datasource?.uid ??
            value.dataSources[0]?.uid ??
            value.suggestedUid ??
            '';
        dashboardForm.datasource = current;
    },
    { immediate: true },
);
const sourceOptions = computed(() =>
    (account?.dataSources ?? []).map((source) => ({
        value: source.uid,
        label: source.name,
        hint: source.isDefault ? trans('Default') : undefined,
    })),
);

function saveDashboard(): void {
    const source = account?.dataSources.find(
        (s) => s.uid === dashboardForm.datasource,
    );
    dashboardForm.name = source?.name ?? dashboardForm.datasource;
    dashboardForm.post(onWorkspace(grafanaRoutes.dashboard(args.value)).url, {
        preserveScroll: true,
        only: reload,
    });
}

const fieldClass =
    'h-8 w-full rounded-[7px] border-[0.5px] border-edge-field bg-surface-field px-2.5 text-[13px]/5 text-fg-primary shadow-field outline-none placeholder:text-fg-faint focus:border-[rgba(51,97,255,0.7)]';
const labelClass = 'text-[12.5px]/4 text-fg-tertiary';
</script>

<template>
    <DashTray
        icon="activity-fill"
        :title="$t('Metrics')"
        :meta="
            account
                ? $t(':count series for each machine, service and monitor', {
                      count: String(account.series.length),
                  })
                : null
        "
    >
        <template #actions>
            <FilterPills
                v-if="account"
                v-model="mode"
                :options="modes"
                :label="$t('How metrics get to Grafana')"
            />
        </template>

        <div
            v-if="account === undefined"
            class="flex flex-col gap-2 px-3.5 py-3"
            role="status"
            :aria-label="$t('Loading metrics')"
        >
            <span
                class="h-3 w-64 animate-pulse rounded bg-surface-badge motion-reduce:animate-none"
            />
            <span
                class="h-3 w-44 animate-pulse rounded bg-surface-badge motion-reduce:animate-none"
            />
        </div>

        <div
            v-else-if="mode === 'push'"
            class="flex flex-col gap-3.5 px-3.5 py-3"
        >
            <p class="text-[13px]/5 text-fg-tertiary">
                {{
                    $t(
                        'ServerOS sends the metrics to your Grafana Cloud stack every minute, over OTLP. In Grafana Cloud, open your stack, then OpenTelemetry › Configure: copy the OTLP endpoint and instance ID, and make a token there (it gets metrics:write).',
                    )
                }}
            </p>

            <div
                v-if="account.push && !editingPush"
                class="flex items-center gap-3 rounded-[8px] border-[0.5px] border-edge-tray-panel px-3 py-2.5"
            >
                <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                    <span
                        class="truncate text-[13px]/5 font-medium text-fg-primary"
                        >{{ host(account.push.endpoint) }}</span
                    >
                    <span
                        class="text-[12px]/4"
                        :class="
                            account.push.error
                                ? 'text-status-down'
                                : 'text-fg-muted'
                        "
                        >{{ pushLine }}</span
                    >
                </div>
                <StatusBadge
                    :status="
                        account.push.failing
                            ? 'down'
                            : account.push.error
                              ? 'pending'
                              : 'live'
                    "
                    :label="
                        account.push.failing
                            ? $t('Stopped')
                            : account.push.error
                              ? $t('Retrying')
                              : $t('Sending')
                    "
                />
                <DashButton
                    size="sm"
                    :label="$t('Change')"
                    :disabled="disabled"
                    @click="editingPush = true"
                />
                <DashButton
                    size="sm"
                    :label="$t('Stop')"
                    :loading="stopping"
                    @click="stopPush"
                />
            </div>

            <form
                v-if="showPushForm"
                class="flex flex-col gap-3"
                @submit.prevent="savePush"
            >
                <label class="flex flex-col gap-1.5">
                    <span :class="labelClass">{{ $t('OTLP endpoint') }}</span>
                    <input
                        v-model="pushForm.endpoint"
                        :class="[fieldClass, 'font-ui-mono text-[12px]!']"
                        autocomplete="off"
                        spellcheck="false"
                        placeholder="https://otlp-gateway-prod-eu-west-2.grafana.net/otlp"
                    />
                    <span
                        v-if="pushForm.errors.endpoint"
                        class="text-[12px]/4 text-status-down"
                        >{{ pushForm.errors.endpoint }}</span
                    >
                </label>
                <div class="grid grid-cols-[minmax(0,1fr)_minmax(0,2fr)] gap-3">
                    <label class="flex flex-col gap-1.5">
                        <span :class="labelClass">{{ $t('Instance ID') }}</span>
                        <input
                            v-model="pushForm.instance"
                            :class="[fieldClass, 'font-ui-mono text-[12px]!']"
                            autocomplete="off"
                            spellcheck="false"
                            placeholder="1234567"
                        />
                        <span
                            v-if="pushForm.errors.instance"
                            class="text-[12px]/4 text-status-down"
                            >{{ pushForm.errors.instance }}</span
                        >
                    </label>
                    <label class="flex flex-col gap-1.5">
                        <span :class="labelClass">{{ $t('Token') }}</span>
                        <input
                            v-model="pushForm.token"
                            type="password"
                            :class="fieldClass"
                            autocomplete="off"
                            spellcheck="false"
                            :placeholder="
                                account.push
                                    ? $t('Leave empty to keep the token')
                                    : $t('Paste the token')
                            "
                        />
                        <span
                            v-if="pushForm.errors.token"
                            class="text-[12px]/4 text-status-down"
                            >{{ pushForm.errors.token }}</span
                        >
                    </label>
                </div>
                <div class="flex justify-end gap-2">
                    <DashButton
                        v-if="account.push"
                        size="sm"
                        :label="$t('Cancel')"
                        @click="editingPush = false"
                    />
                    <DashButton
                        type="submit"
                        size="sm"
                        :label="$t('Send metrics')"
                        :loading="pushForm.processing"
                        :disabled="disabled"
                    />
                </div>
            </form>
        </div>

        <div v-else class="flex flex-col gap-3.5 px-3.5 py-3">
            <p class="text-[13px]/5 text-fg-tertiary">
                {{
                    $t(
                        'Your Prometheus scrapes ServerOS with a metrics token, which can read metrics and nothing else, up to :count times a minute. Add this to its scrape configs, then pick it as the dashboard’s data source.',
                        { count: String(account.scrape.perMinute) },
                    )
                }}
            </p>
            <CopyBlock
                :text="scrapeConfig"
                :label="$t('Copy the scrape config')"
            />

            <div class="flex flex-col">
                <div class="flex items-center gap-2 pb-1.5">
                    <span
                        class="flex-1 text-[12px]/4 font-medium text-fg-secondary"
                        >{{ $t('Metrics tokens') }}</span
                    >
                    <DashButton
                        size="sm"
                        icon="key-fill"
                        :label="$t('Make a metrics token')"
                        :disabled="disabled"
                        @click="naming = true"
                    />
                </div>
                <ul
                    v-if="account.scrape.tokens.length"
                    class="flex flex-col rounded-[8px] border-[0.5px] border-edge-tray-panel"
                >
                    <li
                        v-for="token in account.scrape.tokens"
                        :key="token.id"
                        class="flex items-center gap-3 border-t-[0.5px] border-edge-tray-panel px-3 py-2 first:border-t-0"
                    >
                        <span class="flex min-w-0 flex-1 flex-col">
                            <span
                                class="truncate text-[13px]/5 font-medium text-fg-primary"
                                >{{ token.name }}</span
                            >
                            <span class="text-[12px]/4 text-fg-muted"
                                ><span class="font-ui-mono"
                                    >{{ token.prefix }}…</span
                                >
                                ·
                                {{
                                    token.lastUsedAt
                                        ? $t('last scraped :when', {
                                              when: timeAgo(token.lastUsedAt),
                                          })
                                        : $t('not used yet')
                                }}</span
                            >
                        </span>
                        <DashButton
                            size="sm"
                            :label="$t('Revoke')"
                            @click="revoke(token.id)"
                        />
                    </li>
                </ul>
                <p v-else class="text-[12px]/4 text-fg-muted">
                    {{ $t('No metrics token yet.') }}
                </p>
            </div>
        </div>
    </DashTray>

    <DashTray
        icon="layout-grid"
        :title="$t('Dashboard')"
        :meta="
            account?.dashboard
                ? $t('Updated :when', { when: timeAgo(account.dashboard.at) })
                : null
        "
    >
        <template v-if="account?.dashboard" #actions>
            <DashButton
                size="sm"
                icon="open-fill"
                :label="$t('Open in Grafana')"
                :href="account.dashboard.href"
                external
            />
        </template>

        <div v-if="account" class="flex flex-col gap-3 px-3.5 py-3">
            <p class="text-[13px]/5 text-fg-tertiary">
                {{
                    $t(
                        'One dashboard for this workspace: machines reporting, services and monitors down, then CPU, memory, disk, load, network, processes and uptime for each machine, and monitor response times. It reads the Prometheus data source you pick, and can switch to another in Grafana.',
                    )
                }}
            </p>
            <form
                class="flex flex-wrap items-end gap-2"
                @submit.prevent="saveDashboard"
            >
                <label class="flex min-w-[220px] flex-1 flex-col gap-1.5">
                    <span :class="labelClass">{{
                        $t('Prometheus data source')
                    }}</span>
                    <SelectMenu
                        v-if="account.dataSources.length"
                        v-model="dashboardForm.datasource"
                        :options="sourceOptions"
                        :aria-label="$t('Prometheus data source')"
                        :class="fieldClass"
                    />
                    <input
                        v-else
                        v-model="dashboardForm.datasource"
                        :class="[fieldClass, 'font-ui-mono text-[12px]!']"
                        autocomplete="off"
                        spellcheck="false"
                        :placeholder="$t('Its UID')"
                    />
                </label>
                <DashButton
                    type="submit"
                    :variant="account.dashboard ? 'secondary' : 'primary'"
                    :label="
                        account.dashboard
                            ? $t('Update the dashboard')
                            : $t('Create the dashboard')
                    "
                    :loading="dashboardForm.processing"
                    :disabled="disabled || !dashboardForm.datasource"
                />
            </form>
            <p
                v-if="dashboardForm.errors.datasource"
                class="text-[12px]/4 text-status-down"
                role="alert"
            >
                {{ dashboardForm.errors.datasource }}
            </p>
            <p
                v-else-if="account.dataSourcesError"
                class="flex items-start gap-1.5 text-[12px]/4 text-fg-muted"
            >
                <FilledIcon
                    name="info-fill"
                    :size="13"
                    class="mt-px shrink-0"
                />
                {{
                    account.cloud
                        ? $t(
                              'This token can’t list data sources, so type the UID. Grafana Cloud’s own Prometheus is usually grafanacloud-prom.',
                          )
                        : $t(
                              'This token can’t list data sources, so type the UID: it’s at the end of the data source’s address in Connections › Data sources.',
                          )
                }}
            </p>
            <p
                v-else-if="!account.dataSources.length"
                class="text-[12px]/4 text-fg-muted"
            >
                {{
                    $t(
                        'Grafana has no Prometheus data source yet. Add the one your Prometheus is, then come back.',
                    )
                }}
            </p>
            <p v-if="account.dashboard" class="text-[12px]/4 text-fg-muted">
                {{
                    $t(
                        'Version :version, reading :source. Updating replaces changes made to it in Grafana.',
                        {
                            version: String(account.dashboard.version),
                            source:
                                account.dashboard.datasource?.name ??
                                $t('its data source'),
                        },
                    )
                }}
            </p>
        </div>
    </DashTray>

    <Dialog v-model:open="naming">
        <DialogContent
            class="workspace-panel ds-modal gap-0 !rounded-[12px] p-0 font-ui text-fg-primary sm:max-w-[560px]"
        >
            <div class="flex flex-col gap-0.5 px-5 pt-[18px] pb-4">
                <DialogTitle
                    class="text-[16px]/6 font-medium tracking-[-0.01em]"
                    >{{ $t('Make a metrics token') }}</DialogTitle
                >
                <DialogDescription class="text-[13px]/5 text-fg-tertiary">
                    {{
                        secret
                            ? $t(
                                  'Copy it now: ServerOS shows it once. It can read this workspace’s metrics and nothing else.',
                              )
                            : $t(
                                  'It can read this workspace’s metrics and nothing else. Disconnecting this account revokes it.',
                              )
                    }}
                </DialogDescription>
            </div>
            <div class="flex flex-col gap-3 px-5 pb-[18px]">
                <template v-if="secret">
                    <CopyBlock
                        :text="secret"
                        :label="$t('Copy the token')"
                        wrap
                    />
                    <span :class="labelClass">{{
                        $t('The scrape config, with the token in it')
                    }}</span>
                    <CopyBlock
                        :text="scrapeConfig"
                        :label="$t('Copy the scrape config')"
                    />
                </template>
                <label v-else class="flex flex-col gap-1.5">
                    <span :class="labelClass">{{ $t('Name') }}</span>
                    <input
                        v-model="tokenName"
                        :class="fieldClass"
                        autocomplete="off"
                        maxlength="80"
                        @keydown.enter.prevent="makeToken"
                    />
                    <span
                        v-if="tokenError"
                        class="text-[12px]/4 text-status-down"
                        role="alert"
                        >{{ tokenError }}</span
                    >
                </label>
            </div>
            <div
                class="flex justify-end gap-2 border-t-[0.5px] border-edge bg-surface-card-footer py-3 pr-4 pl-5"
            >
                <DashButton
                    :label="secret ? $t('Done') : $t('Cancel')"
                    @click="naming = false"
                />
                <DashButton
                    v-if="!secret"
                    variant="primary"
                    :label="$t('Make the token')"
                    :loading="making"
                    :disabled="!tokenName.trim()"
                    @click="makeToken"
                />
            </div>
        </DialogContent>
    </Dialog>
</template>
