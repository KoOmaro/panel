<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, ref, watch } from 'vue';
import DashButton from '@/components/dash/DashButton.vue';
import DashCheckbox from '@/components/dash/DashCheckbox.vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import type {
    CloudServer,
    MachineCloudServer,
    Operation,
    ServerSize,
    ServerTypeOption,
    SizeLimits,
} from '@/components/integrations/integrations';
import {
    money,
    sendJson,
    sizeLine,
    stopChangesIp,
} from '@/components/integrations/integrations';
import OperationSteps from '@/components/integrations/OperationSteps.vue';
import ServerTypeList from '@/components/integrations/ServerTypeList.vue';
import SizeFields from '@/components/integrations/SizeFields.vue';
import {
    operationActive,
    useOperation,
} from '@/components/integrations/useOperation';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { onWorkspace } from '@/lib/workspaceRoute';
import serverRoutes from '@/routes/workspace/machines/server';
import type { Workspace } from '@/types';

type Options = {
    server: CloudServer;
    current: ServerTypeOption | null;
    types: ServerTypeOption[];
    currency: string;
    needsPowerOff: boolean;
    busy: boolean;
    sizing?: 'types' | 'custom';
    limits?: SizeLimits;
};

const { machine, cloud } = defineProps<{
    machine: { id: number; name: string };
    cloud: MachineCloudServer;
}>();

const open = defineModel<boolean>('open', { default: false });
const page = usePage<{ workspace: Workspace }>();
const args = computed(() => ({
    workspace: page.props.workspace.slug,
    machine: machine.id,
}));

const options = ref<Options | null>(null);
const loading = ref(false);
const loadError = ref('');
const picked = ref<string | null>(null);
const growDisk = ref(false);
const saving = ref(false);
const error = ref('');
const errors = ref<Record<string, string>>({});
const size = ref<ServerSize>({ cores: 1, memoryGb: 1, diskGb: 1 });
const restart = ref(true);
const custom = computed(() => cloud.rules.sizing === 'custom');

const { operation, follow } = useOperation(
    () => page.props.workspace.slug,
    cloud.operation?.kind === 'resize' && operationActive(cloud.operation)
        ? cloud.operation
        : null,
    () => router.reload({ only: ['cloudServer', 'machine'] }),
);

const chosen = computed(
    () => options.value?.types.find((t) => t.name === picked.value) ?? null,
);
const smaller = computed(
    () =>
        !!chosen.value &&
        !!options.value?.current &&
        chosen.value.cores * 1000 + chosen.value.memoryGb <
            options.value.current.cores * 1000 + options.value.current.memoryGb,
);
const sizeChanged = computed(
    () =>
        !!options.value &&
        (size.value.cores !== options.value.server.cores ||
            Math.abs(size.value.memoryGb - options.value.server.memoryGb) >
                0.01 ||
            size.value.diskGb > (options.value.limits?.minDiskGb ?? 0)),
);
const restartNeeded = computed(
    () =>
        !!options.value?.limits &&
        options.value.server.status === 'running' &&
        ((size.value.cores !== options.value.server.cores &&
            !options.value.limits.liveCores) ||
            (Math.abs(size.value.memoryGb - options.value.server.memoryGb) >
                0.01 &&
                !options.value.limits.liveMemory)),
);
const canGrowDisk = computed(
    () =>
        cloud.rules.disk === 'optional' &&
        !!chosen.value &&
        !!options.value &&
        chosen.value.diskGb > options.value.server.diskGb,
);

async function load(): Promise<void> {
    loading.value = true;
    loadError.value = '';
    const result = await sendJson<Options>(
        'GET',
        onWorkspace(serverRoutes.types(args.value)).url,
    );
    loading.value = false;
    if (!result.ok) {
        loadError.value =
            result.message || trans('The sizes couldn’t be loaded. Try again.');

        return;
    }
    options.value = result.data;
    size.value = {
        cores: result.data.server.cores,
        memoryGb: result.data.server.memoryGb,
        diskGb: result.data.limits?.minDiskGb ?? result.data.server.diskGb,
    };
    picked.value =
        result.data.types.find(
            (t) =>
                result.data.current !== null &&
                t.cores * 1000 + t.memoryGb >
                    result.data.current.cores * 1000 +
                        result.data.current.memoryGb,
        )?.name ??
        result.data.types[0]?.name ??
        null;
}

watch(open, (shown) => {
    if (!shown) return;
    error.value = '';
    errors.value = {};
    growDisk.value = false;
    restart.value = true;
    if (!operationActive(operation.value)) {
        operation.value = null;
        void load();
    }
});

async function submit(): Promise<void> {
    if (custom.value ? !sizeChanged.value : !picked.value) return;
    saving.value = true;
    error.value = '';
    errors.value = {};
    const result = await sendJson<{ operation: Operation }>(
        'POST',
        onWorkspace(serverRoutes.resize(args.value)).url,
        custom.value
            ? {
                  cores: size.value.cores,
                  memory_gb: size.value.memoryGb,
                  disk_gb: size.value.diskGb,
                  restart: restart.value,
              }
            : {
                  type: picked.value,
                  grow_disk: canGrowDisk.value && growDisk.value,
              },
    );
    saving.value = false;
    if (!result.ok) {
        errors.value = result.errors;
        error.value =
            result.errors.form ??
            (custom.value && Object.keys(result.errors).length
                ? ''
                : result.message || trans('That didn’t work. Try again.'));

        return;
    }
    follow(result.data.operation);
    router.reload({ only: ['cloudServer'] });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="workspace-panel ds-modal flex max-h-[calc(100svh-2rem)] flex-col gap-0 overflow-hidden !rounded-[12px] p-0 font-ui text-fg-primary sm:max-w-[520px]"
            :show-close-button="false"
        >
            <div class="flex items-start gap-3 pt-[18px] pr-4 pb-4 pl-5">
                <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                    <DialogTitle
                        class="text-[16px]/6 font-medium tracking-[-0.01em] text-fg-primary"
                    >
                        {{ $t('Resize :name', { name: machine.name }) }}
                    </DialogTitle>
                    <DialogDescription class="text-[13px]/5 text-fg-tertiary">
                        {{
                            operation
                                ? $t(
                                      'You can close this. It keeps going, and shows on the machine page.',
                                  )
                                : custom
                                  ? $t(
                                        'Change its cores, memory and disk at :provider. Everything on it stays.',
                                        { provider: cloud.integration.name },
                                    )
                                  : $t(
                                        'Move it to another :provider server type. Its disk and everything on it stay.',
                                        { provider: cloud.integration.name },
                                    )
                        }}
                    </DialogDescription>
                </div>
                <kbd
                    class="flex h-5 items-center rounded-[5px] border-[0.5px] border-edge-strong px-1.5 font-ui text-[11px]/[14px] text-fg-muted"
                    >Esc</kbd
                >
            </div>

            <div v-if="operation" class="flex flex-col gap-4 px-5 pb-5">
                <OperationSteps :operation="operation" />
                <div class="flex justify-end">
                    <DashButton :label="$t('Close')" @click="open = false" />
                </div>
            </div>

            <form
                v-else
                class="flex min-h-0 flex-1 flex-col"
                @submit.prevent="submit"
            >
                <div
                    class="flex min-h-0 flex-1 flex-col gap-3.5 overflow-y-auto px-5 pb-[18px]"
                >
                    <p
                        v-if="loading"
                        class="text-[13px]/5 text-fg-muted"
                        role="status"
                    >
                        {{
                            $t('Asking :provider which sizes fit…', {
                                provider: cloud.integration.name,
                            })
                        }}
                    </p>
                    <div
                        v-else-if="loadError"
                        class="flex flex-col items-start gap-3"
                    >
                        <p class="text-[13px]/5 text-status-down" role="alert">
                            {{ loadError }}
                        </p>
                        <DashButton
                            size="sm"
                            :label="$t('Try again')"
                            @click="load"
                        />
                    </div>
                    <template v-else-if="options && custom">
                        <div
                            class="flex items-center gap-3 rounded-[10px] border-[0.5px] border-edge-card bg-surface-card px-3 py-2"
                        >
                            <span class="flex min-w-0 flex-1 flex-col">
                                <span class="text-[12px]/4 text-fg-muted">{{
                                    $t('Now')
                                }}</span>
                                <span
                                    class="text-[13px]/5 font-medium text-fg-primary"
                                    >{{ sizeLine(options.server) }}</span
                                >
                            </span>
                        </div>
                        <SizeFields
                            v-model="size"
                            :max-cores="options.limits?.maxCores"
                            :max-memory-gb="options.limits?.maxMemoryGb"
                            :min-disk-gb="options.limits?.minDiskGb"
                            :errors="errors"
                        />
                        <label
                            v-if="restartNeeded"
                            class="flex cursor-pointer items-start gap-2.5 text-[13px]/5"
                        >
                            <span class="pt-[3px]">
                                <DashCheckbox
                                    v-model="restart"
                                    :label="$t('Restart it now')"
                                />
                            </span>
                            <span class="flex flex-col">
                                <span class="text-fg-primary">{{
                                    $t('Restart it now')
                                }}</span>
                                <span class="text-[12px]/4 text-fg-muted">{{
                                    $t(
                                        'Leave it off to keep it running. The new size then applies the next time it restarts.',
                                    )
                                }}</span>
                            </span>
                        </label>
                        <p
                            class="flex items-start gap-2.5 rounded-[8px] border-[0.5px] border-[rgba(245,168,51,0.22)] bg-[rgba(245,168,51,0.07)] px-3 py-2.5 text-[12px]/[18px] text-fg-secondary"
                        >
                            <FilledIcon
                                name="info-fill"
                                :size="14"
                                class="mt-0.5 text-[#f5a833]"
                            />
                            <span>{{
                                restartNeeded && restart
                                    ? $t(
                                          ':provider changes cores, and memory without memory hotplug, only when a VM restarts. ServerOS restarts :name for you, so everything on it is down for a minute.',
                                          {
                                              name: machine.name,
                                              provider: cloud.integration.name,
                                          },
                                      )
                                    : restartNeeded
                                      ? $t(
                                            'The new size is saved now and applies the next time :name restarts.',
                                            { name: machine.name },
                                        )
                                      : $t(
                                            ':provider makes this change without a restart. A bigger disk also needs its partition grown inside the system, which cloud images do on their next boot.',
                                            {
                                                provider:
                                                    cloud.integration.name,
                                            },
                                        )
                            }}</span>
                        </p>
                        <p
                            v-if="options.busy"
                            class="text-[12.5px]/[18px] text-status-pending"
                        >
                            {{
                                $t(
                                    'Something else is changing this server. Wait for it to finish.',
                                )
                            }}
                        </p>
                    </template>
                    <template v-else-if="options">
                        <div
                            v-if="options.current"
                            class="flex items-center gap-3 rounded-[10px] border-[0.5px] border-edge-card bg-surface-card px-3 py-2"
                        >
                            <span class="flex min-w-0 flex-1 flex-col">
                                <span class="text-[12px]/4 text-fg-muted">{{
                                    $t('Now')
                                }}</span>
                                <span
                                    class="text-[13px]/5 font-medium text-fg-primary"
                                    >{{ options.current.label }}
                                    <span class="font-normal text-fg-tertiary"
                                        >· {{ sizeLine(options.current) }}</span
                                    ></span
                                >
                            </span>
                            <span
                                v-if="options.current.monthly"
                                class="text-[12.5px]/4 text-fg-tertiary tabular-nums"
                                >{{
                                    $t(':price a month', {
                                        price: money(
                                            options.current.monthly,
                                            options.currency,
                                        ),
                                    })
                                }}</span
                            >
                        </div>
                        <ServerTypeList
                            v-model="picked"
                            :types="options.types"
                            :currency="options.currency"
                            :label="$t('New server type')"
                        />
                        <label
                            v-if="canGrowDisk"
                            class="flex cursor-pointer items-start gap-2.5 text-[13px]/5"
                        >
                            <span class="pt-[3px]">
                                <DashCheckbox
                                    v-model="growDisk"
                                    :label="$t('Grow the disk too')"
                                />
                            </span>
                            <span class="flex flex-col">
                                <span class="text-fg-primary">{{
                                    $t('Grow the disk too')
                                }}</span>
                                <span class="text-[12px]/4 text-fg-muted">{{
                                    $t(
                                        'Gives it the new type’s disk for good, so it can’t move to a smaller type again. Leave it off to change only CPU and RAM.',
                                    )
                                }}</span>
                            </span>
                        </label>
                        <p
                            class="flex items-start gap-2.5 rounded-[8px] border-[0.5px] border-[rgba(245,168,51,0.22)] bg-[rgba(245,168,51,0.07)] px-3 py-2.5 text-[12px]/[18px] text-fg-secondary"
                        >
                            <FilledIcon
                                name="info-fill"
                                :size="14"
                                class="mt-0.5 text-[#f5a833]"
                            />
                            <span>{{
                                options.needsPowerOff
                                    ? $t(
                                          ':name shuts down, changes type and starts again. That takes a few minutes, and everything on it is down meanwhile.',
                                          { name: machine.name },
                                      )
                                    : $t(
                                          ':provider moves :name to the new plan in place, disk included, and restarts it. Everything on it is down for a few minutes.',
                                          {
                                              name: machine.name,
                                              provider: cloud.integration.name,
                                          },
                                      )
                            }}</span>
                        </p>
                        <p
                            v-if="options.needsPowerOff && stopChangesIp(cloud)"
                            class="text-[12px]/4 text-fg-muted"
                        >
                            {{
                                $t(
                                    'It has no Elastic IP, so it comes back with a new public IP address. ServerOS keeps working with it, but DNS records and firewalls that use :ip need the new one.',
                                    { ip: cloud.server.ipv4 ?? '' },
                                )
                            }}
                        </p>
                        <p
                            v-if="!cloud.rules.down"
                            class="text-[12px]/4 text-fg-muted"
                        >
                            {{
                                $t(
                                    ':provider only moves servers to bigger plans, so smaller ones aren’t listed.',
                                    { provider: cloud.integration.name },
                                )
                            }}
                        </p>
                        <p
                            v-if="
                                cloud.rules.disk === 'optional' &&
                                smaller &&
                                !growDisk
                            "
                            class="text-[12px]/4 text-fg-muted"
                        >
                            {{
                                $t(
                                    'Smaller types only work while the disk hasn’t been grown.',
                                )
                            }}
                        </p>
                        <p
                            v-if="options.busy"
                            class="text-[12.5px]/[18px] text-status-pending"
                        >
                            {{
                                $t(
                                    'Something else is changing this server. Wait for it to finish.',
                                )
                            }}
                        </p>
                    </template>
                    <p
                        v-if="error"
                        class="text-[12.5px]/[18px] text-status-down"
                        role="alert"
                    >
                        {{ error }}
                    </p>
                </div>
                <div
                    class="flex shrink-0 items-center justify-end gap-2 border-t-[0.5px] border-edge bg-surface-card-footer py-3 pr-4 pl-5"
                >
                    <DashButton :label="$t('Cancel')" @click="open = false" />
                    <DashButton
                        type="submit"
                        variant="primary"
                        :label="
                            chosen && !custom
                                ? $t('Resize to :type', { type: chosen.label })
                                : $t('Resize')
                        "
                        :loading="saving"
                        :disabled="
                            (custom ? !sizeChanged : !chosen) || options?.busy
                        "
                    />
                </div>
            </form>
        </DialogContent>
    </Dialog>
</template>
