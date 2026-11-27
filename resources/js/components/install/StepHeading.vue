<script setup lang="ts">
defineProps<{ title: string; description?: string }>();
</script>

<template>
    <div class="flex flex-col gap-1.5">
        <h1 class="text-[18px]/6 font-medium text-fg-primary">
            {{ title }}
        </h1>
        <p
            v-if="description"
            class="text-[13px]/5 text-pretty text-fg-secondary"
        >
            {{ description }}
        </p>
    </div>
</template>
