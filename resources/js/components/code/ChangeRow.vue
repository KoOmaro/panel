<script setup lang="ts">
import BrandLogo from '@/components/dash/BrandLogo.vue';
import CodeDiff from '@/components/code/CodeDiff.vue';
import type { DiffLine } from '@/components/code/yaml';
import FilledIcon from '@/components/icons/FilledIcon.vue';

export type PlanChange = {
    id: string;
    op: 'add' | 'change' | 'remove';
    logo: string | null;
    title: string;
    short: string;
    path: string;
    machine: { id: number; name: string; provider: string | null } | null;
    risk: { tone: 'amber' | 'red'; label: string } | null;
    diff: DiffLine[];
    note: string;
};

const { change, open = false } = defineProps<{
    change: PlanChange;
    open?: boolean;
}>();

defineEmits<{ toggle: [] }>();

const ops = {
    add: {
        sign: '+',
        classes:
            'border-[rgb(62_196_109/0.28)] bg-[rgb(62_196_109/0.12)] text-status-live',
    },
    change: {
        sign: '~',
        classes:
            'border-[rgb(245_168_51/0.28)] bg-[rgb(245_168_51/0.12)] text-[#c27c12] dark:text-[#f5a833]',
    },
    remove: {
        sign: '−',
        classes:
            'border-[rgb(240_105_90/0.28)] bg-[rgb(240_105_90/0.12)] text-status-down',
    },
} as const;

const risks = {
    amber: 'border-[rgb(245_168_51/0.24)] bg-[rgb(245_168_51/0.12)] text-[#c27c12] dark:text-[#f5a833]',
    red: 'border-[rgb(240_105_90/0.24)] bg-[rgb(240_105_90/0.12)] text-status-down',
} as const;
</script>

<template>
    <div
        class="flex flex-col border-t-[0.5px] border-edge-tabs"
        :class="{ 'bg-[#f7f7f7] dark:bg-[#1d1d1d]': open }"
    >
        <button
            type="button"
            class="flex w-full items-center gap-3 px-[18px] py-3 text-left transition-colors duration-150"
            :class="{ 'hover:bg-surface-hover': !open }"
            :aria-expanded="open"
            @click="$emit('toggle')"
        >
            <span
                class="flex size-5 shrink-0 items-center justify-center rounded-md border-[0.5px] font-ui-mono text-[13px]/4"
                :class="ops[change.op].classes"
                aria-hidden="true"
                >{{ ops[change.op].sign }}</span
            >
            <span
                class="flex size-6 shrink-0 items-center justify-center rounded-md border-[0.5px] border-[#e2e2e2] bg-white shadow-[0_1px_1.5px_rgb(0_0_0/0.15)] dark:border-[#2d2d2d] dark:bg-surface-raised dark:shadow-[0_1px_1.5px_rgb(0_0_0/0.55)]"
            >
                <BrandLogo v-if="change.logo" :name="change.logo" :size="14" />
                <FilledIcon v-else name="monitors-fill" class="text-fg-muted" />
            </span>
            <span class="flex min-w-0 flex-col gap-px">
                <span
                    class="truncate text-[13px]/5 font-medium text-fg-primary"
                    >{{ change.title }}</span
                >
                <span class="font-ui-mono text-[11px]/4 text-fg-muted">{{
                    change.path
                }}</span>
            </span>
            <span class="grow" />
            <span
                v-if="change.risk"
                class="hidden shrink-0 rounded-md border-[0.5px] px-2 py-[3px] text-[12px]/4 font-medium sm:inline-flex"
                :class="risks[change.risk.tone]"
                >{{ change.risk.label }}</span
            >
            <span
                v-if="change.machine"
                class="hidden shrink-0 items-center gap-1.5 rounded-md border-[0.5px] border-edge-strong bg-surface-badge py-[3px] pr-2 pl-[7px] sm:inline-flex dark:border-[#303030]"
            >
                <BrandLogo :name="change.machine.provider" :size="11" />
                <span class="text-[12px]/4 font-medium text-fg-secondary">{{
                    change.machine.name
                }}</span>
            </span>
            <span
                class="inline-flex shrink-0 text-fg-secondary transition-transform duration-150"
                :class="{ 'rotate-90': open }"
            >
                <FilledIcon name="chevron-right" />
            </span>
        </button>

        <div
            v-if="open"
            class="flex flex-col gap-2.5 pr-[18px] pb-4 pl-[18px] sm:pl-[86px]"
        >
            <CodeDiff file="serveros.yml" :lines="change.diff" />
            <p class="flex items-center gap-2 text-[12px]/4 text-fg-tertiary">
                <FilledIcon name="update-fill" class="shrink-0 text-fg-muted" />
                {{ change.note }}
            </p>
        </div>
    </div>
</template>
