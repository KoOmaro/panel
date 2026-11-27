<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Ellipsis } from '@lucide/vue';
import { computed } from 'vue';
import BrandLogo from '@/components/dash/BrandLogo.vue';
import DashButton from '@/components/dash/DashButton.vue';
import DashTd from '@/components/dash/DashTd.vue';
import DashTr from '@/components/dash/DashTr.vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import type { WorkspaceService } from '@/components/machines/serviceFilters';
import { isManaged } from '@/components/machines/serviceFormat';
import UsageMeter from '@/components/machines/UsageMeter.vue';
import { bytesLabel } from '@/components/machines/machineFormat';
import {
    cpuOf,
    displayName,
    logoName,
    memoryOf,
    portsLabel,
    serviceStatus,
    subtitle,
} from '@/components/services/serviceList';
import type { ListMachine } from '@/components/services/serviceList';
import TreeGuide from '@/components/services/TreeGuide.vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

const {
    service,
    machine,
    href,
    guide = null,
    canManage = false,
    adopting = false,
} = defineProps<{
    service: WorkspaceService;
    machine: ListMachine | undefined;
    href: string;
    guide?: 'middle' | 'last' | null;
    canManage?: boolean;
    adopting?: boolean;
}>();

const emit = defineEmits<{
    open: [event: MouseEvent];
    adopt: [];
    hide: [hidden: boolean];
}>();

const offline = computed(() => machine?.connection === 'offline');
const managed = computed(() => isManaged(service));
const name = computed(() => displayName(service, guide !== null));
const status = computed(() => serviceStatus(service, offline.value));
const cpu = computed(() => (offline.value ? null : cpuOf([service.stat])));
const memory = computed(() =>
    offline.value
        ? null
        : memoryOf([service.stat], machine?.memoryBytes ?? null),
);
const ports = computed(() => portsLabel(service.ports, 3));
const quiet = computed(() => offline.value || !!service.hidden);
</script>

<template>
    <DashTr clickable @click="emit('open', $event)">
        <DashTd first :height="51.5" class="relative">
            <TreeGuide v-if="guide" :kind="guide" />
            <span
                class="flex min-w-0 items-center gap-2"
                :class="{ 'pl-[53px]': guide }"
            >
                <BrandLogo
                    :name="logoName(service)"
                    :src="service.icon"
                    :size="20"
                />
                <span class="flex min-w-0 flex-col">
                    <span class="flex min-w-0 items-center gap-1.5">
                        <Link
                            :href="href"
                            class="truncate tracking-[-0.005em] focus-visible:underline focus-visible:outline-none"
                            :class="quiet ? 'text-fg-muted' : 'text-fg-primary'"
                            :title="
                                name === service.name ? undefined : service.name
                            "
                            @click.stop
                            >{{ name }}</Link
                        >
                        <span
                            v-if="!managed"
                            class="flex h-[18px] shrink-0 items-center rounded-[9px] border-[0.5px] border-edge-strong bg-surface-badge px-1.5 text-[11px]/3.5 font-medium text-fg-secondary dark:border-[#383838]"
                            >{{ $t('Found') }}</span
                        >
                    </span>
                    <span
                        class="truncate font-ui-mono text-[11px]/3.5 text-fg-faint"
                        >{{ subtitle(service, offline) }}</span
                    >
                </span>
            </span>
            <span
                v-if="canManage"
                class="absolute top-1/2 right-3 -translate-y-1/2 opacity-0 group-hover/row:opacity-100 focus-within:opacity-100 has-data-[state=open]:opacity-100"
            >
                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <button
                            type="button"
                            class="flex size-7 cursor-pointer items-center justify-center rounded-md text-fg-muted hover:bg-surface-raised hover:text-fg-primary focus-visible:outline-2 focus-visible:outline-(--ds-ring)"
                            :aria-label="$t('Actions for :name', { name })"
                            @click.stop
                        >
                            <Ellipsis class="size-4" />
                        </button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        class="ds-modal min-w-44 font-ui"
                        align="end"
                        @click.stop
                    >
                        <DropdownMenuItem as-child>
                            <Link :href="href">{{ $t('Open') }}</Link>
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            v-if="!managed && !offline"
                            :disabled="adopting"
                            @select="emit('adopt')"
                        >
                            {{ $t('Adopt service') }}
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            @select="emit('hide', !service.hidden)"
                        >
                            {{
                                service.hidden
                                    ? $t('Show in lists again')
                                    : $t('Hide from lists')
                            }}
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </span>
        </DashTd>
        <DashTd :height="51.5">
            <span class="size-1.5 shrink-0 rounded-full" :class="status.dot" />
            <span class="tracking-[-0.005em]" :class="status.tone"
                >{{ status.label
                }}<span v-if="status.extra" class="text-fg-muted">{{
                    ' ' + status.extra
                }}</span></span
            >
        </DashTd>
        <DashTd :height="51.5">
            <span
                class="truncate tracking-[-0.005em]"
                :class="{ 'text-fg-muted': offline }"
                >{{ service.machine.name }}</span
            >
        </DashTd>
        <DashTd :height="51.5">
            <UsageMeter v-if="cpu !== null" :percent="cpu" class="gap-2!"
                ><span class="text-fg-primary">{{ cpu }}%</span></UsageMeter
            >
            <span v-else class="text-fg-faint">–</span>
        </DashTd>
        <DashTd :height="51.5">
            <UsageMeter v-if="memory" :percent="memory.percent" class="gap-2!"
                ><span class="text-fg-primary">{{
                    bytesLabel(memory.used)
                }}</span></UsageMeter
            >
            <span v-else class="text-fg-faint">–</span>
        </DashTd>
        <DashTd :height="51.5">
            <span
                v-if="ports.shown"
                class="truncate font-ui-mono text-[12px]/4 text-fg-secondary"
                :title="service.ports.join(', ')"
                >{{ ports.shown
                }}<template v-if="ports.more">{{
                    ' +' + ports.more
                }}</template></span
            >
            <span v-else class="text-fg-faint">–</span>
        </DashTd>
        <DashTd :height="51.5" class="border-r-0!">
            <span class="ml-auto flex items-center">
                <DashButton
                    v-if="!managed && canManage && !offline"
                    size="sm"
                    :label="$t('Adopt')"
                    :loading="adopting"
                    @click.stop="emit('adopt')"
                />
                <FilledIcon
                    v-else
                    name="chevron-right"
                    class="text-fg-tertiary opacity-0 group-hover/row:opacity-100"
                />
            </span>
        </DashTd>
    </DashTr>
</template>
