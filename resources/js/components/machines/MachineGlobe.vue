<script setup lang="ts">
import {
    computed,
    onBeforeUnmount,
    onMounted,
    ref,
    useTemplateRef,
    watch,
} from 'vue';
import { isLand } from '@/components/machines/landMask';
import { useIsDark } from '@/composables/useAppearance';

const { lat, lon, label } = defineProps<{
    lat: number;
    lon: number;
    label: string;
}>();

const RADIUS = 125;
const CENTER_Y = 144;
const RING = 147;
const STEP = 1.5;
const TILT = 64.5;

const stage = useTemplateRef<HTMLDivElement>('stage');
const canvas = useTemplateRef<HTMLCanvasElement>('canvas');
const isDark = useIsDark();
const width = ref(0);
const height = ref(0);
let observer: ResizeObserver | undefined;

const view = computed(() => ({
    phi: (Math.max(-85, Math.min(85, lat - TILT)) * Math.PI) / 180,
    lam: (lon * Math.PI) / 180,
}));

function project(latDeg: number, lonDeg: number) {
    const phi = (latDeg * Math.PI) / 180;
    const lam = (lonDeg * Math.PI) / 180 - view.value.lam;
    const { phi: phi0 } = view.value;
    const cosc =
        Math.sin(phi0) * Math.sin(phi) +
        Math.cos(phi0) * Math.cos(phi) * Math.cos(lam);

    return {
        x: width.value / 2 + RADIUS * Math.cos(phi) * Math.sin(lam),
        y:
            CENTER_Y -
            RADIUS *
                (Math.cos(phi0) * Math.sin(phi) -
                    Math.sin(phi0) * Math.cos(phi) * Math.cos(lam)),
        front: cosc,
    };
}

const pin = computed(() => (width.value ? project(lat, lon) : null));

function draw(): void {
    const el = canvas.value;
    const ctx = el?.getContext('2d');
    if (!el || !ctx || !width.value || !height.value) return;

    const dpr = window.devicePixelRatio || 1;
    el.width = Math.round(width.value * dpr);
    el.height = Math.round(height.value * dpr);
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.clearRect(0, 0, width.value, height.value);

    const cx = width.value / 2;
    const dot = isDark.value ? '160,160,160' : '90,90,90';

    for (let la = -90 + STEP / 2; la < 90; la += STEP) {
        for (let lo = -180 + STEP / 2; lo < 180; lo += STEP) {
            const p = project(la, lo);
            if (p.front <= 0.02 || p.y > height.value + 2 || p.y < -2) continue;
            if (!isLand(la, lo)) continue;
            const alpha = 0.35 + 0.6 * p.front;
            ctx.fillStyle = `rgba(${dot},${alpha.toFixed(3)})`;
            const r = 0.55 + 0.45 * p.front;
            ctx.beginPath();
            ctx.arc(p.x, p.y, r, 0, Math.PI * 2);
            ctx.fill();
        }
    }

    const ring = ctx.createLinearGradient(cx - RING, 0, cx + RING, 0);
    const edge = isDark.value ? '255,255,255' : '0,0,0';
    ring.addColorStop(0, `rgba(${edge},0.12)`);
    ring.addColorStop(0.5, `rgba(${edge},0.45)`);
    ring.addColorStop(1, `rgba(${edge},0.12)`);
    ctx.strokeStyle = ring;
    ctx.lineWidth = 0.8;
    ctx.beginPath();
    ctx.arc(cx, CENTER_Y + 5, RING, Math.PI * 1.22, Math.PI * 1.78);
    ctx.stroke();
}

function measure(): void {
    width.value = stage.value?.clientWidth ?? 0;
    height.value = stage.value?.clientHeight ?? 0;
    draw();
}

onMounted(() => {
    measure();
    if (stage.value && 'ResizeObserver' in window) {
        observer = new ResizeObserver(measure);
        observer.observe(stage.value);
    }
});

onBeforeUnmount(() => observer?.disconnect());

watch(
    () => [lat, lon, isDark.value],
    () => draw(),
);
</script>

<template>
    <div
        ref="stage"
        class="relative size-full overflow-hidden"
        aria-hidden="true"
    >
        <canvas ref="canvas" class="absolute inset-0 size-full" />
        <span
            v-if="pin && pin.front > 0"
            class="absolute flex h-3.5 -translate-1/2 items-center gap-[3px] rounded-full border-[0.55px] border-white/30 bg-linear-to-b from-[#0e1213] from-45% to-[#0e1213]/90 px-[5px] shadow-[0_2.7px_12px_0_rgb(0_0_0/0.25),inset_0_1.1px_6.6px_0_rgb(255_255_255/0.1)]"
            :style="{ left: `${pin.x}px`, top: `${pin.y}px` }"
        >
            <span
                class="size-[4.8px] shrink-0 rounded-full bg-[radial-gradient(circle,rgb(255_255_255/0.9),#3ec46d)]"
            />
            <span
                class="font-ui text-[7.5px]/[1.5] font-medium whitespace-nowrap text-white"
                >{{ label }}</span
            >
        </span>
    </div>
</template>
