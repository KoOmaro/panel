<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, nextTick, ref, useTemplateRef } from 'vue';
import DashButton from '@/components/dash/DashButton.vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import FieldSelect from '@/components/machines/settings/FieldSelect.vue';
import type { FieldOption } from '@/components/machines/settings/FieldSelect.vue';
import SettingsCard from '@/components/machines/settings/SettingsCard.vue';
import SettingsRow from '@/components/machines/settings/SettingsRow.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { onWorkspace } from '@/lib/workspaceRoute';
import machinesRoutes from '@/routes/workspace/machines';

export type LogSkipProps = {
    available: boolean;
    since: string;
    rules: {
        services: string[];
        patterns: { service: string | null; pattern: string }[];
    };
};

export type SkippedLogs = {
    rule: string;
    services: { service: string; lines: number }[];
}[];

const props = defineProps<{
    machineId: number;
    machineName: string;
    workspaceSlug: string;
    logSkip: LogSkipProps;
    skipped?: SkippedLogs;
    serviceNames: string[];
    canManage: boolean;
}>();

const emit = defineEmits<{ changed: [] }>();

const args = computed(() => ({
    workspace: props.workspaceSlug,
    machine: props.machineId,
}));

type Row = {
    key: string;
    kind: 'service' | 'pattern';
    title: string;
    scope: string;
    index: number;
};

const rows = computed<Row[]>(() => [
    ...props.logSkip.rules.services.map((service, index) => ({
        key: `s:${service}`,
        kind: 'service' as const,
        title: service,
        scope: trans('Every line'),
        index,
    })),
    ...props.logSkip.rules.patterns.map((rule, index) => ({
        key: `p:${index}:${rule.service ?? ''}:${rule.pattern}`,
        kind: 'pattern' as const,
        title: rule.pattern,
        scope: rule.service ?? trans('Every service'),
        index,
    })),
]);

function storedFor(rule: string): { lines: number; services: string[] } | null {
    if (!props.skipped) return null;
    const match = props.skipped.find((entry) => entry.rule === rule);

    return {
        lines: (match?.services ?? []).reduce((sum, s) => sum + s.lines, 0),
        services: (match?.services ?? []).map((s) => s.service),
    };
}

function compact(n: number): string {
    return n >= 1_000_000
        ? `${(n / 1_000_000).toFixed(1).replace(/\.0$/, '')}M`
        : n.toLocaleString();
}

type Choice = 'keep' | 'delete' | 'remove';

function valueOf(row: Row): Choice {
    if (row.kind === 'pattern') return 'keep';
    const stored = storedFor(row.title);

    return stored === null || stored.lines > 0 ? 'keep' : 'delete';
}

function optionsFor(row: Row): FieldOption<Choice>[] {
    if (row.kind === 'pattern') {
        return [
            {
                value: 'keep',
                label: trans('Keep'),
                hint: trans('Lines it already sent stay in ServerOS'),
            },
            {
                value: 'delete',
                label: trans('Delete'),
                hint: trans(
                    'Lines matching a pattern can’t be picked out of what ServerOS has. Clear the machine’s logs to remove them.',
                ),
                disabled: true,
            },
            {
                value: 'remove',
                label: trans('Remove'),
                hint: trans('Send these lines again'),
                separated: true,
                danger: true,
            },
        ];
    }
    const stored = storedFor(row.title);
    const count = stored ? compact(stored.lines) : null;

    return [
        {
            value: 'keep',
            label: trans('Keep'),
            hint:
                stored === null
                    ? trans('Lines it already sent stay in ServerOS')
                    : stored.lines
                      ? trans('Keep the :count lines it already sent', {
                            count: String(count),
                        })
                      : trans('ServerOS has none of its lines'),
        },
        {
            value: 'delete',
            label: trans('Delete'),
            hint:
                stored && stored.lines
                    ? trans('Delete the :count lines it already sent', {
                          count: String(count),
                      })
                    : trans('Nothing to delete'),
            disabled: !stored || stored.lines === 0,
        },
        {
            value: 'remove',
            label: trans('Remove'),
            hint: trans('Send its logs again'),
            separated: true,
            danger: true,
        },
    ];
}

const busy = ref(false);
const error = ref<string | null>(null);

function put(
    services: string[],
    patterns: { service: string | null; pattern: string }[],
    done?: () => void,
): void {
    if (busy.value) return;
    router.put(
        onWorkspace(machinesRoutes.logSkip.update(args.value)).url,
        {
            services,
            patterns: patterns.map((rule) => ({
                service: rule.service ?? '',
                pattern: rule.pattern,
            })),
        },
        {
            preserveScroll: true,
            preserveState: true,
            onStart: () => {
                busy.value = true;
                error.value = null;
            },
            onFinish: () => (busy.value = false),
            onError: (bag) =>
                (error.value =
                    Object.entries(bag).find(
                        ([key]) =>
                            key === 'services' || key.startsWith('patterns'),
                    )?.[1] ?? trans('That didn’t save. Try again.')),
            onSuccess: () => {
                done?.();
                emit('changed');
            },
        },
    );
}

const pending = ref<{ row: Row; lines: number; services: string[] } | null>(
    null,
);
const deleting = ref(false);
const deleteError = ref<string | null>(null);

function choose(row: Row, choice: Choice): void {
    if (choice === 'remove') {
        const services = [...props.logSkip.rules.services];
        const patterns = [...props.logSkip.rules.patterns];
        if (row.kind === 'service') services.splice(row.index, 1);
        else patterns.splice(row.index, 1);
        put(services, patterns);

        return;
    }
    if (choice === 'delete' && row.kind === 'service') {
        const stored = storedFor(row.title);
        if (!stored || stored.lines === 0) return;
        deleteError.value = null;
        pending.value = { row, ...stored };
    }
}

function deleteStored(): void {
    const target = pending.value;
    if (!target || deleting.value) return;
    router.post(
        onWorkspace(machinesRoutes.collectedData.skipped(args.value)).url,
        { services: target.services },
        {
            preserveScroll: true,
            preserveState: true,
            onStart: () => (deleting.value = true),
            onFinish: () => (deleting.value = false),
            onError: (bag) =>
                (deleteError.value =
                    bag.services ?? trans('That didn’t delete. Try again.')),
            onSuccess: () => {
                pending.value = null;
                emit('changed');
            },
        },
    );
}

const adding = ref(false);
const draft = ref({ service: '', pattern: '' });
const patternInput = useTemplateRef<HTMLInputElement>('patternInput');

function startAdding(): void {
    adding.value = true;
    error.value = null;
    draft.value = { service: '', pattern: '' };
    nextTick(() => patternInput.value?.focus());
}

function add(): void {
    const service = draft.value.service.trim();
    const pattern = draft.value.pattern;
    if (service === '' && pattern.trim() === '') {
        adding.value = false;

        return;
    }
    const services = [...props.logSkip.rules.services];
    const patterns = [...props.logSkip.rules.patterns];
    if (pattern.trim() === '') services.push(service);
    else patterns.push({ service: service || null, pattern });
    put(services, patterns, () => (adding.value = false));
}

const editable = computed(() => props.canManage && props.logSkip.available);
</script>

<template>
    <SettingsCard
        icon="file-fill"
        :title="$t('Logs that stay on :name', { name: machineName })"
    >
        <SettingsRow
            v-if="!logSkip.available"
            :label="$t('Needs a newer daemon')"
            :hint="
                $t(
                    'Needs daemon :version or newer. It updates itself, or run “sudo serverosd update” on the server.',
                    { version: logSkip.since },
                )
            "
        />
        <SettingsRow
            v-else-if="rows.length === 0 && !adding"
            :label="$t('Nothing stays yet')"
            :hint="
                $t(
                    'Lines and services you add here never leave the machine, so ServerOS doesn’t store, show or tail them.',
                )
            "
        />

        <SettingsRow
            v-for="row in rows"
            :key="row.key"
            :label="row.title"
            :hint="row.scope"
            mono
        >
            <FieldSelect
                :model-value="valueOf(row)"
                :options="optionsFor(row)"
                :label="$t('Lines :name already sent', { name: row.title })"
                :width="120"
                :disabled="!editable || busy"
                @update:model-value="(choice) => choose(row, choice)"
            />
        </SettingsRow>

        <SettingsRow v-if="adding" :error="error">
            <template #label>
                <form
                    class="flex flex-wrap items-center gap-2"
                    @submit.prevent="add"
                    @keydown.esc.prevent="adding = false"
                >
                    <div
                        class="dash-field min-w-48 flex-1 font-ui-mono text-[12px]/4"
                    >
                        <input
                            ref="patternInput"
                            v-model="draft.pattern"
                            spellcheck="false"
                            autocomplete="off"
                            maxlength="300"
                            :placeholder="
                                draft.service.trim() === ''
                                    ? 'password=\\S+'
                                    : $t('Every line')
                            "
                            :aria-label="$t('Pattern')"
                        />
                    </div>
                    <div class="dash-field w-40 text-[13px]/5">
                        <input
                            v-model="draft.service"
                            list="log-rule-services"
                            spellcheck="false"
                            autocomplete="off"
                            maxlength="300"
                            :placeholder="$t('Every service')"
                            :aria-label="$t('Service')"
                        />
                        <datalist id="log-rule-services">
                            <option
                                v-for="name in serviceNames"
                                :key="name"
                                :value="name"
                            />
                        </datalist>
                    </div>
                    <DashButton
                        type="submit"
                        size="sm"
                        :label="$t('Add')"
                        :loading="busy"
                    />
                    <DashButton
                        size="sm"
                        :label="$t('Cancel')"
                        :disabled="busy"
                        @click="adding = false"
                    />
                </form>
                <p class="pt-1.5 text-[12px]/4 text-fg-muted">
                    {{
                        $t(
                            'A regular expression, or leave it empty to keep every line of the service. Use * in the service for any characters.',
                        )
                    }}
                </p>
            </template>
        </SettingsRow>

        <div v-else-if="editable" class="flex min-h-[52px] items-center py-2.5">
            <button
                type="button"
                class="flex h-8 cursor-pointer items-center gap-2 rounded-md text-[13px]/5 tracking-[-0.005em] text-fg-secondary hover:text-fg-primary focus-visible:outline-2 focus-visible:outline-(--ds-ring)"
                @click="startAdding"
            >
                <FilledIcon name="plus-fill" :size="12" class="text-fg-muted" />
                {{ $t('Add pattern') }}
            </button>
        </div>
        <p
            v-if="error && !adding"
            class="pb-2.5 text-[12px]/4 text-status-down"
            role="alert"
        >
            {{ error }}
        </p>
    </SettingsCard>

    <Dialog
        :open="pending !== null"
        @update:open="(open) => !open && (pending = null)"
    >
        <DialogContent
            class="workspace-panel ds-modal font-ui text-foreground sm:max-w-md"
        >
            <DialogHeader>
                <DialogTitle>{{
                    $t('Delete the :count lines :name already sent?', {
                        count: compact(pending?.lines ?? 0),
                        name: pending?.row.title ?? '',
                    })
                }}</DialogTitle>
                <DialogDescription>
                    {{
                        $t(
                            'They’re gone from ServerOS for good. New lines from it already stay on :machine.',
                            { machine: machineName },
                        )
                    }}
                </DialogDescription>
            </DialogHeader>
            <p
                v-if="pending && pending.services.length > 1"
                class="font-ui-mono text-[12px]/4 break-words text-fg-muted"
            >
                {{ pending.services.join(', ') }}
            </p>
            <p
                v-if="deleteError"
                class="text-[13px]/5 text-status-down"
                role="alert"
            >
                {{ deleteError }}
            </p>
            <div class="flex justify-end gap-2">
                <DashButton
                    :label="$t('Keep them')"
                    :disabled="deleting"
                    @click="pending = null"
                />
                <DashButton
                    variant="danger"
                    :label="$t('Delete them')"
                    :loading="deleting"
                    @click="deleteStored"
                />
            </div>
        </DialogContent>
    </Dialog>
</template>
