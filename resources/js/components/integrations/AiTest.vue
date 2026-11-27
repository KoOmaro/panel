<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';
import DashButton from '@/components/dash/DashButton.vue';
import DashTray from '@/components/dash/DashTray.vue';
import type { AiModelOption } from '@/components/integrations/ai';
import { tokens } from '@/components/integrations/ai';
import { sendJson } from '@/components/integrations/integrations';
import { SelectMenu } from '@/components/ui/select';
import { onWorkspace } from '@/lib/workspaceRoute';
import aiRoutes from '@/routes/workspace/settings/ai';

const {
    provider,
    models,
    defaultModel = null,
    workspaceSlug,
    disabled = false,
} = defineProps<{
    provider: string;
    models: AiModelOption[];
    defaultModel?: string | null;
    workspaceSlug: string;
    disabled?: boolean;
}>();

const emit = defineEmits<{ tested: [] }>();

type Answer = {
    reply: string;
    model: string;
    ms: number;
    input: number;
    output: number;
};

const model = ref<string>(defaultModel ?? models[0]?.id ?? '');
const sending = ref(false);
const answer = ref<Answer | null>(null);
const error = ref<{ message: string; ms: number | null } | null>(null);

const choices = computed(() => {
    const list = models.map((m) => ({
        value: m.id,
        label: m.label,
        hint: m.label === m.id ? undefined : m.id,
    }));
    if (model.value && !list.some((m) => m.value === model.value)) {
        list.unshift({
            value: model.value,
            label: model.value,
            hint: undefined,
        });
    }

    return list;
});

async function send(): Promise<void> {
    if (sending.value) return;
    sending.value = true;
    answer.value = null;
    error.value = null;
    const result = await sendJson<Answer>(
        'POST',
        onWorkspace(aiRoutes.test({ workspace: workspaceSlug, provider })).url,
        { model: model.value || null },
    );
    sending.value = false;
    if (result.ok) {
        answer.value = result.data;
        emit('tested');

        return;
    }
    error.value = {
        message:
            result.message ||
            trans('Couldn’t send the test. Try again in a minute.'),
        ms: null,
    };
}
</script>

<template>
    <DashTray
        icon="terminal-fill"
        :title="$t('Test it')"
        :meta="$t('Sends a short prompt on this key')"
        panel-class="py-0.5"
    >
        <div class="flex flex-wrap items-center gap-2 px-3.5 py-3">
            <SelectMenu
                v-model="model"
                :options="choices"
                :aria-label="$t('Model to test')"
                :disabled="disabled || sending || choices.length === 0"
                class="h-8 min-w-[200px] flex-1 rounded-[7px] border-[0.5px] border-edge-field bg-surface-field px-2.5 text-[13px]/5 text-fg-primary shadow-field sm:max-w-[320px]"
            />
            <DashButton
                :label="sending ? $t('Sending…') : $t('Send test prompt')"
                :loading="sending"
                :disabled="disabled || !model"
                @click="send"
            />
        </div>
        <div
            v-if="answer"
            class="flex flex-col gap-1.5 border-t-[0.5px] border-edge-tray-panel px-3.5 py-3"
            role="status"
        >
            <p class="text-[13px]/5 whitespace-pre-line text-fg-primary">
                {{ answer.reply || $t('It answered with no words.') }}
            </p>
            <p class="flex flex-wrap gap-x-3 text-[12px]/4 text-fg-muted">
                <span class="font-ui-mono text-[11.5px]/4">{{
                    answer.model
                }}</span>
                <span class="tabular-nums">{{
                    $t(':ms ms', { ms: answer.ms.toLocaleString() })
                }}</span>
                <span class="tabular-nums"
                    >{{ tokens(answer.input) }} {{ $t('in') }} ·
                    {{ tokens(answer.output) }} {{ $t('out') }}</span
                >
            </p>
        </div>
        <p
            v-else-if="error"
            class="border-t-[0.5px] border-edge-tray-panel px-3.5 py-3 text-[13px]/5 text-status-down"
            role="alert"
        >
            {{ error.message }}
        </p>
    </DashTray>
</template>
