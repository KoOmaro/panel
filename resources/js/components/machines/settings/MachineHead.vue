<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';
import BrandLogo from '@/components/dash/BrandLogo.vue';
import DashTab from '@/components/dash/DashTab.vue';
import DashTabs from '@/components/dash/DashTabs.vue';
import StatusBadge from '@/components/dash/StatusBadge.vue';
import type { DashStatus } from '@/components/dash/StatusBadge.vue';
import type { FilledIconName } from '@/components/icons/filledIcons';
import { systemName } from '@/components/machines/machineFormat';
import { environmentLabels } from '@/components/machines/machines';
import type { Machine } from '@/components/machines/machines';
import { onWorkspace } from '@/lib/workspaceRoute';
import machinesRoutes from '@/routes/workspace/machines';

const props = defineProps<{
    machine: Machine;
    workspaceSlug: string;
    settingsUrl: string;
    updatesWaiting?: number;
}>();

const machineBase = computed(
    () =>
        onWorkspace(
            machinesRoutes.show({
                workspace: props.workspaceSlug,
                machine: props.machine.id!,
            }),
        ).url,
);

const tabs = computed<
    { key: string; label: string; icon: FilledIconName; href: string }[]
>(() => [
    {
        key: 'overview',
        label: trans('Overview'),
        icon: 'monitors-fill',
        href: machineBase.value,
    },
    {
        key: 'services',
        label: trans('Services'),
        icon: 'layers-fill',
        href: `${machineBase.value}/services`,
    },
    {
        key: 'system-updates',
        label: trans('Updates'),
        icon: 'update-fill',
        href: `${machineBase.value}/system-updates`,
    },
    {
        key: 'backups',
        label: trans('Backups'),
        icon: 'activity-fill',
        href: `${machineBase.value}/backups`,
    },
    {
        key: 'firewall',
        label: trans('Firewall'),
        icon: 'settings-fill',
        href: `${machineBase.value}/firewall`,
    },
    {
        key: 'terminal',
        label: trans('Terminal'),
        icon: 'terminal-fill',
        href: `${machineBase.value}/terminal`,
    },
]);

const status = computed<{ status: DashStatus; label: string }>(() => {
    if (props.machine.maintenance)
        return { status: 'pending', label: trans('In maintenance') };
    if (props.machine.setup)
        return {
            status: 'deploying',
            label: {
                connecting: trans('Connecting'),
                discovering: trans('Discovering'),
                securing: trans('Securing'),
            }[props.machine.setup],
        };
    switch (props.machine.connection) {
        case 'online':
        case 'demo':
            return { status: 'live', label: trans('Live') };
        case 'degraded':
            return { status: 'pending', label: trans('Delayed') };
        case 'connecting':
            return { status: 'deploying', label: trans('Connecting') };
        default:
            return { status: 'down', label: trans('Offline') };
    }
});

const chips = computed(() => {
    const region =
        props.machine.region ?? props.machine.facts?.hosting?.region ?? null;

    return [
        { key: 'env', label: environmentLabels[props.machine.environment] },
        {
            key: 'provider',
            label: [
                props.machine.provider === 'Other'
                    ? trans('Other')
                    : props.machine.provider,
                region,
            ]
                .filter(Boolean)
                .join(' · '),
            logo: props.machine.provider,
        },
        { key: 'os', label: systemName(props.machine, true) },
    ].filter((chip): chip is { key: string; label: string; logo?: string } =>
        Boolean(chip.label),
    );
});
</script>

<template>
    <div class="flex flex-wrap items-center gap-3 px-6 pt-5 pb-4">
        <img
            v-if="machine.iconUrl"
            :src="machine.iconUrl"
            alt=""
            class="size-7 shrink-0 rounded-[7px] border-[0.5px] border-edge-card object-cover"
        />
        <h1
            class="min-w-0 truncate text-[20px]/7 font-medium tracking-[-0.02em] text-fg-primary"
        >
            {{ machine.name }}
        </h1>
        <StatusBadge :status="status.status" :label="status.label" />
        <span class="grow" />
        <span
            v-for="chip in chips"
            :key="chip.key"
            class="flex h-[26px] items-center gap-1.5 rounded-full border-[0.5px] border-[#dcdcdc] bg-[#f4f4f4] px-2.5 text-[12px]/4 font-medium tracking-[-0.005em] whitespace-nowrap text-fg-secondary dark:border-[#303030] dark:bg-[#202020]"
        >
            <BrandLogo v-if="chip.logo" :name="chip.logo" :size="12" />
            {{ chip.label }}
        </span>
    </div>

    <DashTabs data-machine-tabs>
        <span v-for="item in tabs" :key="item.key" class="relative">
            <DashTab :label="item.label" :icon="item.icon" :href="item.href" />
            <span
                v-if="item.key === 'system-updates' && updatesWaiting"
                class="pointer-events-none absolute top-[11.5px] left-[20.5px] size-[9px] rounded-full border-[1.5px] border-surface-main bg-status-deploying"
                :aria-label="
                    $t(':count updates waiting', {
                        count: String(updatesWaiting),
                    })
                "
            />
        </span>
        <DashTab
            :label="$t('Settings')"
            icon="settings-fill"
            :href="settingsUrl"
            active
        />
    </DashTabs>
</template>
