<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import DashTab from '@/components/dash/DashTab.vue';
import DashTabs from '@/components/dash/DashTabs.vue';
import { onWorkspace } from '@/lib/workspaceRoute';
import monitorsRoutes from '@/routes/workspace/monitors';
import settingsRoutes from '@/routes/workspace/settings';
import workflowsRoutes from '@/routes/workspace/workflows';

export type MonitorTab = 'monitors' | 'incidents' | 'workflows';

const { active, local = false } = defineProps<{
    active: MonitorTab;
    local?: boolean;
}>();

const emit = defineEmits<{ select: [tab: 'monitors' | 'incidents'] }>();

const page = usePage<{ workspace: { slug: string } }>();
const slug = computed(() => page.props.workspace.slug);

const monitorsHref = computed(
    () => onWorkspace(monitorsRoutes.index(slug.value)).url,
);
const incidentsHref = computed(
    () =>
        onWorkspace(
            monitorsRoutes.index(slug.value, { query: { tab: 'incidents' } }),
        ).url,
);
const statusPageHref = computed(
    () => onWorkspace(settingsRoutes.statusPage(slug.value)).url,
);
const workflowsHref = computed(
    () => onWorkspace(workflowsRoutes.index(slug.value)).url,
);

function pick(tab: 'monitors' | 'incidents', event: MouseEvent): void {
    if (!local) return;
    event.preventDefault();
    emit('select', tab);
}
</script>

<template>
    <DashTabs>
        <DashTab
            :label="$t('Monitors')"
            icon="monitors-fill"
            :active="active === 'monitors'"
            :href="local ? undefined : monitorsHref"
            @click="pick('monitors', $event)"
        />
        <DashTab
            :label="$t('Incidents')"
            icon="activity-fill"
            :active="active === 'incidents'"
            :href="local ? undefined : incidentsHref"
            @click="pick('incidents', $event)"
        />
        <DashTab
            :label="$t('Status page')"
            icon="globe-fill"
            :href="statusPageHref"
        />
        <DashTab
            :label="$t('Workflows')"
            icon="branch-fill"
            :active="active === 'workflows'"
            :href="workflowsHref"
        />
    </DashTabs>
</template>
