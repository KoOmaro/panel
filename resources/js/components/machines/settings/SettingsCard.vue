<script setup lang="ts">
import FilledIcon from '@/components/icons/FilledIcon.vue';
import type { FilledIconName } from '@/components/icons/filledIcons';

defineProps<{ title: string; icon?: FilledIconName }>();
defineSlots<{ default?: () => unknown; actions?: () => unknown }>();
</script>

<template>
    <section
        class="flex w-full min-w-0 flex-col overflow-hidden rounded-[14px] border-[0.5px] border-[#e3e3e3] bg-[#f6f6f6] px-[5px] pb-[5px] shadow-card dark:border-[#2b2b2b] dark:bg-[#1f1f1f]"
        :aria-label="title"
    >
        <header
            class="flex min-h-[43px] min-w-0 items-center gap-[9px] pt-3 pr-[11px] pb-[11px] pl-[13px]"
        >
            <FilledIcon
                v-if="icon"
                :name="icon"
                :size="15"
                class="text-fg-muted"
            />
            <h2
                class="min-w-0 grow truncate text-[13px]/5 font-medium tracking-[-0.005em] text-fg-primary"
            >
                {{ title }}
            </h2>
            <slot name="actions" />
        </header>
        <div
            class="flex flex-col gap-1 overflow-hidden rounded-[10px] border-[0.5px] border-[#e3e3e3] bg-white px-[18px] pt-3 pb-1 shadow-[0_-0.5px_0_rgb(0_0_0/0.06)] dark:border-[#2a2a2a] dark:bg-[#181818] dark:shadow-[0_-0.5px_0_rgb(0_0_0/0.35)]"
        >
            <slot />
        </div>
    </section>
</template>
