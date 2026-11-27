<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';
import DashTray from '@/components/dash/DashTray.vue';
import type { HomeOverview } from '@/components/home/home';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import type { FilledIconName } from '@/components/icons/filledIcons';
import { onWorkspace } from '@/lib/workspaceRoute';
import machinesRoutes from '@/routes/workspace/machines';
import monitorsRoutes from '@/routes/workspace/monitors';
import servicesRoutes from '@/routes/workspace/services';
import type { Workspace } from '@/types';

const { overview, workspace, licenseMachines } = defineProps<{
    overview: HomeOverview;
    workspace: Workspace;
    licenseMachines?: {
        allowed: number;
        connected: number;
        over: number;
    } | null;
}>();

const page = usePage<{ workspace: { slug: string } }>();
const slug = computed(() => page.props.workspace.slug);

type Figure = {
    key: string;
    label: string;
    icon: FilledIconName;
    value: string;
    suffix?: string;
    foot: string;
    dot?: 'red' | 'blue';
    href: string;
};

const dots = {
    red: 'bg-status-down',
    blue: 'bg-status-deploying',
};

function allowance(): string {
    const count = String(overview.machines);
    if (licenseMachines) {
        return trans(':count of :limit in your license', {
            count,
            limit: String(licenseMachines.allowed),
        });
    }
    const limit = workspace.billing.machineLimit;
    if (limit === null) {
        return trans('All online');
    }

    return workspace.billing.selfHosted
        ? trans(':count of :limit on this install', {
              count,
              limit: String(limit),
          })
        : trans(':count of :limit on your plan', {
              count,
              limit: String(limit),
          });
}

function machinesFoot(): Pick<Figure, 'foot' | 'dot'> {
    const { offline, connecting } = overview;
    if (offline.length === 1) {
        return {
            foot: trans(':name is offline', { name: offline[0] }),
            dot: 'red',
        };
    }
    if (offline.length === 2) {
        return {
            foot: trans(':first and :second are offline', {
                first: offline[0],
                second: offline[1],
            }),
            dot: 'red',
        };
    }
    if (offline.length > 2) {
        return {
            foot: trans(':count are offline', {
                count: String(offline.length),
            }),
            dot: 'red',
        };
    }
    if (connecting > 0) {
        return {
            foot:
                connecting === 1
                    ? trans('1 is connecting')
                    : trans(':count are connecting', {
                          count: String(connecting),
                      }),
            dot: 'blue',
        };
    }

    return { foot: allowance() };
}

function servicesFoot(): Pick<Figure, 'foot' | 'dot'> {
    const { services, running, serviceProblems } = overview;
    if (serviceProblems > 0) {
        return {
            foot:
                serviceProblems === 1
                    ? trans('1 has a problem')
                    : trans(':count have problems', {
                          count: String(serviceProblems),
                      }),
            dot: 'red',
        };
    }
    if (services === 0) {
        return { foot: trans('No services yet') };
    }

    return {
        foot:
            running < services
                ? trans(':count stopped', { count: String(services - running) })
                : trans('All running'),
    };
}

function uptimeFoot(): Pick<Figure, 'foot' | 'dot'> {
    const { monitors, monitorsDown } = overview;
    if (monitors === 0) {
        return { foot: trans('No monitors yet') };
    }
    const count =
        monitors === 1
            ? trans('1 monitor')
            : trans(':count monitors', { count: String(monitors) });

    return monitorsDown > 0
        ? {
              foot: trans(':monitors, :down down', {
                  monitors: count,
                  down: String(monitorsDown),
              }),
              dot: 'red',
          }
        : { foot: trans(':monitors, all up', { monitors: count }) };
}

function percent(value: number): string {
    if (value >= 100) return '100%';

    return `${(Math.floor(value * 100) / 100).toFixed(2)}%`;
}

const figures = computed<Figure[]>(() => [
    {
        key: 'machines',
        label: trans('Machines'),
        icon: 'machines-fill',
        value: String(overview.online),
        suffix: trans('of :count', { count: String(overview.machines) }),
        ...machinesFoot(),
        href: onWorkspace(machinesRoutes.index(slug.value)).url,
    },
    {
        key: 'services',
        label: trans('Services'),
        icon: 'layers-fill',
        value: String(overview.running),
        suffix: trans('of :count running', {
            count: String(overview.services),
        }),
        ...servicesFoot(),
        href: onWorkspace(servicesRoutes.index(slug.value)).url,
    },
    {
        key: 'uptime',
        label: trans('Uptime, 30 days'),
        icon: 'monitors-fill',
        value: overview.uptime === null ? '–' : percent(overview.uptime),
        ...uptimeFoot(),
        href: onWorkspace(monitorsRoutes.index(slug.value)).url,
    },
    {
        key: 'cpu',
        label: trans('Average CPU'),
        icon: 'activity-fill',
        value: overview.averageCpu === null ? '–' : `${overview.averageCpu}%`,
        foot:
            overview.reporting > 0
                ? overview.reporting === 1
                    ? trans('across 1 machine')
                    : trans('across :count machines', {
                          count: String(overview.reporting),
                      })
                : trans('No readings yet'),
        href: onWorkspace(machinesRoutes.index(slug.value)).url,
    },
]);
</script>

<template>
    <DashTray icon="house-fill" :title="$t('Overview')">
        <template #meta>{{ $t('live') }}</template>
        <div class="@container">
            <dl class="grid grid-cols-2 @3xl:grid-cols-4">
                <div
                    v-for="figure in figures"
                    :key="figure.key"
                    class="relative flex min-w-0 flex-col border-[#e3e3e3] px-4 py-3.5 transition-colors hover:bg-surface-hover @max-3xl:odd:border-r-[0.5px] @3xl:not-last:border-r-[0.5px] dark:border-[#2a2a2a] @max-3xl:[&:nth-child(-n+2)]:border-b-[0.5px]"
                >
                    <dt
                        class="flex min-w-0 items-center gap-[7px] text-[13px]/5 tracking-[-0.005em] text-fg-secondary"
                    >
                        <FilledIcon
                            :name="figure.icon"
                            :size="14"
                            class="text-fg-muted"
                        />
                        <Link
                            :href="figure.href"
                            class="truncate after:absolute after:inset-0 focus-visible:outline-none focus-visible:after:outline-2 focus-visible:after:-outline-offset-2 focus-visible:after:outline-(--ds-ring)"
                            >{{ figure.label }}</Link
                        >
                    </dt>
                    <dd class="mt-2 flex min-w-0 items-baseline gap-[5px]">
                        <span
                            class="text-[24px]/8 tracking-[-0.02em] text-fg-primary"
                            >{{ figure.value }}</span
                        >
                        <span
                            v-if="figure.suffix"
                            class="truncate text-[13px]/5 tracking-[-0.005em] text-fg-muted"
                            >{{ figure.suffix }}</span
                        >
                    </dd>
                    <dd
                        class="mt-[22px] flex min-w-0 items-center gap-[7px] text-[12px]/4 tracking-[-0.005em] text-fg-muted"
                    >
                        <span
                            v-if="figure.dot"
                            class="size-1.5 shrink-0 rounded-full"
                            :class="dots[figure.dot]"
                            aria-hidden="true"
                        />
                        <span class="truncate">{{ figure.foot }}</span>
                    </dd>
                </div>
            </dl>
        </div>
    </DashTray>
</template>
