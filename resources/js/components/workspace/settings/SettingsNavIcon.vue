<script setup lang="ts">
import FilledIcon from '@/components/icons/FilledIcon.vue';
import type { NavIcon } from '@/components/workspace/settingsNav';

const { icon, size = 18 } = defineProps<{
    icon: NavIcon;
    size?: number;
}>();
</script>

<template>
    <FilledIcon v-if="typeof icon === 'string'" :name="icon" :size="size" />
    <component
        :is="icon"
        v-else
        class="shrink-0"
        :style="{ width: `${size}px`, height: `${size}px` }"
        :stroke-width="1.6"
    />
</template>
