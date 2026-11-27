<script setup lang="ts">
import BrandLogo from '@/components/dash/BrandLogo.vue';
import { LOGO_IMAGES } from '@/components/integrations/integrations';

const {
    logo = null,
    initials = null,
    small = false,
} = defineProps<{
    logo?: string | null;
    initials?: string | null;
    small?: boolean;
}>();
</script>

<template>
    <span
        class="flex shrink-0 items-center justify-center overflow-hidden border-[0.5px] border-[#dcdcdc] bg-[#f4f4f4] dark:border-[#424242] dark:bg-[#262626]"
        :class="small ? 'size-[26px] rounded-[7px]' : 'size-9 rounded-[9px]'"
        aria-hidden="true"
    >
        <span
            v-if="initials"
            class="text-[12px]/[14px] font-medium text-fg-secondary"
            >{{ initials }}</span
        >
        <BrandLogo
            v-else
            :name="logo"
            :src="logo ? LOGO_IMAGES[logo] : null"
            :size="small ? 14 : 20"
        />
    </span>
</template>
