<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';
import DashButton from '@/components/dash/DashButton.vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { toast } from '@/lib/toast';

const open = defineModel<boolean>('open', { default: false });

const {
    title,
    description,
    commands,
    expiresAt = null,
    reason = null,
} = defineProps<{
    title: string;
    description: string;
    commands: string[];
    expiresAt?: string | null;
    reason?: string | null;
}>();

const expires = computed(() =>
    expiresAt
        ? new Date(expiresAt).toLocaleTimeString([], {
              hour: '2-digit',
              minute: '2-digit',
          })
        : null,
);

async function copy(): Promise<void> {
    try {
        await navigator.clipboard.writeText(commands.join('\n'));
        toast.success(trans('Copied.'));
    } catch {
        toast.error(trans('Couldn’t copy. Select it and copy it yourself.'));
    }
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="workspace-panel ds-modal gap-0 !rounded-[12px] p-0 font-ui text-fg-primary sm:max-w-[560px]"
        >
            <div class="flex flex-col gap-0.5 px-5 pt-[18px] pb-4">
                <DialogTitle
                    class="text-[16px]/6 font-medium tracking-[-0.01em]"
                    >{{ title }}</DialogTitle
                >
                <DialogDescription class="text-[13px]/5 text-fg-tertiary">
                    {{ description }}
                </DialogDescription>
            </div>
            <div class="flex flex-col gap-3 px-5 pb-[18px]">
                <p
                    v-if="reason"
                    class="flex items-start gap-2 rounded-[8px] border-[0.5px] border-[rgba(240,148,60,0.25)] bg-[rgba(240,148,60,0.07)] px-3 py-2 text-[12.5px]/[18px] text-fg-secondary"
                >
                    <FilledIcon
                        name="info-fill"
                        :size="14"
                        class="mt-0.5 shrink-0 text-status-pending"
                    />
                    {{ reason }}
                </p>
                <pre
                    class="overflow-x-auto rounded-[8px] border-[0.5px] border-edge-field bg-surface-field px-3 py-2.5 font-ui-mono text-[12px]/[19px] break-all whitespace-pre-wrap text-fg-primary select-all"
                    >{{ commands.join('\n') }}</pre>
                <p v-if="expires" class="text-[12px]/4 text-fg-muted">
                    {{
                        $t(
                            'The key works once and runs out at :time. ServerOS doesn’t keep it, and links the machine as soon as it joins.',
                            { time: expires },
                        )
                    }}
                </p>
            </div>
            <div
                class="flex justify-end gap-2 border-t-[0.5px] border-edge bg-surface-card-footer py-3 pr-4 pl-5"
            >
                <DashButton :label="$t('Close')" @click="open = false" />
                <DashButton
                    variant="primary"
                    icon="copy"
                    :label="$t('Copy commands')"
                    @click="copy"
                />
            </div>
        </DialogContent>
    </Dialog>
</template>
