<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    Check,
    ChevronDown,
    ChevronRight,
    ChevronsUpDown,
    CircleCheck,
    CircleMinus,
    CircleX,
    Ellipsis,
    LoaderCircle,
} from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import type { Component } from 'vue';
import { computed, ref } from 'vue';
import BrandLogo from '@/components/dash/BrandLogo.vue';
import DashButton from '@/components/dash/DashButton.vue';
import DashCheckbox from '@/components/dash/DashCheckbox.vue';
import DashTable from '@/components/dash/DashTable.vue';
import DashTd from '@/components/dash/DashTd.vue';
import DashTh from '@/components/dash/DashTh.vue';
import DashTr from '@/components/dash/DashTr.vue';
import LogoStack from '@/components/dash/LogoStack.vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import TintBadge from '@/components/summary/TintBadge.vue';
import type { TintTone } from '@/components/summary/TintBadge.vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuSub,
    DropdownMenuSubContent,
    DropdownMenuSubTrigger,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { brandMark } from '@/lib/brandLogos';
import { workspacePath } from '@/lib/workspaceRoute';
import {
    destinationKind,
    serviceKind,
    serviceLogo,
    size,
    startedAgo,
    when,
} from './backups';
import type {
    BackupMachine,
    BackupService,
    BackupState,
    Destination,
    Schedule,
} from './backups';

const { machines, destinations, canManage, saving, running } = defineProps<{
    machines: BackupMachine[];
    destinations: Destination[];
    canManage: boolean;
    saving: number | null;
    running: number[];
}>();

const selected = defineModel<number[]>('selected', { required: true });

const emit = defineEmits<{
    save: [
        machine: BackupMachine,
        change: { destinationId?: number | null; schedule?: Schedule },
    ];
    run: [machine: BackupMachine];
    restore: [machine: BackupMachine, service: BackupService];
    addDestination: [];
}>();

const expanded = ref<number[]>([]);

function toggle(id: number): void {
    expanded.value = expanded.value.includes(id)
        ? expanded.value.filter((n) => n !== id)
        : [...expanded.value, id];
}

function pick(id: number, on: boolean): void {
    selected.value = on
        ? [...new Set([...selected.value, id])]
        : selected.value.filter((n) => n !== id);
}

const allPicked = computed(
    () =>
        machines.length > 0 &&
        machines.every((m) => selected.value.includes(m.id)),
);
const somePicked = computed(
    () =>
        !allPicked.value && machines.some((m) => selected.value.includes(m.id)),
);

const byId = computed(
    () => new Map(destinations.map((d) => [d.id, d] as const)),
);

function destinationOf(machine: BackupMachine): Destination | null {
    return machine.destinationId === null
        ? null
        : (byId.value.get(machine.destinationId) ?? null);
}

function destinationLogo(destination: Destination): string | null {
    return destinationKind(destination).logo;
}

const schedules = computed<{ value: Schedule; label: string }[]>(() => [
    { value: 'daily', label: trans('Daily') },
    { value: 'weekly', label: trans('Weekly') },
    { value: 'off', label: trans('Off') },
]);

function scheduleLabel(schedule: Schedule): string {
    return schedules.value.find((s) => s.value === schedule)?.label ?? '';
}

type Badge = { tone: TintTone; icon: Component; label: string; spin?: boolean };

const badges: Record<BackupState | 'never', Badge> = {
    succeeded: { tone: 'green', icon: CircleCheck, label: trans('Done') },
    failed: { tone: 'red', icon: CircleX, label: trans('Failed') },
    running: {
        tone: 'blue',
        icon: LoaderCircle,
        label: trans('Running'),
        spin: true,
    },
    queued: {
        tone: 'blue',
        icon: LoaderCircle,
        label: trans('Queued'),
        spin: true,
    },
    skipped: {
        tone: 'grey',
        icon: CircleMinus,
        label: trans('Nothing to keep'),
    },
    never: { tone: 'grey', icon: CircleMinus, label: trans('Never') },
};

function machineBadge(machine: BackupMachine): Badge {
    return badges[machine.lastRun?.state ?? 'never'];
}

function serviceNames(machine: BackupMachine): string[] {
    return machine.serviceList.map(serviceLogo);
}

function servicesLabel(count: number): string {
    return count === 1
        ? trans('1 service')
        : trans(':count services', { count: String(count) });
}

function serviceTitle(service: BackupService): string {
    return brandMark(serviceLogo(service))?.title ?? serviceKind(service.kind);
}

const machineHref = (id: number) => workspacePath(`/machines/${id}`);
</script>

<template>
    <DashTable>
        <template #head>
            <DashTh icon="machines-fill" :width="250" first>
                <template v-if="canManage" #lead>
                    <DashCheckbox
                        :model-value="allPicked"
                        :indeterminate="somePicked"
                        :label="$t('Select all machines')"
                        @update:model-value="
                            (on: boolean) =>
                                (selected = on ? machines.map((m) => m.id) : [])
                        "
                    />
                </template>
                {{ $t('Machine') }}
            </DashTh>
            <DashTh icon="database-fill" :width="196">{{
                $t('Destination')
            }}</DashTh>
            <DashTh icon="activity-fill" :width="116">{{
                $t('Schedule')
            }}</DashTh>
            <DashTh icon="layers-fill" :width="140">{{
                $t('Services')
            }}</DashTh>
            <DashTh icon="setup-fill" :width="200">{{
                $t('Last backup')
            }}</DashTh>
            <DashTh icon="zip-fill" :width="90" class="border-r-0!">{{
                $t('Size')
            }}</DashTh>
            <DashTh fill class="border-r-0!" />
        </template>

        <template v-for="machine in machines" :key="machine.id">
            <DashTr
                clickable
                :class="{
                    'bg-[rgb(59_156_255/0.06)]': selected.includes(machine.id),
                }"
                :aria-selected="selected.includes(machine.id) || undefined"
                :aria-expanded="expanded.includes(machine.id)"
                @click="toggle(machine.id)"
            >
                <DashTd first class="[&>div]:max-w-[250px]">
                    <template v-if="canManage" #lead>
                        <DashCheckbox
                            :model-value="selected.includes(machine.id)"
                            :label="$t('Select :name', { name: machine.name })"
                            @update:model-value="
                                (on: boolean) => pick(machine.id, on)
                            "
                        />
                    </template>
                    <span class="flex min-w-0 items-center gap-2">
                        <BrandLogo :name="machine.provider" />
                        <span
                            class="max-w-[110px] shrink-0 truncate font-medium tracking-[-0.01em] text-fg-primary"
                            >{{ machine.name }}</span
                        >
                        <span
                            class="truncate font-ui-mono text-[11.5px]/4 text-fg-faint"
                            >{{ machine.ip }}</span
                        >
                    </span>
                    <component
                        :is="
                            expanded.includes(machine.id)
                                ? ChevronDown
                                : ChevronRight
                        "
                        class="ml-auto size-3.5 shrink-0 text-fg-muted"
                        :stroke-width="2"
                        aria-hidden="true"
                    />
                </DashTd>

                <DashTd class="[&>div]:max-w-[196px]">
                    <DropdownMenu v-if="canManage">
                        <DropdownMenuTrigger as-child>
                            <button
                                type="button"
                                class="-ml-1 flex h-7 max-w-full min-w-0 cursor-pointer items-center gap-2 rounded-[6px] px-1 hover:bg-surface-raised focus-visible:outline-2 focus-visible:outline-(--ds-ring) data-[state=open]:bg-surface-raised"
                                :aria-label="
                                    $t('Where :name’s backups go', {
                                        name: machine.name,
                                    })
                                "
                                :disabled="saving === machine.id"
                                @click.stop
                            >
                                <template v-if="destinationOf(machine)">
                                    <BrandLogo
                                        v-if="
                                            destinationLogo(
                                                destinationOf(machine)!,
                                            )
                                        "
                                        :name="
                                            destinationLogo(
                                                destinationOf(machine)!,
                                            )
                                        "
                                    />
                                    <FilledIcon
                                        v-else
                                        name="database-fill"
                                        class="text-fg-muted"
                                    />
                                    <span
                                        class="truncate font-medium tracking-[-0.01em] text-fg-secondary"
                                        >{{
                                            destinationOf(machine)!.name
                                        }}</span
                                    >
                                </template>
                                <span v-else class="text-fg-faint">{{
                                    $t('Not set')
                                }}</span>
                            </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent
                            align="start"
                            class="min-w-56 font-ui"
                            @click.stop
                        >
                            <DropdownMenuLabel
                                class="text-[12px] font-medium text-fg-muted"
                                >{{ $t('Send backups to') }}</DropdownMenuLabel
                            >
                            <DropdownMenuItem
                                v-for="destination in destinations"
                                :key="destination.id"
                                :disabled="destination.machineId === machine.id"
                                @select="
                                    emit('save', machine, {
                                        destinationId: destination.id,
                                    })
                                "
                            >
                                <BrandLogo
                                    v-if="destinationLogo(destination)"
                                    :name="destinationLogo(destination)"
                                    :size="14"
                                />
                                <FilledIcon
                                    v-else
                                    name="database-fill"
                                    :size="14"
                                    class="text-fg-muted"
                                />
                                <span class="flex-1 truncate">{{
                                    destination.machineId === machine.id
                                        ? $t(':name (on this machine)', {
                                              name: destination.name,
                                          })
                                        : destination.name
                                }}</span>
                                <Check
                                    v-if="
                                        machine.destinationId === destination.id
                                    "
                                    class="size-4"
                                />
                            </DropdownMenuItem>
                            <DropdownMenuSeparator v-if="destinations.length" />
                            <DropdownMenuItem
                                @select="
                                    emit('save', machine, {
                                        destinationId: null,
                                    })
                                "
                            >
                                <span class="flex-1">{{
                                    $t('Not backed up')
                                }}</span>
                                <Check
                                    v-if="machine.destinationId === null"
                                    class="size-4"
                                />
                            </DropdownMenuItem>
                            <DropdownMenuItem @select="emit('addDestination')">
                                {{ $t('Add a destination…') }}
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                    <template v-else-if="destinationOf(machine)">
                        <BrandLogo
                            v-if="destinationLogo(destinationOf(machine)!)"
                            :name="destinationLogo(destinationOf(machine)!)"
                        />
                        <span class="truncate font-medium text-fg-secondary">{{
                            destinationOf(machine)!.name
                        }}</span>
                    </template>
                    <span v-else class="text-fg-faint">{{
                        $t('Not set')
                    }}</span>
                </DashTd>

                <DashTd>
                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <button
                                type="button"
                                class="flex h-[26px] cursor-pointer items-center gap-1.5 rounded-[6px] border-[0.5px] border-edge-control bg-surface-badge pr-1.5 pl-[9px] text-[12.5px]/4 font-medium tracking-[-0.005em] shadow-[0_1px_1.5px_rgb(0_0_0/0.45),inset_0_1px_0_rgb(255_255_255/0.04)] focus-visible:outline-2 focus-visible:outline-(--ds-ring) disabled:cursor-not-allowed dark:border-[#303030] dark:bg-[#1f1f1f]"
                                :class="
                                    machine.schedule === 'off'
                                        ? 'text-fg-tertiary'
                                        : 'text-fg-primary'
                                "
                                :disabled="
                                    !canManage ||
                                    !machine.destinationId ||
                                    saving === machine.id
                                "
                                :title="
                                    machine.destinationId
                                        ? undefined
                                        : $t(
                                              'Choose where its backups go first.',
                                          )
                                "
                                :aria-label="
                                    $t('How often :name is backed up', {
                                        name: machine.name,
                                    })
                                "
                                @click.stop
                            >
                                {{ scheduleLabel(machine.schedule) }}
                                <ChevronsUpDown
                                    class="size-[13px] text-fg-muted"
                                    :stroke-width="2"
                                    aria-hidden="true"
                                />
                            </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent
                            align="start"
                            class="min-w-36 font-ui"
                            @click.stop
                        >
                            <DropdownMenuItem
                                v-for="option in schedules"
                                :key="option.value"
                                @select="
                                    emit('save', machine, {
                                        schedule: option.value,
                                    })
                                "
                            >
                                <span class="flex-1">{{ option.label }}</span>
                                <Check
                                    v-if="machine.schedule === option.value"
                                    class="size-4"
                                />
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </DashTd>

                <DashTd class="[&>div]:max-w-[140px]">
                    <LogoStack :names="serviceNames(machine)" :max="2" />
                    <span
                        class="font-medium tracking-[-0.01em]"
                        :class="{ 'text-fg-faint': !machine.services }"
                        :title="
                            machine.services
                                ? undefined
                                : $t(
                                      'Only services ServerOS manages are backed up. Deploy or adopt one first.',
                                  )
                        "
                        >{{ servicesLabel(machine.services) }}</span
                    >
                </DashTd>

                <DashTd class="[&>div]:max-w-[200px]">
                    <TintBadge
                        :tone="machineBadge(machine).tone"
                        :icon="machineBadge(machine).icon"
                        :spin="machineBadge(machine).spin"
                        :label="machineBadge(machine).label"
                    />
                    <span
                        v-if="machine.lastRun"
                        class="truncate text-[12px]/4 tracking-[-0.005em] text-fg-tertiary"
                        :title="
                            machine.lastRun.error ??
                            (machine.lastRun.at
                                ? new Date(machine.lastRun.at).toLocaleString()
                                : undefined)
                        "
                        >{{
                            machine.lastRun.state === 'running'
                                ? startedAgo(machine.lastRun.at)
                                : when(machine.lastRun.at)
                        }}</span
                    >
                </DashTd>

                <DashTd class="border-r-0! [&>div]:max-w-[90px]">
                    <span
                        v-if="machine.lastRun?.bytes"
                        class="font-ui-mono text-[12px]/4 text-fg-secondary"
                        >{{ size(machine.lastRun.bytes) }}</span
                    >
                    <span v-else class="font-medium text-fg-faint">–</span>
                </DashTd>

                <DashTd
                    class="border-r-0! [&>div]:relative [&>div]:justify-end [&>div]:gap-2.5"
                >
                    <template v-if="canManage">
                        <DashButton
                            v-if="machine.lastRun?.state === 'failed'"
                            size="sm"
                            :label="$t('Retry')"
                            :loading="running.includes(machine.id)"
                            @click.stop="emit('run', machine)"
                        />
                        <span
                            v-else-if="
                                machine.destinationId &&
                                machine.lastRun?.state !== 'running'
                            "
                            class="absolute inset-y-0 right-[46px] flex items-center transition-opacity duration-100 group-hover/row:pointer-events-auto group-hover/row:opacity-100 focus-within:pointer-events-auto focus-within:opacity-100"
                            :class="
                                running.includes(machine.id)
                                    ? 'opacity-100'
                                    : 'pointer-events-none opacity-0'
                            "
                        >
                            <DashButton
                                size="sm"
                                :label="$t('Back up now')"
                                :loading="running.includes(machine.id)"
                                @click.stop="emit('run', machine)"
                            />
                        </span>
                        <DropdownMenu>
                            <DropdownMenuTrigger as-child>
                                <button
                                    type="button"
                                    class="flex size-6 cursor-pointer items-center justify-center rounded-[6px] text-fg-muted hover:bg-surface-raised hover:text-fg-secondary focus-visible:outline-2 focus-visible:outline-(--ds-ring) data-[state=open]:bg-surface-raised"
                                    :aria-label="
                                        $t('Actions for :name', {
                                            name: machine.name,
                                        })
                                    "
                                    @click.stop
                                >
                                    <Ellipsis
                                        class="size-4"
                                        :stroke-width="2"
                                    />
                                </button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent
                                align="end"
                                class="min-w-48 font-ui"
                                @click.stop
                            >
                                <DropdownMenuItem
                                    :disabled="!machine.destinationId"
                                    @select="emit('run', machine)"
                                >
                                    {{ $t('Back up now') }}
                                </DropdownMenuItem>
                                <DropdownMenuItem @select="toggle(machine.id)">
                                    {{
                                        expanded.includes(machine.id)
                                            ? $t('Hide services')
                                            : $t('Show services and restore')
                                    }}
                                </DropdownMenuItem>
                                <DropdownMenuSub>
                                    <DropdownMenuSubTrigger>
                                        {{ $t('Destination') }}
                                    </DropdownMenuSubTrigger>
                                    <DropdownMenuSubContent class="font-ui">
                                        <DropdownMenuItem
                                            v-for="destination in destinations"
                                            :key="destination.id"
                                            :disabled="
                                                destination.machineId ===
                                                machine.id
                                            "
                                            @select="
                                                emit('save', machine, {
                                                    destinationId:
                                                        destination.id,
                                                })
                                            "
                                        >
                                            <span class="flex-1">{{
                                                destination.name
                                            }}</span>
                                            <Check
                                                v-if="
                                                    machine.destinationId ===
                                                    destination.id
                                                "
                                                class="size-4"
                                            />
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                            @select="
                                                emit('save', machine, {
                                                    destinationId: null,
                                                })
                                            "
                                        >
                                            {{ $t('Not backed up') }}
                                        </DropdownMenuItem>
                                    </DropdownMenuSubContent>
                                </DropdownMenuSub>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem as-child>
                                    <Link :href="machineHref(machine.id)">{{
                                        $t('Open machine')
                                    }}</Link>
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </template>
                </DashTd>
            </DashTr>

            <template v-if="expanded.includes(machine.id)">
                <tr
                    v-for="service in machine.serviceList"
                    :key="`${machine.id}-${service.key}`"
                    class="bg-surface-card-footer"
                >
                    <DashTd
                        :height="36"
                        class="[&>div]:max-w-[250px] [&>div]:pl-[47px]!"
                    >
                        <span class="flex min-w-0 items-center gap-3">
                            <BrandLogo
                                :name="serviceLogo(service)"
                                :src="service.iconUrl"
                                :size="14"
                            />
                            <span
                                class="max-w-[120px] shrink-0 truncate font-medium tracking-[-0.01em] text-fg-secondary"
                                :title="service.name"
                                >{{ service.name }}</span
                            >
                            <span
                                class="truncate text-[12px]/4 tracking-[-0.005em] text-fg-tertiary"
                                >{{ serviceTitle(service) }}</span
                            >
                        </span>
                    </DashTd>
                    <DashTd :height="36" />
                    <DashTd :height="36" />
                    <DashTd :height="36" />
                    <DashTd :height="36">
                        <template v-if="service.last">
                            <TintBadge
                                :tone="badges[service.last.state].tone"
                                :icon="badges[service.last.state].icon"
                                :spin="badges[service.last.state].spin"
                                :label="badges[service.last.state].label"
                            />
                            <span
                                class="truncate text-[12px]/4 text-fg-tertiary"
                                :title="service.last.error ?? undefined"
                                >{{
                                    service.last.state === 'running' ||
                                    service.last.state === 'queued'
                                        ? startedAgo(service.last.createdAt)
                                        : when(service.last.createdAt)
                                }}</span
                            >
                        </template>
                        <TintBadge
                            v-else
                            :tone="badges.never.tone"
                            :icon="badges.never.icon"
                            :label="badges.never.label"
                        />
                    </DashTd>
                    <DashTd :height="36" class="border-r-0!">
                        <span
                            v-if="service.last?.bytes"
                            class="font-ui-mono text-[12px]/4 text-fg-secondary"
                            >{{ size(service.last.bytes) }}</span
                        >
                        <span v-else class="font-medium text-fg-faint">–</span>
                    </DashTd>
                    <DashTd
                        :height="36"
                        class="border-r-0! [&>div]:justify-end"
                    >
                        <button
                            v-if="
                                canManage && service.last?.state === 'succeeded'
                            "
                            type="button"
                            class="cursor-pointer rounded-[4px] text-[12.5px]/4 font-medium tracking-[-0.005em] focus-visible:outline-2 focus-visible:outline-(--ds-ring)"
                            :class="
                                service.last.cannotRestore
                                    ? 'text-fg-faint'
                                    : 'text-fg-secondary hover:text-fg-primary'
                            "
                            :title="service.last.cannotRestore ?? undefined"
                            @click="emit('restore', machine, service)"
                        >
                            {{ $t('Restore…') }}
                        </button>
                    </DashTd>
                </tr>
                <tr
                    v-if="!machine.serviceList.length"
                    class="bg-surface-card-footer"
                >
                    <td
                        colspan="7"
                        class="h-9 border-b-[0.5px] border-edge pl-[47px] text-[12px]/4 text-fg-tertiary"
                    >
                        {{
                            $t(
                                'Only services ServerOS manages are backed up. Deploy or adopt one first.',
                            )
                        }}
                    </td>
                </tr>
            </template>
        </template>
    </DashTable>
</template>
