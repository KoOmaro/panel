<script setup lang="ts">
import { computed, ref } from 'vue';
import EmptyArt from '@/components/dash/EmptyArt.vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import type { FilledIconName } from '@/components/icons/filledIcons';
import TemplateLogo from './TemplateLogo.vue';

export type StagePhase =
    | 'idle'
    | 'packing'
    | 'uploading'
    | 'detecting'
    | 'found'
    | 'unknown';

export type StageChip = {
    label: string;
    icon?: string | null;
    glyph?: FilledIconName;
};

const {
    phase,
    progress = 0,
    showPercent = true,
    fileLabel = '',
    machineLabel = '',
    status = '',
    chips = [],
    note = null,
    busy = false,
} = defineProps<{
    phase: StagePhase;
    progress?: number;
    showPercent?: boolean;
    fileLabel?: string;
    machineLabel?: string;
    status?: string;
    chips?: StageChip[];
    note?: string | null;
    busy?: boolean;
}>();

const emit = defineEmits<{
    drop: [transfer: DataTransfer | null];
    chooseFiles: [];
    chooseFolder: [];
}>();

const depth = ref(0);
const over = computed(() => depth.value > 0 && !busy);

const working = computed(() =>
    ['packing', 'uploading', 'detecting'].includes(phase),
);
const settled = computed(() => phase === 'found' || phase === 'unknown');
const active = computed(() => phase !== 'idle');
const percent = computed(() =>
    Math.round(Math.min(1, Math.max(0, progress)) * 100),
);
const fill = computed(() =>
    phase === 'detecting' || settled.value ? 1 : Math.min(1, progress),
);

function onEnter(): void {
    depth.value++;
}

function onLeave(): void {
    depth.value = Math.max(0, depth.value - 1);
}

function onDrop(event: DragEvent): void {
    depth.value = 0;
    if (!busy) emit('drop', event.dataTransfer);
}
</script>

<template>
    <div
        class="deploy-stage relative min-h-[240px] overflow-clip rounded-[10px] border border-dashed border-[#d4d4d4] bg-[#fafafa] dark:border-[#3a3a3a] dark:bg-[#151515]"
        :data-phase="phase"
        :aria-busy="working || undefined"
        @dragenter.prevent="onEnter"
        @dragover.prevent
        @dragleave.prevent="onLeave"
        @drop.prevent="onDrop"
    >
        <div class="deploy-stage-grid pointer-events-none absolute -inset-px" />
        <img
            src="/images/deploy/dropzone-glow.svg"
            alt=""
            width="360"
            height="190"
            class="pointer-events-none absolute top-[-11px] left-[calc(50%-181px)] max-w-none select-none"
            draggable="false"
        />
        <EmptyArt
            kind="upload"
            class="absolute top-[7px] left-[calc(50%-126.5px)] h-[109.2px] w-[252px]"
        />

        <span
            class="deploy-line deploy-line--upload absolute top-[61px] left-[calc(50%-69.5px)] h-0.5 rounded-[1px] bg-linear-to-r from-[#2f5af7] to-[#8ea6ff] shadow-[0_0_6px_rgb(51_97_255/0.8)]"
            :class="active && phase !== 'packing' && 'is-on'"
            :style="{ width: `${Math.max(1, fill * 32)}px` }"
            aria-hidden="true"
        />
        <span
            class="deploy-line deploy-line--deploy absolute top-[61px] left-[calc(50%+37.5px)] h-0.5 rounded-[1px] bg-[#3ec46d] shadow-[0_0_6px_rgb(62_196_109/0.7)]"
            :class="phase === 'found' && 'is-on'"
            aria-hidden="true"
        />
        <span
            v-for="ring in 2"
            :key="ring"
            class="deploy-pulse pointer-events-none absolute top-[25.8px] left-[calc(50%-35.9px)] size-[72.5px] rounded-full border-[1.5px] border-[#6f8fff]"
            :class="[phase === 'detecting' && 'is-on', `deploy-pulse--${ring}`]"
            aria-hidden="true"
        />
        <span
            class="deploy-tick absolute top-[83px] left-[calc(50%+21.5px)] flex size-4 items-center justify-center rounded-full border-2 border-[#141414] bg-[#2fb35f] text-white shadow-[0_0_6px_rgb(47_179_95/0.5)]"
            :class="phase === 'found' && 'is-on'"
            aria-hidden="true"
        >
            <svg viewBox="0 0 9 9" class="size-[9px]" fill="none">
                <path
                    d="M7.5 2.25 3.375 6.375 1.5 4.5"
                    stroke="currentColor"
                    stroke-width="1.5"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                />
            </svg>
        </span>
        <span
            class="deploy-label absolute top-[86px] left-[calc(50%-88px)] max-w-[120px] truncate text-[11.5px]/[14px] font-medium tracking-[-0.005em] text-fg-secondary"
            :class="active && 'is-on'"
            >{{ fileLabel }}</span
        >
        <span
            class="deploy-label absolute top-[86px] left-[calc(50%+88.5px)] max-w-[120px] truncate text-[11.5px]/[14px] font-medium tracking-[-0.005em] text-fg-secondary"
            :class="active && 'is-on'"
            >{{ machineLabel }}</span
        >

        <div
            class="deploy-over pointer-events-none absolute -inset-px rounded-[10px] border border-dashed border-[rgb(77_123_255/0.9)] bg-[rgb(51_97_255/0.06)]"
            :class="over && 'is-on'"
            aria-hidden="true"
        />

        <div class="relative grid justify-items-center px-5 pt-[119px] pb-4">
            <div
                class="deploy-copy col-start-1 row-start-1 flex flex-col items-center gap-1 text-center"
                :class="!active && 'is-on'"
                :inert="active || undefined"
            >
                <p
                    class="text-[15px]/[22px] font-medium tracking-[-0.01em] text-fg-primary"
                >
                    {{ $t('Drop files here') }}
                </p>
                <p
                    class="text-[12.5px]/[18px] tracking-[-0.005em] text-fg-tertiary"
                >
                    {{ $t('A folder, a .zip, or a single Dockerfile') }}
                </p>
                <div class="flex flex-wrap justify-center gap-2 pt-2.5">
                    <button
                        type="button"
                        class="dash-button dash-button--secondary"
                        :disabled="busy"
                        @click="emit('chooseFiles')"
                    >
                        <FilledIcon name="upload-fill" />
                        {{ $t('Choose files') }}
                    </button>
                    <button
                        type="button"
                        class="dash-button dash-button--secondary"
                        :disabled="busy"
                        @click="emit('chooseFolder')"
                    >
                        <FilledIcon name="projects-fill" />
                        {{ $t('Choose a folder') }}
                    </button>
                </div>
            </div>

            <div
                class="deploy-result col-start-1 row-start-1 flex w-full flex-col items-center pt-1.5"
                :class="active && 'is-on'"
                :inert="!active || undefined"
                aria-live="polite"
            >
                <div class="relative h-[34px] w-[320px] max-w-full">
                    <p
                        class="absolute top-0 left-0 max-w-[260px] truncate text-[12.5px]/4 font-medium tracking-[-0.005em]"
                        :class="
                            phase === 'found'
                                ? 'text-fg-primary'
                                : 'text-fg-secondary'
                        "
                    >
                        <Transition name="deploy-status" mode="out-in">
                            <span :key="status">{{ status }}</span>
                        </Transition>
                    </p>
                    <p
                        v-if="showPercent && phase !== 'packing'"
                        class="absolute top-0 right-0 font-ui-mono text-[11.5px]/4 text-fg-tertiary tabular-nums"
                    >
                        {{ percent }}%
                    </p>
                    <div
                        class="absolute top-[26px] left-0 h-1 w-full overflow-clip rounded-[2px] bg-[#e5e5e5] dark:bg-[#262626]"
                    >
                        <span
                            class="deploy-fill absolute inset-y-0 left-0 rounded-[2px] bg-linear-to-r from-[#2f5af7] to-[#7f9cff] shadow-[0_0_6px_rgb(51_97_255/0.5)]"
                            :class="phase !== 'found' && 'is-on'"
                            :style="{ width: `${fill * 100}%` }"
                        />
                        <span
                            class="deploy-fill-done absolute inset-0 rounded-[2px] bg-[#3ec46d] shadow-[0_0_6px_rgb(62_196_109/0.45)]"
                            :class="phase === 'found' && 'is-on'"
                        />
                        <span
                            class="deploy-scan absolute inset-y-0 left-[-90px] w-[90px] bg-linear-to-r from-white/0 via-white/70 to-white/0"
                            :class="phase === 'detecting' && 'is-on'"
                        />
                    </div>
                </div>

                <ul
                    v-if="settled && chips.length"
                    class="mt-3 flex max-w-full flex-wrap justify-center gap-2"
                >
                    <li
                        v-for="(chip, index) in chips"
                        :key="chip.label"
                        class="deploy-chip flex h-[26px] items-center gap-1.5 rounded-[6px] border-[0.5px] border-[#dcdcdc] bg-white pr-[9px] text-[12px]/4 font-medium tracking-[-0.005em] text-fg-secondary shadow-[0_1px_1.5px_rgb(0_0_0/0.08)] dark:border-[#323232] dark:bg-[#1f1f1f] dark:shadow-[inset_0_1px_0_rgb(255_255_255/0.05),0_1px_1.5px_rgb(0_0_0/0.5)]"
                        :class="chip.icon || chip.glyph ? 'pl-1.5' : 'pl-[9px]'"
                        :style="{ '--i': index }"
                    >
                        <TemplateLogo
                            v-if="chip.icon"
                            :icon="chip.icon"
                            :size="14"
                        />
                        <FilledIcon
                            v-else-if="chip.glyph"
                            :name="chip.glyph"
                            :size="14"
                            class="text-fg-muted"
                        />
                        {{ chip.label }}
                    </li>
                </ul>
                <p
                    v-if="settled && note"
                    class="deploy-note mt-2.5 max-w-[520px] text-center text-[12px]/4 tracking-[-0.005em] text-fg-tertiary"
                >
                    {{ note }}
                </p>
            </div>
        </div>
    </div>
</template>

<style scoped>
.deploy-stage-grid {
    --grid-line: rgb(0 0 0 / 0.06);
    background-image:
        linear-gradient(to right, var(--grid-line) 1px, transparent 1px),
        linear-gradient(to bottom, var(--grid-line) 1px, transparent 1px);
    background-size: 32px 30.476px;
    mask-image: radial-gradient(
        closest-side,
        #fff,
        rgb(255 255 255 / 0.5) 50%,
        transparent
    );
}

.dark .deploy-stage-grid {
    --grid-line: rgb(255 255 255 / 0.05);
}

.deploy-over {
    opacity: 0;
    transition: opacity 130ms cubic-bezier(0, 0, 0.58, 1);
}

.deploy-over.is-on {
    opacity: 1;
    transition-duration: 120ms;
}

.deploy-copy {
    opacity: 0;
    transform: translateY(6px);
    transition:
        opacity 170ms cubic-bezier(0, 0, 0.58, 1),
        transform 170ms cubic-bezier(0, 0, 0.58, 1);
}

.deploy-copy.is-on {
    opacity: 1;
    transform: none;
}

.deploy-label {
    opacity: 0;
    translate: -50% 4px;
    transition:
        opacity 200ms cubic-bezier(0, 0, 0.58, 1),
        translate 230ms cubic-bezier(0, 0, 0.58, 1);
}

.deploy-label.is-on {
    opacity: 1;
    translate: -50% 0;
    transition-delay: 70ms;
}

.deploy-result {
    opacity: 0;
    transition: opacity 200ms cubic-bezier(0, 0, 0.58, 1);
}

.deploy-result.is-on {
    opacity: 1;
    transition-delay: 150ms;
}

.deploy-line {
    opacity: 0;
    transition:
        opacity 70ms cubic-bezier(0.5, 0, 0.5, 1),
        width 200ms linear;
}

.deploy-line.is-on {
    opacity: 1;
}

.deploy-line--deploy {
    width: 1px;
    transition:
        opacity 60ms cubic-bezier(0.5, 0, 0.5, 1),
        width 300ms cubic-bezier(0, 0, 0.58, 1);
}

.deploy-line--deploy.is-on {
    width: 32px;
}

.deploy-fill {
    opacity: 0;
    transition:
        opacity 200ms cubic-bezier(0, 0, 0.58, 1),
        width 200ms linear;
}

.deploy-fill.is-on {
    opacity: 1;
}

.deploy-fill-done {
    opacity: 0;
    transition: opacity 200ms cubic-bezier(0, 0, 0.58, 1);
}

.deploy-fill-done.is-on {
    opacity: 1;
}

.deploy-tick {
    opacity: 0;
    scale: 0.4;
    transition:
        opacity 130ms cubic-bezier(0, 0, 0.58, 1),
        scale 300ms cubic-bezier(0.45, 1.45, 0.8, 1);
}

.deploy-tick.is-on {
    opacity: 1;
    scale: 1;
    transition-delay: 100ms;
}

.deploy-pulse {
    opacity: 0;
}

.deploy-pulse.is-on {
    animation: deploy-pulse 1s cubic-bezier(0, 0, 0.58, 1) infinite;
}

.deploy-pulse--2.is-on {
    animation-delay: 220ms;
}

@keyframes deploy-pulse {
    0% {
        opacity: 0;
        scale: 1;
    }
    6% {
        opacity: 0.8;
    }
    50% {
        opacity: 0;
        scale: 1.5;
    }
    100% {
        opacity: 0;
        scale: 1.5;
    }
}

.deploy-scan {
    opacity: 0;
}

.deploy-scan.is-on {
    animation: deploy-scan 1.1s cubic-bezier(0.42, 0, 0.58, 1) infinite;
}

@keyframes deploy-scan {
    0% {
        opacity: 0;
        transform: translateX(0);
    }
    6% {
        opacity: 1;
    }
    76% {
        opacity: 1;
    }
    95%,
    100% {
        opacity: 0;
        transform: translateX(410px);
    }
}

.deploy-chip {
    animation: deploy-rise 280ms cubic-bezier(0, 0, 0.58, 1) both;
    animation-delay: calc(250ms + var(--i) * 90ms);
}

@keyframes deploy-rise {
    from {
        opacity: 0;
        translate: 0 8px;
    }
    80% {
        opacity: 1;
    }
    to {
        opacity: 1;
        translate: 0 0;
    }
}

.deploy-note {
    animation: deploy-fade 200ms cubic-bezier(0, 0, 0.58, 1) 700ms both;
}

@keyframes deploy-fade {
    from {
        opacity: 0;
    }
    to {
        opacity: 1;
    }
}

.deploy-status-enter-active {
    transition: opacity 150ms cubic-bezier(0, 0, 0.58, 1);
}

.deploy-status-leave-active {
    transition: opacity 120ms cubic-bezier(0, 0, 0.58, 1);
}

.deploy-status-enter-from,
.deploy-status-leave-to {
    opacity: 0;
}

@media (prefers-reduced-motion: reduce) {
    .deploy-stage *,
    .deploy-stage *::before,
    .deploy-stage *::after {
        transition-duration: 0ms !important;
        transition-delay: 0ms !important;
    }

    .deploy-pulse.is-on,
    .deploy-scan.is-on {
        animation: none;
        opacity: 0;
    }

    .deploy-chip,
    .deploy-note {
        animation: none;
    }
}
</style>
