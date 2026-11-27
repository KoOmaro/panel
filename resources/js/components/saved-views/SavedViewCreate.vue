<script setup lang="ts" generic="F extends SavedViewFilters">
import {
    PopoverContent,
    PopoverPortal,
    PopoverRoot,
    PopoverTrigger,
} from 'reka-ui';
import { ref, watch } from 'vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import SavedViewForm from '@/components/saved-views/SavedViewForm.vue';
import type {
    SavedViewFilters,
    SavedViews,
    SavedViewSummaryRow,
} from '@/composables/useSavedViews';

const props = defineProps<{
    views: SavedViews<F>;
    name: string;
    summary: SavedViewSummaryRow[];
}>();

const open = ref(false);
const error = ref<string | null>(null);

watch(open, (isOpen) => {
    if (isOpen) error.value = null;
});

async function save(name: string): Promise<void> {
    const errors = await props.views.create(name);
    if (errors) {
        error.value = Object.values(errors)[0] ?? null;

        return;
    }
    open.value = false;
}
</script>

<template>
    <PopoverRoot v-model:open="open">
        <PopoverTrigger as-child>
            <button
                type="button"
                class="flex size-6 cursor-pointer items-center justify-center rounded-[6px] text-fg-muted transition-colors hover:text-fg-secondary focus-visible:outline-2 focus-visible:outline-(--ds-ring) data-[state=open]:text-fg-secondary"
                :aria-label="$t('Save a view')"
            >
                <FilledIcon name="plus-fill" />
            </button>
        </PopoverTrigger>
        <PopoverPortal>
            <PopoverContent
                align="start"
                :side-offset="8"
                :collision-padding="12"
                class="workspace-panel ds-modal z-50 overflow-hidden text-fg-primary data-[state=open]:animate-in data-[state=open]:fade-in-0 data-[state=open]:zoom-in-95"
            >
                <SavedViewForm
                    :title="$t('Save this view')"
                    :name="name"
                    :summary="summary"
                    :submit-label="$t('Save view')"
                    :busy="views.busy"
                    :error="error"
                    @submit="save"
                    @cancel="open = false"
                />
            </PopoverContent>
        </PopoverPortal>
    </PopoverRoot>
</template>
