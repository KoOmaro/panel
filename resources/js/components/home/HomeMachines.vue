<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';
import DotMeter from '@/components/charts/DotMeter.vue';
import BrandLogo from '@/components/dash/BrandLogo.vue';
import DashTable from '@/components/dash/DashTable.vue';
import DashTd from '@/components/dash/DashTd.vue';
import DashTh from '@/components/dash/DashTh.vue';
import DashTr from '@/components/dash/DashTr.vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import type { Machine } from '@/components/machines/machines';
import { brandMark } from '@/lib/brandLogos';
import { onWorkspace } from '@/lib/workspaceRoute';
import machinesRoutes from '@/routes/workspace/machines';
import type { WorkspaceStats } from '@/types';

const { machines, stats, memberCount } = defineProps<{
    machines: Machine[];
    stats: WorkspaceStats;
    memberCount: number;
}>();

const page = usePage<{ workspace: { slug: string } }>();
const slug = computed(() => page.props.workspace.slug);

const BUSY = 80;

const allHref = computed(
    () => onWorkspace(machinesRoutes.index(slug.value)).url,
);

function machineHref(machine: Machine): string | null {
    return machine.id
        ? onWorkspace(
              machinesRoutes.show({
                  workspace: slug.value,
                  machine: machine.id,
              }),
          ).url
        : null;
}

function open(machine: Machine): void {
    const href = machineHref(machine);
    if (href) router.visit(href);
}

const environments = computed(() => [
    { key: 'production', label: trans('Production') },
    { key: 'staging', label: trans('Staging') },
    { key: 'development', label: trans('Development') },
]);

const groups = computed(() =>
    environments.value
        .map((environment) => ({
            ...environment,
            rows: machines.filter(
                (machine) => machine.environment === environment.key,
            ),
        }))
        .filter((group) => group.rows.length > 0),
);

const facts = computed(() => [
    ...environments.value
        .map((environment) => ({
            ...environment,
            count: stats.environments[
                environment.key as keyof WorkspaceStats['environments']
            ],
        }))
        .filter((environment) => environment.count > 0)
        .map(
            (environment) =>
                `${environment.count} ${environment.label.toLowerCase()}`,
        ),
    `${stats.services} ${stats.services === 1 ? trans('service') : trans('services')}`,
    `${memberCount} ${memberCount === 1 ? trans('member') : trans('members')}`,
]);

const connectionTone: Record<NonNullable<Machine['connection']>, string> = {
    online: 'bg-status-live',
    degraded: 'bg-status-pending',
    offline: 'bg-status-down',
    connecting: 'bg-status-deploying',
    demo: 'bg-fg-faint',
};

function providerLabel(provider: string): string {
    return brandMark(provider)?.title ?? provider;
}
</script>

<template>
    <section class="dash-card" aria-labelledby="home-machines-heading">
        <div class="flex min-w-0 items-center gap-2 px-[18px] py-3">
            <h2
                id="home-machines-heading"
                class="text-[14px]/5 font-medium text-fg-primary"
            >
                {{ $t('Machines') }}
            </h2>
            <span class="text-[13px]/5 text-fg-tertiary tabular-nums">{{
                stats.machines
            }}</span>
            <span
                class="hidden min-w-0 truncate text-[13px]/5 text-fg-muted md:inline"
                >· {{ facts.join(' · ') }}</span
            >
            <Link
                :href="allHref"
                class="ml-auto flex shrink-0 items-center gap-1 rounded-md text-[12px]/4 font-medium text-fg-tertiary transition-colors hover:text-fg-primary focus-visible:outline-2 focus-visible:outline-(--ds-ring)"
            >
                {{ $t('All machines') }}
                <FilledIcon name="chevron-right" :size="14" />
            </Link>
        </div>
        <div class="border-t-[0.5px] border-edge-tabs">
            <DashTable>
                <template #head>
                    <DashTh first :width="200">{{ $t('Machine') }}</DashTh>
                    <DashTh :width="130">{{ $t('Provider') }}</DashTh>
                    <DashTh :width="128">{{ $t('CPU') }}</DashTh>
                    <DashTh :width="128">{{ $t('Disk') }}</DashTh>
                    <DashTh :width="92">{{ $t('Memory') }}</DashTh>
                    <DashTh fill>{{ $t('Backup') }}</DashTh>
                </template>
                <template v-for="group in groups" :key="group.key">
                    <tr>
                        <th
                            colspan="6"
                            scope="colgroup"
                            class="h-8 border-b-[0.5px] border-edge bg-surface-card-footer pl-5 text-left text-[12px]/4 font-medium text-fg-tertiary"
                        >
                            {{ group.label }}
                            <span class="ml-1 font-normal text-fg-muted">{{
                                group.rows.length
                            }}</span>
                        </th>
                    </tr>
                    <DashTr
                        v-for="machine in group.rows"
                        :key="machine.id ?? machine.name"
                        :clickable="!!machine.id"
                        @click="open(machine)"
                    >
                        <DashTd first>
                            <span
                                class="size-1.5 shrink-0 rounded-full"
                                :class="
                                    connectionTone[machine.connection ?? 'demo']
                                "
                                :title="machine.connection"
                            />
                            <component
                                :is="machine.id ? Link : 'span'"
                                :href="machineHref(machine) ?? undefined"
                                class="truncate font-medium tracking-[-0.01em] text-fg-primary focus-visible:outline-2 focus-visible:outline-(--ds-ring)"
                                @click.stop
                                >{{ machine.name }}</component
                            >
                            <span
                                class="truncate font-ui-mono text-[11.5px]/4 text-fg-muted max-sm:hidden"
                                >{{ machine.ip }}</span
                            >
                        </DashTd>
                        <DashTd>
                            <BrandLogo :name="machine.provider" />
                            <span class="truncate">{{
                                providerLabel(machine.provider)
                            }}</span>
                        </DashTd>
                        <DashTd>
                            <template v-if="machine.cpu !== null">
                                <DotMeter
                                    :value="machine.cpu"
                                    :warn-at="BUSY"
                                    :dots="8"
                                />
                                <span
                                    class="ml-auto font-ui-mono text-[12px]/4 tabular-nums"
                                    :class="
                                        machine.cpu >= BUSY
                                            ? 'text-status-pending'
                                            : 'text-fg-secondary'
                                    "
                                    >{{ machine.cpu }}%</span
                                >
                            </template>
                            <span v-else class="text-fg-faint">–</span>
                        </DashTd>
                        <DashTd>
                            <template
                                v-if="
                                    machine.diskPercent !== null &&
                                    machine.diskPercent !== undefined
                                "
                            >
                                <DotMeter
                                    :value="machine.diskPercent"
                                    :warn-at="BUSY"
                                    :dots="8"
                                />
                                <span
                                    class="ml-auto font-ui-mono text-[12px]/4 tabular-nums"
                                    :class="
                                        machine.diskPercent >= BUSY
                                            ? 'text-status-pending'
                                            : 'text-fg-secondary'
                                    "
                                    >{{ machine.diskPercent }}%</span
                                >
                            </template>
                            <span v-else class="text-fg-faint">–</span>
                        </DashTd>
                        <DashTd>
                            <span
                                class="font-ui-mono text-[12px]/4 tabular-nums"
                                >{{ machine.memory }}</span
                            >
                        </DashTd>
                        <DashTd>
                            <span class="text-fg-muted">{{
                                machine.lastBackup
                            }}</span>
                        </DashTd>
                    </DashTr>
                </template>
            </DashTable>
        </div>
    </section>
</template>
