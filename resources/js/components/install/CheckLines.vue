<script setup lang="ts">
import type { Check } from '@/lib/install';
import CheckRow from './CheckRow.vue';
import * as ui from './styles';

defineProps<{ lines: Check[] }>();
</script>

<template>
    <ul :class="ui.rows">
        <CheckRow v-for="line in lines" :key="line.text" :status="line.status">
            <p class="text-fg-secondary">{{ line.text }}</p>
        </CheckRow>
    </ul>
</template>
