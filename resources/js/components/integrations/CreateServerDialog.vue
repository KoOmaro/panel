<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, ref, watch } from 'vue';
import DashButton from '@/components/dash/DashButton.vue';
import DashCheckbox from '@/components/dash/DashCheckbox.vue';
import FilterPills from '@/components/dash/FilterPills.vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import IntegrationLogo from '@/components/integrations/IntegrationLogo.vue';
import type {
    Operation,
    ServerPlans,
    ServerSize,
} from '@/components/integrations/integrations';
import { money, sendJson } from '@/components/integrations/integrations';
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
import { SelectMenu } from '@/components/ui/select';
import { onWorkspace } from '@/lib/workspaceRoute';
import integrationRoutes from '@/routes/workspace/integrations';
import machinesRoutes from '@/routes/workspace/machines';
import type { Workspace } from '@/types';

const { integration } = defineProps<{
    integration: {
        id: number;
        label: string;
        providerName: string;
        logo: string;
    };
}>();

const emit = defineEmits<{ made: [operation: Operation] }>();
const open = defineModel<boolean>('open', { default: false });
const page = usePage<{ workspace: Workspace }>();
const slug = computed(() => page.props.workspace.slug);

const plans = ref<ServerPlans | null>(null);
const custom = computed(() => plans.value?.rules?.sizing === 'custom');
const size = ref<ServerSize>({ cores: 2, memoryGb: 2, diskGb: 20 });
const user = ref('');
const sshKeys = ref('');
const network = ref<'dhcp' | 'static'>('dhcp');
const address = ref('');
const gateway = ref('');
const loading = ref(false);
const loadError = ref('');
const saving = ref(false);
const errors = ref<Record<string, string>>({});
const name = ref('');
const location = ref<string | null>(null);
const type = ref<string | null>(null);
const image = ref<string | null>(null);
const keys = ref<Record<string, boolean>>({});
const family = ref<'shared' | 'arm' | 'dedicated'>('shared');

const { operation, follow } = useOperation(
    () => slug.value,
    null,
    (done) => {
        if (done.state === 'done') {
            router.reload({ only: ['servers', 'operations', 'usage'] });
        }
    },
);

const familyOf = (t: { cpu: string; architecture: string }) =>
    t.cpu === 'dedicated'
        ? 'dedicated'
        : t.architecture === 'arm'
          ? 'arm'
          : 'shared';

const typesHere = computed(() =>
    (plans.value?.types ?? []).filter(
        (t) =>
            location.value !== null &&
            (t.locations ?? []).includes(location.value),
    ),
);
const families = computed(() =>
    (
        [
            { value: 'shared', label: trans('Shared') },
            { value: 'arm', label: trans('Arm') },
            { value: 'dedicated', label: trans('Dedicated') },
        ] as const
    )
        .map((option) => ({
            ...option,
            count: typesHere.value.filter((t) => familyOf(t) === option.value)
                .length,
        }))
        .filter((option) => option.count > 0),
);
const shownTypes = computed(() =>
    typesHere.value.filter((t) => familyOf(t) === family.value),
);
const chosenType = computed(
    () => plans.value?.types.find((t) => t.name === type.value) ?? null,
);
const images = computed(() =>
    custom.value
        ? (plans.value?.images ?? [])
        : (plans.value?.images ?? []).filter(
              (i) =>
                  i.architecture === (chosenType.value?.architecture ?? 'x86'),
          ),
);
const imageOptions = computed(() =>
    images.value.map((i) => {
        const name =
            custom.value && i.location ? `${i.label} · ${i.location}` : i.label;

        return {
            value: i.id,
            label: i.recommended
                ? trans(':name (recommended)', { name })
                : name,
        };
    }),
);
const template = computed(
    () => images.value.find((i) => i.id === image.value) ?? null,
);
const templateNote = computed(() => {
    const t = template.value;
    if (!custom.value || !t) return null;
    if (!t.agent) {
        return trans(
            'It has no QEMU guest agent, so once it’s up you’ll get one command to run on it as root.',
        );
    }
    if (!t.cloudInit) {
        return trans(
            'It has no cloud-init drive, so the user, keys and network below aren’t applied.',
        );
    }

    return trans(
        'It has the QEMU guest agent and a cloud-init drive, so it installs ServerOS on its own.',
    );
});
const price = computed(() => {
    const t = chosenType.value;
    if (!t || !location.value || !plans.value) return null;
    const amount = t.prices?.[location.value]?.monthly;

    return amount === undefined ? null : money(amount, plans.value.currency);
});

watch(location, () => {
    if (!families.value.some((f) => f.value === family.value)) {
        family.value = families.value[0]?.value ?? 'shared';
    }
    if (!shownTypes.value.some((t) => t.name === type.value)) {
        type.value = shownTypes.value[0]?.name ?? null;
    }
});
watch(family, () => {
    if (!shownTypes.value.some((t) => t.name === type.value)) {
        type.value = shownTypes.value[0]?.name ?? null;
    }
});
watch(template, (picked) => {
    if (custom.value && picked?.size) {
        size.value = { ...picked.size };
    }
});
watch(chosenType, () => {
    if (!images.value.some((i) => i.id === image.value)) {
        image.value =
            images.value.find((i) => i.recommended)?.id ??
            images.value[0]?.id ??
            null;
    }
});

async function load(): Promise<void> {
    loading.value = true;
    loadError.value = '';
    const result = await sendJson<ServerPlans>(
        'GET',
        onWorkspace(
            integrationRoutes.servers.options({
                workspace: slug.value,
                integration: integration.id,
            }),
        ).url,
    );
    loading.value = false;
    if (!result.ok) {
        loadError.value =
            result.message ||
            trans('The options couldn’t be loaded. Try again.');

        return;
    }
    plans.value = result.data;
    keys.value = Object.fromEntries(result.data.keys.map((k) => [k.id, true]));
    location.value = result.data.locations[0]?.name ?? null;
    if (custom.value) {
        image.value =
            images.value.find((i) => i.recommended)?.id ??
            images.value[0]?.id ??
            null;
    }
}

watch(open, (shown) => {
    if (!shown) return;
    errors.value = {};
    if (!operationActive(operation.value)) {
        operation.value = null;
        name.value = '';
    }
    if (!plans.value) void load();
});

async function submit(): Promise<void> {
    if (!image.value || (!custom.value && (!type.value || !location.value))) {
        return;
    }
    saving.value = true;
    errors.value = {};
    const result = await sendJson<{ operation: Operation }>(
        'POST',
        onWorkspace(
            integrationRoutes.servers.store({
                workspace: slug.value,
                integration: integration.id,
            }),
        ).url,
        custom.value
            ? {
                  name: name.value.trim(),
                  image: image.value,
                  cores: size.value.cores,
                  memory_gb: size.value.memoryGb,
                  disk_gb: size.value.diskGb,
                  user: user.value.trim() || null,
                  ssh_keys: sshKeys.value.trim() || null,
                  address:
                      network.value === 'static' ? address.value.trim() : null,
                  gateway:
                      network.value === 'static' ? gateway.value.trim() : null,
              }
            : {
                  name: name.value.trim(),
                  location: location.value,
                  type: type.value,
                  image: image.value,
                  keys: Object.entries(keys.value)
                      .filter(([, on]) => on)
                      .map(([id]) => id),
              },
    );
    saving.value = false;
    if (!result.ok) {
        errors.value = {
            ...result.errors,
            form:
                result.errors.form ??
                (Object.keys(result.errors).length
                    ? ''
                    : result.message || trans('That didn’t work. Try again.')),
        };

        return;
    }
    follow(result.data.operation);
    emit('made', result.data.operation);
}

const fieldClass =
    'h-8 w-full rounded-[7px] border-[0.5px] border-edge-field bg-surface-field px-2.5 text-[13px]/5 text-fg-primary shadow-field outline-none placeholder:text-fg-faint focus:border-[rgba(51,97,255,0.7)]';
const labelClass = 'text-[12.5px]/4 text-fg-tertiary';
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="workspace-panel ds-modal flex max-h-[calc(100svh-2rem)] flex-col gap-0 overflow-hidden !rounded-[12px] p-0 font-ui text-fg-primary sm:max-w-[560px]"
            :show-close-button="false"
        >
            <div class="flex items-start gap-3 pt-[18px] pr-4 pb-4 pl-5">
                <IntegrationLogo :logo="integration.logo" />
                <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                    <DialogTitle
                        class="text-[16px]/6 font-medium tracking-[-0.01em] text-fg-primary"
                    >
                        {{
                            operation
                                ? operation.name
                                : $t('New server in :name', {
                                      name: integration.label,
                                  })
                        }}
                    </DialogTitle>
                    <DialogDescription class="text-[13px]/5 text-fg-tertiary">
                        {{
                            operation
                                ? $t(
                                      'You can close this. It keeps going, and shows on the account page.',
                                  )
                                : custom
                                  ? $t(
                                        ':provider can’t take a startup script, so ServerOS installs itself through the QEMU guest agent once the VM is up, and it joins :workspace. Without the agent, you get one command to run.',
                                        {
                                            provider: integration.providerName,
                                            workspace:
                                                page.props.workspace.name,
                                        },
                                    )
                                  : $t(
                                        'It installs ServerOS as it starts, and joins :workspace on its own.',
                                        {
                                            workspace:
                                                page.props.workspace.name,
                                        },
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
                <div class="flex justify-end gap-2">
                    <DashButton :label="$t('Close')" @click="open = false" />
                    <DashButton
                        v-if="operation.state === 'done' && operation.machineId"
                        variant="primary"
                        :href="
                            onWorkspace(
                                machinesRoutes.show({
                                    workspace: slug,
                                    machine: operation.machineId,
                                }),
                            ).url
                        "
                        :label="$t('Open :name', { name: operation.name })"
                    />
                </div>
            </div>

            <form
                v-else
                class="flex min-h-0 flex-1 flex-col"
                @submit.prevent="submit"
            >
                <div
                    v-if="loading"
                    class="px-5 pb-6 text-[13px]/5 text-fg-muted"
                    role="status"
                >
                    {{
                        $t('Asking :name what it has…', {
                            name: integration.providerName,
                        })
                    }}
                </div>
                <div
                    v-else-if="loadError"
                    class="flex flex-col items-start gap-3 px-5 pb-5"
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
                <div
                    v-else-if="plans"
                    class="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto px-5 pb-[18px]"
                >
                    <label class="flex flex-col gap-1.5">
                        <span :class="labelClass">{{ $t('Name') }}</span>
                        <input
                            v-model="name"
                            type="text"
                            maxlength="63"
                            required
                            autofocus
                            autocomplete="off"
                            spellcheck="false"
                            :placeholder="$t('web-3')"
                            :class="fieldClass"
                        />
                        <span
                            v-if="errors.name"
                            class="text-[12px]/4 text-status-down"
                            role="alert"
                            >{{ errors.name }}</span
                        >
                    </label>

                    <template v-if="custom">
                        <label class="flex flex-col gap-1.5">
                            <span :class="labelClass">{{
                                $t('Template')
                            }}</span>
                            <SelectMenu
                                v-if="imageOptions.length"
                                v-model="image"
                                :options="imageOptions"
                                :aria-label="$t('Template')"
                                class="h-8 rounded-[7px] border-[0.5px] border-edge-field bg-surface-field px-2.5 text-[13px]/5 text-fg-primary shadow-field"
                            />
                            <span
                                v-else
                                class="text-[12.5px]/[18px] text-fg-muted"
                                >{{
                                    $t(
                                        'There are no VM templates here yet. Make one in :provider (convert a VM with cloud-init and the QEMU guest agent to a template), then try again.',
                                        { provider: integration.providerName },
                                    )
                                }}</span
                            >
                            <span
                                v-if="errors.image"
                                class="text-[12px]/4 text-status-down"
                                role="alert"
                                >{{ errors.image }}</span
                            >
                            <span
                                v-else-if="templateNote"
                                class="text-[12px]/4 text-fg-muted"
                                >{{ templateNote }}</span
                            >
                        </label>

                        <SizeFields
                            v-model="size"
                            :min-disk-gb="template?.size?.diskGb ?? 1"
                            :errors="errors"
                        />

                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <label class="flex flex-col gap-1.5">
                                <span :class="labelClass">{{
                                    $t('User')
                                }}</span>
                                <input
                                    v-model="user"
                                    type="text"
                                    maxlength="32"
                                    autocomplete="off"
                                    spellcheck="false"
                                    :placeholder="$t('The template’s own')"
                                    :class="fieldClass"
                                />
                                <span
                                    v-if="errors.user"
                                    class="text-[12px]/4 text-status-down"
                                    role="alert"
                                    >{{ errors.user }}</span
                                >
                            </label>
                            <div class="flex flex-col gap-1.5">
                                <span :class="labelClass">{{
                                    $t('Network')
                                }}</span>
                                <FilterPills
                                    v-model="network"
                                    :options="[
                                        { value: 'dhcp', label: $t('DHCP') },
                                        {
                                            value: 'static',
                                            label: $t('Fixed address'),
                                        },
                                    ]"
                                    :label="$t('Network')"
                                />
                            </div>
                        </div>

                        <div
                            v-if="network === 'static'"
                            class="grid grid-cols-1 gap-3 sm:grid-cols-2"
                        >
                            <label class="flex flex-col gap-1.5">
                                <span :class="labelClass">{{
                                    $t('IPv4 address')
                                }}</span>
                                <input
                                    v-model="address"
                                    type="text"
                                    required
                                    autocomplete="off"
                                    spellcheck="false"
                                    placeholder="192.168.1.50/24"
                                    :class="fieldClass"
                                />
                                <span
                                    v-if="errors.address"
                                    class="text-[12px]/4 text-status-down"
                                    role="alert"
                                    >{{ errors.address }}</span
                                >
                            </label>
                            <label class="flex flex-col gap-1.5">
                                <span :class="labelClass">{{
                                    $t('Gateway')
                                }}</span>
                                <input
                                    v-model="gateway"
                                    type="text"
                                    required
                                    autocomplete="off"
                                    spellcheck="false"
                                    placeholder="192.168.1.1"
                                    :class="fieldClass"
                                />
                                <span
                                    v-if="errors.gateway"
                                    class="text-[12px]/4 text-status-down"
                                    role="alert"
                                    >{{ errors.gateway }}</span
                                >
                            </label>
                        </div>

                        <label class="flex flex-col gap-1.5">
                            <span :class="labelClass">{{
                                $t('SSH keys')
                            }}</span>
                            <textarea
                                v-model="sshKeys"
                                rows="3"
                                spellcheck="false"
                                :placeholder="
                                    $t(
                                        'ssh-ed25519 AAAA… you@laptop, one per line',
                                    )
                                "
                                class="min-h-[72px] w-full rounded-[7px] border-[0.5px] border-edge-field bg-surface-field px-2.5 py-1.5 font-ui-mono text-[12px]/[18px] text-fg-primary shadow-field outline-none placeholder:font-ui placeholder:text-fg-faint focus:border-[rgba(51,97,255,0.7)]"
                            />
                            <span
                                v-if="errors.ssh_keys"
                                class="text-[12px]/4 text-status-down"
                                role="alert"
                                >{{ errors.ssh_keys }}</span
                            >
                            <span v-else class="text-[12px]/4 text-fg-muted">{{
                                $t(
                                    'Optional. Cloud-init adds them for the user above.',
                                )
                            }}</span>
                        </label>
                    </template>

                    <div v-if="!custom" class="flex flex-col gap-1.5">
                        <span :class="labelClass">{{ $t('Location') }}</span>
                        <div
                            role="radiogroup"
                            :aria-label="$t('Location')"
                            class="grid grid-cols-2 gap-1.5 sm:grid-cols-3"
                        >
                            <button
                                v-for="place in plans.locations"
                                :key="place.name"
                                type="button"
                                role="radio"
                                :aria-checked="location === place.name"
                                class="flex cursor-pointer flex-col items-start rounded-[8px] border-[0.5px] px-2.5 py-1.5 text-left focus-visible:outline-2 focus-visible:outline-(--ds-ring)"
                                :class="
                                    location === place.name
                                        ? 'border-[rgba(51,97,255,0.7)] bg-[rgba(51,97,255,0.08)]'
                                        : 'border-edge-card bg-surface-card hover:bg-surface-hover'
                                "
                                @click="location = place.name"
                            >
                                <span
                                    class="text-[13px]/5 font-medium text-fg-primary"
                                    >{{ place.city }}</span
                                >
                                <span
                                    class="font-ui-mono text-[11.5px]/4 text-fg-muted"
                                    >{{ place.name }} ·
                                    {{ place.country }}</span
                                >
                            </button>
                        </div>
                        <span
                            v-if="errors.location"
                            class="text-[12px]/4 text-status-down"
                            >{{ errors.location }}</span
                        >
                    </div>

                    <div v-if="!custom" class="flex flex-col gap-1.5">
                        <div class="flex items-center justify-between gap-3">
                            <span :class="labelClass">{{
                                $t('Server type')
                            }}</span>
                            <FilterPills
                                v-if="families.length > 1"
                                v-model="family"
                                :options="families"
                                :label="$t('Kind of server')"
                            />
                        </div>
                        <ServerTypeList
                            v-model="type"
                            :types="shownTypes"
                            :currency="plans.currency"
                            :location="location"
                            :label="$t('Server type')"
                        />
                        <span
                            v-if="errors.type"
                            class="text-[12px]/4 text-status-down"
                            >{{ errors.type }}</span
                        >
                    </div>

                    <label v-if="!custom" class="flex flex-col gap-1.5">
                        <span :class="labelClass">{{ $t('System') }}</span>
                        <SelectMenu
                            v-model="image"
                            :options="imageOptions"
                            :aria-label="$t('System')"
                            class="h-8 rounded-[7px] border-[0.5px] border-edge-field bg-surface-field px-2.5 text-[13px]/5 text-fg-primary shadow-field"
                        />
                        <span
                            v-if="errors.image"
                            class="text-[12px]/4 text-status-down"
                            >{{ errors.image }}</span
                        >
                    </label>

                    <div v-if="!custom" class="flex flex-col gap-1.5">
                        <span :class="labelClass">{{ $t('SSH keys') }}</span>
                        <div
                            v-if="plans.keys.length"
                            class="flex flex-col rounded-[10px] border-[0.5px] border-edge-card bg-surface-card"
                        >
                            <label
                                v-for="key in plans.keys"
                                :key="key.id"
                                class="flex cursor-pointer items-center gap-2.5 border-t-[0.5px] border-edge px-3 py-2 first:border-t-0"
                            >
                                <DashCheckbox
                                    v-model="keys[key.id]"
                                    :label="key.name"
                                />
                                <span class="flex-1 truncate text-[13px]/5">{{
                                    key.name
                                }}</span>
                                <span
                                    v-if="key.fingerprint"
                                    class="font-ui-mono text-[11px]/4 text-fg-faint"
                                    >{{ key.fingerprint.slice(-11) }}</span
                                >
                            </label>
                        </div>
                        <span class="text-[12px]/4 text-fg-muted">{{
                            plans.rules?.rootPassword === false
                                ? $t(
                                      'Optional. Without one, use the ServerOS terminal.',
                                  )
                                : $t(
                                      'Optional. Without one, use the ServerOS terminal, or reset the root password at :provider.',
                                      { provider: integration.providerName },
                                  )
                        }}</span>
                    </div>

                    <p
                        v-if="errors.form"
                        class="rounded-[8px] border-[0.5px] border-[rgba(240,105,90,0.25)] bg-[rgba(240,105,90,0.07)] px-3 py-2 text-[12.5px]/[18px] text-fg-secondary"
                        role="alert"
                    >
                        {{ errors.form }}
                    </p>
                </div>

                <div
                    class="flex shrink-0 items-center gap-2 border-t-[0.5px] border-edge bg-surface-card-footer py-3 pr-4 pl-5"
                >
                    <span
                        class="flex flex-1 items-center gap-1.5 text-[12.5px]/4 text-fg-muted"
                    >
                        <template v-if="price">
                            <FilledIcon name="info-fill" :size="13" />
                            {{
                                $t(':price a month at :provider', {
                                    price,
                                    provider: integration.providerName,
                                })
                            }}
                        </template>
                    </span>
                    <DashButton :label="$t('Cancel')" @click="open = false" />
                    <DashButton
                        type="submit"
                        variant="primary"
                        :label="$t('Make server')"
                        :loading="saving"
                        :disabled="
                            !plans ||
                            !image ||
                            !name.trim() ||
                            (!custom && !type)
                        "
                    />
                </div>
            </form>
        </DialogContent>
    </Dialog>
</template>
