<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, ref, watch } from 'vue';
import DashButton from '@/components/dash/DashButton.vue';
import type { N8nWorkflow } from '@/components/integrations/n8n';
import { jsonRequest } from '@/components/integrations/request';
import { SelectMenu } from '@/components/ui/select';
import { errorMessage } from '@/lib/errors';
import { toast } from '@/lib/toast';
import { onWorkspace, workspacePath } from '@/lib/workspaceRoute';
import n8nRoutes from '@/routes/workspace/integrations/n8n';
import type { Workspace } from '@/types';
import type { Options, WorkflowNode } from './kinds';
import { useWorkflowEditor } from './useWorkflowEditor';

const props = defineProps<{
    node: WorkflowNode;
    options: Options;
}>();

const editor = useWorkflowEditor();
const readOnly = editor.readOnly;
const page = usePage<{ workspace: Workspace }>();

function set(key: string, value: string | number | boolean | null): void {
    editor.setRule(props.node.id, key, value);
}

function value(key: string): string {
    const v = props.node.rules[key];

    return v === null || v === undefined ? '' : String(v);
}

const accounts = computed(() => props.options.n8n ?? []);
const account = computed(() =>
    accounts.value.find((a) => String(a.id) === value('integration')),
);

const loaded = ref<
    Record<string, { list: N8nWorkflow[]; error: string | null }>
>({});
const loading = ref(false);

async function load(fresh = false): Promise<void> {
    const id = value('integration');
    if (!id || (loaded.value[id] && !fresh)) return;
    loading.value = true;
    try {
        const body = await jsonRequest<{
            workflows: N8nWorkflow[];
            error: string | null;
        }>(
            onWorkspace(
                n8nRoutes.workflows(
                    {
                        workspace: page.props.workspace.slug,
                        integration: Number(id),
                    },
                    fresh ? { query: { fresh: 1 } } : undefined,
                ),
            ).url,
        );
        loaded.value = {
            ...loaded.value,
            [id]: { list: body.workflows, error: body.error },
        };
    } catch (cause) {
        loaded.value = {
            ...loaded.value,
            [id]: { list: [], error: errorMessage(cause) },
        };
    } finally {
        loading.value = false;
    }
}

watch(
    () => value('integration'),
    (id) => {
        if (id) void load();
    },
    { immediate: true },
);

watch(
    accounts,
    (list) => {
        if (!value('integration') && list.length === 1 && !readOnly.value)
            set('integration', String(list[0].id));
    },
    { immediate: true },
);

const current = computed(() => loaded.value[value('integration')] ?? null);
const workflows = computed(() => current.value?.list ?? []);
const chosen = computed(
    () => workflows.value.find((w) => w.id === value('workflow')) ?? null,
);

const workflowOptions = computed(() =>
    workflows.value.map((w) => ({
        value: w.id,
        label: w.name,
        hint: w.problem
            ? trans('Can’t be started')
            : w.active
              ? undefined
              : trans('Off'),
        disabled: !w.webhook,
    })),
);

function chooseWorkflow(id: string): void {
    const found = workflows.value.find((w) => w.id === id);
    set('workflow', id);
    set('workflow_name', found?.name ?? '');
    set('node', '');
}

function chooseAccount(id: string): void {
    set('integration', id);
    set('workflow', '');
    set('workflow_name', '');
    set('node', '');
}

const activating = ref(false);

async function activate(): Promise<void> {
    const workflow = chosen.value;
    if (!workflow || activating.value) return;
    activating.value = true;
    try {
        await jsonRequest(
            onWorkspace(
                n8nRoutes.activate({
                    workspace: page.props.workspace.slug,
                    integration: Number(value('integration')),
                    workflow: workflow.id,
                }),
            ).url,
            'POST',
        );
        toast.success(
            trans('Turned on :name in n8n.', { name: workflow.name }),
        );
        await load(true);
    } catch (cause) {
        toast.error(errorMessage(cause));
    } finally {
        activating.value = false;
    }
}

const waits = computed(() => [
    { value: 'no', label: trans('Don’t wait, go on once n8n has it') },
    { value: 'answer', label: trans('Wait for its answer, up to a minute') },
    { value: 'callback', label: trans('Wait until it calls back') },
]);

const bodyHint = trans(
    'A JSON object, sent as input next to the trigger, machine, service and monitor. Later steps can use :placeholder from what n8n sends back.',
    { placeholder: '{{n8n.field}}' },
);

const field = 'wf-field h-9 w-full px-3 text-[13px]/5';
const label = 'text-[12px]/5 font-[550] text-(--wf-muted)';
const hint = 'text-[12px]/4 text-(--wf-faint)';
</script>

<template>
    <div class="flex flex-col gap-4">
        <p v-if="!accounts.length" :class="hint">
            {{ $t('Connect n8n first, then pick one of its workflows here.') }}
            <Link
                :href="workspacePath('/integrations')"
                class="text-(--wf-text) underline-offset-2 hover:underline"
                >{{ $t('Open Integrations') }}</Link
            >
        </p>

        <template v-else>
            <label v-if="accounts.length > 1" class="flex flex-col gap-1.5">
                <span :class="label">{{ $t('n8n account') }}</span>
                <SelectMenu
                    :class="field"
                    :model-value="value('integration')"
                    :disabled="readOnly"
                    :placeholder="$t('Choose…')"
                    :options="
                        accounts.map((a) => ({
                            value: String(a.id),
                            label: a.label,
                            hint: a.failing ? $t('Needs a new key') : undefined,
                        }))
                    "
                    @update:model-value="(v) => chooseAccount(String(v))"
                />
            </label>
            <span
                v-if="account?.failing"
                class="-mt-2 text-[12px]/4 text-(--ds-amber)"
                >{{
                    $t(
                        'n8n stopped taking this account’s API key. Connect it again with a new one.',
                    )
                }}</span
            >

            <div v-if="value('integration')" class="flex flex-col gap-1.5">
                <div class="flex items-center gap-2">
                    <span :class="[label, 'flex-1']">{{ $t('Workflow') }}</span>
                    <button
                        type="button"
                        class="text-[12px]/4 text-(--wf-faint) hover:text-(--wf-text) disabled:opacity-50"
                        :disabled="loading"
                        @click="load(true)"
                    >
                        {{ loading ? $t('Loading…') : $t('Refresh') }}
                    </button>
                </div>
                <SelectMenu
                    :class="field"
                    :model-value="value('workflow')"
                    :disabled="readOnly || loading"
                    :placeholder="
                        value('workflow_name') ||
                        (loading ? $t('Loading…') : $t('Choose…'))
                    "
                    :options="workflowOptions"
                    @update:model-value="(v) => chooseWorkflow(String(v))"
                />
                <span
                    v-if="current?.error"
                    class="text-[12px]/4 text-(--ds-amber)"
                    >{{ current.error }}</span
                >
                <span v-else-if="current && !workflows.length" :class="hint">{{
                    $t(
                        'No workflows in n8n yet. Make one that starts with a Webhook trigger.',
                    )
                }}</span>
                <span v-else-if="!value('workflow')" :class="hint">{{
                    $t(
                        'It needs a Webhook trigger with the POST method. ServerOS sends what happened as JSON.',
                    )
                }}</span>
            </div>

            <template v-if="chosen">
                <span
                    v-if="chosen.problem"
                    class="-mt-2 text-[12px]/4 text-(--ds-amber)"
                    >{{ chosen.problem }}</span
                >
                <div
                    v-else-if="!chosen.active"
                    class="-mt-1 flex items-center gap-2"
                >
                    <span class="flex-1 text-[12px]/4 text-(--ds-amber)">{{
                        $t(
                            'It’s off in n8n, so its webhook doesn’t answer yet.',
                        )
                    }}</span>
                    <DashButton
                        size="sm"
                        :label="$t('Turn it on')"
                        :loading="activating"
                        :disabled="readOnly"
                        @click="activate"
                    />
                </div>
                <a
                    :href="chosen.href"
                    target="_blank"
                    rel="noopener"
                    class="-mt-2 text-[12px]/4 text-(--wf-faint) underline-offset-2 hover:text-(--wf-text) hover:underline"
                    >{{ $t('Open it in n8n') }}</a
                >
            </template>

            <label class="flex flex-col gap-1.5">
                <span :class="label">{{ $t('Then') }}</span>
                <SelectMenu
                    :class="field"
                    :model-value="value('wait') || 'no'"
                    :disabled="readOnly"
                    :options="waits"
                    @update:model-value="(v) => set('wait', String(v))"
                />
                <span
                    v-if="
                        value('wait') === 'answer' &&
                        chosen?.webhook &&
                        !chosen.webhook.answers
                    "
                    class="text-[12px]/4 text-(--ds-amber)"
                    >{{
                        $t(
                            'Its webhook answers straight away. To get its result, set the webhook’s Respond to When Last Node Finishes, or use a Respond to Webhook node.',
                        )
                    }}</span
                >
                <span v-else-if="value('wait') === 'callback'" :class="hint">{{
                    $t(
                        'n8n gets a callback_url. POST JSON to it with an HTTP Request node when the workflow is done; an "error" field fails this step.',
                    )
                }}</span>
            </label>

            <label
                v-if="value('wait') === 'callback'"
                class="flex flex-col gap-1.5"
            >
                <span :class="label">{{ $t('Give up after') }}</span>
                <SelectMenu
                    :class="field"
                    :model-value="value('minutes') || '30'"
                    :disabled="readOnly"
                    :options="
                        [5, 15, 30, 60, 120, 360, 1440].map((m) => ({
                            value: m,
                            label:
                                m < 60
                                    ? $t(':count minutes', { count: String(m) })
                                    : m === 60
                                      ? $t('1 hour')
                                      : $t(':count hours', {
                                            count: String(m / 60),
                                        }),
                        }))
                    "
                    @update:model-value="(v) => set('minutes', v)"
                />
            </label>

            <label class="flex flex-col gap-1.5">
                <span :class="label">{{ $t('Extra input (optional)') }}</span>
                <textarea
                    class="wf-field min-h-16 w-full px-3 py-2 font-ui-mono text-[12px]/5"
                    :value="value('body')"
                    :disabled="readOnly"
                    placeholder='{"reason": "{{trigger}}"}'
                    spellcheck="false"
                    @input="
                        set(
                            'body',
                            ($event.target as HTMLTextAreaElement).value,
                        )
                    "
                />
                <span :class="hint">{{ bodyHint }}</span>
            </label>
        </template>
    </div>
</template>
