<script setup lang="ts">
import { computed } from 'vue';
import BrandLogo from '@/components/dash/BrandLogo.vue';
import DashTd from '@/components/dash/DashTd.vue';
import DashTr from '@/components/dash/DashTr.vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import type { WorkspaceService } from '@/components/machines/serviceFilters';
import UsageMeter from '@/components/machines/UsageMeter.vue';
import { bytesLabel } from '@/components/machines/machineFormat';
import {
    cpuOf,
    groupStatus,
    memoryOf,
    portsLabel,
} from '@/components/services/serviceList';
import type { ListMachine } from '@/components/services/serviceList';
import TreeGuide from '@/components/services/TreeGuide.vue';

const {
    name,
    kind,
    iconUrl = null,
    services,
    machine,
    open,
} = defineProps<{
    name: string;
    kind: 'group' | 'stack';
    iconUrl?: string | null;
    services: WorkspaceService[];
    machine: ListMachine | undefined;
    open: boolean;
}>();

const emit = defineEmits<{ toggle: [] }>();

const offline = computed(() => machine?.connection === 'offline');
const status = computed(() => groupStatus(services, offline.value));
const stats = computed(() => services.map((service) => service.stat));
const cpu = computed(() => (offline.value ? null : cpuOf(stats.value)));
const memory = computed(() =>
    offline.value ? null : memoryOf(stats.value, machine?.memoryBytes ?? null),
);
const ports = computed(() =>
    portsLabel(services.flatMap((service) => service.ports)),
);
</script>

<template>
    <DashTr
        clickable
        class="bg-surface-card-footer focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-(--ds-ring)"
        tabindex="0"
        :aria-expanded="open"
        @click="emit('toggle')"
        @keydown.enter.self.prevent="emit('toggle')"
        @keydown.space.self.prevent="emit('toggle')"
    >
        <DashTd first :height="47.5" class="relative">
            <TreeGuide v-if="open && services.length" kind="start" />
            <span class="-ml-1 flex min-w-0 items-center gap-2">
                <FilledIcon
                    :name="open ? 'chevron-down' : 'chevron-right'"
                    :size="14"
                    class="text-fg-muted"
                />
                <span
                    class="flex size-[26px] shrink-0 items-center justify-center overflow-hidden rounded-[7px] border-[0.5px] border-edge-strong bg-surface-badge shadow-tile dark:border-[#383838]"
                    aria-hidden="true"
                >
                    <img
                        v-if="iconUrl"
                        :src="iconUrl"
                        alt=""
                        class="size-full object-cover"
                    />
                    <BrandLogo
                        v-else-if="kind === 'stack'"
                        name="docker"
                        :size="15"
                    />
                    <FilledIcon
                        v-else
                        name="folder-fill"
                        :size="15"
                        class="text-fg-tertiary"
                    />
                </span>
                <span class="flex min-w-0 flex-col">
                    <span
                        class="truncate font-medium tracking-[-0.005em] text-fg-primary"
                        >{{ name }}</span
                    >
                    <span
                        class="truncate text-[12px]/4 tracking-[-0.005em] text-fg-muted"
                        >{{
                            kind === 'stack'
                                ? services.length === 1
                                    ? $t('Compose stack · 1 service')
                                    : $t('Compose stack · :count services', {
                                          count: String(services.length),
                                      })
                                : services.length === 1
                                  ? $t('Group · 1 service')
                                  : $t('Group · :count services', {
                                        count: String(services.length),
                                    })
                        }}</span
                    >
                </span>
            </span>
        </DashTd>
        <DashTd :height="47.5">
            <span class="size-1.5 shrink-0 rounded-full" :class="status.dot" />
            <span class="tracking-[-0.005em]" :class="status.tone">{{
                status.label
            }}</span>
        </DashTd>
        <DashTd :height="47.5">
            <span
                class="truncate tracking-[-0.005em]"
                :class="{ 'text-fg-muted': offline }"
                >{{ machine?.name }}</span
            >
        </DashTd>
        <DashTd :height="47.5">
            <UsageMeter
                v-if="cpu !== null"
                :percent="Math.min(100, cpu)"
                class="gap-2!"
                ><span class="text-fg-primary">{{ cpu }}%</span></UsageMeter
            >
            <span v-else class="text-fg-faint">–</span>
        </DashTd>
        <DashTd :height="47.5">
            <UsageMeter v-if="memory" :percent="memory.percent" class="gap-2!"
                ><span class="text-fg-primary">{{
                    bytesLabel(memory.used)
                }}</span></UsageMeter
            >
            <span v-else class="text-fg-faint">–</span>
        </DashTd>
        <DashTd :height="47.5">
            <span
                v-if="ports.shown"
                class="truncate font-ui-mono text-[12px]/4 text-fg-secondary"
                >{{ ports.shown
                }}<template v-if="ports.more">{{
                    ' +' + ports.more
                }}</template></span
            >
            <span v-else class="text-fg-faint">–</span>
        </DashTd>
        <DashTd :height="47.5" class="border-r-0!" />
    </DashTr>
</template>
