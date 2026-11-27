<script setup lang="ts">
import { Check, X } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import DashButton from '@/components/dash/DashButton.vue';
import type { Operation } from '@/components/integrations/integrations';
import { toast } from '@/lib/toast';

const { operation } = defineProps<{
    operation: Pick<Operation, 'steps' | 'state' | 'error'> &
        Partial<Pick<Operation, 'note' | 'command'>>;
}>();

async function copy(): Promise<void> {
    try {
        await navigator.clipboard.writeText(operation.command ?? '');
        toast.success(trans('Install command copied'));
    } catch {
        toast.error(
            trans('Could not copy. Select the command and copy it manually.'),
        );
    }
}
</script>

<template>
    <div class="flex flex-col gap-2.5">
        <ol class="flex flex-col gap-2" :aria-label="$t('Progress')">
            <li
                v-for="step in operation.steps"
                :key="step.key"
                class="flex items-center gap-2.5 text-[13px]/5"
            >
                <span
                    class="flex size-[18px] shrink-0 items-center justify-center rounded-full border-[0.5px]"
                    :class="{
                        'border-[rgba(62,196,109,0.3)] bg-[rgba(62,196,109,0.14)] text-status-live':
                            step.state === 'done',
                        'border-[rgba(240,105,90,0.3)] bg-[rgba(240,105,90,0.14)] text-status-down':
                            step.state === 'failed',
                        'border-edge-strong bg-surface-badge':
                            step.state === 'current' || step.state === 'todo',
                    }"
                    aria-hidden="true"
                >
                    <Check
                        v-if="step.state === 'done'"
                        :size="11"
                        :stroke-width="2"
                    />
                    <X
                        v-else-if="step.state === 'failed'"
                        :size="11"
                        :stroke-width="2"
                    />
                    <svg
                        v-else-if="step.state === 'current'"
                        viewBox="0 0 16 16"
                        class="size-3 animate-spin motion-reduce:animate-none"
                    >
                        <path
                            d="M8 1.5a6.5 6.5 0 0 1 6.5 6.5"
                            fill="none"
                            stroke="#3d63ff"
                            stroke-width="2.2"
                            stroke-linecap="round"
                        />
                    </svg>
                </span>
                <span
                    :class="
                        step.state === 'todo'
                            ? 'text-fg-muted'
                            : step.state === 'failed'
                              ? 'text-status-down'
                              : 'text-fg-primary'
                    "
                    >{{ step.label }}</span
                >
                <span v-if="step.state === 'current'" class="sr-only">{{
                    $t('In progress')
                }}</span>
            </li>
        </ol>
        <p
            v-if="operation.note && operation.state !== 'failed'"
            class="text-[12.5px]/[18px] text-fg-tertiary"
            role="status"
        >
            {{ operation.note }}
        </p>
        <div v-if="operation.command" class="flex flex-col items-start gap-2">
            <pre
                class="max-h-40 w-full overflow-auto rounded-[8px] border-[0.5px] border-edge-field bg-surface-field px-3 py-2.5 font-ui-mono text-[12px]/[18px] break-all whitespace-pre-wrap text-fg-secondary shadow-field"
                >{{ operation.command }}</pre>
            <DashButton
                size="sm"
                icon="copy"
                :label="$t('Copy command')"
                @click="copy"
            />
        </div>
        <p
            v-if="operation.state === 'failed' && operation.error"
            class="rounded-[8px] border-[0.5px] border-[rgba(240,105,90,0.25)] bg-[rgba(240,105,90,0.07)] px-3 py-2 text-[12px]/[18px] text-fg-secondary"
            role="alert"
        >
            {{ operation.error }}
        </p>
    </div>
</template>
