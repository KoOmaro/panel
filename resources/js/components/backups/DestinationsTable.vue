<script setup lang="ts">
import {
    CircleCheck,
    CircleX,
    Ellipsis,
    LoaderCircle,
    Pencil,
    RotateCw,
    Trash2,
} from '@lucide/vue';
import BrandLogo from '@/components/dash/BrandLogo.vue';
import DashTable from '@/components/dash/DashTable.vue';
import DashTd from '@/components/dash/DashTd.vue';
import DashTh from '@/components/dash/DashTh.vue';
import DashTr from '@/components/dash/DashTr.vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import TintBadge from '@/components/summary/TintBadge.vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { destinationKind, size } from './backups';
import type { Destination } from './backups';

const { destinations, canManage } = defineProps<{
    destinations: Destination[];
    canManage: boolean;
}>();

const emit = defineEmits<{
    edit: [destination: Destination];
    remove: [destination: Destination];
    retry: [destination: Destination];
}>();
</script>

<template>
    <DashTable>
        <template #head>
            <DashTh icon="database-fill" :width="320" first>{{
                $t('Destination')
            }}</DashTh>
            <DashTh icon="table-fill" :width="180">{{ $t('Kind') }}</DashTh>
            <DashTh icon="activity-fill" :width="220">{{
                $t('Status')
            }}</DashTh>
            <DashTh icon="machines-fill" :width="140">{{
                $t('Used by')
            }}</DashTh>
            <DashTh icon="zip-fill" :width="110">{{ $t('Stored') }}</DashTh>
            <DashTh fill class="border-r-0!" />
        </template>
        <DashTr v-for="destination in destinations" :key="destination.id">
            <DashTd first>
                <BrandLogo
                    v-if="destinationKind(destination).logo"
                    :name="destinationKind(destination).logo"
                />
                <FilledIcon v-else name="database-fill" class="text-fg-muted" />
                <span
                    class="truncate font-medium tracking-[-0.01em] text-fg-primary"
                    >{{ destination.name }}</span
                >
                <span
                    class="truncate font-ui-mono text-[11.5px]/4 text-fg-faint"
                    >{{ destination.where }}</span
                >
            </DashTd>
            <DashTd>
                <span class="truncate">{{
                    destinationKind(destination).label
                }}</span>
            </DashTd>
            <DashTd>
                <TintBadge
                    v-if="destination.status === 'ready'"
                    tone="green"
                    :icon="CircleCheck"
                    :label="$t('Ready')"
                />
                <TintBadge
                    v-else-if="destination.status === 'setting_up'"
                    tone="blue"
                    :icon="LoaderCircle"
                    spin
                    :label="$t('Setting up')"
                />
                <template v-else>
                    <TintBadge
                        tone="red"
                        :icon="CircleX"
                        :label="$t('Failed')"
                    />
                    <span
                        v-if="destination.statusMessage"
                        class="truncate text-[12px]/4 text-fg-tertiary"
                        :title="destination.statusMessage"
                        >{{ destination.statusMessage }}</span
                    >
                </template>
            </DashTd>
            <DashTd>
                <span class="tabular-nums">{{
                    destination.machines === 1
                        ? $t('1 machine')
                        : $t(':count machines', {
                              count: String(destination.machines),
                          })
                }}</span>
            </DashTd>
            <DashTd>
                <span
                    v-if="destination.stored"
                    class="font-ui-mono text-[12px]/4 text-fg-secondary"
                    >{{ size(destination.stored) }}</span
                >
                <span v-else class="font-medium text-fg-faint">–</span>
            </DashTd>
            <DashTd class="border-r-0! [&>div]:justify-end">
                <DropdownMenu v-if="canManage">
                    <DropdownMenuTrigger as-child>
                        <button
                            type="button"
                            class="flex size-6 cursor-pointer items-center justify-center rounded-[6px] text-fg-muted hover:bg-surface-raised hover:text-fg-secondary focus-visible:outline-2 focus-visible:outline-(--ds-ring) data-[state=open]:bg-surface-raised"
                            :aria-label="
                                $t('Actions for :name', {
                                    name: destination.name,
                                })
                            "
                        >
                            <Ellipsis class="size-4" :stroke-width="2" />
                        </button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="min-w-40 font-ui">
                        <DropdownMenuItem
                            v-if="
                                destination.status === 'failed' &&
                                destination.type === 'machine'
                            "
                            @select="emit('retry', destination)"
                        >
                            <RotateCw class="size-4" :stroke-width="1.75" />
                            {{ $t('Retry setup') }}
                        </DropdownMenuItem>
                        <DropdownMenuItem @select="emit('edit', destination)">
                            <Pencil class="size-4" :stroke-width="1.75" />
                            {{ $t('Edit') }}
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            class="text-status-down focus:text-status-down"
                            @select="emit('remove', destination)"
                        >
                            <Trash2
                                class="size-4 text-current"
                                :stroke-width="1.75"
                            />
                            {{ $t('Remove') }}
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </DashTd>
        </DashTr>
    </DashTable>
</template>
