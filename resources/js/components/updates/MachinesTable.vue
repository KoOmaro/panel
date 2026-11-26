<script setup lang="ts">
import { Lock, RotateCw } from '@lucide/vue';
import BrandLogo from '@/components/dash/BrandLogo.vue';
import DashTable from '@/components/dash/DashTable.vue';
import DashTd from '@/components/dash/DashTd.vue';
import DashTh from '@/components/dash/DashTh.vue';
import DashTr from '@/components/dash/DashTr.vue';
import SpinnerArc from '@/components/summary/SpinnerArc.vue';
import { machineOnline, shortAgo } from './updates';
import type { UpdatesMachine } from './updates';

const { machines, checking } = defineProps<{
    machines: UpdatesMachine[];
    checking: number[];
}>();

defineEmits<{ open: [machine: UpdatesMachine] }>();
</script>

<template>
    <DashTable>
        <template #head>
            <DashTh icon="machines-fill" :width="250" first>{{
                $t('Machine')
            }}</DashTh>
            <DashTh icon="layers-fill" :width="200">{{ $t('System') }}</DashTh>
            <DashTh icon="update-fill" :width="160">{{ $t('Updates') }}</DashTh>
            <DashTh icon="monitors-fill" :width="140">{{
                $t('Security')
            }}</DashTh>
            <DashTh icon="lock-fill" :width="120">{{ $t('Held') }}</DashTh>
            <DashTh icon="activity-fill" fill class="border-r-0!">{{
                $t('Status')
            }}</DashTh>
        </template>
        <DashTr
            v-for="machine in machines"
            :key="machine.id"
            clickable
            @click="$emit('open', machine)"
        >
            <DashTd first>
                <BrandLogo :name="machine.provider" />
                <span
                    class="truncate font-medium tracking-[-0.01em] text-fg-primary"
                    >{{ machine.name }}</span
                >
                <span
                    v-if="machine.ip"
                    class="truncate font-ui-mono text-[11.5px]/4 text-fg-faint"
                    >{{ machine.ip }}</span
                >
            </DashTd>
            <DashTd>
                <span class="truncate">{{ machine.os ?? '–' }}</span>
            </DashTd>
            <DashTd>
                <span v-if="!machine.checkedAt" class="text-fg-faint">{{
                    $t('Not checked yet')
                }}</span>
                <span v-else-if="machine.updates === 0" class="text-fg-muted">{{
                    $t('Up to date')
                }}</span>
                <span
                    v-else
                    class="font-medium tracking-[-0.01em] tabular-nums"
                    >{{
                        machine.updates === 1
                            ? $t('1 update')
                            : $t(':count updates', {
                                  count: String(machine.updates),
                              })
                    }}</span
                >
            </DashTd>
            <DashTd>
                <span
                    v-if="machine.security"
                    class="flex items-center gap-2 font-medium text-fg-primary tabular-nums"
                >
                    <span
                        class="size-1.5 rounded-full bg-status-down"
                        aria-hidden="true"
                    />
                    {{ machine.security }}
                </span>
                <span v-else class="font-medium text-fg-faint">–</span>
            </DashTd>
            <DashTd>
                <span
                    v-if="machine.held.length"
                    class="flex items-center gap-1.5 text-fg-tertiary"
                    :title="machine.held.join(', ')"
                >
                    <Lock
                        class="size-3"
                        :stroke-width="2.2"
                        aria-hidden="true"
                    />
                    {{ machine.held.length }}
                </span>
                <span v-else class="font-medium text-fg-faint">–</span>
            </DashTd>
            <DashTd class="border-r-0!">
                <template v-if="checking.includes(machine.id)">
                    <SpinnerArc />
                    <span class="text-fg-tertiary">{{ $t('Checking…') }}</span>
                </template>
                <template v-else-if="machine.rebootRequired">
                    <RotateCw
                        class="size-[13px] text-status-pending"
                        :stroke-width="2.2"
                        aria-hidden="true"
                    />
                    <span class="font-medium text-status-pending">{{
                        $t('Restart needed')
                    }}</span>
                </template>
                <template v-else>
                    <span
                        class="size-1.5 shrink-0 rounded-full"
                        :class="
                            machineOnline(machine)
                                ? 'bg-status-live'
                                : 'bg-fg-muted'
                        "
                        aria-hidden="true"
                    />
                    <span class="text-fg-tertiary">{{
                        machineOnline(machine) ? $t('Online') : $t('Offline')
                    }}</span>
                </template>
                <span
                    v-if="machine.checkedAt"
                    class="ml-auto text-[12px]/4 text-fg-muted"
                    :title="new Date(machine.checkedAt).toLocaleString()"
                    >{{
                        $t('checked :when', {
                            when: shortAgo(machine.checkedAt),
                        })
                    }}</span
                >
            </DashTd>
        </DashTr>
    </DashTable>
</template>
