<script setup lang="ts">
import { computed } from 'vue';

export type EmptyArtKind =
    | 'art'
    | 'drop'
    | 'drop-blue'
    | 'project-graph'
    | 'project-graph-blue'
    | 'upload';

const { kind = 'art' } = defineProps<{ kind?: EmptyArtKind }>();

const art = {
    art: { src: '/images/dash/empty-art.svg', width: 468, height: 228 },
    drop: { src: '/images/dash/empty-drop.svg', width: 317, height: 173 },
    'drop-blue': {
        src: '/images/dash/empty-drop-blue.svg',
        width: 317,
        height: 173,
    },
    'project-graph': {
        src: '/images/dash/empty-project-graph.svg',
        width: 424,
        height: 170,
    },
    'project-graph-blue': {
        src: '/images/dash/empty-project-graph-blue.svg',
        width: 424,
        height: 170,
    },
    upload: { src: '/images/dash/empty-upload.png', width: 600, height: 260 },
} as const;

const current = computed(() => art[kind]);
</script>

<template>
    <img
        :src="current.src"
        alt=""
        :width="current.width"
        :height="current.height"
        class="pointer-events-none max-w-none shrink-0 select-none"
        draggable="false"
    />
</template>
