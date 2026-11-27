<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';
import DashButton from '@/components/dash/DashButton.vue';
import DashTray from '@/components/dash/DashTray.vue';
import HomeGraph from '@/components/home/HomeGraph.vue';
import type { GraphChip } from '@/components/home/HomeGraph.vue';
import type { HomeBackups } from '@/components/home/home';
import { onWorkspace } from '@/lib/workspaceRoute';
import backupsRoutes from '@/routes/workspace/backups';
import machinesRoutes from '@/routes/workspace/machines';

const { backups } = defineProps<{ backups: HomeBackups }>();

const page = usePage<{ workspace: { slug: string } }>();
const slug = computed(() => page.props.workspace.slug);
const backupsHref = computed(
    () => onWorkspace(backupsRoutes.index(slug.value)).url,
);

const meta = computed(() => {
    const { failed, covered, machines } = backups;
    if (failed > 0) {
        return {
            text: trans(':count failed', { count: String(failed) }),
            tone: 'text-[#b7791f] dark:text-[#f5a833]',
        };
    }
    if (covered === 0) return { text: trans('off'), tone: '' };

    return {
        text:
            covered < machines
                ? trans(':count not backed up', {
                      count: String(machines - covered),
                  })
                : trans('all backed up'),
        tone: '',
    };
});

function clock(iso: string): string {
    return new Date(iso).toLocaleTimeString([], {
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
    });
}

function when(iso: string): string {
    const at = new Date(iso);
    const today = new Date();
    const yesterday = new Date();
    yesterday.setDate(today.getDate() - 1);
    const time = clock(iso);
    if (at.toDateString() === today.toDateString()) {
        return trans('today at :time', { time });
    }
    if (at.toDateString() === yesterday.toDateString()) {
        return trans('yesterday at :time', { time });
    }

    return trans('on :date at :time', {
        date: at.toLocaleDateString([], { day: 'numeric', month: 'short' }),
        time,
    });
}

const schedules: Record<string, string> = {
    daily: trans('Daily'),
    weekly: trans('Weekly'),
};

const center = computed<GraphChip>(() => ({
    key: 'run',
    label: backups.lastRunAt
        ? clock(backups.lastRunAt)
        : backups.schedule
          ? schedules[backups.schedule]
          : trans('Off'),
    icon: 'archive',
    href: backupsHref.value,
}));

const left = computed<GraphChip[]>(() =>
    backups.sources.map((machine) => ({
        key: machine.id,
        label: machine.name,
        icon: machine.database ? 'database-fill' : 'machines-fill',
        href: onWorkspace(
            machinesRoutes.show({ workspace: slug.value, machine: machine.id }),
        ).url,
    })),
);

const right = computed<GraphChip[]>(() =>
    backups.destinations.map((destination) => ({
        key: destination.id,
        label: destination.name,
        logo: destination.logo,
        icon: 'upload-fill',
        href: backupsHref.value,
    })),
);

const summary = computed(() => {
    const { covered, machines, lastRunAt, failed } = backups;
    if (covered === 0) return trans('No machine is backed up yet.');
    const share = trans(':covered of :machines machines backed up.', {
        covered: String(covered),
        machines: String(machines),
    });
    if (!lastRunAt) return share;
    const run =
        failed > 0
            ? trans('Last run :when, :failed failed.', {
                  when: when(lastRunAt),
                  failed: String(failed),
              })
            : trans('Last run :when.', { when: when(lastRunAt) });

    return `${share} ${run}`;
});

const graphLabel = computed(() =>
    trans(':sources back up to :destinations', {
        sources: backups.sources.map((machine) => machine.name).join(', '),
        destinations: backups.destinations.map((d) => d.name).join(', '),
    }),
);
</script>

<template>
    <DashTray icon="upload-fill" :title="$t('Backups')">
        <template #meta>
            <span :class="meta.tone">{{ meta.text }}</span>
        </template>
        <div class="flex justify-center overflow-hidden">
            <HomeGraph
                :left="left"
                :center="center"
                :right="right"
                :left-gap="24"
                :right-gap="40"
                :label="backups.covered === 0 ? summary : graphLabel"
            />
        </div>
        <div
            class="flex items-center gap-3 border-t-[0.5px] border-[#e3e3e3] py-3 pr-3 pl-4 dark:border-[#2a2a2a]"
        >
            <p
                class="min-w-0 grow text-[13px]/5 tracking-[-0.005em] text-fg-secondary"
            >
                {{ summary }}
            </p>
            <DashButton
                size="sm"
                :href="backupsHref"
                :label="backups.covered === 0 ? $t('Set up') : $t('Open')"
            />
        </div>
    </DashTray>
</template>
