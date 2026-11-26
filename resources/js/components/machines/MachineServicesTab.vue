<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { Link } from '@inertiajs/vue3';
import { Check, LoaderCircle } from '@lucide/vue';
import { computed, ref } from 'vue';
import type { Component } from 'vue';
import DashButton from '@/components/dash/DashButton.vue';
import DashTable from '@/components/dash/DashTable.vue';
import DashTd from '@/components/dash/DashTd.vue';
import DashTh from '@/components/dash/DashTh.vue';
import DashTr from '@/components/dash/DashTr.vue';
import SearchWell from '@/components/dash/SearchWell.vue';
import ToolbarButton from '@/components/dash/ToolbarButton.vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import DeployWorkflowsMenu from '@/components/machines/DeployWorkflowsMenu.vue';
import type { DaemonJob } from '@/components/machines/JobLogDialog.vue';
import ServicesTable from '@/components/machines/ServicesTable.vue';
import type {
    ServiceGroup,
    ServiceStat,
} from '@/components/machines/ServicesTable.vue';
import type { BuildingService } from '@/components/machines/buildingServices';
import type { Machine } from '@/components/machines/machines';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { jobReason } from '@/lib/errors';
import { jobStatus } from '@/lib/jobStatus';
import type { Tone } from '@/lib/jobStatus';
import {
    SolarCircleCheck as CircleCheck,
    SolarCircleSlash as CircleSlash,
    SolarCircleX as CircleX,
} from '@/lib/solarIcons';
import { timeAgo } from '@/lib/timeAgo';
import { workspacePath } from '@/lib/workspaceRoute';

export type ScheduledTask = {
    command: string;
    schedule: string;
    when: string | null;
    user: string | null;
    kind: 'cron' | 'timer';
    source: string;
    description: string | null;
    nextRun: string | null;
    lastRun: string | null;
    service: { key: string; name: string } | null;
    ours: boolean;
};

type JobFilter = 'all' | 'active' | 'done' | 'failed';
type JobSort = 'newest' | 'oldest' | 'service';

const props = defineProps<{
    machine: Machine & { serviceStats?: Record<string, ServiceStat> };
    services: NonNullable<Machine['inventory']>['services'];
    building: BuildingService[];
    jobs: DaemonJob[];
    serviceGroups: ServiceGroup[];
    scheduled: ScheduledTask[];
    deployEnabled: boolean;
    deployWorkflows: { available: boolean; count: number };
    canManage: boolean;
    refreshing: boolean;
}>();

const emit = defineEmits<{ refresh: []; openLog: [jobId: string] }>();

function formatDate(timestamp: number | undefined): string {
    return timestamp
        ? new Date(timestamp * 1000).toLocaleString()
        : trans('Not scanned yet');
}

const FAILED_STATES = ['failed', 'refused', 'timed_out', 'cancelled'];

function jobGroup(state: string): Exclude<JobFilter, 'all'> {
    if (state === 'succeeded') return 'done';
    if (FAILED_STATES.includes(state)) return 'failed';

    return 'active';
}

const jobFilter = ref<JobFilter>('all');
const jobSearch = ref('');
const jobSort = ref<JobSort>('newest');

const deployJobs = computed(() =>
    props.jobs.filter((job) => job.kind !== 'service_exec'),
);

const jobTabs = computed(() =>
    (
        [
            ['all', trans('All')],
            ['active', trans('In progress')],
            ['done', trans('Done')],
            ['failed', trans('Failed')],
        ] as const
    ).map(([value, label]) => ({
        value,
        label,
        count:
            value === 'all'
                ? deployJobs.value.length
                : deployJobs.value.filter(
                      (job) => jobGroup(job.state) === value,
                  ).length,
    })),
);

const jobSortOptions: { value: JobSort; label: string }[] = [
    { value: 'newest', label: trans('Newest') },
    { value: 'oldest', label: trans('Oldest') },
    { value: 'service', label: trans('Service') },
];

const jobSortLabel = computed(
    () =>
        jobSortOptions.find((option) => option.value === jobSort.value)
            ?.label ?? trans('Newest'),
);

const visibleJobs = computed(() => {
    const needle = jobSearch.value.trim().toLowerCase();
    const time = (job: DaemonJob) =>
        job.createdAt ? Date.parse(job.createdAt) : 0;

    return deployJobs.value
        .filter(
            (job) =>
                jobFilter.value === 'all' ||
                jobGroup(job.state) === jobFilter.value,
        )
        .filter(
            (job) =>
                !needle || (job.service ?? '').toLowerCase().includes(needle),
        )
        .toSorted((a, b) =>
            jobSort.value === 'service'
                ? (a.service ?? '').localeCompare(b.service ?? '')
                : jobSort.value === 'oldest'
                  ? time(a) - time(b)
                  : time(b) - time(a),
        );
});

const jobTone: Record<Tone, string> = {
    green: 'text-status-live',
    red: 'text-status-down',
    amber: 'text-status-pending',
    blue: 'text-status-deploying',
    neutral: 'text-fg-faint',
};

function jobIcon(job: DaemonJob): Component {
    const { tone, busy } = jobStatus(job.state);
    if (busy) return LoaderCircle;
    if (tone === 'green') return CircleCheck;
    if (tone === 'red') return CircleX;

    return CircleSlash;
}

function jobDetail(job: DaemonJob): string {
    if (job.error) return job.explanation?.cause ?? jobReason(job.error, '');
    if (job.state === 'succeeded' && job.result?.container) {
        return trans('Container: :container · port :port', {
            container: String(job.result.container ?? ''),
            port: String(job.result.port ?? ''),
        });
    }

    return '';
}

function serviceUrl(key: string): string {
    return workspacePath(`/machines/${props.machine.id}/services/${key}`);
}
</script>

<template>
    <div class="flex flex-col gap-6 px-6 py-4">
        <div
            v-if="!deployEnabled"
            class="dash-card px-[18px] py-3 text-[13px]/5 text-fg-secondary"
        >
            {{
                $t(
                    'Deploys aren’t set up on this panel yet. Services and listening ports still show below.',
                )
            }}
        </div>

        <section class="flex flex-col gap-3" :aria-label="$t('Services')">
            <div class="flex flex-wrap items-center gap-2">
                <h2
                    class="text-[14px]/5 font-medium tracking-[-0.01em] text-fg-primary"
                >
                    {{ $t('Running and discovered services') }}
                </h2>
                <span class="grow" />
                <span class="text-[12px]/4 text-fg-muted">{{
                    $t('Last scan: :value', {
                        value: formatDate(machine.inventory?.scanned_at),
                    })
                }}</span>
                <DashButton
                    size="sm"
                    icon="history"
                    :label="$t('Scan again')"
                    :loading="refreshing"
                    @click="emit('refresh')"
                />
            </div>
            <p
                v-if="machine.inventory && !machine.inventory.complete"
                class="text-[13px]/5 text-status-pending"
            >
                {{
                    $t(
                        'The last scan was incomplete, so some services may be missing.',
                    )
                }}
            </p>
            <ServicesTable
                v-if="services.length || building.length"
                :services="services"
                :building="building"
                :stats="machine.serviceStats ?? {}"
                :machine-id="machine.id!"
                :groups="serviceGroups"
                :can-manage="canManage"
                @open-log="emit('openLog', $event)"
            />
            <p
                v-else
                class="dash-card px-[18px] py-3 text-[13px]/5 text-fg-muted"
            >
                {{
                    machine.inventory
                        ? $t('No named services were found in the last scan.')
                        : $t('Waiting for the daemon’s first discovery scan.')
                }}
            </p>
        </section>

        <section
            id="deployments"
            class="flex flex-col gap-3"
            :aria-label="$t('Deployments')"
        >
            <div class="flex flex-wrap items-center gap-2">
                <h2
                    class="text-[14px]/5 font-medium tracking-[-0.01em] text-fg-primary"
                >
                    {{ $t('Deployments') }}
                </h2>
                <span class="grow" />
                <template v-if="deployJobs.length">
                    <div
                        class="flex gap-0.5 rounded-[7px] border-[0.5px] border-edge-row bg-surface-well p-0.5"
                        role="group"
                        :aria-label="$t('Filter by status')"
                    >
                        <button
                            v-for="option in jobTabs"
                            :key="option.value"
                            type="button"
                            class="flex h-[26px] cursor-pointer items-center gap-1.5 rounded-[5px] px-2.5 text-[12.5px]/4 font-medium focus-visible:outline-2 focus-visible:outline-(--ds-ring)"
                            :class="
                                jobFilter === option.value
                                    ? 'border-[0.5px] border-[#dcdcdc] bg-white text-fg-primary shadow-card dark:border-[#363636] dark:bg-[#262626]'
                                    : 'text-fg-tertiary hover:text-fg-secondary'
                            "
                            :aria-pressed="jobFilter === option.value"
                            @click="jobFilter = option.value"
                        >
                            {{ option.label }}
                            <span class="text-fg-faint tabular-nums">{{
                                option.count
                            }}</span>
                        </button>
                    </div>
                    <SearchWell
                        v-model="jobSearch"
                        :placeholder="$t('Search by service')"
                        :width="220"
                    />
                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <ToolbarButton
                                icon="sort-fill"
                                :label="jobSortLabel"
                            />
                        </DropdownMenuTrigger>
                        <DropdownMenuContent
                            class="ds-modal min-w-40 font-ui"
                            align="end"
                        >
                            <DropdownMenuItem
                                v-for="option in jobSortOptions"
                                :key="option.value"
                                @select="jobSort = option.value"
                            >
                                {{ option.label }}
                                <Check
                                    v-if="jobSort === option.value"
                                    class="ml-auto size-3.5"
                                />
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </template>
                <DeployWorkflowsMenu
                    v-if="canManage && deployWorkflows.count === 0"
                    :available="deployWorkflows.available"
                />
            </div>
            <ul
                v-if="visibleJobs.length"
                class="dash-card flex flex-col overflow-hidden"
            >
                <li
                    v-for="job in visibleJobs"
                    :key="job.id"
                    class="border-b-[0.5px] border-edge-row last:border-b-0"
                >
                    <button
                        type="button"
                        class="group flex min-h-11 w-full cursor-pointer items-center gap-3 px-[18px] py-2 text-left text-[13px]/5 transition-colors hover:bg-surface-hover focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-(--ds-ring)"
                        :aria-label="
                            job.service
                                ? $t('View the log for :service', {
                                      service: job.service,
                                  })
                                : $t('View the log for this deploy')
                        "
                        @click="emit('openLog', job.id)"
                    >
                        <component
                            :is="jobIcon(job)"
                            class="size-4 shrink-0"
                            :class="[
                                jobTone[jobStatus(job.state).tone],
                                { 'animate-spin': jobStatus(job.state).busy },
                            ]"
                            :stroke-width="2"
                            aria-hidden="true"
                        />
                        <span
                            class="shrink-0 font-medium tracking-[-0.01em] text-fg-primary"
                        >
                            {{ job.service ?? $t('Deploy') }}
                        </span>
                        <span class="min-w-0 truncate text-fg-muted">
                            {{ jobDetail(job) }}
                        </span>
                        <span
                            class="ml-auto flex shrink-0 items-center gap-3 text-fg-muted tabular-nums"
                        >
                            <span
                                class="hidden sm:inline"
                                :class="jobTone[jobStatus(job.state).tone]"
                                >{{
                                    jobStatus(
                                        job.state,
                                        job.phase,
                                        job.progress,
                                    ).label
                                }}</span
                            >
                            <span
                                class="hidden w-24 text-right md:inline"
                                :title="
                                    job.createdAt
                                        ? new Date(
                                              job.createdAt,
                                          ).toLocaleString()
                                        : undefined
                                "
                                >{{ timeAgo(job.createdAt) }}</span
                            >
                            <FilledIcon
                                name="chevron-right"
                                :size="14"
                                class="text-fg-faint"
                            />
                        </span>
                    </button>
                </li>
            </ul>
            <p
                v-else
                class="dash-card px-[18px] py-3 text-[13px]/5 text-fg-muted"
            >
                {{
                    deployJobs.length
                        ? $t('No deployments match these filters.')
                        : $t(
                              'No deployments yet. Deploy a service to start one.',
                          )
                }}
            </p>
        </section>

        <section
            id="scheduled"
            class="flex flex-col gap-3"
            :aria-label="$t('Scheduled tasks')"
        >
            <div class="flex flex-wrap items-baseline gap-2">
                <h2
                    class="text-[14px]/5 font-medium tracking-[-0.01em] text-fg-primary"
                >
                    {{ $t('Scheduled tasks') }}
                </h2>
                <span class="text-[12px]/4 text-fg-muted">{{
                    $t('Cron jobs and systemd timers, from the last scan')
                }}</span>
            </div>
            <div v-if="scheduled.length" class="dash-card overflow-hidden">
                <DashTable>
                    <template #head>
                        <DashTh icon="terminal-fill" :width="320" first>{{
                            $t('Task')
                        }}</DashTh>
                        <DashTh icon="history" :width="200">{{
                            $t('Runs')
                        }}</DashTh>
                        <DashTh icon="user-fill" :width="110">{{
                            $t('As')
                        }}</DashTh>
                        <DashTh icon="activity-fill" fill class="border-r-0!">{{
                            $t('Last and next')
                        }}</DashTh>
                    </template>
                    <DashTr
                        v-for="(task, index) in scheduled"
                        :key="`${task.source}-${task.command}-${index}`"
                    >
                        <DashTd first :height="52">
                            <span class="flex min-w-0 flex-col">
                                <span
                                    class="max-w-[420px] truncate font-ui-mono text-[12px]/4 text-fg-primary"
                                    :title="task.command"
                                    >{{ task.command }}</span
                                >
                                <span
                                    class="max-w-[420px] truncate text-[12px]/4 text-fg-muted"
                                >
                                    {{
                                        task.description ??
                                        (task.kind === 'timer'
                                            ? task.source
                                            : task.source.replace(
                                                  /^crontab:/,
                                                  $t('crontab of') + ' ',
                                              ))
                                    }}
                                    <template v-if="task.service">
                                        ·
                                        <Link
                                            :href="serviceUrl(task.service.key)"
                                            class="font-medium text-fg-secondary hover:underline hover:underline-offset-4"
                                            >{{ task.service.name }}</Link
                                        >
                                    </template>
                                    <template v-if="task.ours">
                                        {{ ' ' + $t('· set up by ServerOS') }}
                                    </template>
                                </span>
                            </span>
                        </DashTd>
                        <DashTd :height="52">
                            <span class="flex min-w-0 flex-col">
                                <span
                                    v-if="task.when"
                                    class="first-letter:uppercase"
                                    >{{ task.when }}</span
                                >
                                <span
                                    class="truncate font-ui-mono text-[11.5px]/4"
                                    :class="
                                        task.when
                                            ? 'text-fg-faint'
                                            : 'text-fg-secondary'
                                    "
                                    >{{ task.schedule }}</span
                                >
                            </span>
                        </DashTd>
                        <DashTd :height="52">{{ task.user ?? '–' }}</DashTd>
                        <DashTd :height="52" class="border-r-0!">
                            <span
                                class="flex flex-col text-[12px]/4 text-fg-muted"
                            >
                                <template v-if="task.lastRun || task.nextRun">
                                    <span v-if="task.lastRun">{{
                                        $t('Last ran :value', {
                                            value: timeAgo(task.lastRun),
                                        })
                                    }}</span>
                                    <span v-if="task.nextRun">{{
                                        $t('Next :value', {
                                            value: timeAgo(task.nextRun),
                                        })
                                    }}</span>
                                </template>
                                <span v-else class="text-fg-faint">{{
                                    task.kind === 'cron'
                                        ? $t('Cron doesn’t record runs')
                                        : $t('Not scheduled to run')
                                }}</span>
                            </span>
                        </DashTd>
                    </DashTr>
                </DashTable>
            </div>
            <p
                v-else
                class="dash-card px-[18px] py-3 text-[13px]/5 text-fg-muted"
            >
                {{
                    machine.inventory
                        ? $t(
                              'No cron jobs or timers were found in the last scan.',
                          )
                        : $t('Waiting for the daemon’s first discovery scan.')
                }}
            </p>
        </section>
    </div>
</template>
