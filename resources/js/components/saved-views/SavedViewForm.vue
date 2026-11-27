<script setup lang="ts">
import { nextTick, onMounted, ref, useTemplateRef } from 'vue';
import DashButton from '@/components/dash/DashButton.vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import type { SavedViewSummaryRow } from '@/composables/useSavedViews';

const props = defineProps<{
    title: string;
    name: string;
    submitLabel: string;
    summary?: SavedViewSummaryRow[];
    busy?: boolean;
    error?: string | null;
}>();

const emit = defineEmits<{ submit: [name: string]; cancel: [] }>();

const value = ref(props.name);
const input = useTemplateRef<HTMLInputElement>('input');

onMounted(async () => {
    await nextTick();
    input.value?.focus();
    input.value?.select();
});

function submit(): void {
    const name = value.value.trim();
    if (name) emit('submit', name);
}
</script>

<template>
    <form class="flex w-[300px] flex-col font-ui" @submit.prevent="submit">
        <div class="flex flex-col gap-2.5 p-3.5 pb-3">
            <p
                class="text-[13px]/5 font-medium tracking-[-0.01em] text-fg-primary"
            >
                {{ title }}
            </p>
            <label class="dash-field">
                <span class="sr-only">{{ $t('View name') }}</span>
                <input
                    ref="input"
                    v-model="value"
                    maxlength="60"
                    required
                    autocomplete="off"
                    spellcheck="false"
                    :placeholder="$t('New view')"
                    :aria-invalid="error ? true : undefined"
                />
            </label>
            <p v-if="error" class="text-[12px]/4 text-status-down">
                {{ error }}
            </p>
        </div>

        <div
            v-if="summary?.length"
            class="border-t-[0.5px] border-edge-row px-3.5 pt-2.5 pb-3"
        >
            <p class="text-[12px]/4 text-fg-muted">
                {{ $t('What’s saved') }}
            </p>
            <dl class="mt-1.5 flex flex-col">
                <div
                    v-for="row in summary"
                    :key="row.label"
                    class="flex h-6 items-center gap-2 text-[12.5px]/4"
                >
                    <FilledIcon
                        :name="row.icon"
                        :size="14"
                        class="shrink-0 text-fg-muted"
                    />
                    <dt class="w-[84px] shrink-0 text-fg-tertiary">
                        {{ row.label }}
                    </dt>
                    <dd
                        class="min-w-0 truncate"
                        :class="
                            row.empty
                                ? 'text-fg-faint'
                                : 'font-medium tracking-[-0.01em] text-fg-primary'
                        "
                        :title="row.empty ? undefined : row.value"
                    >
                        {{ row.value }}
                    </dd>
                </div>
            </dl>
        </div>

        <div
            class="flex justify-end gap-2 border-t-[0.5px] border-edge-row bg-surface-card-footer px-3.5 py-2.5"
        >
            <DashButton
                size="sm"
                :label="$t('Cancel')"
                @click="emit('cancel')"
            />
            <DashButton
                size="sm"
                type="submit"
                variant="primary"
                :label="submitLabel"
                :loading="busy"
                :disabled="!value.trim()"
            />
        </div>
    </form>
</template>
