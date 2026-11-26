<script setup lang="ts">
const { as = 'div' } = defineProps<{ as?: string }>();

defineSlots<{ default?: () => unknown; footer?: () => unknown }>();
</script>

<template>
    <component :is="as" class="dash-card flex flex-col">
        <slot />
        <div v-if="$slots.footer" class="dash-card-footer">
            <slot name="footer" />
        </div>
    </component>
</template>
