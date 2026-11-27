<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { computed, onMounted, ref, watch } from 'vue';
import DashButton from '@/components/dash/DashButton.vue';
import DashTray from '@/components/dash/DashTray.vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import type { ExistingSiteRow } from '@/components/machines/ExistingSitesTraffic.vue';
import type { ServiceApp } from '@/components/service/serviceTypes';
import { onWorkspace } from '@/lib/workspaceRoute';
import machinesRoutes from '@/routes/workspace/machines';

const props = defineProps<{
    app: ServiceApp | null;
    sites?: ExistingSiteRow[];
    serves?: boolean;
    ports: number[];
    workspaceSlug: string;
    machineId: number;
    canManage: boolean;
    online: boolean;
}>();

defineEmits<{ change: []; site: [] }>();

type Check = { status: 'ready' | 'missing' | 'proxied' | 'elsewhere' };

const domain = computed(() => props.app?.domains[0] ?? null);
const wildcard = computed(
    () => !!domain.value && !!props.app?.domains.includes(`*.${domain.value}`),
);
const port = computed(() => {
    const ports = props.app?.port ? [props.app.port] : props.ports;
    if (ports.length === 0) return null;

    return ports.length === 1
        ? trans('Port :port', { port: String(ports[0]) })
        : trans('Ports :ports', { ports: ports.slice(0, 3).join(', ') });
});
const dns = ref<Check['status'] | null>(null);

async function check(): Promise<void> {
    dns.value = null;
    if (!domain.value) return;
    const asked = domain.value;
    try {
        const response = await fetch(
            onWorkspace(
                machinesRoutes.domainCheck({
                    workspace: props.workspaceSlug,
                    machine: props.machineId,
                }),
            ).url,
            {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN':
                        document.querySelector<HTMLMetaElement>(
                            'meta[name="csrf-token"]',
                        )?.content ?? '',
                },
                body: JSON.stringify({ domain: asked, wildcard: false }),
            },
        );
        if (response.ok && asked === domain.value) {
            dns.value = ((await response.json()) as Check).status;
        }
    } catch {
        dns.value = null;
    }
}

onMounted(check);
watch(domain, check);

const notes = computed(() => {
    const site = props.app?.site;
    if (!site) return '';
    const parts: string[] = [];
    if (dns.value === 'ready') parts.push(trans('Points at this machine.'));
    else if (dns.value === 'proxied')
        parts.push(trans('Behind Cloudflare’s proxy.'));
    else if (dns.value === 'missing' || dns.value === 'elsewhere')
        parts.push(trans('Doesn’t point at this machine yet.'));
    if (site.wwwRedirect && site.wwwName) {
        parts.push(
            trans(':www redirects here.', { www: String(site.wwwName) }),
        );
    }
    if (wildcard.value) {
        parts.push(
            trans('Every subdomain too, *.:domain.', {
                domain: String(domain.value),
            }),
        );
    }
    if (site.maintenance) parts.push(trans('The maintenance page is up.'));
    if (site.password) parts.push(trans('Behind a password.'));
    if (site.redirects.length) {
        parts.push(
            site.redirects.length === 1
                ? trans('1 redirect.')
                : trans(':count redirects.', {
                      count: String(site.redirects.length),
                  }),
        );
    }
    const job = site.job?.state;
    if (job === 'queued' || job === 'running') {
        parts.push(trans('Applying the last site change…'));
    } else if (job === 'failed' || job === 'stale') {
        parts.push(
            trans('The last site change didn’t apply: :reason', {
                reason: site.job?.error || trans('the machine didn’t answer'),
            }),
        );
    }

    return parts.join(' ');
});

const found = computed(() =>
    props.app || props.serves ? [] : (props.sites ?? []),
);
</script>

<template>
    <DashTray icon="globe-fill" :title="$t('Domain')" class="shrink-0">
        <template v-if="app && canManage" #actions>
            <DashButton
                size="sm"
                :label="domain ? $t('Change domain') : $t('Add domain')"
                :disabled="!app.canMove || !online"
                :title="
                    app.canMove
                        ? undefined
                        : $t(
                              'Upload the code again as a new version to change this.',
                          )
                "
                @click="$emit('change')"
            />
        </template>

        <div class="flex flex-col gap-3 px-[18px] pt-3 pb-3.5">
            <div v-if="app && domain" class="flex items-center gap-2.5">
                <FilledIcon name="globe-fill" class="shrink-0 text-fg-muted" />
                <div class="flex min-w-0 flex-1 flex-col">
                    <span class="flex min-w-0 items-center gap-2">
                        <a
                            :href="`${app.https ? 'https' : 'http'}://${domain}`"
                            target="_blank"
                            rel="noopener"
                            class="truncate text-[13px]/5 tracking-[-0.005em] text-fg-primary hover:underline hover:underline-offset-4"
                            >{{ domain }}</a
                        >
                        <span
                            v-if="app.https"
                            class="flex h-[18px] shrink-0 items-center gap-1 rounded-[9px] bg-status-live/14 pr-[7px] pl-1.5 text-[11px]/3.5 font-medium text-status-live"
                        >
                            <FilledIcon name="lock-fill" :size="10" />
                            {{ $t('HTTPS') }}
                        </span>
                    </span>
                    <button
                        v-if="canManage && notes"
                        type="button"
                        class="cursor-pointer truncate text-left text-[12px]/4 tracking-[-0.005em] text-fg-muted hover:text-fg-tertiary"
                        :title="$t('Site settings')"
                        @click="$emit('site')"
                    >
                        {{ notes }}
                    </button>
                    <span
                        v-else-if="notes"
                        class="truncate text-[12px]/4 tracking-[-0.005em] text-fg-muted"
                        >{{ notes }}</span
                    >
                </div>
                <span
                    v-if="port"
                    class="shrink-0 font-ui-mono text-[12px]/4 text-fg-muted"
                    >{{ port }}</span
                >
            </div>

            <div
                v-for="(site, index) in found"
                :key="site.label"
                class="flex items-center gap-2.5"
            >
                <FilledIcon name="globe-fill" class="shrink-0 text-fg-muted" />
                <div class="flex min-w-0 flex-1 flex-col">
                    <span
                        class="truncate text-[13px]/5 tracking-[-0.005em] text-fg-primary"
                        >{{ site.label }}</span
                    >
                    <span
                        v-if="site.where"
                        class="truncate text-[12px]/4 tracking-[-0.005em] text-fg-muted"
                        >{{ site.where }}</span
                    >
                </div>
                <span
                    v-if="index === 0 && port"
                    class="shrink-0 font-ui-mono text-[12px]/4 text-fg-muted"
                    >{{ port }}</span
                >
            </div>

            <div
                v-if="!domain && found.length === 0"
                class="flex items-center gap-2.5"
            >
                <FilledIcon name="globe-fill" class="shrink-0 text-fg-faint" />
                <div class="flex min-w-0 flex-1 flex-col">
                    <span
                        class="truncate text-[13px]/5 tracking-[-0.005em] text-fg-muted"
                        >{{
                            app
                                ? $t('No domain yet')
                                : serves
                                  ? $t('It’s the web server here')
                                  : $t('No domain found')
                        }}</span
                    >
                    <span
                        class="truncate text-[12px]/4 tracking-[-0.005em] text-fg-faint"
                        >{{
                            app
                                ? $t(
                                      'It answers on its port on the machine until you add one.',
                                  )
                                : serves
                                  ? $t(
                                        'The sites it serves are on the Traffic tab.',
                                    )
                                  : $t(
                                        'No web server on this machine sends a domain to it.',
                                    )
                        }}</span
                    >
                </div>
                <span
                    v-if="port"
                    class="shrink-0 font-ui-mono text-[12px]/4 text-fg-muted"
                    >{{ port }}</span
                >
            </div>
        </div>
    </DashTray>
</template>
