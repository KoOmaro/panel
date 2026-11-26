<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import type { FilledIconName } from '@/components/icons/filledIcons';
import { STEPS } from './kinds';
import type { StepKind } from './kinds';

const { targetLabel, draggingKind } = defineProps<{
    targetLabel: string | null;
    draggingKind: StepKind | null;
}>();

const emit = defineEmits<{
    itemPointerdown: [kind: StepKind, event: PointerEvent];
    add: [kind: StepKind];
}>();

type Group = {
    key: string;
    label: string;
    description: string;
    icon: FilledIconName;
    kinds: StepKind[];
};

const groups: Group[] = [
    {
        key: 'services',
        label: trans('Services'),
        description: trans('Restart, stop, or run a command.'),
        icon: 'layers-fill',
        kinds: ['restart', 'start', 'stop', 'command'],
    },
    {
        key: 'deploys',
        label: trans('Deploys'),
        description: trans('Redeploy, release or promote.'),
        icon: 'update-fill',
        kinds: ['redeploy', 'release', 'promote'],
    },
    {
        key: 'checks',
        label: trans('Checks'),
        description: trans('Is a site up? Check something.'),
        icon: 'monitors-fill',
        kinds: ['check_monitor', 'branch'],
    },
    {
        key: 'alerts',
        label: trans('Alerts'),
        description: trans('Tell the team, or call a URL.'),
        icon: 'user-fill',
        kinds: ['alert', 'http'],
    },
    {
        key: 'agents',
        label: trans('Agents'),
        description: trans('A coding agent looks into it.'),
        icon: 'terminal-fill',
        kinds: ['agent'],
    },
    {
        key: 'data',
        label: trans('Data'),
        description: trans('Back up, or copy data to Staging.'),
        icon: 'machines-fill',
        kinds: ['backup', 'copy_data'],
    },
    {
        key: 'wait',
        label: trans('Wait'),
        description: trans('Pause before the next step.'),
        icon: 'activity-fill',
        kinds: ['wait'],
    },
];

const query = ref('');
const opened = ref<string | null>(null);

const found = computed(() => {
    const needle = query.value.trim().toLowerCase();
    if (!needle) return null;

    return groups
        .flatMap((g) => g.kinds.map((kind) => ({ kind, group: g })))
        .filter(({ kind, group }) =>
            [
                STEPS[kind].label,
                STEPS[kind].title,
                STEPS[kind].description,
                group.label,
            ]
                .join(' ')
                .toLowerCase()
                .includes(needle),
        );
});

const openGroup = computed(
    () => groups.find((g) => g.key === opened.value) ?? null,
);

function onKey(kind: StepKind, event: KeyboardEvent): void {
    if (event.key !== 'Enter' && event.key !== ' ') return;
    event.preventDefault();
    emit('add', kind);
}

function toggle(key: string): void {
    opened.value = opened.value === key ? null : key;
}
</script>

<template>
    <aside
        :aria-label="$t('What happens next?')"
        data-canvas-overlay
        class="absolute top-5 right-5 z-20 flex max-h-[calc(100%-40px)] w-[300px] flex-col overflow-hidden rounded-[14px] border-[0.5px] border-edge-field bg-[#fafafa] shadow-[0_10px_24px_-6px_rgb(0_0_0/0.45),0_1px_2px_rgb(0_0_0/0.5)] dark:bg-[#171717]"
        @pointerdown.stop
    >
        <div class="flex shrink-0 flex-col gap-2.5 px-4 pt-3.5 pb-3">
            <h2 class="flex min-w-0 items-baseline gap-1.5 whitespace-nowrap">
                <span
                    class="text-[14px]/5 font-medium tracking-[-0.01em] text-fg-primary"
                    >{{ $t('What happens next?') }}</span
                >
                <span
                    v-if="targetLabel"
                    class="truncate text-[12px]/4 tracking-[-0.005em] text-fg-faint"
                    >{{ $t('after :step', { step: targetLabel }) }}</span
                >
            </h2>
            <label
                class="flex h-[34px] items-center gap-2 rounded-[8px] border-[0.5px] border-[#e0e0e0] bg-white px-2.5 focus-within:border-edge-checkbox-hover dark:border-[#2c2c2c] dark:bg-[#1e1e1e]"
            >
                <FilledIcon name="search-fill" class="text-fg-muted" />
                <span class="sr-only">{{ $t('Search steps') }}</span>
                <input
                    v-model="query"
                    type="search"
                    :placeholder="$t('Search steps…')"
                    class="h-full min-w-0 grow bg-transparent text-[13px]/5 tracking-[-0.005em] text-fg-primary outline-none placeholder:text-fg-faint [&::-webkit-search-cancel-button]:hidden"
                    @keydown.esc.stop="query = ''"
                />
            </label>
        </div>

        <div class="min-h-0 overflow-y-auto px-2 pb-2">
            <ul v-if="found" class="flex flex-col gap-0.5">
                <li
                    v-for="{ kind, group } in found"
                    :key="kind"
                    role="button"
                    tabindex="0"
                    :title="STEPS[kind].help"
                    :data-dragging="draggingKind === kind || undefined"
                    class="flex cursor-grab touch-none items-center gap-3 rounded-[10px] p-2 outline-none select-none hover:bg-black/[0.04] focus-visible:ring-1 focus-visible:ring-(--ds-ring) active:cursor-grabbing data-dragging:opacity-50 dark:hover:bg-[#1f1f1f]"
                    @pointerdown="emit('itemPointerdown', kind, $event)"
                    @keydown="onKey(kind, $event)"
                >
                    <span
                        class="flex size-[30px] shrink-0 items-center justify-center rounded-[8px] border-[0.5px] border-[#e0e0e0] bg-white text-fg-secondary dark:border-[#323232] dark:bg-[#202020]"
                    >
                        <FilledIcon :name="group.icon" />
                    </span>
                    <span class="flex min-w-0 flex-1 flex-col">
                        <span
                            class="truncate text-[13px]/[18px] font-medium tracking-[-0.01em] text-fg-primary"
                            >{{ STEPS[kind].title }}</span
                        >
                        <span
                            class="truncate text-[12px]/4 tracking-[-0.005em] text-fg-tertiary"
                            >{{ STEPS[kind].description }}</span
                        >
                    </span>
                </li>
                <li
                    v-if="found.length === 0"
                    class="px-2 py-6 text-center text-[13px]/5 text-fg-muted"
                >
                    {{ $t('No step like that.') }}
                </li>
            </ul>

            <ul v-else class="flex flex-col gap-0.5">
                <li v-for="group in groups" :key="group.key">
                    <button
                        type="button"
                        class="group/row flex w-full cursor-pointer items-center gap-3 rounded-[10px] p-2 text-left outline-none hover:bg-black/[0.04] focus-visible:ring-1 focus-visible:ring-(--ds-ring) dark:hover:bg-[#1f1f1f]"
                        :class="{
                            'bg-black/[0.04] dark:bg-[#1f1f1f]':
                                opened === group.key,
                        }"
                        :aria-expanded="opened === group.key"
                        @click="toggle(group.key)"
                    >
                        <span
                            class="flex size-[30px] shrink-0 items-center justify-center rounded-[8px] border-[0.5px] border-[#e0e0e0] bg-white text-fg-secondary dark:border-[#323232] dark:bg-[#202020]"
                        >
                            <FilledIcon :name="group.icon" />
                        </span>
                        <span class="flex min-w-0 flex-1 flex-col">
                            <span
                                class="text-[13px]/[18px] font-medium tracking-[-0.01em] text-fg-primary"
                                >{{ group.label }}</span
                            >
                            <span
                                class="truncate text-[12px]/4 tracking-[-0.005em] text-fg-tertiary"
                                >{{ group.description }}</span
                            >
                        </span>
                        <span
                            class="flex size-7 shrink-0 items-center justify-center rounded-[7px] border-[0.5px] border-[#dcdcdc] text-fg-secondary shadow-[0_1px_2px_rgb(0_0_0/0.15)] transition-transform group-hover/row:bg-white dark:border-[#333] dark:bg-[#1c1c1c] dark:shadow-[0_1px_2px_rgb(0_0_0/0.5)] dark:group-hover/row:bg-[#272727]"
                            :class="{
                                'bg-white dark:bg-[#272727]':
                                    opened === group.key,
                            }"
                        >
                            <FilledIcon
                                name="chevron-right"
                                class="transition-transform duration-150"
                                :class="{ 'rotate-90': opened === group.key }"
                            />
                        </span>
                    </button>
                    <ul
                        v-if="openGroup?.key === group.key"
                        class="mt-0.5 mb-1.5 ml-[23px] flex flex-col gap-0.5 border-l-[0.5px] border-edge-row pl-3"
                    >
                        <li
                            v-for="kind in group.kinds"
                            :key="kind"
                            role="button"
                            tabindex="0"
                            :title="STEPS[kind].help"
                            :data-dragging="draggingKind === kind || undefined"
                            class="flex cursor-grab touch-none flex-col rounded-[8px] px-2 py-1.5 outline-none select-none hover:bg-black/[0.04] focus-visible:ring-1 focus-visible:ring-(--ds-ring) active:cursor-grabbing data-dragging:opacity-50 dark:hover:bg-[#1f1f1f]"
                            @pointerdown="emit('itemPointerdown', kind, $event)"
                            @keydown="onKey(kind, $event)"
                        >
                            <span
                                class="text-[13px]/[18px] font-medium tracking-[-0.01em] text-fg-primary"
                                >{{ STEPS[kind].title }}</span
                            >
                            <span
                                class="text-[12px]/4 tracking-[-0.005em] text-fg-tertiary"
                                >{{ STEPS[kind].description }}</span
                            >
                        </li>
                    </ul>
                </li>
            </ul>
        </div>
    </aside>
</template>
