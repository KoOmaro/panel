<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, ref, watch } from 'vue';
import DashButton from '@/components/dash/DashButton.vue';
import DashTray from '@/components/dash/DashTray.vue';
import FilterPills from '@/components/dash/FilterPills.vue';
import StatusBadge from '@/components/dash/StatusBadge.vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import CopyBlock from '@/components/integrations/CopyBlock.vue';
import type {
    N8nAccount,
    N8nAccountWorkflow,
} from '@/components/integrations/n8n';
import { jsonRequest } from '@/components/integrations/request';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { errorMessage } from '@/lib/errors';
import { timeAgo } from '@/lib/timeAgo';
import { toast } from '@/lib/toast';
import { onWorkspace, workspacePath } from '@/lib/workspaceRoute';
import n8nRoutes from '@/routes/workspace/integrations/n8n';
import type { Workspace } from '@/types';

const {
    account,
    integrationId,
    disabled = false,
} = defineProps<{
    account: N8nAccount | undefined;
    integrationId: number;
    disabled?: boolean;
}>();
const page = usePage<{ workspace: Workspace }>();
const args = computed(() => ({
    workspace: page.props.workspace.slug,
    integration: integrationId,
}));

type Filter = 'all' | 'ready' | 'used';
const filter = ref<Filter>('all');
const workflows = computed(() => account?.workflows ?? []);
const ready = computed(() =>
    workflows.value.filter((w) => w.webhook && !w.problem),
);
const used = computed(() => workflows.value.filter((w) => w.usedBy.length));
const shown = computed(() =>
    filter.value === 'ready'
        ? ready.value
        : filter.value === 'used'
          ? used.value
          : workflows.value,
);
const pills = computed(() => [
    {
        value: 'all' as const,
        label: trans('All'),
        count: workflows.value.length,
    },
    {
        value: 'ready' as const,
        label: trans('With a webhook'),
        count: ready.value.length,
    },
    {
        value: 'used' as const,
        label: trans('Used here'),
        count: used.value.length,
    },
]);
const meta = computed(() => {
    if (!account) return null;

    return workflows.value.length === 1
        ? trans('1 workflow, :ready ServerOS can start', {
              ready: String(ready.value.length),
          })
        : trans(':count workflows, :ready ServerOS can start', {
              count: String(workflows.value.length),
              ready: String(ready.value.length),
          });
});

function webhookLine(workflow: N8nAccountWorkflow): string {
    if (workflow.problem) return workflow.problem;
    if (!workflow.webhook) return '';

    return workflow.webhook.answers
        ? trans(':method webhook, answers when it finishes', {
              method: workflow.webhook.method ?? 'POST',
          })
        : trans(':method webhook, answers straight away', {
              method: workflow.webhook.method ?? 'POST',
          });
}

const activating = ref<string | null>(null);

async function activate(workflow: N8nAccountWorkflow): Promise<void> {
    if (activating.value) return;
    activating.value = workflow.id;
    try {
        await jsonRequest(
            onWorkspace(
                n8nRoutes.activate({ ...args.value, workflow: workflow.id }),
            ).url,
            'POST',
        );
        toast.success(
            trans('Turned on :name in n8n.', { name: workflow.name }),
        );
        router.reload({ only: ['account', 'usage', 'integration'] });
    } catch (cause) {
        toast.error(errorMessage(cause));
    } finally {
        activating.value = null;
    }
}

const making = ref(false);
const sending = ref(false);
const starterError = ref<string | null>(null);

watch(making, (open) => {
    if (open) starterError.value = null;
});

function makeStarter(): void {
    if (sending.value) return;
    sending.value = true;
    starterError.value = null;
    router.post(
        onWorkspace(n8nRoutes.starter(args.value)).url,
        {},
        {
            preserveScroll: true,
            only: ['account', 'usage', 'dependents', 'flash'],
            onSuccess: () => (making.value = false),
            onError: (errors) => {
                starterError.value =
                    errors.starter ?? trans('That didn’t work. Try again.');
            },
            onFinish: () => (sending.value = false),
        },
    );
}

const example = ref<string>('machines');
const shownExample = computed(
    () =>
        account?.api.examples.find((e) => e.key === example.value) ??
        account?.api.examples[0] ??
        null,
);
</script>

<template>
    <DashTray
        icon="update-fill"
        :title="$t('Workflows')"
        :meta="meta"
        panel-class="py-0.5"
    >
        <template #actions>
            <FilterPills
                v-if="account && workflows.length"
                v-model="filter"
                :options="pills"
                :label="$t('Show')"
                class="max-sm:hidden"
            />
        </template>

        <div
            v-if="account === undefined"
            class="flex flex-col"
            role="status"
            :aria-label="$t('Loading workflows')"
        >
            <div
                v-for="n in 3"
                :key="n"
                class="flex items-center gap-3 border-t-[0.5px] border-edge-tray-panel px-3.5 py-3 first:border-t-0"
            >
                <span
                    class="h-3 w-40 animate-pulse rounded bg-surface-badge motion-reduce:animate-none"
                />
                <span
                    class="h-3 w-24 animate-pulse rounded bg-surface-badge motion-reduce:animate-none"
                />
            </div>
        </div>
        <template v-else>
            <p
                v-if="account.error"
                class="px-3.5 py-2.5 text-[12.5px]/[18px] text-status-down"
                role="alert"
            >
                {{ account.error }}
            </p>
            <ul v-if="shown.length" class="flex flex-col">
                <li
                    v-for="workflow in shown"
                    :key="workflow.id"
                    class="flex min-w-0 items-center gap-3 border-t-[0.5px] border-edge-tray-panel px-3.5 py-2.5 first:border-t-0"
                >
                    <div class="flex min-w-0 flex-1 flex-col gap-1">
                        <a
                            :href="workflow.href"
                            target="_blank"
                            rel="noopener"
                            class="flex min-w-0 items-center gap-1 text-[13px]/5 font-medium tracking-[-0.01em] text-fg-primary hover:underline"
                        >
                            <span class="truncate">{{ workflow.name }}</span>
                            <FilledIcon
                                name="open-fill"
                                :size="12"
                                class="shrink-0 text-fg-muted"
                            />
                        </a>
                        <span
                            v-if="webhookLine(workflow)"
                            class="text-[12px]/4"
                            :class="
                                workflow.problem && workflow.webhook
                                    ? 'text-status-pending'
                                    : 'text-fg-muted'
                            "
                            >{{ webhookLine(workflow) }}</span
                        >
                        <div
                            v-if="workflow.usedBy.length"
                            class="flex flex-wrap gap-x-3 gap-y-1"
                        >
                            <Link
                                v-for="user in workflow.usedBy"
                                :key="user.id"
                                :href="workspacePath(user.href)"
                                class="flex min-w-0 items-center gap-1.5 text-[12px]/4 text-fg-tertiary hover:text-fg-primary"
                            >
                                <FilledIcon
                                    name="branch-fill"
                                    :size="12"
                                    class="text-fg-muted"
                                />
                                <span class="truncate">{{ user.name }}</span>
                                <span
                                    v-if="!user.enabled"
                                    class="text-fg-muted"
                                    >{{ $t('(off)') }}</span
                                >
                            </Link>
                        </div>
                    </div>
                    <DashButton
                        v-if="!workflow.active && workflow.webhook"
                        size="sm"
                        :label="$t('Turn on')"
                        :loading="activating === workflow.id"
                        :disabled="disabled"
                        @click="activate(workflow)"
                    />
                    <StatusBadge
                        :status="workflow.active ? 'live' : 'stopped'"
                        :label="workflow.active ? $t('On') : $t('Off')"
                    />
                </li>
            </ul>
            <p
                v-else-if="!account.error"
                class="px-3.5 py-3 text-[13px]/5 text-fg-muted"
            >
                {{
                    workflows.length
                        ? $t('Nothing here matches.')
                        : $t(
                              'No workflows in n8n yet. Make one that starts with a Webhook trigger, then pick it in a Run an n8n workflow step.',
                          )
                }}
            </p>
        </template>
    </DashTray>

    <DashTray icon="code-fill" :title="$t('Call ServerOS from n8n')">
        <template #actions>
            <DashButton
                v-if="account?.starter"
                size="sm"
                icon="open-fill"
                :label="$t('Open the starter workflow')"
                :href="account.starter.href"
                external
            />
            <DashButton
                v-else
                variant="primary"
                size="sm"
                :label="$t('Create a starter workflow in n8n')"
                :disabled="!account || disabled"
                @click="making = true"
            />
        </template>

        <div v-if="account" class="flex flex-col gap-3.5 px-3.5 py-3">
            <p class="text-[13px]/5 text-fg-tertiary">
                {{
                    $t(
                        'n8n’s HTTP Request node calls the ServerOS API with an API key. Make one in Settings › API: read-only to look things up, or one that can make changes to run workflows and restart services. In n8n, keep it as a Header Auth credential named Authorization, with Bearer, a space and the key as its value.',
                    )
                }}
                <Link
                    :href="workspacePath(account.api.keysHref)"
                    class="text-fg-secondary underline-offset-2 hover:underline"
                    >{{ $t('Open Settings › API') }}</Link
                >
            </p>

            <div class="flex flex-col gap-1.5">
                <span class="text-[12px]/4 font-medium text-fg-secondary">{{
                    $t('API address')
                }}</span>
                <CopyBlock
                    :text="account.api.baseUrl"
                    :label="$t('Copy the address')"
                />
            </div>

            <div class="flex flex-col gap-1.5">
                <div class="flex items-center gap-2">
                    <span
                        class="flex-1 text-[12px]/4 font-medium text-fg-secondary"
                        >{{ $t('HTTP Request nodes to paste into n8n') }}</span
                    >
                    <FilterPills
                        v-model="example"
                        :options="
                            account.api.examples.map((e) => ({
                                value: e.key,
                                label: e.title,
                            }))
                        "
                        :label="$t('Example')"
                    />
                </div>
                <template v-if="shownExample">
                    <p class="text-[12px]/4 text-fg-muted">
                        {{ shownExample.description }}
                        {{
                            shownExample.access === 'read'
                                ? $t('A read-only key is enough.')
                                : ''
                        }}
                    </p>
                    <CopyBlock
                        :text="shownExample.json"
                        :label="$t('Copy the node')"
                    />
                    <p class="text-[12px]/4 text-fg-muted">
                        {{
                            $t(
                                'Copy it, click an empty spot in an n8n workflow and paste. Then pick your ServerOS credential in the node.',
                            )
                        }}
                    </p>
                </template>
            </div>

            <div
                v-if="account.starter"
                class="flex items-start gap-2 rounded-[8px] border-[0.5px] border-edge-tray-panel px-3 py-2.5 text-[12.5px]/[18px]"
            >
                <FilledIcon
                    name="check"
                    :size="14"
                    class="mt-0.5 shrink-0 text-status-live"
                />
                <span class="flex min-w-0 flex-col gap-0.5">
                    <span class="text-fg-secondary">{{
                        $t('ServerOS made :name in n8n :when.', {
                            name: account.starter.name,
                            when: timeAgo(account.starter.at),
                        })
                    }}</span>
                    <span class="text-fg-muted">{{
                        account.starter.key
                            ? account.starter.key.lastUsedAt
                                ? $t(
                                      'It calls ServerOS with the read-only key :name (:prefix…), last used :when.',
                                      {
                                          name: account.starter.key.name,
                                          prefix: account.starter.key.prefix,
                                          when: timeAgo(
                                              account.starter.key.lastUsedAt,
                                          ),
                                      },
                                  )
                                : $t(
                                      'It calls ServerOS with the read-only key :name (:prefix…), not used yet. It’s off until you run it.',
                                      {
                                          name: account.starter.key.name,
                                          prefix: account.starter.key.prefix,
                                      },
                                  )
                            : account.starter.credential
                              ? $t(
                                    'Its API key was revoked. Make a new key in Settings › API and put it in the credential.',
                                )
                              : $t(
                                    'Add a Header Auth credential with a ServerOS API key to its HTTP Request node.',
                                )
                    }}</span>
                    <button
                        type="button"
                        class="self-start text-fg-tertiary underline-offset-2 hover:text-fg-primary hover:underline disabled:opacity-50"
                        :disabled="disabled"
                        @click="making = true"
                    >
                        {{ $t('Make another one') }}
                    </button>
                </span>
            </div>
        </div>
    </DashTray>

    <Dialog v-model:open="making">
        <DialogContent
            class="workspace-panel ds-modal gap-0 !rounded-[12px] p-0 font-ui text-fg-primary sm:max-w-[480px]"
        >
            <div class="flex flex-col gap-0.5 px-5 pt-[18px] pb-4">
                <DialogTitle
                    class="text-[16px]/6 font-medium tracking-[-0.01em]"
                    >{{ $t('Create a starter workflow in n8n') }}</DialogTitle
                >
                <DialogDescription class="text-[13px]/5 text-fg-tertiary">
                    {{
                        $t(
                            'ServerOS makes a read-only API key for you, keeps it in n8n as a Header Auth credential, and makes a workflow that lists your machines with it. The workflow stays off until you run it.',
                        )
                    }}
                </DialogDescription>
            </div>
            <div class="flex flex-col gap-2 px-5 pb-[18px]">
                <p
                    v-if="account?.starter?.key"
                    class="text-[12.5px]/[18px] text-fg-muted"
                >
                    {{
                        $t(
                            'The key of the starter workflow made before is revoked once the new one is made.',
                        )
                    }}
                </p>
                <p
                    v-if="starterError"
                    class="text-[12.5px]/[18px] text-status-down"
                    role="alert"
                >
                    {{ starterError }}
                </p>
            </div>
            <div
                class="flex justify-end gap-2 border-t-[0.5px] border-edge bg-surface-card-footer py-3 pr-4 pl-5"
            >
                <DashButton :label="$t('Cancel')" @click="making = false" />
                <DashButton
                    variant="primary"
                    :label="$t('Create it')"
                    :loading="sending"
                    @click="makeStarter"
                />
            </div>
        </DialogContent>
    </Dialog>
</template>
