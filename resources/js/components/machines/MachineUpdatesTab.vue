<script setup lang="ts">
import DashButton from '@/components/dash/DashButton.vue';
import DashTable from '@/components/dash/DashTable.vue';
import DashTd from '@/components/dash/DashTd.vue';
import DashTh from '@/components/dash/DashTh.vue';
import DashTr from '@/components/dash/DashTr.vue';
import StatusBadge from '@/components/dash/StatusBadge.vue';
import type { MachineOverviewData } from '@/components/machines/MachineOverview.vue';
import { timeAgo } from '@/lib/timeAgo';

defineProps<{
    updates: MachineOverviewData['updates'];
    canManage: boolean;
    connected: boolean;
}>();

const emit = defineEmits<{ review: []; scan: [] }>();
</script>

<template>
    <div class="flex flex-col gap-3 px-6 py-4">
        <section class="dash-card flex flex-col overflow-hidden">
            <div class="flex flex-wrap items-center gap-3 px-[18px] py-3">
                <div class="flex min-w-0 flex-1 flex-col">
                    <h2
                        class="text-[14px]/5 font-medium tracking-[-0.01em] text-fg-primary"
                    >
                        {{
                            updates.count === 0
                                ? $t('Up to date')
                                : updates.count === 1
                                  ? $t('1 update waiting')
                                  : $t(':count updates waiting', {
                                        count: String(updates.count),
                                    })
                        }}
                    </h2>
                    <p class="text-[13px]/5 text-fg-tertiary">
                        <template v-if="updates.security">
                            {{
                                $t(':count for security', {
                                    count: String(updates.security),
                                })
                            }}
                            ·
                        </template>
                        {{
                            updates.checkedAt
                                ? $t('Checked :ago', {
                                      ago: timeAgo(updates.checkedAt),
                                  })
                                : $t('Not checked yet')
                        }}
                    </p>
                </div>
                <DashButton
                    v-if="canManage"
                    icon="lock-fill"
                    :label="$t('Security scan')"
                    @click="emit('scan')"
                />
                <DashButton
                    v-if="canManage"
                    icon="update-fill"
                    :label="
                        updates.count
                            ? $t('Review and install')
                            : $t('Check for updates')
                    "
                    :disabled="!connected"
                    @click="emit('review')"
                />
            </div>
            <p
                v-if="updates.rebootRequired"
                class="border-t-[0.5px] border-edge-row px-[18px] py-2.5 text-[13px]/5 text-status-pending"
            >
                {{
                    $t(
                        'An installed update waits for a restart. Restart from Review and install when it suits you.',
                    )
                }}
            </p>
            <DashTable
                v-if="updates.packages.length"
                class="border-t-[0.5px] border-edge-row"
            >
                <template #head>
                    <DashTh icon="package-fill" :width="260" first>{{
                        $t('Package')
                    }}</DashTh>
                    <DashTh icon="history" :width="220">{{
                        $t('Installed')
                    }}</DashTh>
                    <DashTh icon="update-fill" :width="220">{{
                        $t('New')
                    }}</DashTh>
                    <DashTh icon="lock-fill" fill class="border-r-0!">{{
                        $t('Kind')
                    }}</DashTh>
                </template>
                <DashTr v-for="update in updates.packages" :key="update.name">
                    <DashTd first>
                        <span
                            class="font-medium tracking-[-0.01em] text-fg-primary"
                            >{{ update.name }}</span
                        >
                    </DashTd>
                    <DashTd>
                        <span
                            class="max-w-[200px] truncate font-ui-mono text-[12px] text-fg-muted"
                            >{{ update.from ?? '–' }}</span
                        >
                    </DashTd>
                    <DashTd>
                        <span
                            class="max-w-[200px] truncate font-ui-mono text-[12px] text-fg-secondary"
                            >{{ update.to ?? '–' }}</span
                        >
                    </DashTd>
                    <DashTd class="border-r-0!">
                        <StatusBadge
                            v-if="update.security"
                            status="pending"
                            :label="$t('Security')"
                        />
                        <span v-else class="text-fg-muted">{{
                            $t('Regular')
                        }}</span>
                    </DashTd>
                </DashTr>
            </DashTable>
            <p
                v-if="updates.packages.length < updates.count"
                class="border-t-[0.5px] border-edge-row px-[18px] py-2.5 text-[12px]/4 text-fg-muted"
            >
                {{
                    $t(
                        'Showing :shown of :count. Review and install lists them all.',
                        {
                            shown: String(updates.packages.length),
                            count: String(updates.count),
                        },
                    )
                }}
            </p>
        </section>
    </div>
</template>
