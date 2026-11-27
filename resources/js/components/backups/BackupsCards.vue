<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';
import BrandLogo from '@/components/dash/BrandLogo.vue';
import DashTray from '@/components/dash/DashTray.vue';
import GaugeDial from '@/components/dash/GaugeDial.vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import CardLine from '@/components/summary/CardLine.vue';
import type { CardLineState } from '@/components/summary/CardLine.vue';
import StatGrid from '@/components/summary/StatGrid.vue';
import type { Stat } from '@/components/summary/StatGrid.vue';
import { destinationKind, size, sizeParts, when } from './backups';
import type { BackupsSummary, Destination } from './backups';

const { summary, destinations, canManage } = defineProps<{
    summary: BackupsSummary;
    destinations: Destination[];
    canManage: boolean;
}>();

defineEmits<{ addDestination: []; openDestinations: [] }>();

const stored = computed(() => sizeParts(summary.stored));

const stats = computed<[Stat, Stat, Stat, Stat]>(() => [
    { label: trans('Services'), value: summary.services },
    {
        label: trans('Stored'),
        value: summary.stored ? stored.value.value : 0,
        unit: summary.stored ? stored.value.unit : undefined,
        title: trans('Everything backed up to your destinations so far'),
    },
    {
        label: trans('Failed'),
        value: summary.day.failed,
        title: trans('In the last 24 hours'),
    },
    {
        label: trans('Restores'),
        value: summary.restores,
        title: trans('In the last 30 days'),
    },
]);

const destinationRows = computed(() => {
    const rows = destinations.map((destination) => {
        const kind = destinationKind(destination);

        return {
            id: destination.id,
            name: destination.name,
            logo: kind.logo,
            label: kind.label,
            failed: destination.status === 'failed',
            detail: [
                destination.stored ? size(destination.stored) : null,
                destination.machines === 1
                    ? trans('1 machine')
                    : trans(':count machines', {
                          count: String(destination.machines),
                      }),
            ]
                .filter(Boolean)
                .join(', '),
        };
    });

    return rows.length > 3 ? rows.slice(0, 2) : rows;
});

const destinationNote = computed(() =>
    destinations.length === 0
        ? trans('none yet')
        : summary.stored
          ? trans(':count, :size stored', {
                count: String(destinations.length),
                size: size(summary.stored),
            })
          : String(destinations.length),
);

type Line = {
    key: string;
    state: CardLineState;
    label: string;
    value?: string;
};

const lines = computed<Line[]>(() => {
    const day = summary.day;
    const list = day.total > 5 ? day.shown.slice(0, 4) : day.shown;
    const rows: Line[] = list.map((b) => ({
        key: String(b.id),
        state:
            b.state === 'queued' || b.state === 'running'
                ? 'running'
                : b.state === 'succeeded'
                  ? 'done'
                  : b.state === 'failed'
                    ? 'failed'
                    : 'skipped',
        label:
            b.state === 'queued' || b.state === 'running'
                ? trans('Backing up :service on :machine…', {
                      service: b.service,
                      machine: b.machine,
                  })
                : trans(':service on :machine', {
                      service: b.service,
                      machine: b.machine,
                  }),
        value:
            b.state === 'succeeded'
                ? size(b.bytes)
                : b.state === 'failed'
                  ? trans('failed')
                  : undefined,
    }));
    const rest = day.total - list.length;
    if (rest > 0) {
        const failedRest =
            day.failed - list.filter((b) => b.state === 'failed').length;
        rows.push({
            key: 'more',
            state: 'waiting',
            label: trans(':count more', { count: String(rest) }),
            value:
                failedRest > 0
                    ? trans(':count failed', { count: String(failedRest) })
                    : trans('all done'),
        });
    }

    return rows;
});

const dayNote = computed(() => {
    const day = summary.day;
    const parts = [trans(':count done', { count: String(day.done) })];
    if (day.failed)
        parts.push(trans(':count failed', { count: String(day.failed) }));
    if (day.running)
        parts.push(trans(':count running', { count: String(day.running) }));

    return parts.join(', ');
});
</script>

<template>
    <div
        class="grid grid-cols-1 gap-3 px-5 pt-3.5 pb-1 md:grid-cols-2 xl:flex xl:items-stretch xl:[&>*]:min-h-[214px]"
    >
        <DashTray
            icon="upload-fill"
            :title="$t('Backups')"
            class="shrink-0 md:col-span-2 xl:w-[480px]"
            panel-class="px-[18px] py-3"
        >
            <template #meta>
                <span class="text-fg-tertiary">{{
                    summary.lastRunAt
                        ? $t('last run :when', {
                              when: when(summary.lastRunAt, true),
                          })
                        : $t('nothing has run yet')
                }}</span>
            </template>
            <div class="flex items-center gap-[18px]">
                <GaugeDial
                    :value="
                        summary.machines
                            ? (100 * summary.covered) / summary.machines
                            : 0
                    "
                    :readout="
                        $t(':covered of :total', {
                            covered: String(summary.covered),
                            total: String(summary.machines),
                        })
                    "
                    :label="$t('Covered')"
                    tone="green"
                />
                <GaugeDial
                    :value="summary.succeededRate ?? 0"
                    :readout="
                        summary.succeededRate === null
                            ? '–'
                            : `${Math.floor(summary.succeededRate)}%`
                    "
                    :label="$t('Succeeded')"
                    tone="sky"
                />
                <StatGrid :stats="stats" class="max-sm:hidden" />
            </div>
        </DashTray>

        <DashTray
            icon="database-fill"
            :title="$t('Destinations')"
            class="min-w-0 flex-1"
            panel-class="gap-2.5 px-[18px] py-3"
        >
            <template #meta>
                <span class="text-fg-tertiary">{{ destinationNote }}</span>
            </template>
            <template v-if="destinations.length">
                <div
                    v-for="row in destinationRows"
                    :key="row.id"
                    class="flex items-start gap-3"
                >
                    <span class="flex w-4 shrink-0 justify-center pt-px">
                        <BrandLogo
                            v-if="row.logo"
                            :name="row.logo"
                            :fallback="false"
                        />
                        <FilledIcon
                            v-else
                            name="database-fill"
                            class="text-fg-muted"
                        />
                    </span>
                    <span class="flex min-w-0 flex-col">
                        <span
                            class="truncate text-[13px]/[18px] font-medium tracking-[-0.01em] text-fg-primary"
                            >{{ row.name }}</span
                        >
                        <span
                            class="flex min-w-0 gap-1 text-[13px]/5 tracking-[-0.005em] whitespace-nowrap"
                        >
                            <span class="text-fg-secondary">{{
                                row.label
                            }}</span>
                            <span
                                class="truncate"
                                :class="
                                    row.failed
                                        ? 'text-status-down'
                                        : 'text-fg-faint'
                                "
                                >{{
                                    row.failed ? $t('setup failed') : row.detail
                                }}</span
                            >
                        </span>
                    </span>
                </div>
                <button
                    v-if="destinations.length > 3"
                    type="button"
                    class="flex h-5 cursor-pointer items-center gap-2.5 text-left text-[13px]/5 text-fg-secondary hover:text-fg-primary"
                    @click="$emit('openDestinations')"
                >
                    <FilledIcon name="database-fill" class="text-fg-tertiary" />
                    {{
                        $t(':count more', {
                            count: String(destinations.length - 2),
                        })
                    }}
                </button>
            </template>
            <template v-else>
                <p class="text-[13px]/5 text-pretty text-fg-tertiary">
                    {{
                        $t(
                            'Backups go somewhere off the machine: an S3 bucket, or another of your machines over SFTP.',
                        )
                    }}
                </p>
                <button
                    v-if="canManage"
                    type="button"
                    class="self-start text-[13px]/5 font-medium text-fg-secondary underline-offset-4 hover:text-fg-primary hover:underline"
                    @click="$emit('addDestination')"
                >
                    {{ $t('Add a destination') }}
                </button>
            </template>
        </DashTray>

        <DashTray
            icon="layers-fill"
            :title="$t('Last 24 hours')"
            class="min-w-0 flex-1"
            panel-class="gap-2.5 px-[18px] py-3"
        >
            <template #meta>
                <span class="text-fg-tertiary">{{
                    summary.day.total ? dayNote : $t('nothing ran')
                }}</span>
            </template>
            <CardLine
                v-for="line in lines"
                :key="line.key"
                :state="line.state"
                :label="line.label"
                :value="line.value"
            />
            <p
                v-if="!lines.length"
                class="text-[13px]/5 text-pretty text-fg-tertiary"
            >
                {{
                    $t(
                        'Scheduled backups run a day or a week after the last one, and Back up now runs one straight away.',
                    )
                }}
            </p>
        </DashTray>
    </div>
</template>
