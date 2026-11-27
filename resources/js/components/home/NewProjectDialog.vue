<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { ref, watch } from 'vue';
import DashButton from '@/components/dash/DashButton.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { toast } from '@/lib/toast';
import { onWorkspace } from '@/lib/workspaceRoute';
import projects from '@/routes/workspace/projects';

const open = defineModel<boolean>('open', { default: false });

const page = usePage<{ workspace: { slug: string } }>();
const name = ref('');
const saving = ref(false);

watch(open, (value) => {
    if (value) name.value = '';
});

function create(): void {
    if (!name.value.trim() || saving.value) return;
    saving.value = true;
    router.post(
        onWorkspace(projects.store(page.props.workspace.slug)).url,
        { name: name.value.trim() },
        {
            onError: (errors) =>
                toast.error(
                    Object.values(errors)[0] ?? trans('Couldn’t create it.'),
                ),
            onFinish: () => (saving.value = false),
        },
    );
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="workspace-panel ds-modal gap-0 p-0 font-ui text-fg-primary sm:max-w-md"
        >
            <form class="flex flex-col gap-4 p-5" @submit.prevent="create">
                <div class="flex flex-col gap-1">
                    <DialogTitle
                        class="text-[15px]/5 font-medium tracking-[-0.01em] text-fg-primary"
                        >{{ $t('New project') }}</DialogTitle
                    >
                    <DialogDescription class="text-[13px]/5 text-fg-tertiary">
                        {{
                            $t(
                                'Name it after the app, like Shop or Shop staging. You add what’s in it next.',
                            )
                        }}
                    </DialogDescription>
                </div>
                <label class="dash-field">
                    <span class="sr-only">{{ $t('Project name') }}</span>
                    <input
                        v-model="name"
                        maxlength="80"
                        required
                        autofocus
                        :placeholder="$t('Shop')"
                    />
                </label>
                <div class="flex justify-end gap-2">
                    <DashButton :label="$t('Cancel')" @click="open = false" />
                    <DashButton
                        variant="primary"
                        type="submit"
                        :label="$t('Create project')"
                        :loading="saving"
                        :disabled="!name.trim()"
                    />
                </div>
            </form>
        </DialogContent>
    </Dialog>
</template>
