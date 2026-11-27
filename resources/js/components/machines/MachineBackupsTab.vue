<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';
import DashButton from '@/components/dash/DashButton.vue';
import DashTable from '@/components/dash/DashTable.vue';
import DashTd from '@/components/dash/DashTd.vue';
import DashTh from '@/components/dash/DashTh.vue';
import DashTr from '@/components/dash/DashTr.vue';
import StatusBadge from '@/components/dash/StatusBadge.vue';
import type { DashStatus } from '@/components/dash/StatusBadge.vue';
import type { MachineOverviewData } from '@/components/machines/MachineOverview.vue';
import { bytesLabel } from '@/components/machines/machineFormat';
import { errorMessage, failIfBad } from '@/lib/errors';
import { timeAgo } from '@/lib/timeAgo';
import { toast } from '@/lib/toast';
import { onWorkspace } from '@/lib/workspaceRoute';
import backupsRoutes from '@/routes/workspace/backups';
import machineBackups from '@/routes/workspace/machines/backups';

const props = defineProps<{
    machineId: number;
    workspaceSlug: string;
    backup: MachineOverviewData['backup'];
    backups: MachineOverviewData['backups'];
    canManage: boolean;
}>();

const emit = defineEmits<{ changed: [] }>();

const running = ref(false);

const scheduleLabel = computed(() => {
    const schedule =
        props.backup.schedule === 'daily'
            ? trans('Every day')
            : props.backup.schedule === 'weekly'
              ? trans('Every week')
              : trans('Off');

    return props.backup.destination && props.backup.schedule !== 'off'
        ? trans(':schedule, to :destination', {
              schedule,
              destination: props.backup.destination,
          })
        : schedule;
});

const backupsUrl = computed(
    () => onWorkspace(backupsRoutes.index(props.workspaceSlug)).url,
);

function status(state: string): { status: DashStatus; label: string } {
    if (state === 'succeeded') return { status: 'live', label: trans('Done') };
    if (['failed', 'timed_out', 'refused'].includes(state))
        return { status: 'down', label: trans('Failed') };
    if (state === 'cancelled')
        return { status: 'stopped', label: trans('Cancelled') };

    return { status: 'deploying', label: trans('Running') };
}

function csrf(): string {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}

async function runNow(): Promise<void> {
    if (running.value) return;
    running.value = true;
    try {
        await failIfBad(
            await fetch(
                onWorkspace(
                    machineBackups.run({
                        workspace: props.workspaceSlug,
                        machine: props.machineId,
                    }),
                ).url,
                {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': csrf(),
                    },
                },
            ),
        );
        toast.success(trans('Backup started'));
        emit('changed');
    } catch (cause) {
        toast.error(errorMessage(cause));
    } finally {
        running.value = false;
    }
}
</script>

<template>
    <div class="flex flex-col gap-3 px-6 py-4">
        <section class="dash-card flex flex-col overflow-hidden">
            <div class="flex flex-wrap items-center gap-3 px-[18px] py-3">
                <div class="flex min-w-0 flex-1 flex-col">
                    <h2
                        class="text-[14px]/5 font-medium tracking-[-0.01em] text-fg-primary"
                    >
                        {{ $t('Backups') }}
                    </h2>
                    <p class="text-[13px]/5 text-fg-tertiary">
                        {{ scheduleLabel }}
                    </p>
                </div>
                <DashButton
                    icon="settings-fill"
                    :label="$t('Schedule and destination')"
                    :href="backupsUrl"
                />
                <DashButton
                    v-if="canManage"
                    icon="upload-fill"
                    :label="$t('Back up now')"
                    :loading="running"
                    @click="runNow"
                />
            </div>
            <DashTable
                v-if="backups.length"
                class="border-t-[0.5px] border-edge-row"
            >
                <template #head>
                    <DashTh icon="layers-fill" :width="260" first>{{
                        $t('Service')
                    }}</DashTh>
                    <DashTh icon="activity-fill" :width="160">{{
                        $t('When')
                    }}</DashTh>
                    <DashTh icon="database-fill" :width="120">{{
                        $t('Size')
                    }}</DashTh>
                    <DashTh icon="upload-fill" :width="180">{{
                        $t('Destination')
                    }}</DashTh>
                    <DashTh icon="check" fill class="border-r-0!">{{
                        $t('Status')
                    }}</DashTh>
                </template>
                <DashTr v-for="backup in backups" :key="backup.id">
                    <DashTd first>
                        <span
                            class="max-w-[240px] truncate font-medium tracking-[-0.01em] text-fg-primary"
                            :title="backup.serviceName"
                            >{{ backup.serviceName }}</span
                        >
                    </DashTd>
                    <DashTd>
                        <span
                            :title="
                                backup.createdAt
                                    ? new Date(
                                          backup.createdAt,
                                      ).toLocaleString()
                                    : undefined
                            "
                            >{{ timeAgo(backup.createdAt) }}</span
                        >
                    </DashTd>
                    <DashTd>
                        <span
                            class="font-ui-mono text-[12px] text-fg-tertiary"
                            >{{
                                backup.bytes ? bytesLabel(backup.bytes) : '–'
                            }}</span
                        >
                    </DashTd>
                    <DashTd>{{ backup.destination ?? '–' }}</DashTd>
                    <DashTd class="border-r-0!">
                        <StatusBadge
                            :status="status(backup.state).status"
                            :label="status(backup.state).label"
                        />
                        <span
                            v-if="backup.error"
                            class="max-w-[320px] truncate text-[12px] text-fg-muted"
                            :title="backup.error"
                            >{{ backup.error }}</span
                        >
                    </DashTd>
                </DashTr>
            </DashTable>
            <p
                v-else
                class="border-t-[0.5px] border-edge-row px-[18px] py-3 text-[13px]/5 text-fg-muted"
            >
                {{
                    backup.schedule === 'off'
                        ? $t(
                              'No backups yet. Pick a schedule and a destination in Backups.',
                          )
                        : $t('No backups yet. The first runs on schedule.')
                }}
            </p>
        </section>
    </div>
</template>
