<script setup lang="ts">
export type Stat = {
    label: string;
    value: string | number;
    unit?: string;
    title?: string;
    tone?: string;
};

const { stats } = defineProps<{ stats: [Stat, Stat, Stat, Stat] }>();
</script>

<template>
    <dl class="flex min-w-0 flex-1 flex-col">
        <div
            v-for="(pair, row) in [stats.slice(0, 2), stats.slice(2, 4)]"
            :key="row"
            class="flex"
            :class="
                row === 0 ? 'border-b-[0.5px] border-edge-row pb-3' : 'pt-3'
            "
        >
            <div
                v-for="(stat, column) in pair"
                :key="stat.label"
                class="flex min-w-0 flex-1 flex-col gap-0.5"
                :class="
                    column === 0 ? 'border-r-[0.5px] border-edge-row' : 'pl-3.5'
                "
                :title="stat.title"
            >
                <dt
                    class="truncate text-[12px]/4 tracking-[-0.005em] text-fg-tertiary"
                >
                    {{ stat.label }}
                </dt>
                <dd class="flex items-baseline gap-1 whitespace-nowrap">
                    <span
                        class="text-[18px]/6 font-medium tracking-[-0.02em] tabular-nums"
                        :class="stat.tone ?? 'text-fg-primary'"
                        >{{ stat.value }}</span
                    >
                    <span
                        v-if="stat.unit"
                        class="text-[12px]/4 tracking-[-0.005em] text-fg-tertiary"
                        >{{ stat.unit }}</span
                    >
                </dd>
            </div>
        </div>
    </dl>
</template>
