<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';
import DashButton from '@/components/dash/DashButton.vue';
import DashTray from '@/components/dash/DashTray.vue';
import HomeGraph from '@/components/home/HomeGraph.vue';
import type { GraphChip } from '@/components/home/HomeGraph.vue';
import type { HomeSecurity } from '@/components/home/home';
import { onWorkspace } from '@/lib/workspaceRoute';
import machinesRoutes from '@/routes/workspace/machines';
import updatesRoutes from '@/routes/workspace/updates';

const { security } = defineProps<{ security: HomeSecurity }>();

const page = usePage<{ workspace: { slug: string } }>();
const slug = computed(() => page.props.workspace.slug);
const updatesHref = computed(
    () => onWorkspace(updatesRoutes.index(slug.value)).url,
);

const meta = computed(() => {
    const { critical, high, updates, checkedAt } = security;
    if (critical > 0) {
        return {
            text: trans(':count critical', { count: String(critical) }),
            tone: 'text-status-down',
        };
    }
    if (high > 0) {
        return {
            text: trans(':count high', { count: String(high) }),
            tone: 'text-[#b7791f] dark:text-[#f5a833]',
        };
    }
    if (updates > 0) {
        return {
            text: trans(':count waiting', { count: String(updates) }),
            tone: '',
        };
    }

    return {
        text: checkedAt ? trans('up to date') : trans('not checked yet'),
        tone: '',
    };
});

const severities: Record<string, string> = {
    critical: trans('critical'),
    high: trans('high'),
    medium: trans('medium'),
    low: trans('low'),
    unknown: trans('unrated'),
};

const center = computed<GraphChip & { dot?: 'red' | 'amber' | null }>(() => {
    const { package: name, severity, checkedAt } = security;
    if (name) {
        return {
            key: 'package',
            label: name,
            icon: 'package-fill',
            href: updatesHref.value,
            dot:
                severity === 'critical' || severity === 'high'
                    ? 'red'
                    : severity === 'medium'
                      ? 'amber'
                      : null,
        };
    }

    return {
        key: 'state',
        label: checkedAt ? trans('Up to date') : trans('Not checked'),
        icon: 'lock-fill',
        href: updatesHref.value,
    };
});

const right = computed<GraphChip[]>(() => {
    const chips: GraphChip[] = security.machines.map((machine) => ({
        key: machine.id,
        label: machine.name,
        icon: 'machines-fill',
        href: onWorkspace(
            machinesRoutes.show({ workspace: slug.value, machine: machine.id }),
        ).url,
    }));
    if (security.package && security.affected > chips.length) {
        const kept = chips.slice(0, chips.length - 1);

        return [
            ...kept,
            {
                key: 'more',
                label: trans('+:count more', {
                    count: String(security.affected - kept.length),
                }),
                icon: 'machines-fill',
                href: updatesHref.value,
            },
        ];
    }

    return chips;
});

function waiting(updates: number): string {
    if (updates === 0) return '';

    return updates === 1
        ? trans('1 security update is waiting.')
        : trans(':count security updates are waiting.', {
              count: String(updates),
          });
}

const summary = computed(() => {
    const { package: name, cves, severity, updates, checkedAt } = security;
    if (name && cves > 0) {
        const found =
            cves === 1
                ? trans('1 :severity CVE in :package.', {
                      severity: severities[severity ?? 'unknown'],
                      package: name,
                  })
                : trans(':count :severity CVEs in :package.', {
                      count: String(cves),
                      severity: severities[severity ?? 'unknown'],
                      package: name,
                  });

        return [found, waiting(updates)].filter(Boolean).join(' ');
    }
    if (updates > 0) return waiting(updates);

    return checkedAt
        ? trans('No security updates waiting.')
        : trans('Updates haven’t been checked yet.');
});

const graphLabel = computed(() =>
    security.package
        ? trans(':package on :machines', {
              package: security.package,
              machines: security.machines.map((m) => m.name).join(', '),
          })
        : summary.value,
);
</script>

<template>
    <DashTray icon="lock-fill" :title="$t('Security')">
        <template #meta>
            <span :class="meta.tone">{{ meta.text }}</span>
        </template>
        <div class="flex justify-center overflow-hidden">
            <HomeGraph
                :center="center"
                :right="right"
                :right-gap="100"
                :label="graphLabel"
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
                :href="updatesHref"
                :label="security.package ? $t('Review') : $t('Open')"
            />
        </div>
    </DashTray>
</template>
