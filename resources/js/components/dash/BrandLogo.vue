<script setup lang="ts">
import { computed } from 'vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import { brandMark } from '@/lib/brandLogos';

const {
    name,
    src,
    size = 16,
    fallback = true,
} = defineProps<{
    name?: string | null;
    src?: string | null;
    size?: number;
    fallback?: boolean;
}>();

const logo = computed(() => (src ? null : brandMark(name)));
</script>

<template>
    <img
        v-if="src"
        :src="src"
        alt=""
        class="shrink-0 rounded-[3px] object-contain"
        :style="{ width: `${size}px`, height: `${size}px` }"
    />
    <span
        v-else-if="logo?.kind === 'svg'"
        class="inline-flex shrink-0 [&>svg]:size-full"
        :style="{ width: `${size}px`, height: `${size}px` }"
        :title="logo.title"
        aria-hidden="true"
        v-html="logo.svg"
    />
    <svg
        v-else-if="logo"
        viewBox="0 0 24 24"
        fill="currentColor"
        class="shrink-0 text-(--logo-light) dark:text-(--logo-dark)"
        :style="{
            width: `${size}px`,
            height: `${size}px`,
            '--logo-dark': logo.dark,
            '--logo-light': logo.light,
        }"
        aria-hidden="true"
    >
        <title>{{ logo.title }}</title>
        <path :d="logo.path" />
    </svg>
    <FilledIcon
        v-else-if="fallback"
        name="package-fill"
        :size="size"
        class="text-fg-muted"
    />
</template>
