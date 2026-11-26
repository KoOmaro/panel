<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';
import BrandLogo from '@/components/dash/BrandLogo.vue';
import DashSwitch from '@/components/dash/DashSwitch.vue';
import type { Member } from './types';

const {
    cidr,
    members,
    previews,
    production,
    canManage = false,
    busy = false,
} = defineProps<{
    cidr: string;
    members: Member[];
    previews: {
        enabled: boolean;
        repo: string;
        copyData: boolean;
        limit: number;
        open: number;
    };
    production: string;
    canManage?: boolean;
    busy?: boolean;
}>();

const emit = defineEmits<{ toggle: [on: boolean]; configure: [] }>();

const on = computed({
    get: () => previews.enabled,
    set: (value: boolean) => emit('toggle', value),
});

const memberDot: Record<Member['state'], string> = {
    up: 'bg-status-live shadow-[0_0_4px_color-mix(in_srgb,var(--dash-live)_50%,transparent)]',
    pending: 'bg-status-deploying animate-pulse',
    failed: 'bg-status-down',
    offline: 'bg-status-stopped',
};
const memberLabel: Record<Member['state'], string> = {
    up: 'On the network',
    pending: 'Joining',
    failed: 'Couldn’t join',
    offline: 'Offline',
};

const blurb = computed(() => {
    if (!previews.repo) {
        return trans(
            'Each pull request can get its own copy of :env, running its code, on a machine you pick.',
            { env: production },
        );
    }

    return previews.copyData
        ? trans(
              'Each pull request on :repo gets its own preview, with a copy of :env’s data.',
              { repo: previews.repo, env: production },
          )
        : trans(
              'Each pull request on :repo gets its own preview of :env, with data of its own.',
              { repo: previews.repo, env: production },
          );
});

const card =
    'flex w-full flex-col overflow-hidden rounded-[10px] border-[0.5px] border-edge-card bg-surface-main shadow-card';
const head =
    'flex h-10 shrink-0 items-center gap-2 border-b-[0.5px] border-edge-cell pr-3 pl-3.5';
</script>

<template>
    <aside class="flex w-full flex-col gap-3">
        <section :class="card">
            <header :class="head">
                <h2
                    class="min-w-0 grow truncate text-[13px]/5 font-medium tracking-[-0.01em] text-fg-primary"
                >
                    {{ $t('Private network') }}
                </h2>
                <span
                    class="font-ui-mono text-[11.5px]/4 whitespace-nowrap text-fg-tertiary"
                    >{{ cidr }}</span
                >
            </header>
            <p
                v-if="!members.length"
                class="px-3.5 py-3 text-[12.5px]/[18px] text-fg-tertiary"
            >
                {{
                    $t(
                        'Machines join it when something on them is added to the project.',
                    )
                }}
            </p>
            <div
                v-for="member in members"
                :key="member.machine"
                class="flex h-[38px] items-center gap-2 px-3.5"
                :title="member.error ?? $t(memberLabel[member.state])"
            >
                <BrandLogo :name="member.provider" />
                <span
                    class="min-w-0 grow truncate text-[13px]/5 font-medium tracking-[-0.01em] text-fg-secondary"
                    >{{ member.name }}</span
                >
                <span
                    class="font-ui-mono text-[11.5px]/4 whitespace-nowrap text-fg-tertiary"
                    >{{ member.address }}</span
                >
                <span
                    class="size-1.5 shrink-0 rounded-full"
                    :class="memberDot[member.state]"
                />
            </div>
        </section>

        <section :class="card">
            <header :class="head">
                <h2
                    class="min-w-0 grow truncate text-[13px]/5 font-medium tracking-[-0.01em] text-fg-primary"
                >
                    {{ $t('Pull request previews') }}
                </h2>
                <DashSwitch
                    v-model="on"
                    size="sm"
                    :label="$t('Pull request previews')"
                    :disabled="!canManage || busy"
                />
            </header>
            <div class="flex flex-col gap-2.5 px-3.5 pt-3 pb-3.5">
                <p
                    class="text-[12.5px]/[18px] tracking-[-0.005em] text-pretty text-fg-tertiary"
                >
                    {{ blurb }}
                </p>
                <button
                    type="button"
                    class="flex h-8 w-full cursor-pointer items-center gap-2 rounded-[8px] border-[0.5px] border-[#dedede] bg-white px-2.5 text-left transition-colors hover:border-edge-tile disabled:cursor-default disabled:hover:border-[#dedede] dark:border-[#2c2c2c] dark:bg-[#1e1e1e] dark:disabled:hover:border-[#2c2c2c]"
                    :disabled="!canManage"
                    @click="emit('configure')"
                >
                    <BrandLogo name="github" />
                    <span
                        class="min-w-0 grow truncate text-[13px]/5 font-medium tracking-[-0.01em]"
                        :class="
                            previews.repo
                                ? 'text-fg-secondary'
                                : 'text-fg-muted'
                        "
                        >{{ previews.repo || $t('Choose a repository') }}</span
                    >
                    <span
                        v-if="previews.repo"
                        class="text-[12px]/4 tracking-[-0.005em] whitespace-nowrap text-fg-tertiary"
                        >{{
                            $t(':open of :limit open', {
                                open: String(previews.open),
                                limit: String(previews.limit),
                            })
                        }}</span
                    >
                </button>
            </div>
        </section>
    </aside>
</template>
