<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Check } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref, watch } from 'vue';
import DashButton from '@/components/dash/DashButton.vue';
import DashSwitch from '@/components/dash/DashSwitch.vue';
import DashTray from '@/components/dash/DashTray.vue';
import StatusBadge from '@/components/dash/StatusBadge.vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import type { DockerHubApp } from '@/components/integrations/dockerHub';
import { shortDigest } from '@/components/integrations/dockerHub';
import { bytes } from '@/components/machines/serviceFormat';
import { networkMessage, responseError } from '@/lib/errors';
import { SolarCopy as Copy } from '@/lib/solarIcons';
import { timeAgo } from '@/lib/timeAgo';
import { toast } from '@/lib/toast';
import { onWorkspace, workspacePath } from '@/lib/workspaceRoute';
import appsRoutes from '@/routes/workspace/apps';

const props = defineProps<{
    image: DockerHubApp | undefined;
    workspaceSlug: string;
    machineId: number;
    app: string;
    canManage: boolean;
    online: boolean;
}>();

const current = ref<DockerHubApp | undefined>(props.image);
watch(
    () => props.image,
    (image) => (current.value = image),
);
const pushOn = ref(props.image?.push?.on ?? false);
watch(
    () => current.value?.push?.on,
    (on) => (pushOn.value = on ?? false),
);
const busy = ref<'update' | 'push' | 'address' | null>(null);
const copied = ref(false);

const args = computed(() => ({
    workspace: props.workspaceSlug,
    machine: props.machineId,
    service: props.app,
}));

function send(
    url: string,
    method: 'POST' | 'PUT',
    body?: unknown,
): Promise<Response | null> {
    return fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN':
                document.querySelector<HTMLMetaElement>(
                    'meta[name="csrf-token"]',
                )?.content ?? '',
        },
        body: body === undefined ? undefined : JSON.stringify(body),
    }).catch(() => null);
}

async function update(): Promise<void> {
    busy.value = 'update';
    const response = await send(
        onWorkspace(appsRoutes.dockerHub.update(args.value)).url,
        'POST',
    );
    busy.value = null;
    if (!response?.ok) {
        toast.error(
            response ? await responseError(response) : networkMessage(),
        );

        return;
    }
    toast.success(
        trans('Deploying :app onto the newest image of :image.', {
            app: props.app,
            image: current.value?.image ?? '',
        }),
    );
    router.reload({ only: ['app', 'dockerHub'] });
}

async function savePush(on: boolean, newAddress = false): Promise<void> {
    busy.value = newAddress ? 'address' : 'push';
    const response = await send(
        onWorkspace(appsRoutes.dockerHub(args.value)).url,
        'PUT',
        { redeploy_on_push: on, new_address: newAddress },
    );
    busy.value = null;
    if (!response?.ok) {
        pushOn.value = current.value?.push?.on ?? false;
        toast.error(
            response ? await responseError(response) : networkMessage(),
        );

        return;
    }
    current.value = (
        (await response.json()) as { dockerHub: DockerHubApp }
    ).dockerHub;
}

async function copyUrl(): Promise<void> {
    const url = current.value?.push?.url;
    if (!url) return;
    try {
        await navigator.clipboard.writeText(url);
        copied.value = true;
        setTimeout(() => (copied.value = false), 1600);
    } catch {}
}

const state = computed<{
    status: 'live' | 'pending' | 'deploying' | 'stopped';
    label: string;
}>(() => {
    const image = current.value;
    if (!image) return { status: 'stopped', label: '' };
    if (image.updating)
        return { status: 'deploying', label: trans('Updating') };
    if (image.latest === null)
        return { status: 'stopped', label: trans('Couldn’t check') };

    return image.behind
        ? { status: 'pending', label: trans('Newer image') }
        : { status: 'live', label: trans('Up to date') };
});

const delivery = computed(() => {
    const push = current.value?.push;
    if (!push?.on) return null;
    if (push.lastPushAt)
        return trans('Last push :when', { when: timeAgo(push.lastPushAt) });
    if (push.lastDeliveryAt)
        return trans('Docker Hub reached it :when', {
            when: timeAgo(push.lastDeliveryAt),
        });

    return trans('Waiting for the first delivery from Docker Hub.');
});

const row =
    'flex min-w-0 items-center gap-3 border-t-[0.5px] border-edge-tray-panel px-3.5 py-2.5 first:border-t-0';
const key =
    'w-[112px] shrink-0 text-[13px]/5 tracking-[-0.005em] text-fg-muted';
</script>

<template>
    <DashTray
        icon="package-fill"
        :title="$t('Image')"
        :meta="current?.image ?? null"
        panel-class="py-0.5"
    >
        <template v-if="current" #actions>
            <StatusBadge :status="state.status" :label="state.label" />
        </template>

        <div
            v-if="current === undefined"
            class="flex flex-col gap-2 px-3.5 py-3"
            role="status"
            :aria-label="$t('Loading the image')"
        >
            <span
                class="h-3 w-44 animate-pulse rounded bg-surface-badge motion-reduce:animate-none"
            />
            <span
                class="h-3 w-28 animate-pulse rounded bg-surface-badge motion-reduce:animate-none"
            />
        </div>
        <ul v-else class="flex flex-col">
            <li :class="row">
                <span :class="key">{{ $t('Running') }}</span>
                <span
                    class="min-w-0 truncate font-ui-mono text-[12.5px]/5 text-fg-primary"
                    :title="current.running"
                    >{{ shortDigest(current.running) }}</span
                >
            </li>
            <li :class="row">
                <span :class="key">{{
                    $t(':tag at Docker Hub', { tag: current.tag })
                }}</span>
                <span
                    v-if="current.latest"
                    class="min-w-0 truncate font-ui-mono text-[12.5px]/5"
                    :class="
                        current.behind
                            ? 'text-status-pending'
                            : 'text-fg-primary'
                    "
                    :title="current.latest"
                    >{{ shortDigest(current.latest) }}</span
                >
                <span v-else class="min-w-0 text-[12.5px]/5 text-fg-muted">{{
                    current.error ?? $t('Couldn’t check')
                }}</span>
                <span
                    v-if="current.pushedAt || current.size"
                    class="min-w-0 truncate text-[12px]/4 text-fg-muted max-sm:hidden"
                    >{{
                        [
                            current.pushedAt
                                ? $t('pushed :when', {
                                      when: timeAgo(current.pushedAt),
                                  })
                                : null,
                            current.size ? bytes(current.size) : null,
                        ]
                            .filter(Boolean)
                            .join(' · ')
                    }}</span
                >
                <span class="flex-1" />
                <a
                    v-if="current.url"
                    :href="current.url"
                    target="_blank"
                    rel="noopener"
                    class="flex shrink-0 items-center gap-1 text-[12.5px]/5 text-fg-secondary hover:text-fg-primary hover:underline"
                >
                    {{ $t('Docker Hub') }}
                    <FilledIcon
                        name="open-fill"
                        :size="12"
                        class="text-fg-muted"
                    />
                </a>
            </li>
            <li v-if="current.behind && canManage" :class="row">
                <span class="min-w-0 flex-1 text-[13px]/5 text-fg-secondary">{{
                    $t(
                        'A newer image was pushed to :tag. Updating deploys it as a new version, so you can roll back.',
                        { tag: current.tag },
                    )
                }}</span>
                <DashButton
                    size="sm"
                    variant="primary"
                    :label="$t('Update now')"
                    :loading="busy === 'update'"
                    :disabled="!online || busy !== null || !!current.updating"
                    @click="update"
                />
            </li>
            <li v-if="current.push" :class="[row, 'flex-wrap']">
                <span class="flex min-w-0 flex-1 flex-col">
                    <span class="text-[13px]/5 text-fg-primary">{{
                        $t('Redeploy when a new image is pushed')
                    }}</span>
                    <span class="text-[12px]/4 text-fg-muted">{{
                        current.push.available
                            ? (delivery ??
                              $t(
                                  'A push of :tag deploys it, with the settings it has now.',
                                  {
                                      tag: current.tag,
                                  },
                              ))
                            : $t(
                                  'Connect Docker Hub in Integrations to turn this on.',
                              )
                    }}</span>
                </span>
                <DashButton
                    v-if="!current.push.available && canManage"
                    size="sm"
                    :label="$t('Connect')"
                    :href="workspacePath('/integrations')"
                />
                <DashSwitch
                    v-else
                    v-model="pushOn"
                    :label="$t('Redeploy when a new image is pushed')"
                    :disabled="!canManage || busy !== null"
                    @update:model-value="(on: boolean) => savePush(on)"
                />
                <div
                    v-if="current.push.on && current.push.url"
                    class="flex w-full flex-col gap-1.5 pt-1"
                >
                    <div class="flex items-center gap-2">
                        <code
                            class="min-w-0 flex-1 truncate rounded-[7px] border-[0.5px] border-edge-field bg-surface-field px-2 py-1.5 font-ui-mono text-[11.5px]/4 text-fg-secondary select-all"
                            >{{ current.push.url }}</code
                        >
                        <DashButton
                            size="sm"
                            :label="copied ? $t('Copied') : $t('Copy')"
                            :icon="copied ? Check : Copy"
                            @click="copyUrl"
                        />
                    </div>
                    <p class="text-[12px]/4 text-fg-muted">
                        {{
                            $t(
                                'In Docker Hub, open :repository, go to Webhooks, name it ServerOS and paste this address. Docker Hub’s API can’t add it for you. Anyone with the address can start a redeploy, so keep it private.',
                                { repository: current.repository },
                            )
                        }}
                        <button
                            type="button"
                            class="cursor-pointer text-fg-secondary underline decoration-fg-faint underline-offset-2 hover:text-fg-primary disabled:cursor-default"
                            :disabled="busy !== null"
                            @click="savePush(true, true)"
                        >
                            {{ $t('Make a new address') }}
                        </button>
                    </p>
                </div>
            </li>
        </ul>
    </DashTray>
</template>
