<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';
import SiteTraffic from '@/components/machines/SiteTraffic.vue';
import type { SiteTrafficState } from '@/components/machines/SiteTraffic.vue';
import { fixed } from '@/lib/formatting';

export type ExistingSiteRow = SiteTrafficState & {
    label: string;
    lastDay: {
        requests: number;
        errors: number;
        clientErrors: number;
        visitors: number;
    };
};

export type ExistingSitesTrafficProps = {
    server: string | null;
    collecting: boolean;
    blocked: string | null;
    sites: ExistingSiteRow[];
};

const props = defineProps<{
    machineId: number;
    machineName: string;
    serviceUrl: string;
    traffic: ExistingSitesTrafficProps;
    canManage: boolean;
}>();

const page = usePage();
const selectedKey = computed(() => {
    const query = page.url.split('?')[1] ?? '';

    return new URLSearchParams(query).get('site');
});
const selected = computed(
    () => props.traffic.sites.find((s) => s.site === selectedKey.value) ?? null,
);
const listUrl = computed(() => `${props.serviceUrl}/traffic`);
const server = computed(() => props.traffic.server ?? trans('this service'));

function siteUrl(site: string): string {
    return `${listUrl.value}?site=${encodeURIComponent(site)}`;
}

function open(site: string): void {
    router.visit(siteUrl(site), { preserveScroll: true, preserveState: true });
}

function compact(value: number): string {
    if (value >= 1e9) return `${fixed(value / 1e9, 1)}B`;
    if (value >= 1e6) return `${fixed(value / 1e6, 1)}M`;
    if (value >= 1e4) return `${Math.round(value / 1e3)}k`;
    if (value >= 1e3) return `${fixed(value / 1e3, 1)}k`;

    return String(Math.round(value));
}

function rate(part: number, whole: number): string {
    if (whole === 0) return '—';
    const p = (part / whole) * 100;
    if (p === 0) return '0%';
    if (p < 0.1) return '<0.1%';

    return `${p < 10 ? fixed(p, 1) : Math.round(p)}%`;
}

/** What a site's second line says: its other names, or where it's set up. */
function detail(row: ExistingSiteRow): string {
    const site = row.existing;
    if (!site) return '';
    if (site.shared) return site.names.join(', ');
    if (site.names.length > 1) return site.names.slice(1).join(', ');

    // Where it's set up, without the server's own folder.
    return site.configs[0]?.split('/').slice(-2).join('/') ?? site.server;
}

function reload(): void {
    router.reload({ only: ['siteTraffic'] });
}

const settingsUrl = computed(() => `/machines/${props.machineId}/settings`);
</script>

<template>
    <div v-if="selected" class="flex flex-col gap-4">
        <Link
            :href="listUrl"
            preserve-scroll
            class="flex w-fit items-center gap-1.5 text-[13px]/5 text-(--ds-text-muted) hover:text-(--ds-text)"
        >
            <ArrowLeft class="size-3.5" aria-hidden="true" />
            {{ $t('All :server sites', { server }) }}
        </Link>
        <SiteTraffic
            :key="selected.site"
            :machine-id="machineId"
            :machine-name="machineName"
            :app="selected.site"
            :state="selected"
            :can-manage="canManage"
            @changed="reload"
        />
    </div>

    <div v-else class="flex flex-col gap-5">
        <div class="flex min-h-8 flex-wrap items-center justify-between gap-3">
            <div class="flex min-w-0 items-baseline gap-2">
                <h2 class="text-[15px] font-medium">{{ $t('Traffic') }}</h2>
                <span class="truncate text-[13px] text-(--ds-text-faint)">{{
                    $t('Sites in :server’s config', { server })
                }}</span>
            </div>
            <span
                v-if="traffic.collecting && traffic.sites.length"
                class="text-xs text-(--ds-text-faint)"
                >{{ $t('Last 24 hours') }}</span
            >
        </div>

        <div
            v-if="traffic.blocked"
            class="ds-tile flex flex-col items-center gap-1 px-5 py-10 text-center text-[13px]/5"
        >
            <p class="font-medium">{{ $t('Traffic isn’t available here') }}</p>
            <p class="max-w-md text-(--ds-text-muted)">
                {{ traffic.blocked }}
            </p>
        </div>

        <div
            v-else-if="!traffic.collecting"
            class="ds-tile flex flex-col items-center gap-1 px-5 py-10 text-center text-[13px]/5"
        >
            <p class="font-medium">
                {{ $t(':name doesn’t count traffic', { name: machineName }) }}
            </p>
            <p class="max-w-md text-(--ds-text-muted)">
                {{
                    $t(
                        'Turn on Traffic in the machine’s settings to count visits to :server’s sites from the access logs it already writes. ServerOS only reads them, and visitor IPs never leave the machine.',
                        { server },
                    )
                }}
            </p>
            <Link
                v-if="canManage"
                :href="settingsUrl"
                class="mt-2 font-medium text-(--ds-text) underline-offset-4 hover:underline"
                >{{ $t('Open machine settings') }}</Link
            >
        </div>

        <div
            v-else-if="traffic.sites.length === 0"
            class="ds-tile flex flex-col items-center gap-1 px-5 py-10 text-center text-[13px]/5"
        >
            <p class="font-medium">{{ $t('No sites to count') }}</p>
            <p class="max-w-md text-(--ds-text-muted)">
                {{
                    $t(
                        'ServerOS found no site in :server’s config with an access log it can read. Logs sent to syslog, compressed, or with variables in their path can’t be read.',
                        { server },
                    )
                }}
            </p>
        </div>

        <section
            v-else
            class="ds-tile min-w-0 overflow-hidden"
            :aria-label="$t('Sites')"
        >
            <table
                class="w-full table-fixed border-collapse text-left text-[13px]/5"
            >
                <thead>
                    <tr class="text-[12px]/4 text-(--ds-text-faint)">
                        <th class="py-2.5 pr-3 pl-4 font-normal">
                            {{ $t('Site') }}
                        </th>
                        <th
                            class="hidden w-[28%] border-l border-(--ds-divider) px-3 font-normal md:table-cell"
                        >
                            {{ $t('Access log') }}
                        </th>
                        <th
                            class="w-20 border-l border-(--ds-divider) px-3 text-right font-normal sm:w-24"
                        >
                            {{ $t('Requests') }}
                        </th>
                        <th
                            class="hidden w-24 border-l border-(--ds-divider) px-3 text-right font-normal sm:table-cell"
                        >
                            {{ $t('Errors') }}
                        </th>
                        <th
                            class="w-20 border-l border-(--ds-divider) px-3 text-right font-normal max-sm:pr-4 sm:w-24"
                        >
                            {{ $t('Visitors') }}
                        </th>
                        <th
                            class="hidden w-36 border-l border-(--ds-divider) pr-4 pl-3 font-normal lg:table-cell"
                        >
                            {{ $t('Response times') }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in traffic.sites"
                        :key="row.site"
                        class="cursor-pointer border-t border-(--ds-divider) hover:bg-(--ds-hover)"
                        @click="open(row.site)"
                    >
                        <td class="min-w-0 py-2.5 pr-3 pl-4">
                            <Link
                                :href="siteUrl(row.site)"
                                preserve-scroll
                                preserve-state
                                class="block truncate font-medium text-(--ds-text)"
                                :title="row.label"
                                @click.stop
                                >{{ row.label }}</Link
                            >
                            <span
                                class="block truncate text-xs text-(--ds-text-faint)"
                                :title="detail(row)"
                                >{{ detail(row) }}</span
                            >
                        </td>
                        <td
                            class="hidden min-w-0 px-3 md:table-cell"
                            :title="row.existing?.logs.join(', ')"
                        >
                            <!-- Cut from the left, so the file's name stays in view. -->
                            <span
                                class="block truncate text-left font-ui-mono text-[12px] text-(--ds-text-secondary) [direction:rtl]"
                                >{{
                                    '\u200E' + (row.existing?.logs[0] ?? '—')
                                }}</span
                            >
                        </td>
                        <td class="px-3 text-right tabular-nums">
                            {{ compact(row.lastDay.requests) }}
                        </td>
                        <td
                            class="hidden px-3 text-right tabular-nums sm:table-cell"
                            :class="
                                row.lastDay.requests > 0 &&
                                row.lastDay.errors / row.lastDay.requests >=
                                    0.05
                                    ? 'text-(--ds-red)'
                                    : 'text-(--ds-text-muted)'
                            "
                        >
                            {{ rate(row.lastDay.errors, row.lastDay.requests) }}
                        </td>
                        <td class="px-3 text-right tabular-nums max-sm:pr-4">
                            {{ compact(row.lastDay.visitors) }}
                        </td>
                        <td
                            class="hidden pr-4 pl-3 text-(--ds-text-muted) lg:table-cell"
                        >
                            {{
                                row.existing?.timed
                                    ? $t('Recorded')
                                    : $t('Not recorded')
                            }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>

        <p
            v-if="traffic.collecting && traffic.sites.length"
            class="text-xs text-(--ds-text-faint)"
        >
            {{
                $t(
                    'Counted on :name from the access logs :server already writes, which ServerOS only reads. Errors are the share of server errors (5xx).',
                    { name: machineName, server },
                )
            }}
        </p>
    </div>
</template>
