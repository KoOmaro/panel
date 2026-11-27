<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import BrandLogo from '@/components/dash/BrandLogo.vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import type { FilledIconName } from '@/components/icons/filledIcons';

export type GraphChip = {
    key: string | number;
    label: string;
    icon?: FilledIconName;
    logo?: string | null;
    href?: string;
};

const {
    left = [],
    right = [],
    center,
    leftGap = 24,
    rightGap = 40,
    label,
} = defineProps<{
    left?: GraphChip[];
    right?: GraphChip[];
    center: GraphChip & { dot?: 'red' | 'amber' | null };
    leftGap?: number;
    rightGap?: number;
    label: string;
}>();

const HEIGHT = 150;
const CHIP = 36;
const RADIUS = 6;

function gap(count: number): number {
    return count >= 3 ? 9 : 14;
}

function centers(count: number): number[] {
    const step = CHIP + gap(count);

    return Array.from(
        { length: count },
        (_, index) => HEIGHT / 2 + (index - (count - 1) / 2) * step,
    );
}

function bracket(width: number, count: number, fromCenter: boolean): string[] {
    const mid = HEIGHT / 2 + 0.5;
    const half = width / 2;

    return centers(count).map((raw) => {
        const y = raw + 0.5;
        if (Math.abs(y - mid) < 0.5) {
            return `M0 ${mid}H${width}`;
        }
        const [y0, y1] = fromCenter ? [mid, y] : [y, mid];
        const dir = Math.sign(y1 - y0);
        const r = Math.min(RADIUS, Math.abs(y1 - y0) / 2, half);

        return [
            `M0 ${y0}`,
            `H${half - r}`,
            `Q${half} ${y0} ${half} ${y0 + dir * r}`,
            `V${y1 - dir * r}`,
            `Q${half} ${y1} ${half + r} ${y1}`,
            `H${width}`,
        ].join('');
    });
}

const leftPaths = computed(() => bracket(leftGap, left.length, false));
const rightPaths = computed(() => bracket(rightGap, right.length, true));

const dots = {
    red: 'bg-status-down',
    amber: 'bg-[#f5a833]',
};

const chipClass =
    'flex h-9 shrink-0 items-center gap-[7.92px] rounded-[7.92px] border-[1.446px] border-black/10 bg-linear-to-b from-white to-[#f3f3f3] px-[10.56px] text-[11.22px]/[1.5] font-medium tracking-[-0.01em] text-[#4a4a4a] shadow-[inset_0_1.427px_8.559px_rgb(255_255_255/0.1)] dark:border-white/10 dark:from-[#1e1e1e] dark:to-[#0e1213] dark:text-[#b0b0b0]';
</script>

<template>
    <div
        class="relative mx-auto flex h-[150px] min-w-[363px] items-center justify-center overflow-hidden rounded-[8px] px-[22px]"
        role="img"
        :aria-label="label"
    >
        <div
            class="absolute inset-0 [background-image:linear-gradient(to_right,rgb(0_0_0/0.035)_1px,transparent_1px),linear-gradient(to_bottom,rgb(0_0_0/0.035)_1px,transparent_1px)] [mask-image:radial-gradient(242px_79px_at_50%_50%,#000_0%,rgb(0_0_0/0.6)_55%,transparent_100%)] [background-size:22px_22px] [background-position:5.5px_9px] dark:[background-image:linear-gradient(to_right,rgb(255_255_255/0.025)_1px,transparent_1px),linear-gradient(to_bottom,rgb(255_255_255/0.025)_1px,transparent_1px)]"
            aria-hidden="true"
        />
        <div class="relative flex items-center" aria-hidden="true">
            <template v-if="left.length">
                <div
                    class="flex flex-col"
                    :style="{ gap: `${gap(left.length)}px` }"
                >
                    <div
                        v-for="chip in left"
                        :key="chip.key"
                        class="flex items-center"
                    >
                        <component
                            :is="chip.href ? Link : 'span'"
                            :href="chip.href"
                            :class="chipClass"
                            tabindex="-1"
                        >
                            <BrandLogo
                                v-if="chip.logo"
                                :name="chip.logo"
                                :size="14"
                            />
                            <FilledIcon
                                v-else-if="chip.icon"
                                :name="chip.icon"
                                :size="15"
                            />
                            {{ chip.label }}
                        </component>
                        <span
                            class="h-px min-w-0 grow bg-[#d4d4d4] dark:bg-[#3a3a3a]"
                        />
                    </div>
                </div>
                <svg
                    :width="leftGap"
                    :height="HEIGHT"
                    class="shrink-0 overflow-visible stroke-[#d4d4d4] dark:stroke-[#3a3a3a]"
                    fill="none"
                >
                    <path v-for="(d, index) in leftPaths" :key="index" :d="d" />
                </svg>
            </template>

            <div
                class="shrink-0 rounded-[14.676px] bg-[#ececec] p-[11px] shadow-[0_0_0_7.338px_rgb(0_0_0/0.04),inset_0_0_2.446px_rgb(0_0_0/0.12)] dark:bg-[#101010] dark:shadow-[0_0_0_7.338px_rgb(0_0_0/0.25),inset_0_0_2.446px_rgb(255_255_255/0.25)]"
            >
                <component
                    :is="center.href ? Link : 'span'"
                    :href="center.href"
                    class="flex h-10 min-w-[94px] items-center justify-center gap-[6px] rounded-[7.92px] border-[1.32px] border-white/45 bg-linear-to-b from-[#343434] to-[#5c5c5c] px-[9px] text-[11.22px]/[1.5] font-medium tracking-[-0.01em] text-white shadow-[inset_0_13.71px_82.258px_rgb(255_255_255/0.1)]"
                    tabindex="-1"
                >
                    <BrandLogo
                        v-if="center.logo"
                        :name="center.logo"
                        :size="14"
                    />
                    <FilledIcon
                        v-else-if="center.icon"
                        :name="center.icon"
                        :size="15"
                    />
                    {{ center.label }}
                    <span
                        v-if="center.dot"
                        class="size-1.5 shrink-0 rounded-full"
                        :class="dots[center.dot]"
                    />
                </component>
            </div>

            <template v-if="right.length">
                <svg
                    :width="rightGap"
                    :height="HEIGHT"
                    class="shrink-0 overflow-visible stroke-[#d4d4d4] dark:stroke-[#3a3a3a]"
                    fill="none"
                >
                    <path
                        v-for="(d, index) in rightPaths"
                        :key="index"
                        :d="d"
                    />
                </svg>
                <div
                    class="flex flex-col items-start"
                    :style="{ gap: `${gap(right.length)}px` }"
                >
                    <component
                        :is="chip.href ? Link : 'span'"
                        v-for="chip in right"
                        :key="chip.key"
                        :href="chip.href"
                        :class="chipClass"
                        tabindex="-1"
                    >
                        <BrandLogo
                            v-if="chip.logo"
                            :name="chip.logo"
                            :size="14"
                        />
                        <FilledIcon
                            v-else-if="chip.icon"
                            :name="chip.icon"
                            :size="15"
                        />
                        {{ chip.label }}
                    </component>
                </div>
            </template>
        </div>
    </div>
</template>
