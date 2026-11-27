<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    CircleCheck,
    CircleMinus,
    CircleX,
    Ellipsis,
    LoaderCircle,
    RotateCcw,
} from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import type { Component } from 'vue';
import BrandLogo from '@/components/dash/BrandLogo.vue';
import DashTable from '@/components/dash/DashTable.vue';
import DashTd from '@/components/dash/DashTd.vue';
import DashTh from '@/components/dash/DashTh.vue';
import DashTr from '@/components/dash/DashTr.vue';
import TintBadge from '@/components/summary/TintBadge.vue';
import type { TintTone } from '@/components/summary/TintBadge.vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { workspacePath } from '@/lib/workspaceRoute';
import { size, when } from './backups';
import type { BackupRow, BackupState } from './backups';

const { history, canManage } = defineProps<{
    history: BackupRow[];
    canManage: boolean;
}>();

const emit = defineEmits<{ restore: [backup: BackupRow] }>();

const badges: Record<
    BackupState,
    { tone: TintTone; icon: Component; label: string; spin?: boolean }
> = {
    succeeded: { tone: 'green', icon: CircleCheck, label: trans('Done') },
    failed: { tone: 'red', icon: CircleX, label: trans('Failed') },
    running: {
        tone: 'blue',
        icon: LoaderCircle,
        label: trans('Running'),
        spin: true,
    },
    queued: {
        tone: 'blue',
        icon: LoaderCircle,
        label: trans('Queued'),
        spin: true,
    },
    skipped: {
        tone: 'grey',
        icon: CircleMinus,
        label: trans('Nothing to keep'),
    },
};

const triggers: Record<BackupRow['trigger'], string> = {
    manual: trans('By hand'),
    scheduled: trans('Scheduled'),
    workflow: trans('By a workflow'),
    'pre-restore': trans('Taken before a restore'),
    'pre-deploy': trans('Taken before a deploy'),
};

function restoreNote(backup: BackupRow): string | null {
    const restore = backup.restore;
    if (!restore) return null;
    if (restore.state === 'failed') return trans('Restore failed');
    if (restore.state === 'succeeded')
        return trans('Restored :when', { when: when(restore.at, true) });

    return trans('Restoring…');
}

const serviceHref = (backup: BackupRow) =>
    workspacePath(`/machines/${backup.machine.id}/services/${backup.service}`);
</script>

<template>
    <DashTable>
        <template #head>
            <DashTh icon="layers-fill" :width="250" first>{{
                $t('Service')
            }}</DashTh>
            <DashTh icon="machines-fill" :width="140">{{
                $t('Machine')
            }}</DashTh>
            <DashTh icon="setup-fill" :width="150">{{ $t('Status') }}</DashTh>
            <DashTh icon="zip-fill" :width="96">{{ $t('Size') }}</DashTh>
            <DashTh icon="database-fill" :width="270">{{ $t('Where') }}</DashTh>
            <DashTh icon="activity-fill" :width="150">{{ $t('When') }}</DashTh>
            <DashTh fill class="border-r-0!" />
        </template>
        <DashTr v-for="backup in history" :key="backup.id">
            <DashTd first>
                <BrandLogo :name="backup.serviceLabel" />
                <span class="flex min-w-0 flex-col">
                    <Link
                        :href="serviceHref(backup)"
                        class="max-w-[180px] truncate text-[13px]/[18px] font-medium tracking-[-0.01em] text-fg-primary hover:underline hover:underline-offset-2"
                        :title="backup.serviceName"
                        >{{ backup.serviceLabel }}</Link
                    >
                    <span class="truncate text-[11.5px]/4 text-fg-tertiary">
                        {{ triggers[backup.trigger] ?? $t('By hand') }}
                        <template v-if="restoreNote(backup)">
                            ·
                            <span
                                :class="{
                                    'text-status-down':
                                        backup.restore?.state === 'failed',
                                    'text-status-deploying': [
                                        'queued',
                                        'running',
                                    ].includes(backup.restore?.state ?? ''),
                                }"
                                :title="backup.restore?.error ?? undefined"
                                >{{ restoreNote(backup) }}</span
                            >
                        </template>
                    </span>
                </span>
            </DashTd>
            <DashTd>
                <span class="truncate">{{ backup.machine.name }}</span>
            </DashTd>
            <DashTd>
                <TintBadge
                    :tone="badges[backup.state].tone"
                    :icon="badges[backup.state].icon"
                    :spin="badges[backup.state].spin"
                    :label="badges[backup.state].label"
                />
            </DashTd>
            <DashTd>
                <span
                    v-if="backup.bytes !== null"
                    class="font-ui-mono text-[12px]/4 text-fg-secondary"
                    >{{ size(backup.bytes) }}</span
                >
                <span v-else class="font-medium text-fg-faint">–</span>
            </DashTd>
            <DashTd>
                <span
                    v-if="backup.error"
                    class="max-w-[250px] truncate text-[12px]/4 text-status-down"
                    :title="backup.error"
                    >{{ backup.error }}</span
                >
                <span
                    v-else-if="backup.location"
                    class="max-w-[250px] truncate font-ui-mono text-[11.5px]/4 text-fg-tertiary"
                    :title="backup.location"
                    >{{ backup.location }}</span
                >
                <span
                    v-else-if="backup.trigger === 'pre-restore'"
                    class="truncate text-[12px]/4 text-fg-tertiary"
                    >{{
                        $t('Kept on :name', { name: backup.machine.name })
                    }}</span
                >
                <span
                    v-else-if="backup.destination"
                    class="truncate text-[12px]/4 text-fg-tertiary"
                    >{{ backup.destination }}</span
                >
                <span v-else class="font-medium text-fg-faint">–</span>
            </DashTd>
            <DashTd>
                <span
                    class="text-[12px]/4 text-fg-tertiary"
                    :title="new Date(backup.createdAt).toLocaleString()"
                    >{{ when(backup.createdAt) }}</span
                >
            </DashTd>
            <DashTd class="border-r-0! [&>div]:justify-end">
                <DropdownMenu v-if="canManage && backup.state === 'succeeded'">
                    <DropdownMenuTrigger as-child>
                        <button
                            type="button"
                            class="flex size-6 cursor-pointer items-center justify-center rounded-[6px] text-fg-muted hover:bg-surface-raised hover:text-fg-secondary focus-visible:outline-2 focus-visible:outline-(--ds-ring) data-[state=open]:bg-surface-raised"
                            :aria-label="
                                $t('Actions for the :serviceName backup', {
                                    serviceName: backup.serviceLabel,
                                })
                            "
                        >
                            <Ellipsis class="size-4" :stroke-width="2" />
                        </button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        align="end"
                        class="max-w-72 min-w-44 font-ui"
                    >
                        <DropdownMenuItem
                            :disabled="backup.cannotRestore !== null"
                            @select="emit('restore', backup)"
                        >
                            <RotateCcw class="size-4" :stroke-width="1.75" />
                            {{
                                backup.trigger === 'pre-restore'
                                    ? $t('Undo the restore…')
                                    : $t('Restore…')
                            }}
                        </DropdownMenuItem>
                        <p
                            v-if="backup.cannotRestore"
                            class="px-2 pt-1 pb-1.5 text-[12px]/4 text-fg-muted"
                        >
                            {{ backup.cannotRestore }}
                        </p>
                    </DropdownMenuContent>
                </DropdownMenu>
            </DashTd>
        </DashTr>
    </DashTable>
</template>
