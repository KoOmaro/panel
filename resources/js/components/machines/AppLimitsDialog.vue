<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { computed, ref, watch } from 'vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { appButtonClasses } from '@/lib/appButton';
import { toast } from '@/lib/toast';
import { describe, networkMessage } from '@/lib/errors';

export type AppLimits = {
    setting: { cpus: number | null; memoryMb: number | null } | null;
    applied: { cpus: number | null; memoryMb: number | null } | null;
    unavailable: string | null;
    max: { cpus: number | null; memoryMb: number | null };
};

const props = defineProps<{
    machineId: number;
    app: string;
    limits: AppLimits;
}>();
const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ saved: [jobId: string | null] }>();

const cpus = ref('');
const memory = ref('');
const unit = ref<'MB' | 'GB'>('MB');
const busy = ref(false);
const error = ref<string | null>(null);

const memoryMb = computed<number | null>(() => {
    const value = Number.parseFloat(memory.value);
    if (!memory.value.trim() || Number.isNaN(value)) return null;

    return Math.round(unit.value === 'GB' ? value * 1024 : value);
});

watch(open, (isOpen) => {
    if (!isOpen) return;
    const setting = props.limits.setting;
    cpus.value = setting?.cpus ? String(setting.cpus) : '';
    const mb = setting?.memoryMb ?? null;
    if (mb && mb >= 1024 && mb % 1024 === 0) {
        unit.value = 'GB';
        memory.value = String(mb / 1024);
    } else {
        unit.value = 'MB';
        memory.value = mb ? String(mb) : '';
    }
    error.value = null;
});

function csrf(): string {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}

async function save(): Promise<void> {
    busy.value = true;
    error.value = null;
    const response = await fetch(
        `/machines/${props.machineId}/apps/${encodeURIComponent(props.app)}/limits`,
        {
            method: 'PUT',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf(),
            },
            body: JSON.stringify({
                cpus: cpus.value.trim() === '' ? null : Number(cpus.value),
                memory_mb: memoryMb.value,
            }),
        },
    ).catch(() => null);
    busy.value = false;
    const body = (await response?.json().catch(() => null)) as {
        jobId?: string | null;
    } | null;
    if (!response?.ok) {
        error.value = response
            ? describe(response.status, body)
            : networkMessage();

        return;
    }
    open.value = false;
    if (body?.jobId) {
        toast.success(trans('Deploying it again with the new limits.'));
    }
    emit('saved', body?.jobId ?? null);
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="workspace-panel ds-modal gap-0 p-0 font-ui text-foreground sm:max-w-md"
        >
            <form class="flex flex-col" @submit.prevent="save">
                <div class="flex flex-col gap-1 p-5 pb-4">
                    <DialogTitle class="text-base/6 font-semibold">{{
                        $t('CPU and memory limits')
                    }}</DialogTitle>
                    <DialogDescription
                        class="text-[13px]/5 text-pretty text-(--ds-text-muted)"
                    >
                        {{
                            ' ' +
                            $t(
                                'The most :name may use. Leave one empty for no limit. Saving deploys the app again with them.',
                                { name: app },
                            ) +
                            ' '
                        }}
                    </DialogDescription>
                </div>
                <div class="flex flex-col gap-4 px-5">
                    <label class="flex flex-col gap-1.5">
                        <span class="text-[13px]/5 font-medium">{{
                            $t('CPUs')
                        }}</span>
                        <Input
                            v-model="cpus"
                            type="number"
                            inputmode="decimal"
                            min="0.01"
                            step="0.01"
                            :max="limits.max.cpus ?? undefined"
                            :placeholder="$t('No limit, like 0.5 or 2')"
                            class="font-ui-mono text-[13px]"
                        />
                        <span
                            v-if="limits.max.cpus"
                            class="text-xs/4 text-(--ds-text-muted)"
                            >{{
                                $t('The machine has :count.', {
                                    count: String(limits.max.cpus),
                                })
                            }}</span
                        >
                    </label>
                    <div class="flex flex-col gap-1.5">
                        <label
                            for="app-limits-memory"
                            class="text-[13px]/5 font-medium"
                            >{{ $t('Memory') }}</label
                        >
                        <div class="flex gap-2">
                            <Input
                                id="app-limits-memory"
                                v-model="memory"
                                type="number"
                                inputmode="decimal"
                                min="0"
                                step="any"
                                :placeholder="$t('No limit, like 512')"
                                class="min-w-0 grow font-ui-mono text-[13px]"
                            />
                            <select
                                v-model="unit"
                                class="ds-raised-button h-9 rounded-md px-2 text-[13px]"
                                :aria-label="$t('Unit')"
                            >
                                <option value="MB">MB</option>
                                <option value="GB">GB</option>
                            </select>
                        </div>
                        <span
                            v-if="limits.max.memoryMb"
                            class="text-xs/4 text-(--ds-text-muted)"
                            >{{
                                $t('The machine has :value MB.', {
                                    value: String(limits.max.memoryMb),
                                })
                            }}</span
                        >
                    </div>
                    <p
                        v-if="error"
                        class="text-[13px] text-(--ds-red)"
                        role="alert"
                    >
                        {{ error }}
                    </p>
                </div>
                <div
                    class="mt-5 flex items-center justify-end gap-2 border-t border-(--ds-divider) px-5 py-4"
                >
                    <button
                        type="button"
                        :class="appButtonClasses('secondary')"
                        @click="open = false"
                    >
                        {{ $t('Cancel') }}
                    </button>
                    <button
                        type="submit"
                        :class="appButtonClasses('primary')"
                        :disabled="busy"
                    >
                        {{ busy ? $t('Saving…') : $t('Save and deploy') }}
                    </button>
                </div>
            </form>
        </DialogContent>
    </Dialog>
</template>
