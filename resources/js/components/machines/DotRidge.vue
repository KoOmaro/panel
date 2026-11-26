<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, useTemplateRef } from 'vue';

type Props = {
    values: (number | null)[];
    timestamps: number[];
    max: number;
    format: (value: number) => string;
    ticks: string[];
    hot?: number;
    long?: boolean;
    label: string;
};

const {
    values,
    timestamps,
    max,
    format,
    ticks,
    hot = 4,
    long = false,
    label,
} = defineProps<Props>();

const PITCH = 6;
const ROWS = 10;
const ROW = 5.6;
const BASE = 72.8;
const HEIGHT = 75.6;

const stage = useTemplateRef<HTMLDivElement>('stage');
const width = ref(0);
const hover = ref<number | null>(null);
let observer: ResizeObserver | undefined;

const columns = computed(() => Math.max(8, Math.floor(width.value / PITCH)));

const buckets = computed(() => {
    const count = columns.value;
    const n = values.length;

    return Array.from({ length: count }, (_, col) => {
        if (n === 0) return { value: null, ts: null };
        const from = Math.floor((col * n) / count);
        const to = Math.max(from + 1, Math.floor(((col + 1) * n) / count));
        let value: number | null = null;
        for (let i = from; i < Math.min(n, to); i++) {
            const v = values[i];
            if (v !== null && v !== undefined && (value === null || v > value))
                value = v;
        }

        return { value, ts: timestamps[Math.min(n - 1, from)] ?? null };
    });
});

function rowsFor(value: number | null): number {
    if (value === null) return -1;

    return Math.max(0, Math.min(ROWS, Math.round((value / max) * ROWS)));
}

type Dot = { x: number; y: number; r: number; o: number; blue: boolean };

const dots = computed<Dot[]>(() => {
    const out: Dot[] = [];
    const count = columns.value;

    buckets.value.forEach((bucket, col) => {
        const x = 3 + col * PITCH;
        const top = rowsFor(bucket.value);
        const blue = col >= count - hot && bucket.value !== null;

        for (let row = 0; row <= ROWS; row++) {
            const y = BASE - row * ROW;
            if (top < 0 || row > top) {
                out.push({ x, y, r: 1, o: 0.08, blue: false });
                continue;
            }
            if (top === 0) {
                out.push({ x, y, r: 1, o: 0.24, blue });
                continue;
            }
            const depth = top - row;
            const o =
                depth === 0
                    ? 0.96
                    : depth === 1
                      ? 0.72
                      : Math.max(0.24, 0.72 - (depth - 1) * 0.16);
            const r = depth === 0 ? 1.8 : depth === 1 ? 1.3 : 1;
            out.push({ x, y, r, o, blue });
        }
    });

    return out;
});

const peak = computed(() => {
    let best: number | null = null;
    let index = -1;
    buckets.value.forEach((bucket, col) => {
        if (bucket.value !== null && (best === null || bucket.value >= best)) {
            best = bucket.value;
            index = col;
        }
    });

    return index < 0 ? null : index;
});

function when(ts: number | null): string {
    if (ts === null) return '';
    const date = new Date(ts * 1000);
    const time = date.toLocaleTimeString([], {
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    });

    return long
        ? `${date.toLocaleDateString([], { day: 'numeric', month: 'short' })} ${time}`
        : time;
}

const marker = computed(() => {
    const col = hover.value ?? peak.value;
    if (col === null) return null;
    const bucket = buckets.value[col];
    if (!bucket || bucket.value === null) return null;
    const x = 3 + col * PITCH;
    const top = rowsFor(bucket.value);
    const right = x > width.value - 140;

    return {
        x,
        y: BASE - Math.max(0, top) * ROW - 4,
        right,
        hovering: hover.value !== null,
        text: `${hover.value === null ? 'PEAK ' : ''}${format(bucket.value)} · ${when(bucket.ts)}`,
    };
});

function onMove(event: PointerEvent): void {
    const col = Math.floor(event.offsetX / PITCH);
    hover.value =
        col >= 0 && col < columns.value && buckets.value[col]?.value !== null
            ? col
            : null;
}

function measure(): void {
    width.value = stage.value?.clientWidth ?? 0;
}

onMounted(() => {
    measure();
    if (stage.value && 'ResizeObserver' in window) {
        observer = new ResizeObserver(measure);
        observer.observe(stage.value);
    }
});

onBeforeUnmount(() => observer?.disconnect());
</script>

<template>
    <div class="flex flex-col gap-1.5">
        <div
            ref="stage"
            class="relative w-full"
            :style="{ height: `${HEIGHT}px` }"
            role="img"
            :aria-label="label"
            @pointermove="onMove"
            @pointerleave="hover = null"
        >
            <svg
                v-if="width"
                :width="width"
                :height="HEIGHT"
                class="absolute inset-0 text-[#151515] dark:text-white"
                aria-hidden="true"
            >
                <circle
                    v-for="(dot, index) in dots"
                    :key="index"
                    :cx="dot.x"
                    :cy="dot.y"
                    :r="dot.r"
                    :fill="dot.blue ? '#4d7dff' : 'currentColor'"
                    :fill-opacity="dot.o"
                />
                <line
                    v-if="marker"
                    :x1="marker.x"
                    :x2="marker.x"
                    y1="14"
                    :y2="marker.y"
                    stroke="currentColor"
                    :stroke-opacity="marker.hovering ? 0.6 : 0.35"
                    stroke-width="1"
                />
            </svg>
            <span
                v-if="marker"
                class="pointer-events-none absolute top-0 font-ui-mono text-[10px]/3 tracking-[0.04em] whitespace-nowrap uppercase"
                :class="
                    marker.hovering ? 'text-fg-primary' : 'text-fg-secondary'
                "
                :style="
                    marker.right
                        ? { right: `${width - marker.x + 8}px` }
                        : { left: `${marker.x + 8}px` }
                "
                >{{ marker.text }}</span
            >
        </div>
        <div
            class="flex items-start justify-between font-ui-mono text-[10px]/3 tracking-[0.04em] text-fg-faint uppercase"
        >
            <span
                v-for="(tick, index) in ticks"
                :key="tick"
                :class="{ 'text-fg-secondary': index === ticks.length - 1 }"
                >{{ tick }}</span
            >
        </div>
    </div>
</template>
