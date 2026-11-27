<script setup lang="ts">
import { computed, useId } from 'vue';

const {
    value,
    label,
    readout,
    size = 124,
    tone = 'blue',
} = defineProps<{
    value: number;
    label?: string;
    readout?: string;
    size?: number;
    tone?: 'blue' | 'sky' | 'green' | 'teal' | 'amber' | 'red';
}>();

const id = useId();

const CENTER_X = 61.2753;
const CENTER_Y = 62.2419;
const RADIUS = 48.84;
const START = 135;
const SWEEP = 270;

const stops = {
    blue: ['#3d63ff', '#a9bbff'],
    sky: ['#3b9cff', '#80bfff'],
    green: ['#3ec46d', '#82d9a0'],
    teal: ['#14b8a6', '#99f6e4'],
    amber: ['#f0943c', '#f5b980'],
    red: ['#f0695a', '#f59d94'],
} as const;

function point(angle: number): [number, number] {
    const radians = (angle * Math.PI) / 180;

    return [
        CENTER_X + RADIUS * Math.cos(radians),
        CENTER_Y + RADIUS * Math.sin(radians),
    ];
}

function arc(from: number, to: number): string {
    const [x1, y1] = point(from);
    const [x2, y2] = point(to);
    const large = to - from > 180 ? 1 : 0;

    return `M${x1.toFixed(3)} ${y1.toFixed(3)}A${RADIUS} ${RADIUS} 0 ${large} 1 ${x2.toFixed(3)} ${y2.toFixed(3)}`;
}

const fraction = computed(() => Math.min(1, Math.max(0, value / 100)));
const track = arc(START, START + SWEEP);
const valuePath = computed(() =>
    fraction.value > 0 ? arc(START, START + SWEEP * fraction.value) : null,
);
const shown = computed(() => readout ?? `${Math.round(value)}%`);
</script>

<template>
    <div
        class="relative shrink-0"
        :style="{ width: `${size}px`, height: `${size}px` }"
        role="meter"
        :aria-valuenow="Math.round(value)"
        aria-valuemin="0"
        aria-valuemax="100"
        :aria-label="label"
    >
        <svg
            viewBox="0 0 124 124"
            fill="none"
            class="absolute inset-0 size-full"
            aria-hidden="true"
        >
            <defs>
                <filter
                    :id="`${id}-inner`"
                    x="7.91919"
                    y="8.88574"
                    width="106.712"
                    height="106.712"
                    filterUnits="userSpaceOnUse"
                    color-interpolation-filters="sRGB"
                >
                    <feFlood flood-opacity="0" result="BackgroundImageFix" />
                    <feBlend
                        mode="normal"
                        in="SourceGraphic"
                        in2="BackgroundImageFix"
                        result="shape"
                    />
                    <feColorMatrix
                        in="SourceAlpha"
                        type="matrix"
                        values="0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 127 0"
                        result="hardAlpha"
                    />
                    <feOffset />
                    <feGaussianBlur stdDeviation="0.805376" />
                    <feComposite
                        in2="hardAlpha"
                        operator="arithmetic"
                        k2="-1"
                        k3="1"
                    />
                    <feColorMatrix
                        type="matrix"
                        values="0 0 0 0 1 0 0 0 0 1 0 0 0 0 1 0 0 0 0.25 0"
                    />
                    <feBlend mode="normal" in2="shape" />
                </filter>
                <linearGradient
                    :id="`${id}-edge`"
                    x1="61.2753"
                    y1="8.88574"
                    x2="61.2753"
                    y2="115.598"
                    gradientUnits="userSpaceOnUse"
                >
                    <stop stop-color="white" />
                    <stop offset="1" stop-color="white" stop-opacity="0.1" />
                </linearGradient>
                <linearGradient
                    :id="`${id}-ring`"
                    x1="61.2756"
                    y1="39.7881"
                    x2="61.2756"
                    y2="84.6941"
                    gradientUnits="userSpaceOnUse"
                >
                    <stop stop-color="white" />
                    <stop offset="1" stop-color="white" stop-opacity="0.1" />
                </linearGradient>
                <linearGradient
                    :id="`${id}-value`"
                    x1="8.40283"
                    y1="62.2419"
                    x2="115.115"
                    y2="62.2419"
                    gradientUnits="userSpaceOnUse"
                >
                    <stop :stop-color="stops[tone][0]" />
                    <stop offset="1" :stop-color="stops[tone][1]" />
                </linearGradient>
                <linearGradient
                    :id="`${id}-vertical`"
                    x1="60.5344"
                    y1="8.88574"
                    x2="60.5344"
                    y2="113.667"
                    gradientUnits="userSpaceOnUse"
                >
                    <stop stop-color="white" stop-opacity="0" />
                    <stop offset="0.50454" stop-color="white" />
                    <stop offset="1" stop-color="white" stop-opacity="0" />
                </linearGradient>
                <linearGradient
                    :id="`${id}-horizontal`"
                    x1="113.666"
                    y1="61.0176"
                    x2="8.88531"
                    y2="61.0176"
                    gradientUnits="userSpaceOnUse"
                >
                    <stop stop-color="white" stop-opacity="0" />
                    <stop offset="0.50454" stop-color="white" />
                    <stop offset="1" stop-color="white" stop-opacity="0" />
                </linearGradient>
                <linearGradient
                    :id="`${id}-outer`"
                    x1="62"
                    y1="122.358"
                    x2="62"
                    y2="1.64259"
                    gradientUnits="userSpaceOnUse"
                >
                    <stop stop-color="white" stop-opacity="0.04" />
                    <stop offset="1" stop-color="white" stop-opacity="0.2" />
                </linearGradient>
            </defs>
            <g :filter="`url(#${id}-inner)`">
                <rect
                    x="7.91919"
                    y="8.88574"
                    width="106.712"
                    height="106.712"
                    rx="53.3561"
                    fill="#030708"
                />
            </g>
            <rect
                x="9.12634"
                y="10.0929"
                width="104.298"
                height="104.298"
                rx="52.149"
                :stroke="`url(#${id}-edge)`"
                stroke-opacity="0.1"
                stroke-width="2.4143"
            />
            <rect
                opacity="0.16"
                x="50.331"
                y="51.2972"
                width="21.8891"
                height="21.8891"
                rx="10.9445"
                stroke-width="0.805376"
                :stroke="`url(#${id}-ring)`"
                stroke-opacity="0.2"
            />
            <rect
                opacity="0.16"
                x="39.2257"
                y="40.1908"
                width="44.1007"
                height="44.1007"
                rx="22.0503"
                stroke-width="0.805376"
                :stroke="`url(#${id}-ring)`"
                stroke-opacity="0.2"
            />
            <path
                :d="track"
                stroke-width="9.03311"
                stroke-linecap="round"
                stroke="white"
                stroke-opacity="0.07"
            />
            <path
                v-if="valuePath"
                :d="valuePath"
                stroke-width="9.03311"
                stroke-linecap="round"
                :stroke="`url(#${id}-value)`"
                class="transition-[d] duration-500 ease-out motion-reduce:transition-none"
            />
            <line
                opacity="0.16"
                x1="61.2759"
                y1="8.88574"
                x2="61.2759"
                y2="113.667"
                stroke-width="0.482861"
                :stroke="`url(#${id}-vertical)`"
            />
            <line
                opacity="0.16"
                x1="113.666"
                y1="61.759"
                x2="8.88531"
                y2="61.759"
                stroke-width="0.482861"
                :stroke="`url(#${id}-horizontal)`"
            />
            <rect
                x="2.25823"
                y="2.25847"
                width="119.483"
                height="119.483"
                rx="59.7417"
                stroke-width="1.23179"
                :stroke="`url(#${id}-outer)`"
            />
        </svg>
        <div
            class="absolute top-[calc(50%+2px)] left-1/2 flex -translate-1/2 flex-col items-center font-ui whitespace-nowrap"
            :style="{ scale: size / 124 }"
        >
            <span
                class="text-[22px]/[26px] font-medium tracking-[-0.03em] text-[#ededed]"
                >{{ shown }}</span
            >
            <span v-if="label" class="text-[11.5px]/3.5 text-[#9a9a9a]">{{
                label
            }}</span>
        </div>
    </div>
</template>
