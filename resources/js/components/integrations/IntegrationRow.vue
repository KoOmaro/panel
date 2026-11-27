<script setup lang="ts">
import DashButton from '@/components/dash/DashButton.vue';
import StatusBadge from '@/components/dash/StatusBadge.vue';
import IntegrationLogo from '@/components/integrations/IntegrationLogo.vue';
import type { Integration } from '@/components/integrations/integrations';
import { workspacePath } from '@/lib/workspaceRoute';

const { integration } = defineProps<{ integration: Integration }>();

defineEmits<{ act: [integration: Integration] }>();
</script>

<template>
    <li
        class="flex min-w-0 items-center gap-3.5 border-t-[0.5px] border-edge-tray-panel px-3.5 py-3 first:border-t-0"
    >
        <IntegrationLogo
            :logo="integration.logo"
            :initials="integration.initials"
        />
        <div class="flex min-w-0 flex-1 flex-col gap-[3px]">
            <div class="flex min-w-0 items-center gap-2">
                <span
                    class="truncate text-[13px]/[18px] font-medium text-fg-primary"
                    >{{ integration.name }}</span
                >
                <StatusBadge
                    v-if="integration.connected"
                    status="live"
                    :label="$t('Connected')"
                />
            </div>
            <p class="text-[12px]/[17px] text-fg-muted">
                {{ integration.description }}
            </p>
        </div>
        <ul
            class="flex w-[196px] shrink-0 flex-wrap items-start gap-1 max-md:hidden"
            :aria-label="$t('Works with')"
        >
            <li
                v-for="tag in integration.tags"
                :key="tag"
                class="rounded-[5px] border-[0.5px] border-[#dcdcdc] bg-[#f2f2f2] px-[7px] py-0.5 text-[11px]/4 whitespace-nowrap text-fg-secondary dark:border-[#383838] dark:bg-surface-badge"
            >
                {{ tag }}
            </li>
        </ul>
        <div class="flex w-[92px] shrink-0 justify-end max-sm:w-auto">
            <template v-if="integration.action">
                <DashButton
                    v-if="
                        integration.action.href &&
                        !integration.action.unavailable
                    "
                    :href="workspacePath(integration.action.href)"
                    :label="integration.action.label"
                    :aria-label="`${integration.action.label} ${integration.name}`"
                />
                <DashButton
                    v-else
                    :label="integration.action.label"
                    :aria-label="`${integration.action.label} ${integration.name}`"
                    aria-haspopup="dialog"
                    @click="$emit('act', integration)"
                />
            </template>
        </div>
    </li>
</template>
