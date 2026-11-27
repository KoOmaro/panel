<script setup lang="ts">
import DashButton from '@/components/dash/DashButton.vue';
import IntegrationLogo from '@/components/integrations/IntegrationLogo.vue';
import type { Integration } from '@/components/integrations/integrations';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { workspacePath } from '@/lib/workspaceRoute';

const { integration, requestUrl } = defineProps<{
    integration: Integration | null;
    requestUrl: string | null;
}>();

const open = defineModel<boolean>('open', { default: false });
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="workspace-panel ds-modal gap-0 p-0 font-ui text-fg-primary sm:max-w-md"
        >
            <div v-if="integration" class="flex flex-col gap-4 p-5">
                <div class="flex items-start gap-3">
                    <IntegrationLogo
                        :logo="integration.logo"
                        :initials="integration.initials"
                    />
                    <div class="flex min-w-0 flex-col gap-1">
                        <DialogTitle
                            class="text-[15px]/5 font-medium tracking-[-0.01em] text-fg-primary"
                        >
                            {{
                                $t(':name isn’t available yet', {
                                    name: integration.name,
                                })
                            }}
                        </DialogTitle>
                        <DialogDescription
                            class="text-[13px]/5 text-fg-tertiary"
                        >
                            {{
                                requestUrl
                                    ? $t(
                                          'You can’t connect it from ServerOS yet. Ask for it and we’ll use the requests to pick what we build next.',
                                      )
                                    : $t(
                                          'You can’t connect it from ServerOS yet.',
                                      )
                            }}
                        </DialogDescription>
                    </div>
                </div>
                <p
                    v-if="integration.action?.hint"
                    class="rounded-[8px] border-[0.5px] border-edge-row bg-surface-well px-3 py-2.5 text-[13px]/5 text-fg-secondary"
                >
                    {{ integration.action.hint.text }}
                </p>
                <div class="flex flex-wrap justify-end gap-2">
                    <DashButton :label="$t('Close')" @click="open = false" />
                    <DashButton
                        v-if="integration.action?.hint?.href"
                        :href="workspacePath(integration.action.hint.href)"
                        :label="integration.action.hint.label ?? ''"
                    />
                    <DashButton
                        v-if="requestUrl"
                        variant="primary"
                        :href="requestUrl"
                        external
                        :label="$t('Request it')"
                    />
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
