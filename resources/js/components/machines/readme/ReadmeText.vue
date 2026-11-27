<script setup lang="ts">
import { computed } from 'vue';
import { segments } from '@/components/machines/readme/readme';

const { text } = defineProps<{ text: string }>();

const parts = computed(() => segments(text));
</script>

<template>
    <template v-for="(part, index) in parts" :key="index">
        <span v-if="part.mark === 'bold'" class="font-medium text-fg-primary">{{
            part.text
        }}</span>
        <span
            v-else-if="part.mark === 'risk'"
            class="font-medium text-status-down"
            >{{ part.text }}</span
        >
        <template v-else>{{ part.text }}</template>
    </template>
</template>
