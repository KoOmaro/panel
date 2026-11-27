<script setup lang="ts">
import { TriangleAlert, X } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';
import BrandLogo from '@/components/dash/BrandLogo.vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import { STEPS } from './kinds';
import type { WorkflowNode } from './kinds';
import type { NodeVisual } from './visual';

const props = defineProps<{
    node: WorkflowNode;
    selected: boolean;
    targeted: boolean;
    dragging: boolean;
    runState: string;
    problem: string | null;
    editing: boolean;
    readOnly: boolean;
    visual: NodeVisual;
    subtitle: string;
}>();

const emit = defineEmits<{
    nodePointerdown: [id: string, event: PointerEvent];
    open: [id: string];
}>();

const active = computed(() => ['running', 'waiting'].includes(props.runState));
const dimmed = computed(() =>
    ['skipped', 'pending', 'cancelled'].includes(props.runState),
);

const stateLabel = computed(
    () =>
        ({
            succeeded: trans('Ran'),
            failed: trans('Failed'),
            running: trans('Running'),
            waiting: trans('Waiting'),
            cancelled: trans('Cancelled'),
            pending: trans('Queued'),
            skipped: trans('Didn’t run'),
        })[props.runState] ?? null,
);
</script>

<template>
    <div
        :data-node="node.id"
        :data-selected="selected || undefined"
        :data-dragging="dragging || undefined"
        :data-run="runState === 'idle' ? undefined : runState"
        class="group/node absolute top-0 left-0 h-[86px] w-[214px] font-ui data-dragging:z-10"
        :class="{ 'wf-node-fresh': node.fresh }"
        :style="{ translate: `${node.x}px ${node.y}px` }"
    >
        <div
            class="flex size-full flex-col rounded-[19px] border-[0.5px] bg-[#f3f3f3] p-[5px] drop-shadow-[0_6px_8px_rgb(0_0_0/0.08)] transition-[border-color,box-shadow] duration-150 dark:bg-[#101010] dark:drop-shadow-[0_8px_9px_rgb(0_0_0/0.55)]"
            :class="[
                selected || targeted
                    ? 'border-dash-blue shadow-[0_0_0_1px_var(--dash-blue)]'
                    : 'border-[#e0e0e0] dark:border-[#262626]',
                readOnly
                    ? 'cursor-pointer'
                    : 'cursor-grab group-data-dragging/node:cursor-grabbing',
            ]"
            :title="problem ?? undefined"
            @pointerdown="emit('nodePointerdown', node.id, $event)"
            @dblclick="emit('open', node.id)"
        >
            <div
                class="relative flex min-h-0 flex-1 items-center gap-3 overflow-clip rounded-[14px] border-[0.5px] border-[#e4e4e4] bg-[linear-gradient(164.5deg,#ffffff_14.286%,#f7f7f7_85.714%)] px-4 dark:border-[#2e2e2e] dark:bg-[linear-gradient(164.5deg,#222222_14.286%,#161616_85.714%)] dark:shadow-[inset_0_1px_0_rgb(255_255_255/0.05)]"
            >
                <span
                    v-if="active"
                    aria-hidden="true"
                    class="pointer-events-none absolute inset-0 animate-pulse rounded-[inherit] bg-status-deploying/8 motion-reduce:hidden"
                />
                <span
                    class="flex size-[22px] shrink-0 items-center justify-center"
                    :class="{ 'opacity-60': dimmed }"
                >
                    <BrandLogo
                        v-if="visual.logo"
                        :name="visual.logo"
                        :src="visual.logoSrc"
                        :size="22"
                    />
                    <FilledIcon
                        v-else
                        :name="visual.icon"
                        :size="22"
                        class="text-fg-primary"
                    />
                </span>
                <span
                    class="flex min-w-0 flex-1 flex-col whitespace-nowrap"
                    :class="{ 'opacity-70': dimmed }"
                >
                    <span
                        class="truncate text-[13.5px]/[18px] font-medium tracking-[-0.01em] text-fg-primary"
                        >{{ node.title || STEPS[node.kind].title }}</span
                    >
                    <span
                        class="truncate text-[12px]/4 tracking-[-0.005em] text-fg-tertiary"
                        >{{ subtitle }}</span
                    >
                </span>
                <span
                    class="absolute top-[9.5px] right-[8.5px] flex size-3 items-center justify-center"
                    :title="
                        problem && !readOnly
                            ? problem
                            : (stateLabel ?? undefined)
                    "
                >
                    <TriangleAlert
                        v-if="problem && !readOnly"
                        class="size-3 text-status-pending"
                        :stroke-width="2.2"
                        :aria-label="problem"
                    />
                    <svg
                        v-else-if="runState === 'succeeded'"
                        class="size-3"
                        viewBox="0 0 12 12"
                        fill="none"
                        :aria-label="$t('Ran')"
                    >
                        <path
                            d="M10 3L4.5 8.5L2 6"
                            stroke-width="1.5"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke="#3EC46D"
                        />
                    </svg>
                    <X
                        v-else-if="runState === 'failed'"
                        class="size-3 text-status-down"
                        :stroke-width="2.4"
                        :aria-label="$t('Failed')"
                    />
                    <span
                        v-else-if="active"
                        class="size-1.5 animate-pulse rounded-full bg-status-deploying"
                        :aria-label="stateLabel ?? undefined"
                    />
                </span>
            </div>
        </div>

        <div
            v-if="selected && !dragging && !editing"
            class="absolute -top-9 right-0"
            @pointerdown.stop
        >
            <button
                type="button"
                class="dash-button dash-button--secondary dash-button--sm"
                @click="emit('open', node.id)"
            >
                {{ readOnly ? $t('Details') : $t('Edit') }}
            </button>
        </div>
    </div>
</template>
