<script setup lang="ts">
import close from '@/components/dash/assets/close-small.svg?raw';
import DashButton from '@/components/dash/DashButton.vue';

const {
    title,
    description,
    actionLabel,
    href,
    external = false,
    dismissible = true,
} = defineProps<{
    title: string;
    description?: string;
    actionLabel: string;
    href?: string;
    external?: boolean;
    dismissible?: boolean;
}>();

defineEmits<{ dismiss: []; action: [] }>();
</script>

<template>
    <div
        class="relative flex w-full flex-col overflow-clip rounded-[9px] border-[0.5px] border-edge-row bg-surface-main shadow-card"
    >
        <img
            src="/images/dash/upgrade-art.svg"
            alt=""
            width="200"
            height="64"
            class="h-16 w-[200px] max-w-none shrink-0"
            draggable="false"
        />
        <div class="flex flex-col gap-0.5 px-3 pt-0.5 pb-3">
            <p
                class="text-[13px]/[18px] font-medium tracking-[-0.01em] text-fg-primary"
            >
                {{ title }}
            </p>
            <p
                v-if="description"
                class="text-[12px]/4 tracking-[-0.005em] text-fg-muted"
            >
                {{ description }}
            </p>
            <div class="pt-2.5">
                <DashButton
                    size="sm"
                    block
                    :href="href"
                    :external="external"
                    :label="actionLabel"
                    @click="$emit('action')"
                />
            </div>
        </div>
        <button
            v-if="dismissible"
            type="button"
            class="absolute top-[5.5px] right-[5.5px] flex size-5 cursor-pointer items-center justify-center rounded-[5px] text-fg-faint transition-colors hover:bg-surface-raised hover:text-fg-tertiary focus-visible:outline-2 focus-visible:outline-(--ds-ring)"
            :aria-label="$t('Dismiss')"
            @click="$emit('dismiss')"
        >
            <span class="flex size-3" aria-hidden="true" v-html="close" />
        </button>
    </div>
</template>
