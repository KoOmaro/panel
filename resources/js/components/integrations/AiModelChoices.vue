<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { ref } from 'vue';
import type {
    AiFeatureRow,
    AiProviderModels,
} from '@/components/integrations/ai';
import { SelectMenu } from '@/components/ui/select';
import type { SelectMenuGroup, SelectMenuOption } from '@/components/ui/select';
import { onWorkspace } from '@/lib/workspaceRoute';
import aiRoutes from '@/routes/workspace/settings/ai';

const {
    features,
    options,
    workspaceSlug,
    canManage = true,
} = defineProps<{
    features: AiFeatureRow[];
    options: AiProviderModels[];
    workspaceSlug: string;
    canManage?: boolean;
}>();

const saving = ref<string | null>(null);
const errors = ref<Record<string, string>>({});

function choicesFor(
    feature: AiFeatureRow,
): (SelectMenuOption<string> | SelectMenuGroup<string>)[] {
    const groups: SelectMenuGroup<string>[] = options
        .filter((option) => feature.providers.includes(option.provider))
        .map((option) => {
            const models = option.models.map((model) => ({
                value: `${option.provider}:${model.id}`,
                label: model.label,
                hint: model.label === model.id ? undefined : model.id,
            }));
            const chosen = feature.choice?.startsWith(`${option.provider}:`)
                ? feature.choice
                : null;
            if (chosen && !models.some((m) => m.value === chosen)) {
                models.unshift({
                    value: chosen,
                    label: chosen.slice(option.provider.length + 1),
                    hint: undefined,
                });
            }

            return { label: option.label, options: models };
        });

    return [
        {
            value: '',
            label: trans('Automatic'),
            hint: trans('The first key connected, or ServerOS’s own model'),
        },
        ...groups,
    ];
}

function choose(feature: AiFeatureRow, value: string | null): void {
    const choice = value ?? '';
    if (choice === (feature.choice ?? '')) return;
    saving.value = feature.key;
    errors.value = { ...errors.value, [feature.key]: '' };
    router.put(
        onWorkspace(aiRoutes.models(workspaceSlug)).url,
        { feature: feature.key, choice: choice === '' ? null : choice },
        {
            preserveScroll: true,
            preserveState: true,
            onError: (e) => {
                errors.value = {
                    ...errors.value,
                    [feature.key]: e.choice ?? Object.values(e)[0] ?? '',
                };
            },
            onFinish: () => (saving.value = null),
        },
    );
}
</script>

<template>
    <ul class="flex flex-col">
        <li
            v-for="feature in features"
            :key="feature.key"
            class="flex flex-wrap items-center gap-x-4 gap-y-2 border-t-[0.5px] border-edge-tray-panel px-3.5 py-3 first:border-t-0"
        >
            <div class="flex min-w-0 flex-1 basis-56 flex-col">
                <span
                    class="text-[13px]/5 font-medium tracking-[-0.01em] text-fg-primary"
                    >{{ feature.label }}</span
                >
                <span class="text-[12px]/4 tracking-[-0.005em] text-fg-muted">{{
                    feature.about
                }}</span>
                <span
                    class="mt-1 flex items-center gap-1.5 text-[12px]/4 text-fg-tertiary"
                >
                    <span
                        class="size-1.5 shrink-0 rounded-full"
                        :class="
                            feature.own ? 'bg-status-live' : 'bg-status-stopped'
                        "
                        aria-hidden="true"
                    />
                    {{ $t('Uses :model', { model: feature.uses }) }}
                </span>
                <span
                    v-if="errors[feature.key]"
                    class="mt-1 text-[12px]/4 text-status-down"
                    role="alert"
                    >{{ errors[feature.key] }}</span
                >
            </div>
            <SelectMenu
                :model-value="feature.choice ?? ''"
                :options="choicesFor(feature)"
                :aria-label="
                    $t('Model for :feature', { feature: feature.label })
                "
                :disabled="!canManage || saving === feature.key"
                align="end"
                class="h-8 w-full max-w-[260px] min-w-[200px] rounded-[7px] border-[0.5px] border-edge-field bg-surface-field px-2.5 text-[13px]/5 text-fg-primary shadow-field sm:w-[260px]"
                @update:model-value="(value) => choose(feature, value)"
            />
        </li>
    </ul>
</template>
