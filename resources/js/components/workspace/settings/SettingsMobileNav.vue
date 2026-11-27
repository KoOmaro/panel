<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import SettingsNavIcon from '@/components/workspace/settings/SettingsNavIcon.vue';
import type { Group } from '@/components/workspace/settingsNav';

const { groups } = defineProps<{ groups: Group[] }>();

const links = computed(() =>
    groups.filter((group) => group.inSidebar).flatMap((group) => group.items),
);
</script>

<template>
    <nav
        :aria-label="$t('Settings')"
        class="-mx-5 overflow-x-auto border-b-[0.5px] border-edge px-5 sm:-mx-10 sm:px-10"
    >
        <div class="flex h-11 items-center gap-0.5">
            <Link
                v-for="link in links"
                :key="link.href"
                :href="link.href"
                class="flex h-[30px] shrink-0 items-center gap-2 rounded-[8px] px-2.5 text-[13px]/5 tracking-[-0.01em] whitespace-nowrap focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-(--ds-ring)"
                :class="
                    link.current
                        ? 'bg-surface-raised font-medium text-fg-primary'
                        : 'text-fg-tertiary hover:bg-surface-hover hover:text-fg-secondary'
                "
                :aria-current="link.current ? 'page' : undefined"
            >
                <SettingsNavIcon
                    :icon="link.icon"
                    :size="16"
                    class="text-fg-tertiary"
                />
                {{ link.label }}
            </Link>
        </div>
    </nav>
</template>
