<script setup lang="ts">
const {
    title,
    subtitle,
    state = 'idle',
} = defineProps<{
    title: string;
    subtitle?: string;
    state?: 'ran' | 'idle';
}>();

defineSlots<{ icon?: () => unknown }>();
</script>

<template>
    <div
        class="flex h-[86px] w-[214px] shrink-0 flex-col rounded-[19px] border-[0.5px] border-[#e0e0e0] bg-[#f3f3f3] p-[5px] font-ui drop-shadow-[0_6px_8px_rgb(0_0_0/0.08)] dark:border-[#262626] dark:bg-[#101010] dark:drop-shadow-[0_8px_9px_rgb(0_0_0/0.55)]"
    >
        <div
            class="relative flex min-h-0 flex-1 items-center gap-3 overflow-clip rounded-[14px] border-[0.5px] border-[#e4e4e4] bg-[linear-gradient(164.5deg,#ffffff_14.286%,#f7f7f7_85.714%)] px-4 dark:border-[#2e2e2e] dark:bg-[linear-gradient(164.5deg,#222222_14.286%,#161616_85.714%)] dark:shadow-[inset_0_1px_0_rgb(255_255_255/0.05)]"
        >
            <span class="flex size-[22px] shrink-0 items-center justify-center">
                <slot name="icon" />
            </span>
            <span
                class="flex min-w-0 flex-1 flex-col whitespace-nowrap"
                :class="{ 'opacity-70': state === 'idle' }"
            >
                <span
                    class="truncate text-[13.5px]/[18px] font-medium tracking-[-0.01em] text-fg-primary"
                    >{{ title }}</span
                >
                <span
                    v-if="subtitle"
                    class="truncate text-[12px]/4 tracking-[-0.005em] text-fg-tertiary"
                    >{{ subtitle }}</span
                >
            </span>
            <svg
                v-if="state === 'ran'"
                class="absolute top-[9.5px] right-[8.5px] size-3"
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
        </div>
    </div>
</template>
