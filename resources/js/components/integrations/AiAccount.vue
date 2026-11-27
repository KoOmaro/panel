<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import DashTray from '@/components/dash/DashTray.vue';
import type { AiAccount } from '@/components/integrations/ai';
import AiModelChoices from '@/components/integrations/AiModelChoices.vue';
import AiTest from '@/components/integrations/AiTest.vue';
import AiUsage from '@/components/integrations/AiUsage.vue';

const {
    account,
    workspaceSlug,
    disabled = false,
} = defineProps<{
    account: AiAccount;
    workspaceSlug: string;
    disabled?: boolean;
}>();

function tested(): void {
    router.reload({ only: ['account'] });
}
</script>

<template>
    <DashTray
        icon="sliders"
        :title="$t('Models')"
        :meta="$t('Which model each feature uses')"
        panel-class="py-0.5"
    >
        <AiModelChoices
            :features="account.features"
            :options="account.options"
            :workspace-slug="workspaceSlug"
            :can-manage="account.canManage"
        />
    </DashTray>

    <AiTest
        :provider="account.provider"
        :models="account.models"
        :default-model="account.defaultModel"
        :workspace-slug="workspaceSlug"
        :disabled="disabled || !account.canManage"
        @tested="tested"
    />

    <AiUsage :usage="account.usage" />
</template>
