<script setup lang="ts">
import { computed } from 'vue';
import BrandLogo from '@/components/dash/BrandLogo.vue';
import { brandLogos, brandMark } from '@/lib/brandLogos';

type Logo = { name: string; icon: string | null };

const { logos, max = 4 } = defineProps<{ logos: Logo[]; max?: number }>();

const designMarks = new Set<unknown>(Object.values(brandLogos));

const shown = computed(() => {
    const seen = new Set<string>();
    const ranked = logos
        .map((logo, index) => {
            const mark = logo.icon ? null : brandMark(logo.name);

            return {
                logo,
                key: logo.icon ?? mark?.title ?? null,
                rank: logo.icon || designMarks.has(mark) ? 0 : 1,
                index,
            };
        })
        .filter((entry) => entry.key !== null)
        .sort((a, b) => a.rank - b.rank || a.index - b.index);

    return ranked
        .filter((entry) => {
            if (seen.has(entry.key as string)) return false;
            seen.add(entry.key as string);

            return true;
        })
        .slice(0, max)
        .map((entry) => entry.logo);
});
</script>

<template>
    <span v-if="shown.length" class="isolate flex shrink-0 items-center">
        <span
            v-for="(logo, index) in shown"
            :key="`${logo.name}-${index}`"
            class="flex size-6 shrink-0 items-center justify-center overflow-clip rounded-full border-2 border-surface-card bg-[#ededed] not-last:-mr-1 dark:bg-[#292929]"
            :style="{ zIndex: shown.length - index }"
            :title="logo.name"
        >
            <BrandLogo :name="logo.name" :src="logo.icon" :size="14" />
        </span>
    </span>
</template>
