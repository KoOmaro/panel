<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { router, usePage } from '@inertiajs/vue3';
import {
    ChevronDown,
    ChevronRight,
    CircleArrowUp,
    CircleCheck,
    CircleHelp,
    Hammer,
    LoaderCircle,
} from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import DashButton from '@/components/dash/DashButton.vue';
import DashCheckbox from '@/components/dash/DashCheckbox.vue';
import DashTable from '@/components/dash/DashTable.vue';
import DashTd from '@/components/dash/DashTd.vue';
import DashTh from '@/components/dash/DashTh.vue';
import DashTr from '@/components/dash/DashTr.vue';
import FilterPills from '@/components/dash/FilterPills.vue';
import SearchWell from '@/components/dash/SearchWell.vue';
import TintBadge from '@/components/summary/TintBadge.vue';
import { errorMessage } from '@/lib/errors';
import { toast } from '@/lib/toast';
import { onWorkspace } from '@/lib/workspaceRoute';
import containerImagesRoutes from '@/routes/workspace/container-images';
import { sendJson, severityDot, shortAgo } from './updates';
import type { Severity } from './updates';

export type ContainerRow = {
    id: number;
    machineId: number;
    machine: string | null;
    container: string;
    service: string;
    image: string;
    state: 'available' | 'current' | 'local' | 'unknown';
    error: string | null;
    checkedAt: string | null;
    availableSince: string | null;
    scannedAt: string | null;
    updateJob: string | null;
    vulnerabilities: {
        total: number;
        critical: number;
        high: number;
        exploited: number;
    };
};

type Finding = {
    cve: string;
    title: string | null;
    severity: Severity;
    severityLabel: string;
    exploited: boolean;
    url: string;
    package: string;
    installed: string | null;
    fixedIn: string | null;
};

const props = defineProps<{
    containers: ContainerRow[];
    canManage: boolean;
}>();

const page = usePage<{ workspace: { slug: string } }>();

const filter = ref<'attention' | 'all'>('attention');
const search = ref('');
const picked = ref<number[]>([]);
const updating = ref(false);
const checking = ref(false);
const open = ref<number | null>(null);
const findings = ref<Record<number, Finding[]>>({});
const loadingFindings = ref<number | null>(null);

const needsAttention = (row: ContainerRow) =>
    row.state === 'available' || row.vulnerabilities.total > 0;
const shown = computed(() => {
    const q = search.value.trim().toLowerCase();

    return (
        filter.value === 'attention'
            ? props.containers.filter(needsAttention)
            : props.containers
    ).filter(
        (row) =>
            !q ||
            `${row.container} ${row.image} ${row.machine ?? ''}`
                .toLowerCase()
                .includes(q),
    );
});
const updatable = computed(() =>
    shown.value.filter((row) => row.state === 'available' && !row.updateJob),
);
const allPicked = computed(
    () =>
        updatable.value.length > 0 &&
        updatable.value.every((row) => picked.value.includes(row.id)),
);
const running = computed(() => props.containers.some((row) => row.updateJob));
const lastCheck = computed(
    () =>
        props.containers
            .map((row) => row.checkedAt)
            .filter((at): at is string => !!at)
            .sort()
            .at(-1) ?? null,
);

let timer: ReturnType<typeof setTimeout> | undefined;
function schedule(): void {
    clearTimeout(timer);
    if (running.value) {
        timer = setTimeout(() => router.reload({ only: ['containers'] }), 3000);
    }
}
watch(running, schedule, { immediate: true });
watch(
    () => props.containers,
    () => {
        schedule();
        picked.value = picked.value.filter((id) =>
            updatable.value.some((row) => row.id === id),
        );
    },
);
onBeforeUnmount(() => clearTimeout(timer));

async function update(ids: number[]): Promise<void> {
    updating.value = true;
    try {
        const result = await sendJson<{
            queued: Record<string, string>;
            failed: { container: string; error: string }[];
        }>(
            'POST',
            onWorkspace(containerImagesRoutes.update(page.props.workspace.slug))
                .url,
            { ids },
        );
        const count = Object.keys(result.queued).length;
        if (count) {
            toast.success(
                count === 1
                    ? trans('Updating 1 container.')
                    : trans('Updating :count containers.', {
                          count: String(count),
                      }),
            );
        }
        for (const failure of result.failed) {
            toast.error(`${failure.container}: ${failure.error}`);
        }
        picked.value = [];
        router.reload({ only: ['containers'] });
    } catch (e) {
        toast.error(errorMessage(e));
    } finally {
        updating.value = false;
    }
}

async function checkNow(): Promise<void> {
    checking.value = true;
    try {
        await sendJson(
            'POST',
            onWorkspace(containerImagesRoutes.check(page.props.workspace.slug))
                .url,
        );
        toast.success(
            trans('Checking registries. New images show up in a minute.'),
        );
        router.reload({ only: ['containers'] });
    } catch (e) {
        toast.error(errorMessage(e));
    } finally {
        checking.value = false;
    }
}

async function toggleFindings(row: ContainerRow): Promise<void> {
    if (open.value === row.id) {
        open.value = null;

        return;
    }
    open.value = row.id;
    if (findings.value[row.id]) return;
    loadingFindings.value = row.id;
    try {
        findings.value[row.id] = (
            await sendJson<{ findings: Finding[] }>(
                'GET',
                onWorkspace(
                    containerImagesRoutes.vulnerabilities({
                        workspace: page.props.workspace.slug,
                        image: row.id,
                    }),
                ).url,
            )
        ).findings;
    } catch (e) {
        toast.error(errorMessage(e));
        open.value = null;
    } finally {
        loadingFindings.value = null;
    }
}

function vulnLine(row: ContainerRow): string {
    const v = row.vulnerabilities;
    if (!v.total) return row.scannedAt ? trans('None known') : '–';
    const worst = [
        v.critical
            ? trans(':count critical', { count: String(v.critical) })
            : '',
        v.high ? trans(':count high', { count: String(v.high) }) : '',
    ].filter(Boolean);

    return worst.length ? `${v.total} (${worst.join(', ')})` : String(v.total);
}

function pick(id: number, on: boolean): void {
    picked.value = on
        ? [...new Set([...picked.value, id])]
        : picked.value.filter((n) => n !== id);
}
</script>

<template>
    <section class="flex flex-col" aria-labelledby="containers-heading">
        <h2 id="containers-heading" class="sr-only">
            {{ $t('Containers') }}
        </h2>
        <div
            class="flex h-14 items-center gap-2 px-5 max-md:flex-wrap max-md:py-3 md:h-14"
        >
            <SearchWell
                v-model="search"
                :placeholder="$t('Search containers')"
                :width="300"
                class="max-sm:w-full!"
            />
            <FilterPills
                v-model="filter"
                :label="$t('Show')"
                :options="[
                    {
                        value: 'attention',
                        label: $t('Needs attention'),
                        count: containers.filter(needsAttention).length,
                    },
                    {
                        value: 'all',
                        label: $t('All'),
                        count: containers.length,
                    },
                ]"
            />
            <div class="flex-1" />
            <template v-if="canManage">
                <span
                    v-if="picked.length"
                    class="text-[12.5px]/4 font-medium tracking-[-0.005em] text-fg-secondary"
                    >{{
                        $t(':count selected', { count: String(picked.length) })
                    }}</span
                >
                <DashButton
                    v-if="picked.length"
                    :label="
                        picked.length === 1
                            ? $t('Update 1 container')
                            : $t('Update :count containers', {
                                  count: String(picked.length),
                              })
                    "
                    :loading="updating"
                    @click="update(picked)"
                />
                <DashButton
                    icon="update-fill"
                    :label="$t('Check registries now')"
                    :loading="checking"
                    @click="checkNow"
                />
                <span
                    class="h-[18px] w-[0.5px] shrink-0 bg-edge-control max-md:hidden"
                    aria-hidden="true"
                />
            </template>
            <span class="shrink-0 text-[12px]/4 text-fg-muted max-md:hidden">{{
                lastCheck
                    ? $t('Checked :when', { when: shortAgo(lastCheck) })
                    : $t('Not checked yet')
            }}</span>
        </div>

        <DashTable class="border-t-[0.5px] border-edge">
            <template #head>
                <DashTh icon="layers-fill" :width="250" first>
                    <template v-if="canManage" #lead>
                        <DashCheckbox
                            :model-value="allPicked"
                            :label="
                                $t('Pick every container with a newer image')
                            "
                            @update:model-value="
                                picked = allPicked
                                    ? []
                                    : updatable.map((row) => row.id)
                            "
                        />
                    </template>
                    {{ $t('Container') }}
                </DashTh>
                <DashTh icon="package-fill" :width="300">{{
                    $t('Image')
                }}</DashTh>
                <DashTh icon="update-fill" :width="230">{{
                    $t('Status')
                }}</DashTh>
                <DashTh icon="monitors-fill" :width="200">{{
                    $t('Vulnerabilities')
                }}</DashTh>
                <DashTh fill class="border-r-0!" />
            </template>
            <template v-for="row in shown" :key="row.id">
                <DashTr>
                    <DashTd first>
                        <template v-if="canManage" #lead>
                            <DashCheckbox
                                v-if="
                                    row.state === 'available' && !row.updateJob
                                "
                                :model-value="picked.includes(row.id)"
                                :label="
                                    $t('Pick :name', { name: row.container })
                                "
                                @update:model-value="
                                    (on: boolean) => pick(row.id, on)
                                "
                            />
                            <span v-else class="size-[15px] shrink-0" />
                        </template>
                        <span
                            class="truncate font-medium tracking-[-0.01em] text-fg-primary"
                            >{{ row.container }}</span
                        >
                        <span class="truncate text-[12px]/4 text-fg-tertiary">{{
                            row.machine
                        }}</span>
                    </DashTd>
                    <DashTd>
                        <span
                            class="truncate font-ui-mono text-[12px]/4 text-fg-secondary"
                            :title="row.image"
                            >{{ row.image }}</span
                        >
                    </DashTd>
                    <DashTd>
                        <TintBadge
                            v-if="row.updateJob"
                            tone="blue"
                            :icon="LoaderCircle"
                            spin
                            :label="$t('Updating')"
                        />
                        <template v-else-if="row.state === 'available'">
                            <TintBadge
                                tone="blue"
                                :icon="CircleArrowUp"
                                :label="$t('Newer image')"
                            />
                            <span
                                v-if="row.availableSince"
                                class="text-[12px]/4 text-fg-tertiary"
                                >{{ shortAgo(row.availableSince) }}</span
                            >
                        </template>
                        <TintBadge
                            v-else-if="row.state === 'current'"
                            tone="green"
                            :icon="CircleCheck"
                            :label="$t('Up to date')"
                        />
                        <TintBadge
                            v-else-if="row.state === 'local'"
                            tone="grey"
                            :icon="Hammer"
                            :label="$t('Built on the machine')"
                        />
                        <span
                            v-else
                            class="flex min-w-0 items-center gap-2"
                            :title="row.error ?? undefined"
                        >
                            <TintBadge
                                tone="amber"
                                :icon="CircleHelp"
                                :label="$t('Couldn’t check')"
                            />
                        </span>
                    </DashTd>
                    <DashTd>
                        <button
                            v-if="row.vulnerabilities.total"
                            type="button"
                            class="flex cursor-pointer items-center gap-2 rounded-[4px] focus-visible:outline-2 focus-visible:outline-(--ds-ring)"
                            :aria-expanded="open === row.id"
                            @click="toggleFindings(row)"
                        >
                            <span
                                class="size-1.5 shrink-0 rounded-full"
                                :style="{
                                    backgroundColor:
                                        severityDot[
                                            row.vulnerabilities.critical
                                                ? 'critical'
                                                : row.vulnerabilities.high
                                                  ? 'high'
                                                  : 'medium'
                                        ],
                                }"
                                aria-hidden="true"
                            />
                            <span class="font-medium text-fg-primary">{{
                                vulnLine(row)
                            }}</span>
                            <span
                                v-if="row.vulnerabilities.exploited"
                                class="flex h-[18px] items-center rounded-[4px] bg-[color-mix(in_srgb,var(--dash-down)_14%,transparent)] px-[5px] text-[11px]/[14px] font-medium text-status-down"
                                >{{ $t('Exploited') }}</span
                            >
                            <component
                                :is="
                                    open === row.id ? ChevronDown : ChevronRight
                                "
                                class="size-3.5 text-fg-muted"
                                :stroke-width="2"
                                aria-hidden="true"
                            />
                        </button>
                        <span v-else class="text-fg-faint">{{
                            vulnLine(row)
                        }}</span>
                    </DashTd>
                    <DashTd class="border-r-0! [&>div]:justify-end">
                        <span
                            v-if="row.state === 'unknown' && row.error"
                            class="max-w-80 truncate text-[12px]/4 text-fg-muted"
                            :title="row.error"
                            >{{ row.error }}</span
                        >
                        <DashButton
                            v-if="
                                canManage &&
                                row.state === 'available' &&
                                !row.updateJob
                            "
                            size="sm"
                            :label="$t('Update')"
                            :disabled="updating"
                            @click="update([row.id])"
                        />
                    </DashTd>
                </DashTr>
                <tr v-if="open === row.id" class="bg-surface-card-footer">
                    <td
                        colspan="5"
                        class="border-b-[0.5px] border-edge px-5 py-3 pl-[47px]"
                    >
                        <p
                            v-if="loadingFindings === row.id"
                            class="text-[12.5px] text-fg-muted"
                        >
                            {{ $t('Loading') }}
                        </p>
                        <template v-else>
                            <p
                                class="mb-2 text-[12px]/4 text-pretty text-fg-muted"
                            >
                                {{
                                    row.state === 'available'
                                        ? $t(
                                              'These are fixed in the packages of :image’s distribution. The newer image may already have them.',
                                              { image: row.image },
                                          )
                                        : $t(
                                              'These are fixed in the packages of :image’s distribution, but the image hasn’t been rebuilt with them yet.',
                                              { image: row.image },
                                          )
                                }}
                            </p>
                            <ul class="flex flex-col gap-1.5">
                                <li
                                    v-for="finding in findings[row.id] ?? []"
                                    :key="finding.cve + finding.package"
                                    class="flex flex-wrap items-center gap-x-3 gap-y-0.5 text-[12.5px]/5"
                                >
                                    <span
                                        class="size-1.5 shrink-0 rounded-full"
                                        :style="{
                                            backgroundColor:
                                                severityDot[finding.severity] ??
                                                severityDot.unknown,
                                        }"
                                        aria-hidden="true"
                                    />
                                    <a
                                        :href="finding.url"
                                        target="_blank"
                                        rel="noopener"
                                        class="rounded-sm font-ui-mono text-[12px] text-fg-primary underline decoration-fg-faint underline-offset-2 hover:decoration-current"
                                        >{{ finding.cve }}</a
                                    >
                                    <span
                                        :class="
                                            finding.exploited ||
                                            finding.severity === 'critical'
                                                ? 'text-status-down'
                                                : 'text-fg-tertiary'
                                        "
                                        >{{
                                            finding.exploited
                                                ? $t(
                                                      ':severity, being exploited',
                                                      {
                                                          severity:
                                                              finding.severityLabel,
                                                      },
                                                  )
                                                : finding.severityLabel
                                        }}</span
                                    >
                                    <span
                                        class="font-ui-mono text-[12px] text-fg-muted"
                                    >
                                        {{ finding.package }}
                                        {{ finding.installed }} →
                                        {{ finding.fixedIn }}
                                    </span>
                                    <span
                                        v-if="finding.title"
                                        class="min-w-0 truncate text-fg-tertiary"
                                        >{{ finding.title }}</span
                                    >
                                </li>
                            </ul>
                        </template>
                    </td>
                </tr>
            </template>
            <tr v-if="!shown.length">
                <td
                    colspan="5"
                    class="border-b-[0.5px] border-edge px-5 py-10 text-center text-[13px]/5 text-fg-muted"
                >
                    {{
                        search
                            ? $t('No containers match your search.')
                            : containers.length
                              ? $t(
                                    'Every container runs the newest image, with no known vulnerabilities.',
                                )
                              : $t(
                                    'No containers checked yet. Machines need ServerOS daemon 0.1.1-pre.49 or newer.',
                                )
                    }}
                </td>
            </tr>
        </DashTable>

        <p class="max-w-3xl px-5 py-4 text-[12px]/4 text-pretty text-fg-muted">
            {{
                $t(
                    'Each container’s registry is checked about once an hour for a newer image on the same tag. Updating pulls it and starts the container again with the same settings. If it doesn’t stay up, the old one is put back.',
                )
            }}
        </p>
    </section>
</template>
