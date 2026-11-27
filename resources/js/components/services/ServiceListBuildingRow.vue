<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';
import BrandLogo from '@/components/dash/BrandLogo.vue';
import DashTd from '@/components/dash/DashTd.vue';
import DashTr from '@/components/dash/DashTr.vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import type { BuildingService } from '@/components/machines/buildingServices';

const { entry, machineName } = defineProps<{
    entry: BuildingService;
    machineName: string;
}>();

const emit = defineEmits<{ open: [] }>();

const status = computed(() => {
    switch (entry.state) {
        case 'queued':
            return { dot: 'bg-status-deploying', label: trans('Queued') };
        case 'starting':
            return { dot: 'bg-status-deploying', label: trans('Starting') };
        case 'failed':
            return { dot: 'bg-status-down', label: trans('Deploy failed') };
        default:
            return { dot: 'bg-status-deploying', label: trans('Building') };
    }
});

const progress = computed(() =>
    entry.state === 'building' && entry.progress !== null
        ? `${Math.round(entry.progress)}%`
        : null,
);

const phase = computed(() =>
    [
        trans('Deploy'),
        entry.phase
            ? entry.phase.charAt(0).toUpperCase() + entry.phase.slice(1)
            : null,
    ]
        .filter(Boolean)
        .join(' · '),
);
</script>

<template>
    <DashTr
        clickable
        :aria-label="
            $t(':service: :state. Open the deploy log', {
                service: entry.name,
                state: status.label,
            })
        "
        @click="emit('open')"
    >
        <DashTd first :height="51.5">
            <span class="flex min-w-0 items-center gap-2">
                <BrandLogo :name="entry.name" :size="20" />
                <span class="flex min-w-0 flex-col">
                    <span
                        class="truncate tracking-[-0.005em] text-fg-primary"
                        >{{ entry.name }}</span
                    >
                    <span
                        class="truncate font-ui-mono text-[11px]/3.5 text-fg-faint"
                        >{{ phase }}</span
                    >
                </span>
            </span>
        </DashTd>
        <DashTd :height="51.5">
            <span
                class="size-1.5 shrink-0 rounded-full"
                :class="[
                    status.dot,
                    { 'animate-pulse': entry.state !== 'failed' },
                ]"
            />
            <span
                class="tracking-[-0.005em]"
                :class="{ 'text-status-down': entry.state === 'failed' }"
                >{{ status.label
                }}<span v-if="progress" class="text-fg-muted">{{
                    ' ' + progress
                }}</span></span
            >
        </DashTd>
        <DashTd :height="51.5">
            <span class="truncate tracking-[-0.005em]">{{ machineName }}</span>
        </DashTd>
        <DashTd v-for="n in 3" :key="n" :height="51.5">
            <span class="text-fg-faint">–</span>
        </DashTd>
        <DashTd :height="51.5" class="border-r-0!">
            <FilledIcon
                name="chevron-right"
                class="ml-auto text-fg-tertiary opacity-0 group-hover/row:opacity-100"
            />
        </DashTd>
    </DashTr>
</template>
