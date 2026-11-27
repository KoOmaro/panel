<script setup lang="ts">
defineSlots<{ head?: () => unknown; default?: () => unknown }>();
</script>

<template>
    <div class="min-w-0 overflow-x-auto">
        <table
            class="w-full min-w-max border-separate border-spacing-0 text-left font-ui"
        >
            <thead v-if="$slots.head">
                <tr>
                    <slot name="head" />
                </tr>
            </thead>
            <tbody>
                <slot />
            </tbody>
        </table>
    </div>
</template>
