<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { LoaderCircle } from '@lucide/vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import { trans } from 'laravel-vue-i18n';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import DashSwitch from '@/components/dash/DashSwitch.vue';
import DashTray from '@/components/dash/DashTray.vue';
import SettingsRow from '@/components/machines/settings/SettingsRow.vue';
import { describe, errorMessage, jobReason } from '@/lib/errors';
import { fixed } from '@/lib/formatting';
import { toast } from '@/lib/toast';
import { onWorkspace, workspacePath } from '@/lib/workspaceRoute';
import machinesRoutes from '@/routes/workspace/machines';

export type TrafficSettingsProps = {
    enabled: boolean;
    blocked: string | null;
    proxy: string | null;
    sites: number | null;
    existing: number | null;
    changing: string | null;
    error: string | null;
};

type SiteRow = {
    site: string;
    kind: 'app' | 'existing';
    name: string;
    server: string;
    container?: string | null;
    names: string[];
    logs: string[];
    shared: boolean;
    timed: boolean | null;
    catchAll?: boolean;
    unlogged?: 'off' | 'elsewhere' | null;
    url: string | null;
    requests: number;
    errors: number;
    clientErrors: number;
    visitors: number;
};

const props = defineProps<{
    machineId: number;
    machineName: string;
    traffic: TrafficSettingsProps;
    canManage: boolean;
}>();
const page = usePage<{ workspace: { slug: string } }>();

const on = ref(props.traffic.enabled);
const busy = ref(false);
const error = ref<string | null>(props.traffic.error);
const rows = ref<SiteRow[] | null>(null);
let poll: ReturnType<typeof setTimeout> | undefined;
let controller: AbortController | null = null;
onBeforeUnmount(() => {
    clearTimeout(poll);
    controller?.abort();
});

async function loadSites(): Promise<void> {
    if (!props.traffic.enabled || props.traffic.blocked) {
        rows.value = null;

        return;
    }
    controller?.abort();
    controller = new AbortController();
    try {
        const response = await fetch(
            onWorkspace(
                machinesRoutes.traffic.sites({
                    workspace: page.props.workspace.slug,
                    machine: props.machineId,
                }),
            ).url,
            {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            },
        );
        if (!response.ok) return;
        const body = await response.json();
        rows.value = Array.isArray(body.sites) ? (body.sites as SiteRow[]) : [];
    } catch {
        // The table is extra; the switch works without it.
    }
}

function compact(value: number): string {
    if (value >= 1e6) return `${fixed(value / 1e6, 1)}M`;
    if (value >= 1e4) return `${Math.round(value / 1e3)}k`;
    if (value >= 1e3) return `${fixed(value / 1e3, 1)}k`;

    return String(Math.round(value));
}

function rate(part: number, whole: number): string {
    if (whole === 0) return '–';
    const p = (part / whole) * 100;
    if (p === 0) return '0%';
    if (p < 0.1) return '<0.1%';

    return `${p < 10 ? fixed(p, 1) : Math.round(p)}%`;
}

/** A row's second line: the app's domain, or the site's other names or its log. */
function detail(row: SiteRow): string {
    if (row.kind === 'app') return row.names[0] ?? trans('No domain');
    if (row.unlogged === 'off') return trans('Writes no access log');
    if (row.unlogged) return trans('Logs to syslog or the journal');
    if (row.shared) return row.names.join(', ');
    if (row.catchAll) return trans('Every host no other site answers');
    if (row.names.length > 1) return row.names.slice(1).join(', ');

    // The log's file name: the folder is on the site's own page.
    // The site's own log, not the copy with times ServerOS may have added.
    const own =
        row.logs.find((log) => !log.startsWith('/var/log/serveros-traffic/')) ??
        row.logs[0];
    if (own === '/dev/stdout' || own === '/dev/stderr') {
        return trans('The container’s output');
    }

    return own?.split('/').pop() ?? '';
}

const counted = computed(() =>
    props.traffic.sites === null && props.traffic.existing === null
        ? null
        : (props.traffic.sites ?? 0) + (props.traffic.existing ?? 0),
);

watch(
    () => props.traffic,
    (next) => {
        if (busy.value) return;
        on.value = next.enabled;
        error.value = next.error;
        loadSites();
    },
);

const restarts = computed(
    () => props.traffic.proxy === null || props.traffic.proxy === 'Traefik',
);

function csrf(): string {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}

async function change(next: boolean): Promise<void> {
    if (busy.value) return;
    busy.value = true;
    error.value = null;
    on.value = next;

    try {
        const response = await fetch(
            onWorkspace(
                machinesRoutes.traffic.update({
                    workspace: page.props.workspace.slug,
                    machine: props.machineId,
                }),
            ).url,
            {
                method: 'PUT',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf(),
                },
                body: JSON.stringify({ enabled: next }),
            },
        );
        const body = await response.json().catch(() => ({}));
        if (response.status !== 202) {
            throw new Error(describe(response.status, body));
        }
        await follow(body.jobId, next);
    } catch (e) {
        on.value = !next;
        error.value = errorMessage(e);
    } finally {
        busy.value = false;
        router.reload({ only: ['traffic'] });
    }
}

function follow(jobId: string, next: boolean): Promise<void> {
    let tries = 0;

    return new Promise((resolve, reject) => {
        const check = async () => {
            try {
                const response = await fetch(
                    onWorkspace(
                        machinesRoutes.traffic.show({
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
                        next
                            ? trans(
                                  ':name counts traffic now. Each app’s Traffic tab shows it within a couple of minutes.',
                                  { name: props.machineName },
                              )
                            : trans(':name stopped counting traffic.', {
                                  name: props.machineName,
                              }),
                    );
                    resolve();

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
                                    'The machine didn’t change it. Try again.',
                                ),
                            ),
                        ),
                    );

                    return;
                }
                if (++tries >= 90) {
                    reject(
                        new Error(
                            trans(
                                'The machine is taking a while. This shows the change once it’s done.',
                            ),
                        ),
                    );

                    return;
                }
                poll = setTimeout(check, 1500);
            } catch (e) {
                reject(e);
            }
        };
        poll = setTimeout(check, 1000);
    });
}

onMounted(() => {
    loadSites();
    if (!props.traffic.changing) return;
    busy.value = true;
    follow(props.traffic.changing, props.traffic.enabled)
        .catch((e) => {
            on.value = !props.traffic.enabled;
            error.value = errorMessage(e);
        })
        .finally(() => {
            busy.value = false;
            router.reload({ only: ['traffic'] });
        });
});
</script>

<template>
    <DashTray
        panel-class="gap-1 px-[18px] pt-3 pb-1"
        icon="activity-fill"
        :title="$t('Traffic')"
    >
        <SettingsRow
            :label="$t('Count traffic')"
            :hint="
                (traffic.existing !== null
                    ? $t(
                          'Counts visits to the sites on :name from their access logs: the apps ServerOS runs, and the sites your own web servers serve, whose logs ServerOS only reads. Visitor IPs never leave the machine.',
                          { name: machineName },
                      )
                    : $t(
                          'Counts visits to the sites ServerOS runs on :name from the proxy’s access logs. Visitor IPs never leave the machine.',
                          { name: machineName },
                      )) +
                (restarts
                    ? ' ' +
                      $t(
                          'On Traefik, switching it restarts the proxy, so sites blip for a second.',
                      )
                    : '')
            "
        >
            <DashSwitch
                :model-value="on"
                :label="$t('Count traffic')"
                :disabled="!canManage || busy || traffic.blocked !== null"
                @update:model-value="change"
            />
        </SettingsRow>

        <p v-if="traffic.blocked" class="py-2.5 text-[12px]/4 text-fg-muted">
            {{ traffic.blocked }}
        </p>
        <p
            v-else-if="busy"
            class="flex items-center gap-2 py-2.5 text-[12px]/4 text-fg-muted"
        >
            <LoaderCircle class="size-3.5 animate-spin" aria-hidden="true" />
            {{ on ? $t('Turning it on…') : $t('Turning it off…') }}
        </p>
        <p
            v-else-if="error"
            class="py-2.5 text-[12px]/4 text-status-down"
            role="alert"
        >
            {{ $t('That didn’t apply: :reason', { reason: error }) }}
        </p>
        <p
            v-else-if="traffic.enabled && counted !== null && !rows?.length"
            class="py-2.5 text-[12px]/4 text-fg-muted"
        >
            {{
                $t(
                    counted === 1
                        ? 'Counting 1 site. Each app’s Traffic tab shows its visits.'
                        : 'Counting :count sites. Each app’s Traffic tab shows its visits.',
                    { count: String(counted) },
                )
            }}
        </p>

        <template v-if="traffic.enabled && !busy && !error && rows?.length">
            <SettingsRow v-for="row in rows" :key="row.site">
                <template #label>
                    <component
                        :is="row.url ? Link : 'span'"
                        :href="row.url ? workspacePath(row.url) : undefined"
                        class="truncate text-[13px]/5 tracking-[-0.005em] text-fg-primary"
                        :class="row.url ? 'hover:underline' : ''"
                        :title="row.names.join(', ') || row.name"
                        >{{ row.name }}</component
                    >
                    <span
                        class="truncate text-[12px]/4 tracking-[-0.005em] text-fg-muted"
                        :title="detail(row)"
                        >{{ detail(row)
                        }}{{
                            row.server
                                ? ` · ${row.server}${row.container ? ' ' + $t('in :container', { container: row.container }) : ''}`
                                : ''
                        }}</span
                    >
                </template>
                <span
                    class="flex items-center gap-4 text-[12px]/4 whitespace-nowrap text-fg-muted tabular-nums"
                >
                    <span>
                        <span class="text-fg-secondary">{{
                            row.unlogged ? '–' : compact(row.requests)
                        }}</span>
                        {{ $t('requests') }}
                    </span>
                    <span class="max-sm:hidden">
                        <span
                            :class="
                                !row.unlogged &&
                                row.requests > 0 &&
                                row.errors / row.requests >= 0.05
                                    ? 'text-status-down'
                                    : 'text-fg-secondary'
                            "
                            >{{
                                row.unlogged
                                    ? '–'
                                    : rate(row.errors, row.requests)
                            }}</span
                        >
                        {{ $t('errors') }}
                    </span>
                    <span>
                        <span class="text-fg-secondary">{{
                            row.unlogged ? '–' : compact(row.visitors)
                        }}</span>
                        {{ $t('visitors') }}
                    </span>
                </span>
                <Link
                    v-if="row.url"
                    :href="workspacePath(row.url)"
                    class="flex size-6 items-center justify-center rounded-md text-fg-muted hover:bg-surface-hover hover:text-fg-primary"
                    :aria-label="$t('Traffic for :name', { name: row.name })"
                >
                    <FilledIcon name="chevron-right" :size="14" />
                </Link>
            </SettingsRow>
            <p class="py-2.5 text-[12px]/4 text-fg-muted">
                {{
                    $t(
                        'The last 24 hours. Errors are the share of server errors (5xx). A site that writes no access log has no numbers; its page says how to start one.',
                    )
                }}
            </p>
        </template>
    </DashTray>
</template>
