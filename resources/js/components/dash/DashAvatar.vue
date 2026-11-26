<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { getInitials } from '@/composables/useInitials';
import { sampleAvatars } from '@/lib/sampleAvatars';
import type { SampleAvatar } from '@/lib/sampleAvatars';

const {
    src,
    name,
    sample,
    size = 20,
} = defineProps<{
    src?: string | null;
    name?: string | null;
    sample?: SampleAvatar;
    size?: number;
}>();

const failed = ref(false);

const image = computed(() =>
    failed.value ? null : (src ?? (sample ? sampleAvatars[sample] : null)),
);

watch(
    () => src,
    () => (failed.value = false),
);
</script>

<template>
    <img
        v-if="image"
        :src="image"
        :alt="name ?? ''"
        class="shrink-0 rounded-full object-cover"
        :style="{ width: `${size}px`, height: `${size}px` }"
        @error="failed = true"
    />
    <span
        v-else
        class="flex shrink-0 items-center justify-center overflow-clip rounded-full border-[0.5px] border-edge-strong bg-surface-avatar font-medium text-fg-primary"
        :style="{
            width: `${size}px`,
            height: `${size}px`,
            fontSize: `${size >= 28 ? 11 : Math.max(8, Math.round(size * 0.42))}px`,
        }"
        :aria-label="name ?? undefined"
        :role="name ? 'img' : undefined"
    >
        {{ getInitials(name ?? '') }}
    </span>
</template>
