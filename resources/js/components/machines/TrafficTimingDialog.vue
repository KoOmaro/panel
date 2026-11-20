<script setup lang="ts">
import { LoaderCircle } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { appButtonClasses } from '@/lib/appButton';
import { describe, errorMessage, jobReason } from '@/lib/errors';
import { toast } from '@/lib/toast';

type Change = {
    file: string;
    new: boolean;
    at: string;
    lines: string[];
};

type Plan = {
    changes: Change[];
    check: string;
    reload: string;
};

const props = defineProps<{
    machineId: number;
    site: string;
    label: string;
}>();
const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ applied: [] }>();

const plan = ref<Plan | null>(null);
const step = ref<'loading' | 'ready' | 'applying'>('loading');
const error = ref<string | null>(null);
const output = ref<string[]>([]);
let poll: ReturnType<typeof setTimeout> | undefined;
let asked = 0;
onBeforeUnmount(() => clearTimeout(poll));

const lineCount = computed(
    () => plan.value?.changes.reduce((sum, c) => sum + c.lines.length, 0) ?? 0,
);

function csrf(): string {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}

const base = computed(
    () =>
        `/machines/${props.machineId}/sites/${encodeURIComponent(props.site)}/timing`,
);

async function ask(apply: boolean): Promise<Record<string, unknown>> {
    const response = await fetch(base.value, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrf(),
        },
        body: JSON.stringify({ apply }),
    });
    const body = await response.json().catch(() => ({}));
    if (response.status !== 202) {
        throw new Error(describe(response.status, body));
    }

    return follow(String(body.jobId));
}

/** Waits for the machine to finish the job; resolves with its result. */
function follow(jobId: string): Promise<Record<string, unknown>> {
    let tries = 0;

    return new Promise((resolve, reject) => {
        const check = async () => {
            try {
                const response = await fetch(`${base.value}/${jobId}`, {
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json' },
                });
                const job = await response.json();
                if (job.state === 'succeeded') {
                    resolve((job.result ?? {}) as Record<string, unknown>);

                    return;
                }
                if (
                    [
                        'failed',
                        'refused',
                        'timed_out',
                        'cancelled',
                        'stale',
                    ].includes(job.state)
                ) {
                    output.value = Array.isArray(job.error?.output_tail)
                        ? job.error.output_tail.map(String)
                        : [];
                    reject(
                        new Error(
                            jobReason(
                                job.error,
                                trans('The machine didn’t do it. Try again.'),
                            ),
                        ),
                    );

                    return;
                }
                if (++tries >= 120) {
                    reject(
                        new Error(
                            trans(
                                'The machine is taking a while. Try again in a minute.',
                            ),
                        ),
                    );

                    return;
                }
                poll = setTimeout(check, 1000);
            } catch (e) {
                reject(e);
            }
        };
        poll = setTimeout(check, 800);
    });
}

async function load(): Promise<void> {
    const ticket = ++asked;
    step.value = 'loading';
    plan.value = null;
    error.value = null;
    output.value = [];
    try {
        const result = await ask(false);
        if (ticket !== asked) return;
        plan.value = {
            changes: Array.isArray(result.changes)
                ? (result.changes as Change[])
                : [],
            check: String(result.check ?? ''),
            reload: String(result.reload ?? ''),
        };
        step.value = 'ready';
    } catch (e) {
        if (ticket !== asked) return;
        error.value = errorMessage(e);
        step.value = 'ready';
    }
}

async function apply(): Promise<void> {
    step.value = 'applying';
    error.value = null;
    output.value = [];
    try {
        await ask(true);
        toast.success(
            trans(
                'Response times are on for :site. They show up for new visits within a couple of minutes.',
                { site: props.label },
            ),
        );
        emit('applied');
        open.value = false;
    } catch (e) {
        error.value = errorMessage(e);
        step.value = 'ready';
    }
}

watch(open, (isOpen) => {
    if (isOpen) {
        load();
    } else {
        asked++;
        clearTimeout(poll);
    }
});
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="workspace-panel ds-modal gap-0 p-0 font-ui text-foreground sm:max-w-2xl"
        >
            <div class="flex flex-col gap-1 p-5 pb-4">
                <DialogTitle class="text-base/6 font-semibold">{{
                    $t('Record response times')
                }}</DialogTitle>
                <DialogDescription
                    class="text-[13px]/5 text-pretty text-(--ds-text-muted)"
                >
                    {{
                        $t(
                            'The access log of :site doesn’t say how long requests took. ServerOS can add a second access log with times, which it reads instead. Your current log keeps working as it does now.',
                            { site: label },
                        )
                    }}
                </DialogDescription>
            </div>

            <div
                v-if="step === 'loading'"
                class="flex items-center gap-2 border-t border-(--ds-divider) px-5 py-8 text-[13px]/5 text-(--ds-text-muted)"
            >
                <LoaderCircle
                    class="size-3.5 animate-spin"
                    aria-hidden="true"
                />
                {{ $t('Asking the machine what it would change…') }}
            </div>

            <div
                v-else-if="plan && plan.changes.length > 0"
                class="flex max-h-[55vh] flex-col gap-4 overflow-y-auto border-t border-(--ds-divider) px-5 py-4"
            >
                <p class="text-[13px]/5 text-(--ds-text-secondary)">
                    {{
                        $t(
                            lineCount === 1
                                ? 'It adds this line and changes nothing else:'
                                : 'It adds these :count lines and changes nothing else:',
                            { count: String(lineCount) },
                        )
                    }}
                </p>
                <section
                    v-for="(change, index) in plan.changes"
                    :key="index"
                    class="flex min-w-0 flex-col gap-1.5"
                >
                    <div
                        class="flex min-w-0 flex-wrap items-baseline gap-x-2 gap-y-0.5"
                    >
                        <span
                            class="min-w-0 font-ui-mono text-[12px] break-all text-(--ds-text)"
                            >{{ change.file }}</span
                        >
                        <span
                            v-if="change.new"
                            class="rounded-sm bg-(--ds-hover) px-1.5 text-[11px]/4.5 text-(--ds-text-muted)"
                            >{{ $t('New file') }}</span
                        >
                    </div>
                    <span class="text-xs text-(--ds-text-faint)">{{
                        change.new ? $t('Written by ServerOS') : change.at
                    }}</span>
                    <pre
                        class="overflow-x-auto rounded-md border border-(--ds-border) bg-(--ds-field) px-3 py-2 font-ui-mono text-[12px]/5 text-(--ds-text-secondary)"
                    ><code v-for="(line, n) in change.lines" :key="n" class="block" :class="line.startsWith('#') && 'text-(--ds-text-faint)'">{{ line }}</code></pre>
                </section>
                <p class="text-[13px]/5 text-(--ds-text-muted)">
                    {{
                        $t(
                            'Each file is backed up on the machine first. Then ServerOS runs :check, and if that fails, every file goes back as it was. If it passes, it runs :reload.',
                            { check: plan.check, reload: plan.reload },
                        )
                    }}
                </p>
            </div>

            <div
                v-if="error"
                class="flex flex-col gap-2 border-t border-(--ds-divider) px-5 py-4"
                role="alert"
            >
                <p class="text-[13px]/5 text-(--ds-red)">{{ error }}</p>
                <pre
                    v-if="output.length"
                    class="max-h-40 overflow-auto rounded-md border border-(--ds-border) bg-(--ds-field) px-3 py-2 font-ui-mono text-[12px]/5 text-(--ds-text-secondary)"
                    >{{ output.join('\n') }}</pre>
            </div>

            <div
                class="flex items-center justify-end gap-2 border-t border-(--ds-divider) px-5 py-4"
            >
                <button
                    type="button"
                    :class="appButtonClasses('secondary')"
                    @click="open = false"
                >
                    {{ $t('Cancel') }}
                </button>
                <button
                    type="button"
                    :class="appButtonClasses('primary')"
                    :disabled="
                        step !== 'ready' || !plan || plan.changes.length === 0
                    "
                    @click="apply"
                >
                    <LoaderCircle
                        v-if="step === 'applying'"
                        class="size-3.5 animate-spin"
                        aria-hidden="true"
                    />
                    {{
                        step === 'applying'
                            ? $t('Adding them…')
                            : $t('Add the lines')
                    }}
                </button>
            </div>
        </DialogContent>
    </Dialog>
</template>
