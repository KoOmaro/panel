<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';
import type { Service, ServiceStat } from '@/components/machines/serviceFormat';
import { restartPolicy } from '@/components/service/serviceTypes';

const props = defineProps<{
    service: Service & { managed: boolean };
    stats: ServiceStat | null;
}>();

const rows = computed(() => {
    const s = props.service;
    const list: {
        key: string;
        label: string;
        value: string;
        mono?: boolean;
        wide?: boolean;
        warn?: boolean;
    }[] = [
        { key: 'key', label: trans('Key'), value: s.key, mono: true },
        {
            key: 'managed',
            label: trans('Managed by ServerOS'),
            value: s.managed
                ? s.origin === 'created'
                    ? trans('Yes, ServerOS created it')
                    : trans('Yes, adopted')
                : trans('No, discovered only'),
        },
        {
            key: 'started',
            label: trans('Started'),
            value: props.stats?.startedAt
                ? new Date(props.stats.startedAt * 1000).toLocaleString()
                : trans('Unknown'),
        },
        {
            key: 'processes',
            label: trans('Processes'),
            value:
                props.stats?.pids != null
                    ? String(props.stats.pids)
                    : trans('Unknown'),
        },
        {
            key: 'manager',
            label: trans('Runs under'),
            value: `${s.manager} · ${s.kind}`,
        },
    ];
    if (s.details?.image) {
        list.push({
            key: 'image',
            label: trans('Image'),
            value: s.details.image,
            mono: true,
        });
    }
    if (s.details?.compose_project) {
        list.push({
            key: 'compose',
            label: trans('Compose project'),
            value: s.details.compose_project,
            mono: true,
        });
    }
    if (s.details?.restart_policy) {
        list.push({
            key: 'policy',
            label: trans('Restart policy'),
            value: restartPolicy(s.details.restart_policy),
        });
    }
    list.push(
        {
            key: 'version',
            label: trans('Version'),
            value: s.version || trans('Unknown'),
        },
        {
            key: 'user',
            label: trans('Runs as'),
            value: s.user || trans('Unknown'),
        },
        {
            key: 'ports',
            label: trans('Ports'),
            value: s.ports.length ? s.ports.join(', ') : trans('None'),
            mono: true,
        },
        {
            key: 'capabilities',
            label: trans('Capabilities'),
            value: s.capabilities?.join(', ') || trans('Observation only'),
        },
        {
            key: 'dir',
            label: trans('Working directory'),
            value: s.working_dir || trans('Unknown'),
            mono: true,
            wide: true,
        },
        {
            key: 'exec',
            label: trans('Executable'),
            value: s.exec || trans('Unknown'),
            mono: true,
            wide: true,
        },
    );
    if (s.config_paths?.length) {
        list.push({
            key: 'config',
            label: trans('Config files'),
            value: s.config_paths.join(', '),
            mono: true,
            wide: true,
        });
    }
    if (s.details?.warning) {
        list.push({
            key: 'warning',
            label: trans('Heads up'),
            value: s.details.warning,
            wide: true,
            warn: true,
        });
    }

    return list;
});
</script>

<template>
    <dl
        class="dash-card grid shrink-0 gap-x-6 gap-y-4 border-edge-strong! p-[18px] text-[13px]/5 sm:grid-cols-2 lg:grid-cols-3"
    >
        <div
            v-for="row in rows"
            :key="row.key"
            class="flex min-w-0 flex-col gap-0.5"
            :class="row.wide && 'sm:col-span-2 lg:col-span-3'"
        >
            <dt :class="row.warn ? 'text-status-pending' : 'text-fg-muted'">
                {{ row.label }}
            </dt>
            <dd
                class="break-all text-fg-secondary"
                :class="row.mono && 'font-ui-mono text-[12px]/5'"
            >
                {{ row.value }}
            </dd>
        </div>
    </dl>
</template>
