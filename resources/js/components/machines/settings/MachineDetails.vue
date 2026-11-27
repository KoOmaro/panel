<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { X } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed, nextTick, ref, useTemplateRef } from 'vue';
import BrandLogo from '@/components/dash/BrandLogo.vue';
import DashButton from '@/components/dash/DashButton.vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import { privateAddress } from '@/components/machines/machineFormat';
import { environmentLabels } from '@/components/machines/machines';
import type { Environment, Machine } from '@/components/machines/machines';
import FieldSelect from '@/components/machines/settings/FieldSelect.vue';
import type { FieldOption } from '@/components/machines/settings/FieldSelect.vue';
import DashTray from '@/components/dash/DashTray.vue';
import SettingsRow from '@/components/machines/settings/SettingsRow.vue';
import { onWorkspace } from '@/lib/workspaceRoute';
import machinesRoutes from '@/routes/workspace/machines';

const props = defineProps<{
    machine: Machine;
    workspaceSlug: string;
    environments: string[];
    detected: {
        provider: string | null;
        region: string | null;
        tier: string | null;
    };
    providers: string[];
    canManage: boolean;
}>();

const emit = defineEmits<{ saved: [] }>();

const args = computed(() => ({
    workspace: props.workspaceSlug,
    machine: props.machine.id!,
}));

type Label = { key: string; value: string };

function labelsOf(machine: Machine): Label[] {
    return Object.entries(machine.labels ?? {}).map(([key, value]) => ({
        key,
        value,
    }));
}

const form = useForm({
    name: props.machine.name,
    environment: props.machine.environment as string,
    provider: props.machine.providerManual ? props.machine.provider : '',
    region: props.machine.region ?? '',
    tier: props.machine.tier ?? '',
    labels: labelsOf(props.machine),
});

let queued = false;

async function save(): Promise<void> {
    await nextTick();
    if (!props.canManage || !form.isDirty) return;
    if (form.processing) {
        queued = true;

        return;
    }
    const sent = JSON.parse(JSON.stringify(form.data())) as ReturnType<
        typeof form.data
    >;
    form.transform((data) => ({
        ...data,
        region:
            !props.machine.regionManual &&
            data.region.trim() === (props.detected.region ?? '')
                ? ''
                : data.region,
        labels: data.labels.filter((label) => label.key.trim() !== ''),
    })).patch(onWorkspace(machinesRoutes.update(args.value)).url, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            form.defaults({ ...sent, labels: labelsOf(props.machine) });
            if (!queued) form.labels = labelsOf(props.machine);
            emit('saved');
        },
        onFinish: () => {
            if (!queued) return;
            queued = false;
            save();
        },
    });
}

function commitOnEnter(event: KeyboardEvent): void {
    (event.target as HTMLInputElement).blur();
}

const environmentDots: Record<Environment, string> = {
    production: 'bg-dash-blue',
    staging: 'bg-[#f5a833]',
    development: 'bg-fg-muted',
};

const environmentOptions = computed<FieldOption<string>[]>(() =>
    props.environments.map((value) => ({
        value,
        label: environmentLabels[value as Environment] ?? value,
    })),
);

function setEnvironment(value: string): void {
    form.environment = value;
    save();
}

const autoProvider = computed(() => props.detected.provider ?? 'Other');

const providerOptions = computed<FieldOption<string>[]>(() => {
    const seen = new Set<string>([autoProvider.value.toLowerCase()]);
    const options: FieldOption<string>[] = [
        {
            value: '',
            label:
                autoProvider.value === 'Other'
                    ? trans('Other')
                    : autoProvider.value,
            hint: props.detected.provider
                ? trans('What the daemon detects')
                : trans('The daemon couldn’t tell'),
        },
    ];
    for (const name of [
        ...(props.machine.providerManual ? [props.machine.provider] : []),
        ...props.providers,
    ]) {
        if (!name || seen.has(name.toLowerCase())) continue;
        seen.add(name.toLowerCase());
        options.push({ value: name, label: name });
    }
    options.push({
        value: '\u0000custom',
        label: trans('Type a name…'),
        separated: true,
    });

    return options;
});

const typingProvider = ref(false);
const providerInput = useTemplateRef<HTMLInputElement>('providerInput');

function setProvider(value: string): void {
    if (value === '\u0000custom') {
        typingProvider.value = true;
        nextTick(() => providerInput.value?.focus());

        return;
    }
    form.provider = value;
    save();
}

function finishProvider(): void {
    typingProvider.value = false;
    save();
}

const shownProvider = computed(() =>
    form.provider.trim() === '' ? autoProvider.value : form.provider,
);

const iconInput = useTemplateRef<HTMLInputElement>('iconInput');
const iconBusy = ref(false);
const iconError = ref<string | null>(null);

function uploadIcon(event: Event): void {
    const file = (event.target as HTMLInputElement).files?.[0];
    if (!file) return;
    router.post(
        onWorkspace(machinesRoutes.icon.update(args.value)).url,
        { icon: file },
        {
            forceFormData: true,
            preserveScroll: true,
            preserveState: true,
            onStart: () => {
                iconBusy.value = true;
                iconError.value = null;
            },
            onError: (bag) => (iconError.value = bag.icon ?? null),
            onFinish: () => {
                iconBusy.value = false;
                if (iconInput.value) iconInput.value.value = '';
            },
        },
    );
}

function removeIcon(): void {
    router.delete(onWorkspace(machinesRoutes.icon.destroy(args.value)).url, {
        preserveScroll: true,
        preserveState: true,
        onStart: () => (iconBusy.value = true),
        onFinish: () => (iconBusy.value = false),
    });
}

const adding = ref(false);
const newLabel = ref<Label>({ key: '', value: '' });
const labelKeyInput = useTemplateRef<HTMLInputElement>('labelKeyInput');

function startLabel(): void {
    adding.value = true;
    newLabel.value = { key: '', value: '' };
    nextTick(() => labelKeyInput.value?.focus());
}

function addLabel(): void {
    const key = newLabel.value.key.trim();
    if (key === '') {
        adding.value = false;

        return;
    }
    const existing = form.labels.findIndex((label) => label.key === key);
    if (existing >= 0) form.labels.splice(existing, 1);
    form.labels.push({ key, value: newLabel.value.value.trim() });
    adding.value = false;
    save();
}

function cancelLabel(): void {
    adding.value = false;
}

function removeLabel(index: number): void {
    form.labels.splice(index, 1);
    save();
}

const labelError = computed(
    () =>
        Object.entries(form.errors).find(([key]) =>
            key.startsWith('labels'),
        )?.[1] ?? null,
);

const lan = computed(() => privateAddress(props.machine));
</script>

<template>
    <DashTray
        panel-class="gap-1 px-[18px] pt-3 pb-1"
        icon="settings-fill"
        :title="$t('General')"
    >
        <SettingsRow
            :label="$t('Name')"
            label-for="machine-name"
            :error="form.errors.name"
        >
            <div class="dash-field w-[232px] max-w-full">
                <input
                    id="machine-name"
                    v-model="form.name"
                    maxlength="255"
                    required
                    spellcheck="false"
                    autocomplete="off"
                    :disabled="!canManage"
                    @blur="save"
                    @keydown.enter.prevent="commitOnEnter"
                />
            </div>
        </SettingsRow>

        <SettingsRow
            :label="$t('Environment')"
            :hint="$t('Its badge, and a filter on Machines')"
            :error="form.errors.environment"
        >
            <FieldSelect
                :model-value="form.environment"
                :options="environmentOptions"
                :label="$t('Environment')"
                :disabled="!canManage"
                @update:model-value="setEnvironment"
            >
                <template #lead>
                    <span
                        class="size-[7px] shrink-0 rounded-full"
                        :class="
                            environmentDots[form.environment as Environment]
                        "
                        aria-hidden="true"
                    />
                </template>
            </FieldSelect>
        </SettingsRow>

        <SettingsRow :label="$t('Icon')" :error="iconError">
            <span
                class="flex size-7 shrink-0 items-center justify-center overflow-hidden rounded-[7px] border-[0.5px] border-[#d4d4d4] bg-[#f2f2f2] shadow-card dark:border-[#383838] dark:bg-[#242424]"
            >
                <img
                    v-if="machine.iconUrl"
                    :src="machine.iconUrl"
                    alt=""
                    class="size-full object-cover"
                />
                <BrandLogo v-else :name="machine.provider" :size="15" />
            </span>
            <template v-if="canManage">
                <input
                    ref="iconInput"
                    type="file"
                    accept="image/png,image/jpeg,image/webp,image/gif"
                    class="sr-only"
                    tabindex="-1"
                    aria-hidden="true"
                    @change="uploadIcon"
                />
                <DashButton
                    v-if="machine.iconUrl"
                    size="sm"
                    :label="$t('Remove')"
                    :disabled="iconBusy"
                    @click="removeIcon"
                />
                <DashButton
                    size="sm"
                    :label="$t('Change')"
                    :loading="iconBusy"
                    @click="iconInput?.click()"
                />
            </template>
        </SettingsRow>

        <SettingsRow :label="$t('Labels')" :error="labelError">
            <span
                v-for="(label, index) in form.labels"
                :key="label.key"
                class="group/label flex h-6 max-w-60 items-center overflow-hidden rounded-[6px] border-[0.5px] border-edge-strong bg-surface-badge px-2 font-ui-mono text-[12px]/4 whitespace-nowrap text-fg-secondary"
            >
                <span class="truncate"
                    ><span class="text-fg-muted">{{ label.key }}:</span>
                    {{ label.value }}</span
                >
                <button
                    v-if="canManage"
                    type="button"
                    class="-mr-1 ml-1 hidden size-4 shrink-0 cursor-pointer items-center justify-center rounded-[4px] text-fg-muted group-focus-within/label:flex group-hover/label:flex hover:text-fg-primary"
                    :aria-label="
                        $t('Remove the label :name', { name: label.key })
                    "
                    @click="removeLabel(index)"
                >
                    <X class="size-3" :stroke-width="2" />
                </button>
            </span>
            <form
                v-if="adding"
                class="flex items-center gap-1.5"
                @submit.prevent="addLabel"
                @keydown.esc.prevent="cancelLabel"
            >
                <div class="dash-field h-7 w-28 font-ui-mono text-[12px]">
                    <input
                        ref="labelKeyInput"
                        v-model="newLabel.key"
                        maxlength="40"
                        spellcheck="false"
                        autocomplete="off"
                        :placeholder="$t('customer')"
                        :aria-label="$t('Label name')"
                    />
                </div>
                <div class="dash-field h-7 w-32 font-ui-mono text-[12px]">
                    <input
                        v-model="newLabel.value"
                        maxlength="100"
                        spellcheck="false"
                        autocomplete="off"
                        :placeholder="$t('acme')"
                        :aria-label="$t('Label value')"
                    />
                </div>
                <DashButton type="submit" size="sm" :label="$t('Add')" />
            </form>
            <button
                v-if="canManage && !adding"
                type="button"
                class="flex size-6 shrink-0 cursor-pointer items-center justify-center rounded-[6px] border-[0.5px] border-dashed border-[#c8c8c8] text-fg-muted hover:text-fg-primary focus-visible:outline-2 focus-visible:outline-(--ds-ring) dark:border-[#383838]"
                :aria-label="$t('Add a label')"
                :title="$t('Add a label')"
                @click="startLabel"
            >
                <FilledIcon name="plus-fill" :size="12" />
            </button>
            <span
                v-if="!canManage && form.labels.length === 0"
                class="text-[13px]/5 text-fg-faint"
                >{{ $t('None') }}</span
            >
        </SettingsRow>
    </DashTray>

    <DashTray
        panel-class="gap-1 px-[18px] pt-3 pb-1"
        icon="globe-fill"
        :title="$t('Where it runs')"
    >
        <SettingsRow :label="$t('Provider')" :error="form.errors.provider">
            <div v-if="typingProvider" class="dash-field w-[232px] max-w-full">
                <BrandLogo :name="form.provider" :size="14" />
                <input
                    ref="providerInput"
                    v-model="form.provider"
                    maxlength="60"
                    spellcheck="false"
                    autocomplete="off"
                    :placeholder="autoProvider"
                    :aria-label="$t('Provider')"
                    @blur="finishProvider"
                    @keydown.enter.prevent="commitOnEnter"
                />
            </div>
            <FieldSelect
                v-else
                :model-value="form.provider"
                :options="providerOptions"
                :display="shownProvider"
                :label="$t('Provider')"
                :disabled="!canManage"
                @update:model-value="setProvider"
            >
                <template #lead>
                    <BrandLogo :name="shownProvider" :size="14" />
                </template>
            </FieldSelect>
        </SettingsRow>

        <SettingsRow
            :label="$t('Region')"
            label-for="machine-region"
            :error="form.errors.region"
        >
            <div
                class="dash-field w-[232px] max-w-full font-ui-mono text-[12px]/4"
            >
                <input
                    id="machine-region"
                    v-model="form.region"
                    maxlength="60"
                    spellcheck="false"
                    autocomplete="off"
                    :placeholder="detected.region ?? $t('None')"
                    :disabled="!canManage"
                    @blur="save"
                    @keydown.enter.prevent="commitOnEnter"
                />
            </div>
        </SettingsRow>

        <SettingsRow
            :label="$t('Tier')"
            label-for="machine-tier"
            :error="form.errors.tier"
        >
            <div
                class="dash-field w-[232px] max-w-full font-ui-mono text-[12px]/4"
            >
                <input
                    id="machine-tier"
                    v-model="form.tier"
                    maxlength="60"
                    spellcheck="false"
                    autocomplete="off"
                    :placeholder="detected.tier ?? $t('None')"
                    :disabled="!canManage"
                    @blur="save"
                    @keydown.enter.prevent="commitOnEnter"
                />
            </div>
        </SettingsRow>

        <SettingsRow :label="$t('Address')">
            <span
                class="flex flex-col items-end font-ui-mono text-[12px]/[18px] whitespace-nowrap"
            >
                <span class="text-fg-primary">{{ machine.ip || '–' }}</span>
                <span v-if="lan" class="text-fg-muted">{{
                    $t(':address private', { address: lan })
                }}</span>
            </span>
        </SettingsRow>
    </DashTray>
</template>
