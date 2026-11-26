<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';
import BrandLogo from '@/components/dash/BrandLogo.vue';
import RailSection from '@/components/home/RailSection.vue';
import { brandMark } from '@/lib/brandLogos';
import type { Workspace, WorkspaceStats } from '@/types';

const { workspace, stats, providers, licenseMachines } = defineProps<{
    workspace: Workspace;
    stats: WorkspaceStats;
    providers: { provider: string; machines: number }[];
    licenseMachines?: {
        allowed: number;
        connected: number;
        over: number;
    } | null;
}>();

const allowance = computed(() => {
    const limit = workspace.billing.machineLimit;

    if (licenseMachines) {
        return licenseMachines.over > 0
            ? trans(':count of :limit in your license, over by :over', {
                  count: String(stats.machines),
                  limit: String(licenseMachines.allowed),
                  over: String(licenseMachines.over),
              })
            : trans(':count of :limit in your license', {
                  count: String(stats.machines),
                  limit: String(licenseMachines.allowed),
              });
    }
    if (limit === null) {
        return workspace.billing.selfHosted
            ? trans('No limit')
            : trans('No limit on your plan');
    }

    return workspace.billing.selfHosted
        ? trans(':count of :limit on this install', {
              count: String(stats.machines),
              limit: String(limit),
          })
        : trans(':count of :limit on your plan', {
              count: String(stats.machines),
              limit: String(limit),
          });
});

const environments = computed(() =>
    [
        {
            label: trans('Production'),
            count: stats.environments.production,
            color: 'bg-dash-blue',
        },
        {
            label: trans('Staging'),
            count: stats.environments.staging,
            color: 'bg-fg-secondary',
        },
        {
            label: trans('Development'),
            count: stats.environments.development,
            color: 'bg-fg-faint',
        },
    ].map((environment) => ({
        ...environment,
        share: share(environment.count),
    })),
);

function share(count: number): string {
    return `${Math.round((count / Math.max(stats.machines, 1)) * 100)}%`;
}

const topProviders = computed(() => providers.slice(0, 4));
</script>

<template>
    <RailSection :title="$t('Fleet')">
        <template #meta>{{ allowance }}</template>
        <div class="flex flex-col gap-3 px-1.5 pt-1.5">
            <div class="flex h-1 gap-1" aria-hidden="true">
                <span
                    v-for="environment in environments.filter(
                        (environment) => environment.count > 0,
                    )"
                    :key="environment.label"
                    class="h-full rounded-[2px]"
                    :class="environment.color"
                    :style="{ flexGrow: environment.count }"
                />
            </div>
            <ul class="flex flex-col gap-2 text-[13px]/5">
                <li
                    v-for="environment in environments"
                    :key="environment.label"
                    class="flex items-center gap-2"
                >
                    <span
                        class="size-2 shrink-0 rounded-[2px]"
                        :class="environment.color"
                        aria-hidden="true"
                    />
                    <span class="text-fg-secondary">{{
                        environment.label
                    }}</span>
                    <span
                        class="ml-auto font-medium text-fg-primary tabular-nums"
                        >{{ environment.count }}</span
                    >
                    <span
                        class="w-10 text-right text-[12px]/4 text-fg-muted tabular-nums"
                        >{{ environment.share }}</span
                    >
                </li>
            </ul>
            <ul
                v-if="topProviders.length"
                class="flex flex-col gap-2 border-t-[0.5px] border-edge-tabs pt-3 text-[13px]/5"
            >
                <li
                    v-for="provider in topProviders"
                    :key="provider.provider"
                    class="flex min-w-0 items-center gap-2"
                >
                    <BrandLogo :name="provider.provider" :size="14" />
                    <span class="truncate text-fg-secondary">{{
                        brandMark(provider.provider)?.title ?? provider.provider
                    }}</span>
                    <span
                        class="ml-auto font-medium text-fg-primary tabular-nums"
                        >{{ provider.machines }}</span
                    >
                    <span
                        class="w-10 text-right text-[12px]/4 text-fg-muted tabular-nums"
                        >{{ share(provider.machines) }}</span
                    >
                </li>
            </ul>
        </div>
    </RailSection>
</template>
