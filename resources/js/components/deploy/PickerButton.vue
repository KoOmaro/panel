<script setup lang="ts">
import BrandLogo from '@/components/dash/BrandLogo.vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';

const {
    label,
    logo = null,
    strong = false,
    menu = true,
} = defineProps<{
    label: string;
    logo?: string | null;
    strong?: boolean;
    menu?: boolean;
}>();
</script>

<template>
    <button
        type="button"
        class="flex h-7 max-w-full min-w-0 shrink-0 items-center gap-1.5 rounded-[6px] border-[0.5px] border-[#dcdcdc] bg-white pr-1.5 pl-2 font-ui text-[13px]/5 font-medium tracking-[-0.01em] shadow-[0_1px_1.5px_rgb(0_0_0/0.08)] transition-colors duration-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring) enabled:cursor-pointer enabled:hover:bg-[#f7f7f7] disabled:pr-2 dark:border-[#333] dark:bg-[#1f1f1f] dark:shadow-[inset_0_1px_0_rgb(255_255_255/0.05),0_1px_1.5px_rgb(0_0_0/0.5)] dark:enabled:hover:bg-[#242424]"
        :class="strong ? 'text-fg-primary' : 'text-fg-secondary'"
    >
        <BrandLogo v-if="logo" :name="logo" :size="14" />
        <span class="min-w-0 truncate">{{ label }}</span>
        <FilledIcon
            v-if="menu"
            name="chevrons-up-down"
            :size="strong ? 14 : 16"
            class="text-fg-tertiary"
        />
    </button>
</template>
