<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import close from '@/components/dash/assets/close-small.svg?raw';
import DashButton from '@/components/dash/DashButton.vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import { docsUrl } from '@/lib/docs';

const emit = defineEmits<{ connect: [] }>();

const page = usePage<{ workspace?: { id: number }; docsUrl?: string | null }>();
const docs = computed(() =>
    page.props.docsUrl ? docsUrl(page.props.docsUrl) : null,
);
const storageKey = computed(
    () => `serveros:start-card:${page.props.workspace?.id ?? 'none'}`,
);

const shown = ref(false);

onMounted(() => {
    try {
        shown.value = window.localStorage.getItem(storageKey.value) === null;
    } catch {
        shown.value = true;
    }
});

function dismiss(): void {
    shown.value = false;
    try {
        window.localStorage.setItem(storageKey.value, '1');
    } catch {
        shown.value = false;
    }
}

const tiles = [
    {
        key: 'terminal',
        icon: 'terminal-fill',
        left: 30,
        top: 40,
        box: 38,
        size: 34,
        radius: 9.5,
        iconSize: 17,
        turn: 8,
        delay: 0.7,
    },
    {
        key: 'docs',
        icon: 'docs-fill',
        left: 200,
        top: 32,
        box: 36,
        size: 32,
        radius: 9,
        iconSize: 16,
        turn: -7,
        delay: 1.1,
    },
] as const;

const stars = [
    { left: 84.66, top: 13.64, size: 2, bright: true },
    { left: 172.41, top: 58.38, size: 1.5, bright: false },
    { left: 238.97, top: 12.62, size: 2, bright: true },
    { left: 18.42, top: 18.41, size: 1.5, bright: false },
    { left: 96.39, top: 79.95, size: 1, bright: false },
    { left: 254.89, top: 80.74, size: 1.5, bright: false },
    { left: 65.75, top: 93.85, size: 1, bright: false },
] as const;

const gridLines = [10.81, 98.17, 186.44, 265.7] as const;
</script>

<template>
    <Transition name="start-card" appear>
        <aside
            v-if="shown"
            class="dark fixed right-6 bottom-6 z-30 w-[300px] max-sm:hidden"
            :aria-label="$t('Get started')"
        >
            <div
                class="relative flex flex-col overflow-clip rounded-[12px] border-[0.5px] border-[#262626] bg-[#181818] px-3.5 pt-3 pb-3.5 font-ui shadow-[0_8px_16px_-8px_rgb(0_0_0/0.35),0_1px_2px_rgb(0_0_0/0.5)]"
            >
                <div
                    class="relative h-[112px] w-[272px] shrink-0 overflow-clip"
                    aria-hidden="true"
                >
                    <span
                        v-for="x in gridLines"
                        :key="x"
                        class="absolute top-2 h-[98px] w-px bg-linear-to-b from-white/0 via-white/7 via-70% to-white/0"
                        :style="{ left: `${x}px` }"
                    />
                    <span
                        v-for="(star, index) in stars"
                        :key="index"
                        class="absolute rounded-full bg-white"
                        :class="star.bright ? 'opacity-75' : 'opacity-45'"
                        :style="{
                            left: `${star.left}px`,
                            top: `${star.top}px`,
                            width: `${star.size}px`,
                            height: `${star.size}px`,
                        }"
                    />
                    <span
                        class="absolute top-[90px] left-[60px] h-12 w-[152px] rounded-[50%] bg-[rgb(61_94_240/0.42)] blur-[10px]"
                    />
                    <span
                        class="absolute top-[111px] left-[30px] h-px w-[212px] bg-[linear-gradient(90deg,rgb(61_94_240/0)_0%,rgb(124_147_255/0.8)_30%,#fff_50%,rgb(124_147_255/0.8)_70%,rgb(61_94_240/0)_100%)] opacity-80"
                    />
                    <span
                        class="absolute top-[66px] left-[135.5px] h-[45px] w-px bg-[repeating-linear-gradient(180deg,rgb(124_147_255/0.7)_0_3px,transparent_3px_6px)]"
                    />

                    <span
                        class="start-card-float absolute top-[12px] left-[110px] flex size-[54px] items-center justify-center"
                    >
                        <span
                            class="relative flex size-[48px] -rotate-4 items-center justify-center overflow-clip rounded-[13px] border-[0.37px] border-white/60 bg-linear-to-b from-white to-[#e0e0e0] shadow-[0_4px_8px_-1px_rgb(0_0_0/0.45)]"
                        >
                            <svg
                                width="28"
                                height="28"
                                viewBox="0 0 28 28"
                                fill="none"
                            >
                                <rect
                                    x="3.5"
                                    y="4.5"
                                    width="21"
                                    height="8"
                                    rx="2.4"
                                    fill="#1c1c1c"
                                />
                                <rect
                                    x="3.5"
                                    y="15.5"
                                    width="21"
                                    height="8"
                                    rx="2.4"
                                    fill="#1c1c1c"
                                />
                                <circle
                                    cx="8"
                                    cy="8.5"
                                    r="1.4"
                                    fill="#3d68ff"
                                />
                                <circle
                                    cx="8"
                                    cy="19.5"
                                    r="1.4"
                                    fill="#3ec46d"
                                />
                                <path
                                    d="M12.5 8.5h8M12.5 19.5h8"
                                    stroke="#5c5c5c"
                                    stroke-width="1.3"
                                    stroke-linecap="round"
                                />
                            </svg>
                            <span
                                class="pointer-events-none absolute inset-0 rounded-[inherit] shadow-[inset_0_0.74px_0_rgb(255_255_255/0.9)]"
                            />
                        </span>
                    </span>

                    <span
                        v-for="tile in tiles"
                        :key="tile.key"
                        class="start-card-float absolute flex items-center justify-center"
                        :style="{
                            left: `${tile.left}px`,
                            top: `${tile.top}px`,
                            width: `${tile.box}px`,
                            height: `${tile.box}px`,
                            animationDelay: `${tile.delay}s`,
                        }"
                    >
                        <span
                            class="relative flex shrink-0 items-center justify-center overflow-clip border-[0.37px] border-white/14 bg-linear-to-b from-[#3d3d3d] to-[#212121] text-white/80 shadow-[0_4px_8px_-1px_rgb(0_0_0/0.45)]"
                            :style="{
                                width: `${tile.size}px`,
                                height: `${tile.size}px`,
                                borderRadius: `${tile.radius}px`,
                                transform: `rotate(${tile.turn}deg)`,
                            }"
                        >
                            <FilledIcon
                                :name="tile.icon"
                                :size="tile.iconSize"
                            />
                            <span
                                class="pointer-events-none absolute inset-0 rounded-[inherit] shadow-[inset_0_0.74px_0_rgb(255_255_255/0.12)]"
                            />
                        </span>
                    </span>
                </div>

                <div class="flex flex-col gap-[3px] py-3">
                    <p
                        class="text-[14px]/5 font-medium tracking-[-0.005em] text-fg-primary"
                    >
                        {{ $t('Connect your first server') }}
                    </p>
                    <p class="text-[12px]/[17px] text-fg-muted">
                        {{
                            $t(
                                'Run one command on a Linux server you already have. ServerOS finds what’s running on it and keeps an eye on it from here.',
                            )
                        }}
                    </p>
                </div>

                <div class="flex gap-2">
                    <DashButton
                        class="flex-1"
                        icon="plug"
                        :label="$t('Connect a server')"
                        @click="emit('connect')"
                    />
                    <DashButton
                        v-if="docs"
                        :href="docs"
                        external
                        icon="docs-fill"
                        :label="$t('Docs')"
                    />
                </div>

                <button
                    type="button"
                    class="absolute top-3 right-2 flex size-5 cursor-pointer items-center justify-center rounded-[5px] text-white/45 transition-colors hover:bg-white/8 hover:text-white/80 focus-visible:outline-2 focus-visible:outline-(--ds-ring)"
                    :aria-label="$t('Dismiss')"
                    @click="dismiss"
                >
                    <span
                        class="flex size-3"
                        aria-hidden="true"
                        v-html="close"
                    />
                </button>
            </div>
        </aside>
    </Transition>
</template>

<style scoped>
.start-card-float {
    animation: start-card-float 4.8s ease-in-out infinite alternate;
}

.start-card-enter-active,
.start-card-leave-active {
    transition:
        opacity 220ms ease,
        transform 220ms cubic-bezier(0.23, 1, 0.32, 1);
}

.start-card-enter-from,
.start-card-leave-to {
    opacity: 0;
    transform: translateY(8px);
}

@keyframes start-card-float {
    from {
        translate: 0 0;
    }
    to {
        translate: 0 -3px;
    }
}

@media (prefers-reduced-motion: reduce) {
    .start-card-float {
        animation: none;
    }

    .start-card-enter-active,
    .start-card-leave-active {
        transition: none;
    }
}
</style>
