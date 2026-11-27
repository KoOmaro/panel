<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, ref, watch } from 'vue';
import DashButton from '@/components/dash/DashButton.vue';
import DashCheckbox from '@/components/dash/DashCheckbox.vue';
import type {
    BackupStorageAccount,
    StorageBucket,
} from '@/components/integrations/storage';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { SelectMenu } from '@/components/ui/select';
import { onWorkspace } from '@/lib/workspaceRoute';
import storageRoutes from '@/routes/workspace/integrations/storage';
import type { Workspace } from '@/types';

const {
    account,
    bucket = null,
    integrationId,
    providerName,
} = defineProps<{
    account: BackupStorageAccount;
    bucket?: StorageBucket | null;
    integrationId: number;
    providerName: string;
}>();

const open = defineModel<boolean>('open', { default: false });
const page = usePage<{ workspace: Workspace }>();

const keep = ref('');
const schedule = ref('daily');
const name = ref('');
const choices = ref<Record<string, string>>({});
const picked = ref<Record<number, boolean>>({});
const errors = ref<Record<string, string>>({});
const processing = ref(false);

watch(open, (shown) => {
    if (!shown) return;
    errors.value = {};
    name.value = '';
    schedule.value = 'daily';
    keep.value = bucket?.keepDays ? String(bucket.keepDays) : '';
    choices.value = Object.fromEntries(
        account.options.map((option) => [
            option.key,
            option.options[0]?.value ?? '',
        ]),
    );
    picked.value = Object.fromEntries(
        account.machines.map((machine) => [
            machine.id,
            bucket
                ? bucket.machines.some((m) => m.id === machine.id)
                : machine.destinationId === null,
        ]),
    );
});

const keepOptions = computed(() => [
    { value: '', label: trans('Keep every backup') },
    ...account.keepOptions.map((days) => ({
        value: String(days),
        label: trans('Delete after :days days', { days: String(days) }),
    })),
]);
const scheduleOptions = computed(() => [
    { value: 'daily', label: trans('Daily') },
    { value: 'weekly', label: trans('Weekly') },
]);
const chosen = computed(() =>
    account.machines.filter((machine) => picked.value[machine.id]),
);

function elsewhere(machineId: number): string | null {
    const machine = account.machines.find((m) => m.id === machineId);
    if (
        !machine?.destination ||
        machine.destinationId === bucket?.destination?.id
    ) {
        return null;
    }

    return trans('Backs up to :destination now', {
        destination: machine.destination,
    });
}

function submit(): void {
    const args = {
        workspace: page.props.workspace.slug,
        integration: integrationId,
    };
    const machines = chosen.value.map((machine) => machine.id);
    const options = {
        preserveScroll: true,
        onStart: () => (processing.value = true),
        onFinish: () => (processing.value = false),
        onError: (bag: Record<string, string>) => (errors.value = bag),
        onSuccess: () => (open.value = false),
    };

    if (bucket) {
        router.put(
            onWorkspace(storageRoutes.update({ ...args, bucket: bucket.id }))
                .url,
            {
                keep_days: keep.value === '' ? null : Number(keep.value),
                machines,
                schedule: schedule.value,
            },
            options,
        );

        return;
    }

    router.post(
        onWorkspace(storageRoutes.store(args)).url,
        {
            name: name.value.trim() || null,
            keep_days: keep.value === '' ? null : Number(keep.value),
            machines,
            schedule: schedule.value,
            ...choices.value,
        },
        options,
    );
}

const labelClass = 'text-[12.5px]/4 text-fg-tertiary';
const fieldClass =
    'h-8 w-full rounded-[7px] border-[0.5px] border-edge-field bg-surface-field px-2.5 text-[13px]/5 text-fg-primary shadow-field outline-none placeholder:text-fg-faint focus:border-[rgba(51,97,255,0.7)]';
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="workspace-panel ds-modal max-h-[calc(100svh-2rem)] gap-0 overflow-y-auto !rounded-[12px] p-0 font-ui text-fg-primary sm:max-w-[480px]"
        >
            <form class="flex flex-col" @submit.prevent="submit">
                <div class="flex flex-col gap-0.5 px-5 pt-[18px] pb-4">
                    <DialogTitle
                        class="text-[16px]/6 font-medium tracking-[-0.01em]"
                    >
                        {{
                            bucket
                                ? $t('Edit :bucket', { bucket: bucket.name })
                                : $t('Set up backups')
                        }}
                    </DialogTitle>
                    <DialogDescription class="text-[13px]/5 text-fg-tertiary">
                        <template v-if="bucket">{{
                            $t(
                                'Choose how long backups stay and which machines back up here.',
                            )
                        }}</template>
                        <template v-else>
                            {{ $t('ServerOS makes a private bucket called') }}
                            <span class="font-ui-mono text-fg-secondary">{{
                                account.suggestedName
                            }}</span>
                            {{
                                $t(
                                    'at :provider, with a key that can only reach it, and adds it as a backup destination.',
                                    { provider: providerName },
                                )
                            }}
                        </template>
                    </DialogDescription>
                </div>

                <div class="flex flex-col gap-4 px-5 pb-[18px]">
                    <label class="flex flex-col gap-1.5">
                        <span :class="labelClass">{{
                            $t('How long backups stay')
                        }}</span>
                        <SelectMenu
                            v-model="keep"
                            :options="keepOptions"
                            :aria-label="$t('How long backups stay')"
                            :class="fieldClass"
                        />
                        <span class="text-[12px]/4 text-fg-muted">{{
                            keep === ''
                                ? $t(
                                      'ServerOS doesn’t delete backups there, so the bucket keeps growing.',
                                  )
                                : $t(
                                      ':provider deletes each backup that many days after it was made.',
                                      { provider: providerName },
                                  )
                        }}</span>
                        <span
                            v-if="account.keepNote"
                            class="text-[12px]/4 text-fg-muted"
                            >{{ account.keepNote }}</span
                        >
                        <span
                            v-if="errors.keep_days"
                            class="text-[12px]/4 text-status-down"
                            role="alert"
                            >{{ errors.keep_days }}</span
                        >
                    </label>

                    <template v-if="!bucket">
                        <label
                            v-for="option in account.options"
                            :key="option.key"
                            class="flex flex-col gap-1.5"
                        >
                            <span :class="labelClass">{{ option.label }}</span>
                            <SelectMenu
                                v-model="choices[option.key]"
                                :options="option.options"
                                :aria-label="option.label"
                                :class="fieldClass"
                            />
                            <span
                                v-if="option.help"
                                class="text-[12px]/4 text-fg-muted"
                                >{{ option.help }}</span
                            >
                        </label>
                    </template>

                    <div v-if="account.machines.length" class="flex flex-col">
                        <span :class="[labelClass, 'pb-1.5']">{{
                            $t('Machines that back up here')
                        }}</span>
                        <ul
                            class="flex max-h-56 flex-col overflow-y-auto rounded-[8px] border-[0.5px] border-edge-field bg-surface-field shadow-field"
                        >
                            <li
                                v-for="machine in account.machines"
                                :key="machine.id"
                                class="flex items-center gap-2.5 border-t-[0.5px] border-edge-field px-2.5 py-2 first:border-t-0"
                            >
                                <DashCheckbox
                                    v-model="picked[machine.id]"
                                    :label="machine.name"
                                />
                                <span
                                    class="min-w-0 flex-1 truncate text-[13px]/5 text-fg-primary"
                                    >{{ machine.name }}</span
                                >
                                <span
                                    v-if="elsewhere(machine.id)"
                                    class="truncate text-[12px]/4 text-fg-muted"
                                    >{{ elsewhere(machine.id) }}</span
                                >
                            </li>
                        </ul>
                        <span
                            v-if="errors.machines"
                            class="pt-1.5 text-[12px]/4 text-status-down"
                            role="alert"
                            >{{ errors.machines }}</span
                        >
                    </div>

                    <label
                        v-if="chosen.some((m) => m.schedule === 'off')"
                        class="flex flex-col gap-1.5"
                    >
                        <span :class="labelClass">{{
                            $t('Schedule for machines without one')
                        }}</span>
                        <SelectMenu
                            v-model="schedule"
                            :options="scheduleOptions"
                            :aria-label="$t('Schedule')"
                            :class="fieldClass"
                        />
                    </label>

                    <label v-if="!bucket" class="flex flex-col gap-1.5">
                        <span :class="labelClass">{{
                            $t('Destination name')
                        }}</span>
                        <input
                            v-model="name"
                            type="text"
                            maxlength="60"
                            :placeholder="providerName"
                            :class="fieldClass"
                        />
                    </label>

                    <p
                        v-if="errors.storage"
                        role="alert"
                        class="rounded-[8px] border-[0.5px] border-[rgba(240,105,90,0.25)] bg-[rgba(240,105,90,0.07)] px-3 py-2.5 text-[12.5px]/[18px] text-fg-secondary"
                    >
                        {{ errors.storage }}
                    </p>
                </div>

                <div
                    class="flex justify-end gap-2 border-t-[0.5px] border-edge bg-surface-card-footer py-3 pr-4 pl-5"
                >
                    <DashButton :label="$t('Cancel')" @click="open = false" />
                    <DashButton
                        type="submit"
                        variant="primary"
                        :label="bucket ? $t('Save') : $t('Set it up')"
                        :loading="processing"
                    />
                </div>
            </form>
        </DialogContent>
    </Dialog>
</template>
