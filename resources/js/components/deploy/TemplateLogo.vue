<script setup lang="ts">
import { computed } from 'vue';
import BrandLogo from '@/components/dash/BrandLogo.vue';
import TemplateIcon from '@/components/workspace/TemplateIcon.vue';
import { kitLogo } from './catalog';

const { icon, size = 18 } = defineProps<{
    icon: string | null | undefined;
    size?: number;
}>();

const kit = computed(() => kitLogo(icon));
</script>

<template>
    <BrandLogo v-if="kit" :name="kit" :size="size" />
    <span
        v-else
        class="inline-flex shrink-0 text-fg-secondary [&>svg]:size-full"
        :style="{ width: `${size}px`, height: `${size}px` }"
    >
        <TemplateIcon :icon="icon ?? 'box'" colored />
    </span>
</template>
