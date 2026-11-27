<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import BrandLogo from '@/components/dash/BrandLogo.vue';
import DashButton from '@/components/dash/DashButton.vue';
import DashTray from '@/components/dash/DashTray.vue';
import OperationSteps from '@/components/integrations/OperationSteps.vue';
import TailnetCommands from '@/components/integrations/TailnetCommands.vue';
import type {
    MachineTailnet,
    TailnetChange,
} from '@/components/integrations/tailscale';
import {
    changeActive,
    tailnetRequest,
} from '@/components/integrations/tailscale';
import SettingsRow from '@/components/machines/settings/SettingsRow.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { SelectMenu } from '@/components/ui/select';
import { errorMessage } from '@/lib/errors';
import { timeAgo } from '@/lib/timeAgo';
import { toast } from '@/lib/toast';
import { onWorkspace } from '@/lib/workspaceRoute';
import integrationRoutes from '@/routes/workspace/integrations';
import tailnetRoutes from '@/routes/workspace/machines/tailnet';
import type { Workspace } from '@/types';

const props = defineProps<{
    machineId: number;
    machineName: string;
    tailnet: MachineTailnet;
}>();
const page = usePage<{ workspace: Workspace }>();

const state = ref<MachineTailnet>(props.tailnet);
watch(
    () => props.tailnet,
    (next) => (state.value = next),
);

const args = computed(() => ({
    workspace: page.props.workspace.slug,
    machine: props.machineId,
}));
const accountId = ref<number | null>(props.tailnet.accounts[0]?.id ?? null);
const account = computed(
    () =>
        state.value.accounts.find((a) => a.id === accountId.value) ??
        state.value.accounts[0] ??
        null,
);
const accountOptions = computed(() =>
    state.value.accounts.map((a) => ({ value: a.id, label: a.label })),
);
const device = computed(() => state.value.device);
const change = computed<TailnetChange | null>(() => state.value.change);
const busy = computed(() => changeActive(change.value));
const accountUrl = computed(() => {
    const id = device.value?.integration.id ?? account.value?.id;

    return id
        ? onWorkspace(
              integrationRoutes.show({
                  workspace: page.props.workspace.slug,
                  integration: id,
              }),
          ).url
        : null;
});
const tagLine = computed(() =>
    (account.value?.tags ?? []).length
        ? (account.value?.tags ?? []).join(', ')
        : trans('no tags'),
);
const seen = computed(() => {
    if (!device.value) return '';
    if (device.value.online) return trans('Connected now');

    return device.value.lastSeen
        ? trans('Last seen :when', { when: timeAgo(device.value.lastSeen) })
        : trans('Not connected');
});
const matched = computed(() => {
    const how = device.value?.linkedBy;
    if (!how) return '';

    return {
        joined: trans('Put on the tailnet by ServerOS'),
        address: trans('Matched by its address'),
        hostname: trans('Matched by its hostname'),
    }[how];
});
const phaseLine = computed(() => {
    const phase = change.value?.phase;
    if (!busy.value || !phase) return null;

    return (
        {
            fetch: trans('Getting ready'),
            env: trans('Writing its settings'),
            build: trans('Pulling the Tailscale image and starting it'),
            verify: trans('Checking it started'),
        }[phase] ?? null
    );
});

const working = ref<'join' | 'leave' | 'commands' | null>(null);
const error = ref<string | null>(null);
const commandsOpen = ref(false);
const commands = ref<string[]>([]);
const commandsExpire = ref<string | null>(null);
const commandsReason = ref<string | null>(null);
const commandsKind = ref<'join' | 'leave'>('join');
const confirming = ref(false);
let poll: ReturnType<typeof setTimeout> | undefined;

function follow(): void {
    clearTimeout(poll);
    if (!busy.value) return;
    poll = setTimeout(async () => {
        try {
            const { body } = await tailnetRequest<{
                tailnet: MachineTailnet | null;
            }>(onWorkspace(tailnetRoutes.show(args.value)).url, 'GET');
            const was = state.value.change;
            if (body.tailnet) state.value = body.tailnet;
            const now = state.value.change;
            if (was && changeActive(was) && !changeActive(now)) {
                finished(now);

                return;
            }
        } catch {
            // Try again on the next tick.
        }
        follow();
    }, 3000);
}

function finished(done: TailnetChange | null): void {
    if (done?.state === 'done') {
        toast.success(
            done.kind === 'join'
                ? trans(':name is on the tailnet as :address.', {
                      name: props.machineName,
                      address: done.result.address ?? done.result.name ?? '',
                  })
                : trans(':name is off the tailnet.', {
                      name: props.machineName,
                  }),
        );
    }
    router.reload({ only: ['tailnet'] });
}

watch(busy, (active) => {
    if (active) follow();
});
onMounted(follow);
onBeforeUnmount(() => clearTimeout(poll));

async function join(): Promise<void> {
    if (working.value || !account.value) return;
    working.value = 'join';
    error.value = null;
    try {
        const { status, body } = await tailnetRequest<{
            tailnet?: MachineTailnet;
            error?: string;
            manual?: boolean;
        }>(onWorkspace(tailnetRoutes.store(args.value)).url, 'POST', {
            integration: account.value.id,
        });
        if (status === 409) {
            if (body.manual) {
                await showCommands(body.error ?? null);
            } else {
                error.value =
                    body.error ?? trans('That didn’t work. Try again.');
            }

            return;
        }
        if (body.tailnet) state.value = body.tailnet;
    } catch (e) {
        error.value = errorMessage(e);
    } finally {
        working.value = null;
    }
}

async function showCommands(reason: string | null = null): Promise<void> {
    if (!account.value) return;
    working.value = 'commands';
    error.value = null;
    try {
        const { status, body } = await tailnetRequest<{
            commands?: string[];
            expiresAt?: string | null;
            error?: string;
        }>(onWorkspace(tailnetRoutes.commands(args.value)).url, 'POST', {
            integration: account.value.id,
        });
        if (status === 409 || !body.commands) {
            error.value = body.error ?? trans('That didn’t work. Try again.');

            return;
        }
        commands.value = body.commands;
        commandsExpire.value = body.expiresAt ?? null;
        commandsReason.value = reason;
        commandsKind.value = 'join';
        commandsOpen.value = true;
        router.reload({ only: ['tailnet'] });
    } catch (e) {
        error.value = errorMessage(e);
    } finally {
        working.value = null;
    }
}

async function leave(): Promise<void> {
    if (working.value || !device.value) return;
    working.value = 'leave';
    error.value = null;
    try {
        const { status, body } = await tailnetRequest<{
            tailnet?: MachineTailnet;
            commands?: string[] | null;
            error?: string;
        }>(onWorkspace(tailnetRoutes.destroy(args.value)).url, 'DELETE', {
            integration: device.value.integration.id,
        });
        if (status === 409) {
            error.value = body.error ?? trans('That didn’t work. Try again.');

            return;
        }
        confirming.value = false;
        if (body.tailnet) state.value = body.tailnet;
        if (body.commands?.length) {
            commands.value = body.commands;
            commandsExpire.value = null;
            commandsReason.value = null;
            commandsKind.value = 'leave';
            commandsOpen.value = true;
            toast.success(
                trans('Deleted :name from the tailnet.', {
                    name: props.machineName,
                }),
            );
        }
    } catch (e) {
        error.value = errorMessage(e);
    } finally {
        working.value = null;
    }
}

async function copy(text: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(text);
        toast.success(trans('Copied.'));
    } catch {
        toast.error(trans('Couldn’t copy. Select it and copy it yourself.'));
    }
}
</script>

<template>
    <DashTray
        id="tailscale"
        panel-class="gap-1 px-[18px] pt-3 pb-1"
        :title="$t('Tailscale')"
        :meta="device?.integration.label ?? account?.label ?? null"
    >
        <template #icon>
            <BrandLogo name="tailscale" :size="15" class="text-fg-muted" />
        </template>
        <template #actions>
            <Link
                v-if="accountUrl"
                :href="accountUrl"
                class="flex items-center gap-1 text-[12.5px]/4 text-fg-muted hover:text-fg-primary"
                >{{ $t('Account') }}</Link
            >
        </template>

        <div
            v-if="change && (busy || change.state === 'failed')"
            class="flex flex-col gap-2.5 border-b-[0.5px] border-edge-tabs py-3 last:border-b-0"
        >
            <OperationSteps :operation="change" />
            <p v-if="phaseLine" class="text-[12px]/4 text-fg-muted">
                {{ phaseLine }}
            </p>
            <p v-if="busy && change.manual" class="text-[12px]/4 text-fg-muted">
                {{
                    $t(
                        'Waiting for :name to join with the commands. It’s linked as soon as it does.',
                        { name: machineName },
                    )
                }}
            </p>
            <div
                v-if="change.state === 'failed' && change.kind === 'join'"
                class="flex flex-wrap gap-1.5"
            >
                <DashButton
                    v-if="change.canRunCommands"
                    size="sm"
                    icon="terminal-fill"
                    :label="$t('Show the commands')"
                    :loading="working === 'commands'"
                    @click="showCommands(change.error)"
                />
                <DashButton
                    v-if="!state.blocked"
                    size="sm"
                    :label="$t('Try again')"
                    :loading="working === 'join'"
                    @click="join"
                />
            </div>
        </div>
        <p
            v-if="change?.state === 'done' && change.result.cleanup"
            class="border-b-[0.5px] border-edge-tabs py-3 text-[12.5px]/[18px] text-fg-tertiary"
        >
            {{ change.result.cleanup }}
        </p>

        <template v-if="device">
            <SettingsRow>
                <template #label>
                    <span
                        class="text-[13px]/5 tracking-[-0.005em] text-fg-primary"
                        >{{ $t('Tailnet name') }}</span
                    >
                    <span
                        class="truncate font-ui-mono text-[12px]/[18px] text-fg-tertiary"
                        >{{ device.name }}</span
                    >
                </template>
                <DashButton
                    size="sm"
                    icon="copy"
                    :aria-label="$t('Copy the tailnet name')"
                    @click="copy(device.name)"
                />
            </SettingsRow>
            <SettingsRow>
                <template #label>
                    <span
                        class="text-[13px]/5 tracking-[-0.005em] text-fg-primary"
                        >{{ $t('Tailnet address') }}</span
                    >
                    <span
                        class="truncate font-ui-mono text-[12px]/[18px] text-fg-tertiary"
                        >{{
                            device.addresses.join(', ') || $t('No address yet')
                        }}</span
                    >
                </template>
                <DashButton
                    v-if="device.address"
                    size="sm"
                    icon="copy"
                    :aria-label="$t('Copy the address')"
                    @click="copy(device.address)"
                />
            </SettingsRow>
            <SettingsRow
                :label="seen"
                :hint="
                    [
                        matched,
                        device.tags.length ? device.tags.join(', ') : null,
                        device.version
                            ? $t('Tailscale :version', {
                                  version: device.version,
                              })
                            : null,
                    ]
                        .filter(Boolean)
                        .join(' · ')
                "
            />
            <SettingsRow
                :label="$t('Remove from tailnet')"
                :hint="
                    state.installed === 'serveros'
                        ? $t(
                              'Logs it out, removes the Tailscale ServerOS installed and deletes the device.',
                          )
                        : $t(
                              'Deletes the device from the tailnet. You get the command to log it out on the machine.',
                          )
                "
            >
                <DashButton
                    size="sm"
                    class="text-status-down!"
                    :label="$t('Remove')"
                    :disabled="busy"
                    @click="confirming = true"
                />
            </SettingsRow>
        </template>

        <template v-else-if="!busy">
            <SettingsRow
                :label="$t('Add to tailnet')"
                :hint="
                    $t(
                        'Installs Tailscale on :name as :hostname with a one-off key, tagged :tags, so it gets an address only your tailnet can reach.',
                        {
                            name: machineName,
                            hostname: state.hostname,
                            tags: tagLine,
                        },
                    )
                "
            >
                <SelectMenu
                    v-if="accountOptions.length > 1"
                    v-model="accountId"
                    :options="accountOptions"
                    :aria-label="$t('Tailnet')"
                    class="w-44"
                />
                <DashButton
                    v-if="!state.blocked"
                    size="sm"
                    :label="$t('Add to tailnet')"
                    :loading="working === 'join'"
                    :disabled="!account || account.failing"
                    @click="join"
                />
                <DashButton
                    v-else
                    size="sm"
                    icon="terminal-fill"
                    :label="$t('Show the commands')"
                    :loading="working === 'commands'"
                    :disabled="!account || account.failing"
                    @click="showCommands(state.blocked)"
                />
            </SettingsRow>
            <SettingsRow
                v-if="state.blocked"
                :label="$t('ServerOS can’t install it right now')"
                :hint="state.blocked"
            />
        </template>

        <p
            v-if="error"
            class="py-2.5 text-[12.5px]/[18px] text-status-down"
            role="alert"
        >
            {{ error }}
        </p>
    </DashTray>

    <TailnetCommands
        v-model:open="commandsOpen"
        :title="
            commandsKind === 'join'
                ? $t('Add :name to the tailnet yourself', { name: machineName })
                : $t('Clean up :name', { name: machineName })
        "
        :description="
            commandsKind === 'join'
                ? $t(
                      'Run these on :name as a user who can sudo. Tailscale’s install script works on most Linux distributions.',
                      { name: machineName },
                  )
                : $t(
                      'The device is off the tailnet. Run these on :name to log Tailscale out there too.',
                      { name: machineName },
                  )
        "
        :commands="commands"
        :expires-at="commandsExpire"
        :reason="commandsReason"
    />

    <Dialog v-model:open="confirming">
        <DialogContent
            class="workspace-panel ds-modal font-ui text-foreground sm:max-w-md"
        >
            <div class="flex flex-col gap-1.5">
                <DialogTitle>{{
                    $t('Take :name off the tailnet?', { name: machineName })
                }}</DialogTitle>
                <DialogDescription>
                    {{
                        $t(
                            'Anything that reaches it at :address stops working. Its public address and apps aren’t touched.',
                            { address: device?.address ?? device?.name ?? '' },
                        )
                    }}
                </DialogDescription>
            </div>
            <p
                v-if="error"
                class="text-[12.5px]/[18px] text-status-down"
                role="alert"
            >
                {{ error }}
            </p>
            <div class="flex justify-end gap-2">
                <DashButton :label="$t('Cancel')" @click="confirming = false" />
                <DashButton
                    variant="danger"
                    :label="$t('Remove from tailnet')"
                    :loading="working === 'leave'"
                    @click="leave"
                />
            </div>
        </DialogContent>
    </Dialog>
</template>
