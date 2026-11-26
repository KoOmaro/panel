<script setup lang="ts">
import {
    CircleCheck,
    CircleDashed,
    CirclePause,
    CircleX,
    LoaderCircle,
} from '@lucide/vue';
import type { Component } from 'vue';
import DashTable from '@/components/dash/DashTable.vue';
import DashTd from '@/components/dash/DashTd.vue';
import DashTh from '@/components/dash/DashTh.vue';
import DashTr from '@/components/dash/DashTr.vue';
import TintBadge from '@/components/summary/TintBadge.vue';
import type { TintTone } from '@/components/summary/TintBadge.vue';
import { rolloutStatus, shortAgo } from './updates';
import type { Rollout } from './updates';
import type { Tone } from '@/lib/jobStatus';

const { rollouts } = defineProps<{ rollouts: Rollout[] }>();

defineEmits<{ open: [id: number] }>();

const tones: Record<Tone, TintTone> = {
    green: 'green',
    red: 'red',
    amber: 'amber',
    blue: 'blue',
    neutral: 'grey',
};

const icons: Record<Rollout['state'], Component> = {
    pending: CircleDashed,
    running: LoaderCircle,
    paused: CirclePause,
    done: CircleCheck,
    failed: CircleX,
    cancelled: CircleDashed,
};
</script>

<template>
    <DashTable>
        <template #head>
            <DashTh icon="update-fill" :width="300" first>{{
                $t('Rollout')
            }}</DashTh>
            <DashTh icon="table-fill" :width="140">{{ $t('Versions') }}</DashTh>
            <DashTh icon="activity-fill" :width="150">{{
                $t('Status')
            }}</DashTh>
            <DashTh icon="machines-fill" :width="200">{{
                $t('Machines')
            }}</DashTh>
            <DashTh icon="user-fill" :width="160">{{ $t('By') }}</DashTh>
            <DashTh icon="history" fill class="border-r-0!">{{
                $t('Started')
            }}</DashTh>
        </template>
        <DashTr
            v-for="rollout in rollouts"
            :key="rollout.id"
            clickable
            @click="$emit('open', rollout.id)"
        >
            <DashTd first>
                <span
                    class="truncate font-medium tracking-[-0.01em] text-fg-primary"
                    >{{ rollout.name }}</span
                >
            </DashTd>
            <DashTd>
                <span class="text-fg-tertiary">{{
                    rollout.mode === 'exact'
                        ? $t('Exact versions')
                        : $t('Latest')
                }}</span>
            </DashTd>
            <DashTd>
                <TintBadge
                    :tone="tones[rolloutStatus(rollout).tone]"
                    :icon="icons[rollout.state]"
                    :spin="rollout.state === 'running'"
                    :label="rolloutStatus(rollout).label"
                />
            </DashTd>
            <DashTd>
                <span class="tabular-nums">{{
                    $t(':finished of :total done', {
                        finished: String(rollout.finished),
                        total: String(rollout.total),
                    })
                }}</span>
                <span v-if="rollout.failed" class="text-status-down">{{
                    $t(':count failed', { count: String(rollout.failed) })
                }}</span>
            </DashTd>
            <DashTd>
                <span class="truncate text-fg-tertiary">{{
                    rollout.by ?? '–'
                }}</span>
            </DashTd>
            <DashTd class="border-r-0!">
                <span
                    class="text-fg-muted"
                    :title="
                        rollout.createdAt
                            ? new Date(rollout.createdAt).toLocaleString()
                            : undefined
                    "
                    >{{
                        rollout.state === 'pending' && rollout.scheduledFor
                            ? $t('Starts :when', {
                                  when: new Date(
                                      rollout.scheduledFor,
                                  ).toLocaleString(),
                              })
                            : shortAgo(rollout.startedAt ?? rollout.createdAt)
                    }}</span
                >
            </DashTd>
        </DashTr>
    </DashTable>
</template>
