<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, onMounted, ref } from 'vue';
import UpgradeCard from '@/components/dash/UpgradeCard.vue';
import type { Workspace } from '@/types';

const page = usePage<{ workspace: Workspace }>();

const DISMISS_DAYS = 7;
const dismissedAt = ref<number | null>(null);

function storageKey(): string {
    return `sidebar-upgrade-dismissed:${page.props.workspace.id}`;
}

onMounted(() => {
    try {
        const saved = window.localStorage.getItem(storageKey());
        dismissedAt.value = saved ? Number(saved) : null;
    } catch {}
});

function dismiss(): void {
    dismissedAt.value = Date.now();

    try {
        window.localStorage.setItem(storageKey(), String(dismissedAt.value));
    } catch {}
}

const card = computed(() => {
    const billing = page.props.workspace.billing;
    const upgrade = page.props.upgrade;
    const canManage =
        page.props.workspaceAccess?.permissions.includes('manage-billing') ??
        false;

    if (billing.selfHosted || !upgrade || !canManage) {
        return null;
    }

    if (!billing.onTrial && billing.plan !== null) {
        return null;
    }

    if (
        dismissedAt.value !== null &&
        Date.now() - dismissedAt.value < DISMISS_DAYS * 86_400_000
    ) {
        return null;
    }

    const plan = billing.planLabel;
    const days = billing.trialDaysLeft ?? 0;
    const covers =
        plan && billing.machineLimit
            ? trans(
                  billing.machineLimit === 1
                      ? ':plan covers 1 machine.'
                      : ':plan covers up to :count machines.',
                  { plan, count: String(billing.machineLimit) },
              )
            : '';

    if (billing.onTrial) {
        const ends =
            days <= 0
                ? trans('Your trial ends today.')
                : days === 1
                  ? trans('Your trial ends tomorrow.')
                  : trans('Your trial ends in :count days.', {
                        count: String(days),
                    });

        return {
            title: plan
                ? trans('Upgrade to :plan', { plan })
                : trans('Pick a plan'),
            description: [ends, covers].filter(Boolean).join(' '),
            href: upgrade.href,
        };
    }

    return {
        title: trans('Pick a plan'),
        description: trans('Keep your monitors and alerts running.'),
        href: upgrade.href,
    };
});
</script>

<template>
    <div v-if="card" class="shrink-0 px-3 pb-3">
        <UpgradeCard
            :title="card.title"
            :description="card.description"
            :action-label="$t('See plans')"
            :href="card.href"
            @dismiss="dismiss"
        />
    </div>
</template>
