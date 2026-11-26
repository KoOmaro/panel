<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';
import GaugeDial from '@/components/dash/GaugeDial.vue';
import CardLine from '@/components/summary/CardLine.vue';
import type { CardLineState } from '@/components/summary/CardLine.vue';
import SeverityRing from '@/components/summary/SeverityRing.vue';
import StatGrid from '@/components/summary/StatGrid.vue';
import SummaryCard from '@/components/summary/SummaryCard.vue';
import { severityColour, severityLabel, shortAgo } from './updates';
import type { RolloutDetail, SecuritySummary, UpdatesSummary } from './updates';

const { summary, security, rollout, checking } = defineProps<{
    summary: UpdatesSummary;
    security: SecuritySummary;
    rollout: RolloutDetail | null;
    checking: number;
}>();

defineEmits<{ openRollout: [id: number] }>();

const checkedNote = computed(() => {
    if (checking > 0) {
        return checking === 1
            ? trans('checking 1 machine')
            : trans('checking :count machines', { count: String(checking) });
    }

    return summary.newestCheck
        ? trans('checked :when', { when: shortAgo(summary.newestCheck) })
        : trans('not checked yet');
});

const share = (part: number, whole: number) =>
    whole > 0 ? Math.min(100, (100 * part) / whole) : 0;

const stats = computed(
    () =>
        [
            { label: trans('Packages'), value: summary.packages },
            {
                label: trans('Machines'),
                value: summary.machines,
                unit: trans('of :total', { total: String(summary.total) }),
            },
            {
                label: trans('Restarts'),
                value: summary.rebootRequired,
                title: trans('Machines that need a restart to finish updating'),
            },
            {
                label: trans('Held'),
                value: summary.held,
                title: trans('Packages held back on at least one machine'),
            },
        ] as const,
);

const levels = computed(() =>
    (['critical', 'high', 'medium'] as const).map((severity) => {
        const level = security.levels[severity];
        const where =
            level.packages.length === 1
                ? trans('in :name', { name: level.packages[0] })
                : trans('in :count packages', {
                      count: String(level.packages.length),
                  });

        return {
            severity,
            label: severityLabel(severity),
            colour: severityColour[severity],
            share: security.cves > 0 ? level.cves / security.cves : 0,
            count:
                level.cves === 1
                    ? trans('1 CVE')
                    : trans(':count CVEs', { count: String(level.cves) }),
            detail:
                level.cves === 0
                    ? trans('none known')
                    : level.exploited
                      ? trans(':where, :count exploited', {
                            where,
                            count: String(level.exploited),
                        })
                      : where,
        };
    }),
);

const securityNote = computed(() =>
    security.updates === 0
        ? trans('nothing waiting')
        : trans(':updates updates in :packages packages', {
              updates: String(security.updates),
              packages: String(security.packages),
          }),
);

type Line = {
    key: string;
    state: CardLineState;
    label: string;
    value?: string;
};

const lines = computed<Line[]>(() => {
    if (!rollout) return [];
    const targets = rollout.machines;
    const running: Line[] = targets
        .filter((t) => t.state === 'running')
        .map((t) => ({
            key: `r${t.machineId}`,
            state: 'running',
            label: t.rebootJobId
                ? trans('Restarting :name…', { name: t.name })
                : trans('Installing on :name…', { name: t.name }),
        }));
    const finished: Line[] = targets
        .filter((t) => ['done', 'failed', 'skipped'].includes(t.state))
        .sort((a, b) => (b.finishedAt ?? '').localeCompare(a.finishedAt ?? ''))
        .map((t) => ({
            key: `f${t.machineId}`,
            state: t.state as CardLineState,
            label: t.name,
            value:
                t.state === 'done'
                    ? t.installed.length === 1
                        ? trans('1 update')
                        : trans(':count updates', {
                              count: String(t.installed.length),
                          })
                    : t.state === 'failed'
                      ? trans('failed')
                      : trans('skipped'),
        }));
    const waiting = targets.filter((t) => t.state === 'pending');
    const tail: Line[] = waiting.length
        ? [
              {
                  key: 'waiting',
                  state: 'waiting',
                  label: trans(':count waiting', {
                      count: String(waiting.length),
                  }),
                  value: trans('next: :name', { name: waiting[0].name }),
              },
          ]
        : [];

    return [
        ...running,
        ...finished.slice(0, Math.max(0, 5 - running.length - tail.length)),
        ...tail,
    ].slice(0, 5);
});

const rolloutNote = computed(() => {
    if (!rollout) return null;
    if (rollout.state === 'pending' && rollout.scheduledFor) {
        return trans('starts :when', {
            when: new Date(rollout.scheduledFor).toLocaleString(undefined, {
                weekday: 'short',
                hour: '2-digit',
                minute: '2-digit',
            }),
        });
    }

    return trans(':finished of :total done', {
        finished: String(rollout.finished),
        total: String(rollout.total),
    });
});
</script>

<template>
    <div
        class="grid grid-cols-1 gap-3 px-5 pt-3.5 pb-1 md:grid-cols-2 xl:flex xl:items-stretch xl:[&>*]:min-h-[195px]"
    >
        <SummaryCard
            :title="$t('Updates')"
            :note="checkedNote"
            class="shrink-0 md:col-span-2 xl:w-[480px]"
        >
            <div class="flex items-center gap-[18px]">
                <GaugeDial
                    :value="share(summary.machines, summary.total)"
                    :readout="String(summary.updates)"
                    :label="$t('Waiting')"
                    tone="amber"
                />
                <GaugeDial
                    :value="share(summary.security, summary.updates)"
                    :readout="String(summary.security)"
                    :label="$t('Security')"
                    tone="red"
                />
                <StatGrid :stats="[...stats]" class="max-sm:hidden" />
            </div>
        </SummaryCard>

        <SummaryCard
            :title="$t('Security')"
            :note="securityNote"
            class="min-w-0 flex-1"
        >
            <div
                v-for="level in levels"
                :key="level.severity"
                class="flex items-start gap-3"
            >
                <span class="pt-px">
                    <SeverityRing :value="level.share" :colour="level.colour" />
                </span>
                <span class="flex min-w-0 flex-col">
                    <span
                        class="text-[13px]/[18px] font-medium tracking-[-0.01em] text-fg-primary"
                        >{{ level.label }}</span
                    >
                    <span
                        class="flex min-w-0 gap-1 text-[13px]/5 tracking-[-0.005em] whitespace-nowrap"
                    >
                        <span class="text-fg-secondary">{{ level.count }}</span>
                        <span class="truncate text-fg-tertiary">{{
                            level.detail
                        }}</span>
                    </span>
                </span>
            </div>
        </SummaryCard>

        <SummaryCard
            v-if="rollout"
            as="button"
            type="button"
            :title="rollout.name"
            :note="rolloutNote"
            class="min-w-0 flex-1 cursor-pointer transition-colors hover:border-edge-control focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring)"
            :aria-label="$t('Open the :name rollout', { name: rollout.name })"
            @click="$emit('openRollout', rollout.id)"
        >
            <CardLine
                v-for="line in lines"
                :key="line.key"
                :state="line.state"
                :label="line.label"
                :value="line.value"
            />
        </SummaryCard>
        <SummaryCard
            v-else
            :title="$t('Rollouts')"
            :note="$t('none yet')"
            class="min-w-0 flex-1"
        >
            <p class="text-[13px]/5 text-pretty text-fg-tertiary">
                {{
                    $t(
                        'Pick packages below and roll them out to every machine that has them, all at once or a few at a time. Progress shows here.',
                    )
                }}
            </p>
        </SummaryCard>
    </div>
</template>
