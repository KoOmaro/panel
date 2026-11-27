<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import DashButton from '@/components/dash/DashButton.vue';
import DashCheckbox from '@/components/dash/DashCheckbox.vue';
import DashTray from '@/components/dash/DashTray.vue';
import FilterPills from '@/components/dash/FilterPills.vue';
import StatusBadge from '@/components/dash/StatusBadge.vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import OperationSteps from '@/components/integrations/OperationSteps.vue';
import type { TailscaleAccount } from '@/components/integrations/tailscale';
import {
    changeActive,
    tailnetRequest,
} from '@/components/integrations/tailscale';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { errorMessage } from '@/lib/errors';
import { timeAgo } from '@/lib/timeAgo';
import { toast } from '@/lib/toast';
import { onWorkspace, workspacePath } from '@/lib/workspaceRoute';
import tailnetRoutes from '@/routes/workspace/integrations/tailnet';
import type { Workspace } from '@/types';

const {
    account,
    integrationId,
    disabled = false,
} = defineProps<{
    account: TailscaleAccount | undefined;
    integrationId: number;
    disabled?: boolean;
}>();
const page = usePage<{ workspace: Workspace }>();
const args = computed(() => ({
    workspace: page.props.workspace.slug,
    integration: integrationId,
}));

type Filter = 'all' | 'machines' | 'other';
const filter = ref<Filter>('all');
const devices = computed(() => account?.devices ?? []);
const onServerOS = computed(() => devices.value.filter((d) => d.machine));
const shown = computed(() =>
    filter.value === 'machines'
        ? onServerOS.value
        : filter.value === 'other'
          ? devices.value.filter((d) => !d.machine)
          : devices.value,
);
const pills = computed(() => [
    { value: 'all' as const, label: trans('All'), count: devices.value.length },
    {
        value: 'machines' as const,
        label: trans('Machines'),
        count: onServerOS.value.length,
    },
    {
        value: 'other' as const,
        label: trans('Other devices'),
        count: devices.value.length - onServerOS.value.length,
    },
]);
const meta = computed(() =>
    account
        ? devices.value.length === 1
            ? trans('1 device, :machines on ServerOS', {
                  machines: String(onServerOS.value.length),
              })
            : trans(':count devices, :machines on ServerOS', {
                  count: String(devices.value.length),
                  machines: String(onServerOS.value.length),
              })
        : null,
);
const running = computed(() =>
    (account?.changes ?? []).filter((change) => changeActive(change)),
);
const failed = computed(() =>
    (account?.changes ?? []).filter((change) => change.state === 'failed'),
);

let timer: ReturnType<typeof setTimeout> | undefined;
function follow(): void {
    clearTimeout(timer);
    if (running.value.length === 0) return;
    timer = setTimeout(() => {
        router.reload({
            only: ['tailscale', 'usage', 'integration'],
            onFinish: follow,
        });
    }, 4000);
}
watch(() => running.value.length, follow, { immediate: true });
onBeforeUnmount(() => clearTimeout(timer));

const adding = ref(false);
const picked = ref<number[]>([]);
const sending = ref(false);
const addError = ref<string | null>(null);
const results = ref<
    { machine: number; name: string; ok: boolean; error?: string }[]
>([]);

watch(adding, (open) => {
    if (!open) return;
    picked.value = [];
    results.value = [];
    addError.value = null;
});

function toggle(id: number, on: boolean): void {
    picked.value = on
        ? [...new Set([...picked.value, id])]
        : picked.value.filter((value) => value !== id);
}

async function add(): Promise<void> {
    if (sending.value || picked.value.length === 0) return;
    sending.value = true;
    addError.value = null;
    try {
        const { body } = await tailnetRequest<{
            results?: {
                machine: number;
                name: string;
                ok: boolean;
                error?: string;
            }[];
            started?: number;
            message?: string;
        }>(onWorkspace(tailnetRoutes.join(args.value)).url, 'POST', {
            machines: picked.value,
        });
        results.value = body.results ?? [];
        if ((body.started ?? 0) > 0) {
            toast.success(
                body.started === 1
                    ? trans('Adding 1 machine to the tailnet.')
                    : trans('Adding :count machines to the tailnet.', {
                          count: String(body.started),
                      }),
            );
            router.reload({ only: ['tailscale', 'usage'] });
            if (results.value.every((result) => result.ok)) {
                adding.value = false;
            }
        } else if (results.value.length === 0) {
            addError.value =
                body.message ?? trans('That didn’t work. Try again.');
        }
    } catch (e) {
        addError.value = errorMessage(e);
    } finally {
        sending.value = false;
    }
}

const tags = ref('');
const savingTags = ref(false);
const tagsError = ref<string | null>(null);
watch(
    () => account?.tags,
    (next) => (tags.value = (next ?? []).join(', ')),
    { immediate: true },
);

async function saveTags(): Promise<void> {
    if (savingTags.value) return;
    savingTags.value = true;
    tagsError.value = null;
    const list = tags.value
        .split(/[\s,]+/)
        .map((tag) => tag.trim())
        .filter(Boolean);
    try {
        const { status, body } = await tailnetRequest<{
            tags?: string[];
            errors?: Record<string, string[]>;
            message?: string;
        }>(onWorkspace(tailnetRoutes.update(args.value)).url, 'PUT', {
            tags: list,
        });
        if (status >= 400) {
            tagsError.value =
                Object.values(body.errors ?? {})[0]?.[0] ??
                body.message ??
                trans('That didn’t work. Try again.');

            return;
        }
        toast.success(trans('Saved.'));
        router.reload({ only: ['tailscale', 'integration'] });
    } catch (e) {
        tagsError.value = errorMessage(e);
    } finally {
        savingTags.value = false;
    }
}

const fieldClass =
    'h-8 w-full rounded-[7px] border-[0.5px] border-edge-field bg-surface-field px-2.5 font-ui-mono text-[12.5px]/5 text-fg-primary shadow-field outline-none placeholder:text-fg-faint focus:border-[rgba(51,97,255,0.7)]';
</script>

<template>
    <DashTray
        icon="globe-fill"
        :title="$t('Devices')"
        :meta="meta"
        panel-class="py-0.5"
    >
        <template #actions>
            <FilterPills
                v-if="account && devices.length"
                v-model="filter"
                :options="pills"
                :label="$t('Show')"
                class="max-sm:hidden"
            />
            <DashButton
                size="sm"
                :icon="Plus"
                :label="$t('Add machines')"
                :disabled="!account || disabled"
                @click="adding = true"
            />
        </template>

        <div
            v-if="account === undefined"
            class="flex flex-col"
            role="status"
            :aria-label="$t('Loading devices')"
        >
            <div
                v-for="n in 3"
                :key="n"
                class="flex items-center gap-3 border-t-[0.5px] border-edge-tray-panel px-3.5 py-3 first:border-t-0"
            >
                <span
                    class="h-3 w-28 animate-pulse rounded bg-surface-badge motion-reduce:animate-none"
                />
                <span
                    class="h-3 w-44 animate-pulse rounded bg-surface-badge motion-reduce:animate-none"
                />
            </div>
        </div>
        <template v-else>
            <p
                v-if="account.error"
                class="border-b-[0.5px] border-edge-tray-panel px-3.5 py-2.5 text-[12.5px]/[18px] text-status-down"
                role="alert"
            >
                {{ account.error }}
                {{ $t('This is the list from the last time it worked.') }}
            </p>
            <div
                v-for="change in [...running, ...failed]"
                :key="change.id"
                class="flex flex-col gap-2.5 border-b-[0.5px] border-edge-tray-panel px-3.5 py-3"
            >
                <div class="flex items-center gap-2.5">
                    <Link
                        :href="
                            workspacePath(
                                `/machines/${change.machine.id}/settings`,
                            )
                        "
                        class="min-w-0 flex-1 truncate text-[13px]/5 font-medium text-fg-primary hover:underline"
                        >{{
                            change.kind === 'join'
                                ? $t('Adding :name', {
                                      name: change.machine.name,
                                  })
                                : $t('Taking :name off', {
                                      name: change.machine.name,
                                  })
                        }}</Link
                    >
                    <StatusBadge
                        :status="
                            change.state === 'failed' ? 'down' : 'deploying'
                        "
                        :label="
                            change.state === 'failed'
                                ? $t('Failed')
                                : $t('In progress')
                        "
                    />
                </div>
                <OperationSteps :operation="change" />
            </div>
            <ul v-if="shown.length" class="flex flex-col">
                <li
                    v-for="device in shown"
                    :key="device.id"
                    class="flex min-w-0 items-center gap-3 border-t-[0.5px] border-edge-tray-panel px-3.5 py-2.5 first:border-t-0"
                >
                    <span
                        class="size-1.5 shrink-0 rounded-full"
                        :class="
                            device.online
                                ? 'bg-status-live'
                                : 'bg-status-stopped'
                        "
                        :title="
                            device.online
                                ? $t('Connected')
                                : $t('Not connected')
                        "
                    />
                    <span class="sr-only">{{
                        device.online ? $t('Connected') : $t('Not connected')
                    }}</span>
                    <span class="flex min-w-0 flex-1 flex-col">
                        <span
                            class="truncate text-[13px]/5 font-medium tracking-[-0.01em] text-fg-primary"
                            >{{ device.hostname || device.name }}</span
                        >
                        <span class="truncate text-[12px]/4 text-fg-muted">{{
                            [
                                device.name,
                                device.os,
                                device.tags.join(', ') || null,
                                device.online
                                    ? null
                                    : device.lastSeen
                                      ? $t('seen :when', {
                                            when: timeAgo(device.lastSeen),
                                        })
                                      : null,
                            ]
                                .filter(Boolean)
                                .join(' · ')
                        }}</span>
                    </span>
                    <span
                        class="w-[132px] shrink-0 truncate font-ui-mono text-[11.5px]/4 text-fg-tertiary max-md:hidden"
                        >{{ device.address ?? '–' }}</span
                    >
                    <span
                        class="flex w-[150px] shrink-0 justify-end max-sm:w-auto"
                    >
                        <Link
                            v-if="device.machine"
                            :href="workspacePath(device.machine.href)"
                            class="flex max-w-full items-center gap-1.5 rounded-[6px] px-1.5 py-0.5 text-[13px]/5 text-fg-secondary hover:bg-surface-hover hover:text-fg-primary focus-visible:outline-2 focus-visible:outline-(--ds-ring)"
                            :title="
                                $t('This is :machine', {
                                    machine: device.machine.name,
                                })
                            "
                        >
                            <FilledIcon
                                name="machines-fill"
                                :size="14"
                                class="text-fg-muted"
                            />
                            <span class="truncate">{{
                                device.machine.name
                            }}</span>
                            <FilledIcon
                                name="chevron-right"
                                :size="13"
                                class="text-fg-muted"
                            />
                        </Link>
                        <span v-else class="text-[12px]/4 text-fg-muted">{{
                            device.external
                                ? $t('Shared with you')
                                : $t('Not in ServerOS')
                        }}</span>
                    </span>
                </li>
            </ul>
            <div v-else class="flex flex-col items-start gap-2 px-3.5 py-3">
                <p class="text-[13px]/5 text-fg-muted">
                    {{
                        devices.length
                            ? $t('Nothing here with this filter.')
                            : $t('No devices on this tailnet yet.')
                    }}
                </p>
            </div>
        </template>
    </DashTray>

    <DashTray
        v-if="account"
        icon="key-fill"
        :title="$t('Adding machines')"
        panel-class="gap-2.5 px-[18px] py-3.5"
    >
        <form class="flex flex-col gap-1.5" @submit.prevent="saveTags">
            <label
                for="tailscale-tags"
                class="text-[12.5px]/4 text-fg-tertiary"
                >{{ $t('Tags for new machines') }}</label
            >
            <div class="flex gap-2">
                <input
                    id="tailscale-tags"
                    v-model="tags"
                    type="text"
                    autocomplete="off"
                    spellcheck="false"
                    placeholder="tag:serveros"
                    :class="fieldClass"
                    :aria-invalid="!!tagsError"
                />
                <DashButton
                    type="submit"
                    :label="$t('Save')"
                    :loading="savingTags"
                />
            </div>
            <span
                v-if="tagsError"
                class="text-[12px]/4 text-status-down"
                role="alert"
                >{{ tagsError }}</span
            >
            <span class="text-[12px]/4 text-fg-muted">{{
                $t(
                    'Each one-off key gets these tags, so the machines it adds are owned by the tags instead of a person and don’t expire. Each tag must be in tagOwners in your tailnet’s access controls.',
                )
            }}</span>
        </form>
    </DashTray>

    <Dialog v-model:open="adding">
        <DialogContent
            class="workspace-panel ds-modal gap-0 !rounded-[12px] p-0 font-ui text-fg-primary sm:max-w-[480px]"
        >
            <form class="flex flex-col" @submit.prevent="add">
                <div class="flex flex-col gap-0.5 px-5 pt-[18px] pb-4">
                    <DialogTitle
                        class="text-[16px]/6 font-medium tracking-[-0.01em]"
                        >{{ $t('Add machines to the tailnet') }}</DialogTitle
                    >
                    <DialogDescription class="text-[13px]/5 text-fg-tertiary">
                        {{
                            $t(
                                'ServerOS installs Tailscale on each one with a one-off key that runs out in minutes, then links the device that turns up.',
                            )
                        }}
                    </DialogDescription>
                </div>
                <ul
                    v-if="account?.machines.length"
                    class="mx-5 mb-4 flex max-h-[320px] flex-col overflow-y-auto rounded-[10px] border-[0.5px] border-edge-tray-panel bg-surface-tray-panel"
                >
                    <li
                        v-for="machine in account.machines"
                        :key="machine.id"
                        class="group/row flex items-start gap-3 border-t-[0.5px] border-edge-tray-panel px-3 py-2.5 first:border-t-0"
                    >
                        <DashCheckbox
                            class="mt-0.5"
                            :class="{
                                'pointer-events-none opacity-40':
                                    machine.blocked,
                            }"
                            :model-value="picked.includes(machine.id)"
                            :label="machine.name"
                            :disabled="machine.blocked !== null"
                            @update:model-value="
                                (on: boolean) =>
                                    machine.blocked === null &&
                                    toggle(machine.id, on)
                            "
                        />
                        <span class="flex min-w-0 flex-1 flex-col">
                            <span
                                class="truncate text-[13px]/5 text-fg-primary"
                                :class="{ 'opacity-60': machine.blocked }"
                                >{{ machine.name }}</span
                            >
                            <span
                                v-if="machine.blocked"
                                class="text-[12px]/4 text-fg-muted"
                                >{{ machine.blocked }}
                                {{
                                    $t(
                                        'Its settings page has the commands to run instead.',
                                    )
                                }}</span
                            >
                            <span
                                v-for="result in results.filter(
                                    (r) => r.machine === machine.id && !r.ok,
                                )"
                                :key="result.machine"
                                class="text-[12px]/4 text-status-down"
                                role="alert"
                                >{{ result.error }}</span
                            >
                        </span>
                    </li>
                </ul>
                <p v-else class="px-5 pb-4 text-[13px]/5 text-fg-muted">
                    {{ $t('Every machine is on this tailnet already.') }}
                </p>
                <p
                    v-if="addError"
                    class="px-5 pb-3 text-[12.5px]/[18px] text-status-down"
                    role="alert"
                >
                    {{ addError }}
                </p>
                <div
                    class="flex justify-end gap-2 border-t-[0.5px] border-edge bg-surface-card-footer py-3 pr-4 pl-5"
                >
                    <DashButton :label="$t('Cancel')" @click="adding = false" />
                    <DashButton
                        type="submit"
                        variant="primary"
                        :label="
                            picked.length > 1
                                ? $t('Add :count machines', {
                                      count: String(picked.length),
                                  })
                                : $t('Add to tailnet')
                        "
                        :loading="sending"
                        :disabled="picked.length === 0"
                    />
                </div>
            </form>
        </DialogContent>
    </Dialog>
</template>
