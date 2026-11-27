<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import DashButton from '@/components/dash/DashButton.vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { onWorkspace } from '@/lib/workspaceRoute';
import accessRoutes from '@/routes/workspace/machines/access';

const open = defineModel<boolean>('open', { default: false });

const props = defineProps<{
    workspaceSlug: string;
    machineId: number;
    machineName: string;
    login: string;
    mode: 'ask' | 'key' | 'own';
}>();

const emit = defineEmits<{ done: [] }>();

const form = useForm({ key: '', note: '', login: props.login });

watch(open, (value) => {
    if (value) {
        form.reset();
        form.clearErrors();
    }
});

const asking = computed(() => props.mode === 'ask');

function submit(): void {
    form.post(
        onWorkspace(
            accessRoutes.request({
                workspace: props.workspaceSlug,
                machine: props.machineId,
            }),
        ).url,
        {
            preserveScroll: true,
            only: ['access'],
            onSuccess: () => {
                open.value = false;
                emit('done');
            },
        },
    );
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="workspace-panel ds-modal gap-0 !rounded-[12px] p-0 font-ui text-fg-primary sm:max-w-[540px]"
            :show-close-button="false"
        >
            <form class="flex flex-col" @submit.prevent="submit">
                <div class="flex items-start gap-3 pt-[18px] pr-4 pb-4 pl-5">
                    <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                        <DialogTitle
                            class="text-[16px]/6 font-medium tracking-[-0.01em] text-fg-primary"
                        >
                            {{
                                asking
                                    ? $t('Ask for access to :name', {
                                          name: machineName,
                                      })
                                    : $t('Add your key to :name', {
                                          name: machineName,
                                      })
                            }}
                        </DialogTitle>
                        <DialogDescription
                            class="text-[13px]/5 text-fg-tertiary"
                        >
                            {{
                                asking
                                    ? $t(
                                          'An owner or admin approves it, then ServerOS adds your key.',
                                      )
                                    : $t(
                                          'ServerOS puts it on :name straight away.',
                                          { name: machineName },
                                      )
                            }}
                        </DialogDescription>
                    </div>
                    <kbd
                        class="flex h-5 items-center rounded-[5px] border-[0.5px] border-edge-strong px-1.5 font-ui text-[11px]/[14px] text-fg-muted"
                        >Esc</kbd
                    >
                </div>

                <div class="flex flex-col gap-3.5 px-5 pb-[18px]">
                    <label class="flex flex-col gap-1.5">
                        <span class="text-[13px]/5 text-fg-primary">{{
                            $t('Your public key')
                        }}</span>
                        <textarea
                            v-model="form.key"
                            v-focus
                            rows="3"
                            spellcheck="false"
                            autocomplete="off"
                            :placeholder="$t('ssh-ed25519 AAAA… you@laptop')"
                            class="min-h-[76px] resize-none rounded-[8px] border-[0.5px] border-edge-field bg-surface-field px-2.5 py-2 font-ui-mono text-[12px]/[18px] break-all text-fg-primary shadow-field outline-none placeholder:text-fg-faint focus:border-[rgba(51,97,255,0.7)] focus:shadow-[0_0_0_3px_rgba(51,97,255,0.25)]"
                        />
                        <span
                            v-if="form.errors.key"
                            class="text-[12px]/4 text-status-down"
                            >{{ form.errors.key }}</span
                        >
                        <span v-else class="text-[12px]/4 text-fg-muted">{{
                            $t(
                                'The line in ~/.ssh/id_ed25519.pub on your computer. Never the private key.',
                            )
                        }}</span>
                    </label>

                    <div
                        class="flex flex-col rounded-[10px] border-[0.5px] border-edge-card bg-surface-card"
                    >
                        <div
                            class="flex items-center gap-3 py-[9px] pr-2.5 pl-3.5"
                        >
                            <div class="flex min-w-0 flex-1 flex-col">
                                <span class="text-[13px]/5 text-fg-primary">{{
                                    $t('Logs in as')
                                }}</span>
                                <span class="text-[12px]/4 text-fg-muted">{{
                                    $t(
                                        'The login ServerOS looks after on :name',
                                        {
                                            name: machineName,
                                        },
                                    )
                                }}</span>
                            </div>
                            <span
                                class="flex h-[30px] w-[190px] items-center rounded-[7px] border-[0.5px] border-edge-card bg-surface-field pr-[9px] pl-2.5 font-ui-mono text-[12px]/4 text-fg-primary shadow-field"
                                >{{ login }}</span
                            >
                        </div>
                        <label
                            v-if="asking"
                            class="flex items-center gap-3 border-t-[0.5px] border-edge py-[9px] pr-2.5 pl-3.5"
                        >
                            <span class="flex min-w-0 flex-1 flex-col">
                                <span class="text-[13px]/5 text-fg-primary">{{
                                    $t('Why')
                                }}</span>
                                <span class="text-[12px]/4 text-fg-muted">{{
                                    $t('Optional, for whoever approves it')
                                }}</span>
                            </span>
                            <input
                                v-model="form.note"
                                maxlength="300"
                                :placeholder="$t('Debugging the queue')"
                                class="h-[30px] w-[190px] rounded-[7px] border-[0.5px] border-edge-card bg-surface-field px-2.5 text-[13px]/5 text-fg-primary shadow-field outline-none placeholder:text-fg-faint focus:border-[rgba(51,97,255,0.7)]"
                            />
                        </label>
                    </div>

                    <p
                        v-if="form.errors.note || form.errors.login"
                        class="text-[12px]/4 text-status-down"
                    >
                        {{ form.errors.note ?? form.errors.login }}
                    </p>
                    <p
                        v-if="asking"
                        class="flex items-start gap-2.5 rounded-[8px] border-[0.5px] border-edge-card px-3 py-2.5 text-[12px]/[18px] text-fg-secondary"
                    >
                        <FilledIcon
                            name="info-fill"
                            :size="14"
                            class="mt-0.5 text-fg-muted"
                        />
                        {{
                            $t(
                                'Owners and admins get an email. Nothing changes on :name until one of them approves.',
                                { name: machineName },
                            )
                        }}
                    </p>
                </div>

                <div
                    class="flex items-center gap-2 border-t-[0.5px] border-edge bg-surface-card-footer py-3 pr-4 pl-5"
                >
                    <span class="grow" />
                    <DashButton :label="$t('Cancel')" @click="open = false" />
                    <DashButton
                        type="submit"
                        variant="primary"
                        :label="asking ? $t('Ask for access') : $t('Add key')"
                        :loading="form.processing"
                        :disabled="!form.key.trim()"
                    />
                </div>
            </form>
        </DialogContent>
    </Dialog>
</template>
