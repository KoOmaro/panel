<script setup lang="ts">
defineSlots<{ default?: () => unknown }>();
</script>

<template>
    <div class="dash-card-row">
        <slot />
    </div>
</template>
