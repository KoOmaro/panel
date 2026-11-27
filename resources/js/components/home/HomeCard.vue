<script setup lang="ts">
import FilledIcon from '@/components/icons/FilledIcon.vue';
import type { FilledIconName } from '@/components/icons/filledIcons';

const { icon, title, headingId } = defineProps<{
    icon: FilledIconName;
    title: string;
    headingId: string;
}>();
</script>

<template>
    <section
        class="flex min-w-0 flex-col rounded-[14px] border-[0.5px] border-[#e3e3e3] bg-[#f6f6f6] px-[5px] pb-[5px] shadow-card dark:border-[#2b2b2b] dark:bg-[#1f1f1f]"
        :aria-labelledby="headingId"
    >
        <header
            class="flex min-w-0 items-center gap-[9px] pt-3 pr-[11px] pb-[11px] pl-[13px]"
        >
            <FilledIcon :name="icon" :size="15" class="text-fg-muted" />
            <h2
                :id="headingId"
                class="shrink-0 text-[13px]/5 font-medium tracking-[-0.005em] text-fg-primary"
            >
                {{ title }}
            </h2>
            <span
                v-if="$slots.meta"
                class="min-w-0 truncate text-[13px]/5 tracking-[-0.005em] text-fg-muted"
            >
                <slot name="meta" />
            </span>
            <span class="grow" />
            <slot name="actions" />
        </header>
        <div
            class="flex min-h-0 grow flex-col overflow-hidden rounded-[10px] border-[0.5px] border-[#e3e3e3] bg-white shadow-[0_-0.5px_0_rgb(0_0_0/0.06)] dark:border-[#2a2a2a] dark:bg-[#181818] dark:shadow-[0_-0.5px_0_rgb(0_0_0/0.35)]"
        >
            <slot />
        </div>
    </section>
</template>
