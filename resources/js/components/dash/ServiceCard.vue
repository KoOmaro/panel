<script setup lang="ts">
import { Check } from '@lucide/vue';
import BrandLogo from '@/components/dash/BrandLogo.vue';

const {
    name,
    description,
    logo,
    logoSrc,
    selected = false,
} = defineProps<{
    name: string;
    description?: string;
    logo?: string;
    logoSrc?: string | null;
    selected?: boolean;
}>();

defineSlots<{ logo?: () => unknown }>();
</script>

<template>
    <button
        type="button"
        role="radio"
        :aria-checked="selected"
        class="group/card relative flex h-[52px] w-full min-w-0 cursor-pointer items-center gap-3 overflow-clip rounded-[7px] pr-3 pl-2.5 text-left font-ui transition-[background,box-shadow] duration-150 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring)"
        :class="
            selected
                ? 'border border-[rgb(70_114_255/0.55)] bg-[linear-gradient(13.5deg,rgb(255_255_255/0)_48%,rgb(255_255_255/0.1)_58%,rgb(255_255_255/0.025)_64%,rgb(255_255_255/0.08)_70%,rgb(255_255_255/0)_80%),linear-gradient(90deg,#121a40,#1b2759)] shadow-[inset_0_1px_0_rgb(255_255_255/0.1),0_4px_10px_-3px_rgb(44_77_255/0.4),0_1px_1.5px_rgb(0_0_0/0.55)]'
                : 'bg-white shadow-[0_1px_2px_rgb(0_0_0/0.08),0_0_0_0.5px_rgb(0_0_0/0.12)] hover:bg-[#fafafa] dark:bg-[#212121] dark:shadow-[inset_0_1px_0_rgb(255_255_255/0.05),0_3px_6px_-2px_rgb(0_0_0/0.35),0_1px_1.5px_rgb(0_0_0/0.55)] dark:hover:bg-[#262626] dark:hover:shadow-[inset_0_1px_0_rgb(255_255_255/0.06),0_4px_10px_-3px_rgb(0_0_0/0.45),0_1px_1.5px_rgb(0_0_0/0.55)]'
        "
    >
        <span
            class="flex size-8 shrink-0 items-center justify-center overflow-clip rounded-[6px]"
            :class="
                selected
                    ? 'bg-[rgb(51_97_255/0.28)] shadow-[inset_0_1px_0_rgb(255_255_255/0.14),0_1px_1.5px_rgb(0_0_0/0.5)]'
                    : 'bg-[#f2f2f2] dark:bg-[#2d2d2d] dark:shadow-[inset_0_1px_0_rgb(255_255_255/0.06),0_1px_1.5px_rgb(0_0_0/0.5)] dark:group-hover/card:bg-[#333333]'
            "
        >
            <slot name="logo">
                <BrandLogo :name="logo ?? name" :src="logoSrc" :size="18" />
            </slot>
        </span>
        <span class="flex min-w-0 flex-1 flex-col gap-px">
            <span
                class="truncate text-[13px]/[18px] font-medium tracking-[-0.01em]"
                :class="selected ? 'text-[#ededed]' : 'text-fg-primary'"
                >{{ name }}</span
            >
            <span
                v-if="description"
                class="truncate text-[12px]/4 tracking-[-0.005em]"
                :class="selected ? 'text-[#9a9a9a]' : 'text-fg-tertiary'"
                >{{ description }}</span
            >
        </span>
        <span
            v-if="selected"
            class="flex size-4 shrink-0 items-center justify-center rounded-full bg-[#3d6bff] text-white shadow-[inset_0_1px_0_rgb(255_255_255/0.25),0_1px_1.5px_rgb(0_0_0/0.5)]"
            aria-hidden="true"
        >
            <Check class="size-2.5" :stroke-width="3.5" />
        </span>
        <span
            v-else
            class="size-4 shrink-0 rounded-full border-[1.5px] border-[#c4c4c4] group-hover/card:border-[#a3a3a3] dark:border-[#474747] dark:group-hover/card:border-[#5c5c5c]"
            aria-hidden="true"
        />
    </button>
</template>
