<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';
import DashButton from '@/components/dash/DashButton.vue';
import DashTray from '@/components/dash/DashTray.vue';
import StatusBadge from '@/components/dash/StatusBadge.vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import { shortAgo } from '@/components/deploy/catalog';
import type { DockerHubAccount } from '@/components/integrations/dockerHub';
import { timeAgo } from '@/lib/timeAgo';
import { onWorkspace, workspacePath } from '@/lib/workspaceRoute';
import integrationRoutes from '@/routes/workspace/integrations';

const {
    account,
    workspaceSlug,
    integrationId,
    disabled = false,
} = defineProps<{
    account: DockerHubAccount | undefined;
    workspaceSlug: string;
    integrationId: number;
    disabled?: boolean;
}>();

const taking = ref(false);

const meta = computed(() => {
    if (!account) return null;

    return account.total === 1
        ? trans('1 repository')
        : trans(':count repositories', { count: String(account.total) });
});

const appsMeta = computed(() => {
    const count = account?.apps.length ?? 0;
    if (count === 0) return null;

    return count === 1
        ? trans('1 app')
        : trans(':count apps', { count: String(count) });
});

function useForPulls(): void {
    taking.value = true;
    router.post(
        onWorkspace(
            integrationRoutes.dockerHub.registry({
                workspace: workspaceSlug,
                integration: integrationId,
            }),
        ).url,
        {},
        { preserveScroll: true, onFinish: () => (taking.value = false) },
    );
}

function pushNote(app: DockerHubAccount['apps'][number]): string {
    if (!app.redeploy) return trans('Redeploys by hand');
    if (app.lastPushAt)
        return trans('Last push :when', { when: timeAgo(app.lastPushAt) });

    return app.firstDeliveryAt
        ? trans('Docker Hub reached it')
        : trans('Waiting for the first delivery');
}
</script>

<template>
    <DashTray
        icon="package-fill"
        :title="$t('Repositories')"
        :meta="meta"
        panel-class="py-0.5"
    >
        <div
            v-if="account === undefined"
            class="flex flex-col"
            role="status"
            :aria-label="$t('Loading repositories')"
        >
            <div
                v-for="n in 3"
                :key="n"
                class="flex items-center gap-3 border-t-[0.5px] border-edge-tray-panel px-3.5 py-3 first:border-t-0"
            >
                <span
                    class="h-3 w-36 animate-pulse rounded bg-surface-badge motion-reduce:animate-none"
                />
                <span
                    class="h-3 w-20 animate-pulse rounded bg-surface-badge motion-reduce:animate-none"
                />
            </div>
        </div>
        <template v-else>
            <div
                v-if="account.login.state !== 'linked'"
                class="flex flex-wrap items-center gap-3 border-b-[0.5px] border-edge-tray-panel px-3.5 py-3"
            >
                <p class="min-w-0 flex-1 text-[13px]/5 text-fg-secondary">
                    {{
                        account.login.state === 'other'
                            ? $t(
                                  'Deploys pull from Docker Hub as :name, the login saved in Settings. Use this account instead to pull its private images.',
                                  { name: account.login.username ?? '' },
                              )
                            : $t(
                                  'Deploys don’t sign in to Docker Hub, so private images won’t pull.',
                              )
                    }}
                </p>
                <DashButton
                    size="sm"
                    :label="$t('Use this account for pulls')"
                    :loading="taking"
                    :disabled="disabled || taking"
                    @click="useForPulls"
                />
            </div>
            <p
                v-if="account.error"
                class="border-b-[0.5px] border-edge-tray-panel px-3.5 py-2.5 text-[12.5px]/4 text-status-down"
                role="alert"
            >
                {{ account.error }}
            </p>
            <ul v-if="account.repositories.length" class="flex flex-col">
                <li
                    v-for="repo in account.repositories"
                    :key="repo.repository"
                    class="flex min-w-0 items-center gap-3 border-t-[0.5px] border-edge-tray-panel px-3.5 py-2.5 first:border-t-0"
                >
                    <a
                        :href="repo.url"
                        target="_blank"
                        rel="noopener"
                        class="flex min-w-0 items-center gap-1 text-[13px]/5 hover:underline"
                    >
                        <span class="truncate tracking-[-0.005em] text-fg-muted"
                            >{{ repo.namespace }}/</span
                        >
                        <span
                            class="truncate font-medium tracking-[-0.01em] text-fg-primary"
                            >{{ repo.name }}</span
                        >
                        <FilledIcon
                            v-if="repo.private"
                            name="lock-fill"
                            :size="12"
                            class="shrink-0 text-fg-muted"
                            :aria-label="$t('Private')"
                        />
                    </a>
                    <span class="flex-1" />
                    <span
                        class="shrink-0 text-[12px]/4 tracking-[-0.005em] text-fg-muted"
                        >{{
                            [
                                repo.pulls !== null
                                    ? $t(':count pulls', {
                                          count: String(repo.pulls),
                                      })
                                    : null,
                                repo.pushedAt
                                    ? $t('pushed :when', {
                                          when: shortAgo(repo.pushedAt) ?? '',
                                      })
                                    : null,
                            ]
                                .filter(Boolean)
                                .join(' · ')
                        }}</span
                    >
                </li>
            </ul>
            <p
                v-else-if="!account.error"
                class="px-3.5 py-3 text-[13px]/5 text-fg-muted"
            >
                {{
                    $t(
                        'No repositories yet. Push an image to Docker Hub and it shows here.',
                    )
                }}
            </p>
        </template>
    </DashTray>

    <DashTray
        v-if="account?.apps.length"
        icon="layers-fill"
        :title="$t('Apps from these images')"
        :meta="appsMeta"
        panel-class="py-0.5"
    >
        <ul class="flex flex-col">
            <li
                v-for="app in account.apps"
                :key="`${app.machine}/${app.app}`"
                class="flex min-w-0 items-center gap-3 border-t-[0.5px] border-edge-tray-panel px-3.5 py-2.5 first:border-t-0"
            >
                <Link
                    :href="workspacePath(app.href)"
                    class="flex min-w-0 items-center gap-1.5 text-[13px]/5 font-medium text-fg-primary hover:underline"
                >
                    <span class="truncate">{{ app.app }}</span>
                    <span class="truncate font-normal text-fg-muted">{{
                        $t('on :machine', { machine: app.machine })
                    }}</span>
                </Link>
                <span
                    class="min-w-0 truncate font-ui-mono text-[12px]/4 text-fg-muted max-sm:hidden"
                    >{{ app.image }}</span
                >
                <span class="flex-1" />
                <span
                    class="shrink-0 text-[12px]/4 text-fg-muted max-sm:hidden"
                    >{{ pushNote(app) }}</span
                >
                <StatusBadge
                    :status="app.redeploy ? 'live' : 'stopped'"
                    :label="app.redeploy ? $t('On push') : $t('By hand')"
                />
            </li>
        </ul>
    </DashTray>
</template>
