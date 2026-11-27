<script setup lang="ts">
import { computed } from 'vue';
import BrandLogo from '@/components/dash/BrandLogo.vue';

type Logo = { key: string; name: string; src?: string | null };

const { logos, max = 2 } = defineProps<{ logos: Logo[]; max?: number }>();

const shown = computed(() => logos.slice(0, max));
const more = computed(() => Math.max(0, logos.length - max));
</script>

<template>
    <span v-if="logos.length" class="flex items-center gap-1.5">
        <span class="flex shrink-0 items-center">
            <span
                v-for="(logo, index) in shown"
                :key="logo.key"
                class="flex size-5 shrink-0 items-center justify-center overflow-clip rounded-full border-[1.5px] border-surface-main bg-[#f0f0f0] not-last:-mr-[5px] dark:bg-[#202020]"
                :style="{ zIndex: shown.length - index }"
                :title="logo.name"
            >
                <BrandLogo :name="logo.name" :src="logo.src" :size="12" />
            </span>
        </span>
        <span v-if="more" class="text-[12px]/4 text-fg-muted tabular-nums"
            >+{{ more }}</span
        >
    </span>
    <span v-else class="text-fg-faint">–</span>
</template>
