<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, ref, watch } from 'vue';
import DashButton from '@/components/dash/DashButton.vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import type {
    MachineCloudServer,
    Operation,
} from '@/components/integrations/integrations';
import {
    sendJson,
    stopChangesIp,
} from '@/components/integrations/integrations';
import OperationSteps from '@/components/integrations/OperationSteps.vue';
import {
    operationActive,
    useOperation,
} from '@/components/integrations/useOperation';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { toast } from '@/lib/toast';
import { onWorkspace } from '@/lib/workspaceRoute';
import serverRoutes from '@/routes/workspace/machines/server';
import type { Workspace } from '@/types';

const { machine, cloud, kind } = defineProps<{
    machine: { id: number; name: string };
    cloud: MachineCloudServer;
    kind: 'reboot' | 'power_cycle';
}>();

const open = defineModel<boolean>('open', { default: false });
const page = usePage<{ workspace: Workspace }>();
const sending = ref(false);
const error = ref('');

const { operation, follow } = useOperation(
    () => page.props.workspace.slug,
    cloud.operation?.kind === 'power_cycle' && operationActive(cloud.operation)
        ? cloud.operation
        : null,
    () => router.reload({ only: ['cloudServer', 'machine', 'live'] }),
);

watch(open, (shown) => {
    if (!shown) return;
    error.value = '';
    if (!operationActive(operation.value)) operation.value = null;
});

const hard = computed(() => kind === 'power_cycle');
const following = computed(() => hard.value && operation.value !== null);
const ipChanges = computed(
    () => hard.value && !!cloud.rules.powerCycleSteps && stopChangesIp(cloud),
);

async function confirm(): Promise<void> {
    sending.value = true;
    error.value = '';
    const args = { workspace: page.props.workspace.slug, machine: machine.id };
    const route = hard.value
        ? serverRoutes.powerCycle(args)
        : serverRoutes.reboot(args);
    const result = await sendJson<{
        message: string;
        operation?: Operation | null;
    }>('POST', onWorkspace(route).url);
    sending.value = false;
    if (!result.ok) {
        error.value = result.message || trans('That didn’t work. Try again.');

        return;
    }
    toast.success(result.data.message);
    if (result.data.operation) {
        follow(result.data.operation);
        router.reload({ only: ['cloudServer'] });

        return;
    }
    open.value = false;
    router.reload({ only: ['machine', 'live'] });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="workspace-panel ds-modal font-ui text-foreground sm:max-w-md"
        >
            <DialogHeader>
                <DialogTitle>{{
                    hard
                        ? $t('Power cycle :name?', { name: machine.name })
                        : $t('Restart :name?', { name: machine.name })
                }}</DialogTitle>
                <DialogDescription>
                    {{
                        following
                            ? $t(
                                  'You can close this. It keeps going, and shows on the machine page.',
                              )
                            : hard && cloud.rules.powerCycleSteps
                              ? $t(
                                    ':provider turns it off straight away, like pulling the plug, and starts it again once it’s off. Use it when it’s stuck. Anything not yet written to disk is lost.',
                                    { provider: cloud.integration.name },
                                )
                              : hard
                                ? $t(
                                      ':provider cuts its power and starts it again, like pulling the plug. Use it when it’s stuck. Anything not yet written to disk is lost.',
                                      { provider: cloud.integration.name },
                                  )
                                : cloud.rules.gracefulRestart
                                  ? $t(
                                        ':provider asks its system to restart, like pressing the power button once. It works even when the daemon is offline.',
                                        { provider: cloud.integration.name },
                                    )
                                  : $t(
                                        ':provider restarts it straight away, like pressing the reset button. It works even when the daemon is offline. Anything not yet written to disk is lost.',
                                        { provider: cloud.integration.name },
                                    )
                    }}
                </DialogDescription>
            </DialogHeader>
            <template v-if="following && operation">
                <OperationSteps :operation="operation" />
                <div class="flex justify-end">
                    <DashButton :label="$t('Close')" @click="open = false" />
                </div>
            </template>
            <template v-else>
                <p
                    v-if="ipChanges"
                    class="flex items-start gap-2.5 rounded-[8px] border-[0.5px] border-[rgba(245,168,51,0.22)] bg-[rgba(245,168,51,0.07)] px-3 py-2.5 text-[12px]/[18px] text-fg-secondary"
                >
                    <FilledIcon
                        name="info-fill"
                        :size="14"
                        class="mt-0.5 text-[#f5a833]"
                    />
                    <span>{{
                        $t(
                            'It has no Elastic IP, so it comes back with a new public IP address. ServerOS keeps working with it, but DNS records and firewalls that use :ip need the new one.',
                            { ip: cloud.server.ipv4 ?? '' },
                        )
                    }}</span>
                </p>
                <p
                    v-if="error"
                    class="text-[12.5px]/[18px] text-status-down"
                    role="alert"
                >
                    {{ error }}
                </p>
                <div class="flex justify-end gap-2">
                    <DashButton :label="$t('Cancel')" @click="open = false" />
                    <DashButton
                        :variant="hard ? 'danger' : 'primary'"
                        :label="hard ? $t('Power cycle') : $t('Restart')"
                        :loading="sending"
                        @click="confirm"
                    />
                </div>
            </template>
        </DialogContent>
    </Dialog>
</template>
