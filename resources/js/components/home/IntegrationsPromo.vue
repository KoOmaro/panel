<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import close from '@/components/dash/assets/close-small.svg?raw';
import BrandLogo from '@/components/dash/BrandLogo.vue';
import DashButton from '@/components/dash/DashButton.vue';
import { onWorkspace } from '@/lib/workspaceRoute';
import { integrations } from '@/routes/workspace';
import integrationRoutes from '@/routes/workspace/integrations';

const page = usePage<{ workspace?: { slug: string } }>();
const slug = computed(() => page.props.workspace?.slug ?? '');
const href = computed(
    () =>
        onWorkspace(integrations(slug.value, { query: { from: 'promo' } })).url,
);

const shown = ref(true);

const tiles = [
    {
        logo: 'github',
        left: 14.41,
        top: 19.26,
        box: 41.79,
        size: 37,
        radius: 10.36,
        logoSize: 20.72,
        turn: -8,
        light: false,
        delay: 0,
    },
    {
        logo: 'linear',
        left: 85.48,
        top: 68.92,
        box: 29.28,
        size: 26.64,
        radius: 7.4,
        logoSize: 14.8,
        turn: 6,
        light: false,
        delay: 0.9,
    },
    {
        logo: 'cloudflare',
        left: 131.67,
        top: 15.79,
        box: 31.34,
        size: 28.12,
        radius: 7.4,
        logoSize: 15.54,
        turn: 7,
        light: true,
        delay: 0.4,
    },
    {
        logo: 'slack',
        left: 195.46,
        top: 8.62,
        box: 47.17,
        size: 42.92,
        radius: 11.84,
        logoSize: 23.68,
        turn: 6,
        light: true,
        delay: 1.3,
    },
    {
        logo: 'hetzner',
        left: 176.53,
        top: 65.45,
        box: 25.4,
        size: 22.2,
        radius: 5.92,
        logoSize: 12.58,
        turn: -9,
        light: false,
        delay: 0.6,
    },
] as const;

const stars = [
    { left: 84.66, top: 13.64, size: 2, bright: true },
    { left: 150.41, top: 47.38, size: 1.5, bright: false },
    { left: 226.97, top: 64.62, size: 2, bright: true },
    { left: 32.42, top: 80.41, size: 1.5, bright: false },
    { left: 186.44, top: 22.26, size: 1, bright: false },
    { left: 254.89, top: 33.74, size: 1.5, bright: false },
    { left: 123.39, top: 73.95, size: 1, bright: false },
    { left: 65.75, top: 53.85, size: 1, bright: false },
] as const;

const gridLines = [10.81, 98.17, 186.44, 265.7] as const;

function dismiss(): void {
    shown.value = false;
    router.post(
        onWorkspace(integrationRoutes.promo.dismiss(slug.value)).url,
        {},
        {
            preserveScroll: true,
            preserveState: true,
            only: ['integrationsPromo'],
        },
    );
}
</script>

<template>
    <Transition name="integrations-promo" appear>
        <aside
            v-if="shown"
            class="dark fixed right-6 bottom-6 z-30 w-[300px] max-sm:hidden"
            :aria-label="$t('What’s new')"
        >
            <div
                class="relative flex flex-col overflow-clip rounded-[12px] border-[0.5px] border-[#262626] bg-[#181818] px-3.5 pt-3 pb-3.5 font-ui shadow-[0_8px_16px_-8px_rgb(0_0_0/0.35),0_1px_2px_rgb(0_0_0/0.5)]"
            >
                <div class="flex w-full gap-1" aria-hidden="true">
                    <span
                        class="relative h-[1.5px] flex-1 overflow-clip rounded-[1px] bg-white/12"
                    >
                        <span
                            class="integrations-promo-played absolute inset-y-0 left-0 rounded-[1px] bg-white/75"
                        />
                    </span>
                </div>

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
                        v-for="tile in tiles"
                        :key="tile.logo"
                        class="integrations-promo-float absolute flex items-center justify-center"
                        :style="{
                            left: `${tile.left}px`,
                            top: `${tile.top}px`,
                            width: `${tile.box}px`,
                            height: `${tile.box}px`,
                            animationDelay: `${tile.delay}s`,
                        }"
                    >
                        <span
                            class="relative flex shrink-0 items-center justify-center overflow-clip border-[0.37px] shadow-[0_4px_8px_-1px_rgb(0_0_0/0.45)]"
                            :class="
                                tile.light
                                    ? 'border-white/60 bg-linear-to-b from-white to-[#e0e0e0]'
                                    : 'border-white/14 bg-linear-to-b from-[#3d3d3d] to-[#212121]'
                            "
                            :style="{
                                width: `${tile.size}px`,
                                height: `${tile.size}px`,
                                borderRadius: `${tile.radius}px`,
                                transform: `rotate(${tile.turn}deg)`,
                            }"
                        >
                            <BrandLogo
                                :name="tile.logo"
                                :size="tile.logoSize"
                            />
                            <span
                                class="pointer-events-none absolute inset-0 rounded-[inherit]"
                                :class="
                                    tile.light
                                        ? 'shadow-[inset_0_0.74px_0_rgb(255_255_255/0.9)]'
                                        : 'shadow-[inset_0_0.74px_0_rgb(255_255_255/0.12)]'
                                "
                            />
                        </span>
                    </span>
                </div>

                <div class="flex flex-col gap-[3px] py-3">
                    <p
                        class="text-[14px]/5 font-medium tracking-[-0.005em] text-fg-primary"
                    >
                        {{ $t('Integrations') }}
                    </p>
                    <p class="text-[12px]/[17px] text-fg-muted">
                        {{
                            $t(
                                'Connect GitHub, Slack, Cloudflare and your cloud accounts once, then use them in deploys, alerts, workflows and agents.',
                            )
                        }}
                    </p>
                </div>

                <DashButton
                    block
                    :href="href"
                    :label="$t('Connect integrations')"
                />

                <button
                    type="button"
                    class="absolute top-[18px] right-2 flex size-5 cursor-pointer items-center justify-center rounded-[5px] text-white/45 transition-colors hover:bg-white/8 hover:text-white/80 focus-visible:outline-2 focus-visible:outline-(--ds-ring)"
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
.integrations-promo-played {
    width: 100%;
    animation: integrations-promo-play 7s linear both;
}

.integrations-promo-float {
    animation: integrations-promo-float 4.8s ease-in-out infinite alternate;
}

.integrations-promo-enter-active,
.integrations-promo-leave-active {
    transition:
        opacity 220ms ease-out,
        transform 220ms ease-out;
}

.integrations-promo-enter-from,
.integrations-promo-leave-to {
    opacity: 0;
    transform: translateY(8px);
}

@keyframes integrations-promo-play {
    from {
        width: 0;
    }
}

@keyframes integrations-promo-float {
    from {
        transform: translateY(1.5px);
    }
    to {
        transform: translateY(-1.5px);
    }
}

@media (prefers-reduced-motion: reduce) {
    .integrations-promo-played,
    .integrations-promo-float {
        animation: none;
    }

    .integrations-promo-enter-active,
    .integrations-promo-leave-active {
        transition: none;
    }
}
</style>
