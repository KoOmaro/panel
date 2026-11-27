<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import DashButton from '@/components/dash/DashButton.vue';
import DashTray from '@/components/dash/DashTray.vue';
import SettingsRow from '@/components/machines/settings/SettingsRow.vue';
import { SolarCopy as Copy } from '@/lib/solarIcons';
import { toast } from '@/lib/toast';
import { describe, errorMessage, jobReason } from '@/lib/errors';
import { onWorkspace } from '@/lib/workspaceRoute';
import machinesRoutes from '@/routes/workspace/machines';

export type TunnelSettingsProps = {
    supported: boolean;
    since: string;
    current: {
        provider: string;
        tunnel_id: string | null;
        state: string;
        plain_http: boolean;
        since: string;
    } | null;
    target: string | null;
    changing: string | null;
};

const props = defineProps<{
    machineId: number;
    machineName: string;
    tunnel: TunnelSettingsProps;
    canManage: boolean;
}>();
const page = usePage<{ workspace: { slug: string } }>();

const token = ref('');
const busy = ref<'up' | 'down' | null>(null);
const error = ref<string | null>(null);
const editing = ref(false);
let poll: ReturnType<typeof setTimeout> | undefined;
onBeforeUnmount(() => clearTimeout(poll));

const connected = computed(() => props.tunnel.current?.state === 'connected');
const showForm = computed(
    () => props.canManage && (!connected.value || editing.value),
);

function csrf(): string {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}

async function send(action: 'up' | 'down'): Promise<void> {
    if (busy.value) return;
    busy.value = action;
    error.value = null;

    try {
        const args = {
            workspace: page.props.workspace.slug,
            machine: props.machineId,
        };
        const response = await fetch(
            onWorkspace(
                action === 'up'
                    ? machinesRoutes.tunnel.store(args)
                    : machinesRoutes.tunnel.destroy(args),
            ).url,
            {
                method: action === 'up' ? 'POST' : 'DELETE',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf(),
                },
                body:
                    action === 'up'
                        ? JSON.stringify({ token: token.value })
                        : undefined,
            },
        );
        const body = await response.json().catch(() => ({}));
        if (response.status !== 202) {
            throw new Error(describe(response.status, body));
        }
        await follow(body.jobId, action);
        token.value = '';
        editing.value = false;
    } catch (e) {
        error.value = errorMessage(e);
    } finally {
        busy.value = null;
    }
}

function follow(jobId: string, action: 'up' | 'down'): Promise<void> {
    let tries = 0;

    return new Promise((resolve, reject) => {
        const check = async () => {
            try {
                const response = await fetch(
                    onWorkspace(
                        machinesRoutes.tunnel.show({
                            workspace: page.props.workspace.slug,
                            machine: props.machineId,
                            job: jobId,
                        }),
                    ).url,
                    {
                        credentials: 'same-origin',
                        headers: { Accept: 'application/json' },
                    },
                );
                const job = await response.json();
                if (job.state === 'succeeded') {
                    toast.success(
                        action === 'up'
                            ? trans(
                                  ':name is connected to the tunnel. Its apps answer over plain HTTP, and Cloudflare does HTTPS.',
                                  { name: props.machineName },
                              )
                            : trans(
                                  'The tunnel is off :name. Its apps get their own certificates again.',
                                  { name: props.machineName },
                              ),
                    );
                    router.reload({
                        only: ['tunnel'],
                        onFinish: () => resolve(),
                    });

                    return;
                }
                if (
                    [
                        'failed',
                        'refused',
                        'timed_out',
                        'cancelled',
                        'stale',
                    ].includes(job.state)
                ) {
                    reject(
                        new Error(
                            jobReason(
                                job.error,
                                trans(
                                    'The machine didn’t change the tunnel. Try again.',
                                ),
                            ),
                        ),
                    );

                    return;
                }
                // Installing cloudflared can take a few minutes.
                if (++tries >= 300) {
                    reject(
                        new Error(
                            trans(
                                'The machine is taking a while. The tunnel will show here when it’s done.',
                            ),
                        ),
                    );

                    return;
                }
                poll = setTimeout(check, 2000);
            } catch (e) {
                reject(e);
            }
        };
        poll = setTimeout(check, 1000);
    });
}

onMounted(() => {
    if (props.tunnel.changing) {
        busy.value = 'up';
        follow(props.tunnel.changing, connected.value ? 'down' : 'up')
            .catch((e) => {
                error.value = errorMessage(e);
            })
            .finally(() => {
                busy.value = null;
            });
    }
});

async function copy(text: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(text);
        toast.success(trans('Copied.'));
    } catch {
        toast.error(trans('Couldn’t copy. Select it and copy it yourself.'));
    }
}
</script>

<template>
    <DashTray
        panel-class="gap-1 px-[18px] pt-3 pb-1"
        icon="lock-fill"
        :title="$t('Cloudflare Tunnel')"
    >
        <template v-if="connected" #actions>
            <span
                class="flex items-center gap-1.5 text-[12px]/4 text-fg-secondary"
            >
                <span
                    class="size-1.5 rounded-full bg-status-live"
                    aria-hidden="true"
                />
                {{ $t('Connected') }}
            </span>
        </template>

        <SettingsRow
            :label="$t('Serve apps through a tunnel')"
            :hint="
                $t(
                    'Serve the apps on :name through a Cloudflare Tunnel, so it needs no open ports for them. Cloudflare does HTTPS, and the machine answers each app’s domain over plain HTTP.',
                    { name: machineName },
                )
            "
        />

        <SettingsRow
            v-if="!tunnel.supported"
            :label="$t('Needs a newer daemon')"
            :hint="
                $t(
                    'Update the ServerOS daemon on :name to :version or later to use a tunnel.',
                    { name: machineName, version: tunnel.since },
                )
            "
        />

        <template v-else>
            <template v-if="connected">
                <SettingsRow
                    v-if="tunnel.target"
                    :label="$t('Point domains at')"
                    :hint="
                        $t(
                            'In Cloudflare, add a published application in the tunnel’s Routes for each app’s domain (or one for *.yourdomain.com), with the service URL http://localhost:80. If you add DNS records yourself, make each one a proxied CNAME to this name.',
                        )
                    "
                >
                    <span
                        class="flex max-w-80 min-w-0 items-center gap-2 font-ui-mono text-[12px]/4 text-fg-primary"
                    >
                        <span class="truncate">{{ tunnel.target }}</span>
                        <button
                            type="button"
                            class="shrink-0 cursor-pointer text-fg-muted hover:text-fg-primary"
                            :aria-label="$t('Copy')"
                            @click="copy(tunnel.target)"
                        >
                            <Copy class="size-3.5" aria-hidden="true" />
                        </button>
                    </span>
                </SettingsRow>
                <SettingsRow
                    v-if="canManage && !editing"
                    :label="$t('Token')"
                    :hint="$t('Deploy apps with their domain as usual.')"
                >
                    <DashButton
                        size="sm"
                        :label="$t('Use another token')"
                        :disabled="busy !== null"
                        @click="editing = true"
                    />
                    <DashButton
                        size="sm"
                        class="text-status-down!"
                        :label="$t('Take the tunnel off')"
                        :loading="busy === 'down'"
                        :disabled="busy !== null"
                        @click="send('down')"
                    />
                </SettingsRow>
            </template>

            <SettingsRow v-if="showForm" stack :error="error">
                <template #label>
                    <ol
                        v-if="!connected"
                        class="flex list-decimal flex-col gap-1 pl-4 text-[12px]/4 text-fg-muted"
                    >
                        <li>
                            {{
                                $t(
                                    'In Cloudflare, open Networking › Tunnels and create a tunnel with Cloudflared.',
                                )
                            }}
                        </li>
                        <li>
                            {{
                                $t(
                                    'Copy its install command (or just the token at the end of it) and paste it below. ServerOS installs cloudflared on :name and runs it.',
                                    { name: machineName },
                                )
                            }}
                        </li>
                        <li>
                            {{
                                $t(
                                    'In the tunnel’s Routes, add a published application for your apps, like *.example.com, with the service URL http://localhost:80.',
                                )
                            }}
                        </li>
                    </ol>
                </template>
                <form
                    class="flex w-full flex-wrap items-center gap-2"
                    @submit.prevent="send('up')"
                >
                    <label class="dash-field min-w-60 flex-1">
                        <span class="sr-only">{{ $t('Tunnel token') }}</span>
                        <input
                            v-model="token"
                            type="password"
                            autocomplete="off"
                            spellcheck="false"
                            :placeholder="
                                $t('eyJhIjoi… or the whole install command')
                            "
                            :disabled="busy !== null"
                        />
                    </label>
                    <DashButton
                        v-if="editing"
                        :label="$t('Cancel')"
                        :disabled="busy !== null"
                        @click="editing = false"
                    />
                    <DashButton
                        type="submit"
                        :label="
                            busy === 'up'
                                ? $t('Connecting…')
                                : $t('Connect the tunnel')
                        "
                        :loading="busy === 'up'"
                        :disabled="busy !== null || token.trim() === ''"
                    />
                </form>
            </SettingsRow>
            <p
                v-else-if="error"
                class="py-2.5 text-[12px]/4 text-status-down"
                role="alert"
            >
                {{ error }}
            </p>
        </template>
    </DashTray>
</template>
