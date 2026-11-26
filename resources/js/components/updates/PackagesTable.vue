<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    ChevronDown,
    ChevronRight,
    CircleArrowUp,
    Lock,
    RotateCw,
    ShieldAlert,
} from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';
import BrandLogo from '@/components/dash/BrandLogo.vue';
import DashCheckbox from '@/components/dash/DashCheckbox.vue';
import DashTable from '@/components/dash/DashTable.vue';
import DashTd from '@/components/dash/DashTd.vue';
import DashTh from '@/components/dash/DashTh.vue';
import DashTr from '@/components/dash/DashTr.vue';
import LogoStack from '@/components/dash/LogoStack.vue';
import TintBadge from '@/components/summary/TintBadge.vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { brandMark } from '@/lib/brandLogos';
import { workspacePath } from '@/lib/workspaceRoute';
import { machineOnline, severityDot, severityLabel } from './updates';
import type { UpdatesMachine, UpdatesPackage } from './updates';

const { packages, machines, canManage } = defineProps<{
    packages: UpdatesPackage[];
    machines: UpdatesMachine[];
    canManage: boolean;
}>();

const selected = defineModel<string[]>('selected', { required: true });

const byId = computed(
    () => new Map(machines.map((machine) => [machine.id, machine])),
);

const expanded = ref<string[]>([]);
const showAll = ref<string[]>([]);
const SHOWN = 3;

function toggle(name: string): void {
    expanded.value = expanded.value.includes(name)
        ? expanded.value.filter((n) => n !== name)
        : [...expanded.value, name];
}

function pick(name: string, on: boolean): void {
    selected.value = on
        ? [...new Set([...selected.value, name])]
        : selected.value.filter((n) => n !== name);
}

const pickable = computed(() =>
    packages.filter((p) => p.machineIds.length || p.heldOn.length),
);
const allPicked = computed(
    () =>
        pickable.value.length > 0 &&
        pickable.value.every((p) => selected.value.includes(p.name)),
);
const somePicked = computed(
    () =>
        !allPicked.value &&
        pickable.value.some((p) => selected.value.includes(p.name)),
);

function pickAll(on: boolean): void {
    selected.value = on ? pickable.value.map((p) => p.name) : [];
}

function distro(pkg: UpdatesPackage): string {
    const os = byId.value.get(pkg.machineIds[0] ?? pkg.heldOn[0])?.os ?? '';

    return /debian/i.test(os)
        ? 'debian'
        : /ubuntu/i.test(os)
          ? 'ubuntu'
          : 'linux';
}

function logo(pkg: UpdatesPackage): string {
    return brandMark(pkg.name) ? pkg.name : distro(pkg);
}

function providers(pkg: UpdatesPackage): string[] {
    return [
        ...new Set(
            pkg.machineIds
                .map((id) => byId.value.get(id)?.provider)
                .filter((p): p is string => !!p),
        ),
    ];
}

function machineCount(count: number): string {
    return count === 1
        ? trans('1 machine')
        : trans(':count machines', { count: String(count) });
}

function rows(pkg: UpdatesPackage) {
    const ids = [...new Set([...pkg.machineIds, ...pkg.heldOn])];
    const list = ids.map((id) => {
        const machine = byId.value.get(id);
        const on = pkg.on.find((row) => row.id === id);

        return {
            id,
            name: machine?.name ?? `#${id}`,
            provider: machine?.provider ?? null,
            online: machine ? machineOnline(machine) : false,
            installed: on?.installed ?? null,
            candidate: on?.candidate ?? null,
            held: pkg.heldOn.includes(id),
        };
    });

    return showAll.value.includes(pkg.name) ? list : list.slice(0, SHOWN);
}

function hidden(pkg: UpdatesPackage): number {
    if (showAll.value.includes(pkg.name)) return 0;

    return Math.max(
        0,
        new Set([...pkg.machineIds, ...pkg.heldOn]).size - SHOWN,
    );
}

function heldNote(pkg: UpdatesPackage): string {
    return pkg.heldOn.length === 1
        ? trans('Held on :name', {
              name: byId.value.get(pkg.heldOn[0])?.name ?? '',
          })
        : trans('Held on :count machines', {
              count: String(pkg.heldOn.length),
          });
}

function cveCount(count: number): string {
    return count === 1
        ? trans('1 CVE')
        : trans(':count CVEs', { count: String(count) });
}

function epss(value: number | null): string | null {
    if (value === null) return null;

    return trans(':value% chance of use in 30 days', {
        value: (value * 100).toFixed(value < 0.1 ? 1 : 0),
    });
}

const machineHref = (id: number) => workspacePath(`/machines/${id}`);
</script>

<template>
    <DashTable>
        <template #head>
            <DashTh icon="package-fill" :width="250" first>
                <template v-if="canManage" #lead>
                    <DashCheckbox
                        :model-value="allPicked"
                        :indeterminate="somePicked"
                        :label="$t('Select all packages')"
                        @update:model-value="pickAll"
                    />
                </template>
                {{ $t('Package') }}
            </DashTh>
            <DashTh icon="table-fill" :width="116">{{ $t('Kind') }}</DashTh>
            <DashTh icon="update-fill" :width="206">{{
                $t('New version')
            }}</DashTh>
            <DashTh icon="machines-fill" :width="196">{{
                $t('Machines')
            }}</DashTh>
            <DashTh icon="monitors-fill" :width="194">{{ $t('Fixes') }}</DashTh>
            <DashTh icon="activity-fill" fill class="border-r-0!">{{
                $t('Notes')
            }}</DashTh>
        </template>

        <template v-for="pkg in packages" :key="pkg.name">
            <DashTr
                clickable
                :class="{
                    'bg-[rgb(59_156_255/0.06)]': selected.includes(pkg.name),
                }"
                :aria-selected="selected.includes(pkg.name) || undefined"
                :aria-expanded="expanded.includes(pkg.name)"
                @click="toggle(pkg.name)"
            >
                <DashTd first>
                    <template v-if="canManage" #lead>
                        <DashCheckbox
                            :model-value="selected.includes(pkg.name)"
                            :label="$t('Select :name', { name: pkg.name })"
                            @update:model-value="
                                (on: boolean) => pick(pkg.name, on)
                            "
                        />
                    </template>
                    <span class="flex min-w-0 items-center gap-2">
                        <BrandLogo :name="logo(pkg)" />
                        <span
                            class="max-w-[150px] truncate font-medium tracking-[-0.01em] text-fg-primary"
                            :title="pkg.name"
                            >{{ pkg.name }}</span
                        >
                    </span>
                    <component
                        :is="
                            expanded.includes(pkg.name)
                                ? ChevronDown
                                : ChevronRight
                        "
                        class="ml-auto size-3.5 shrink-0 text-fg-muted"
                        :stroke-width="2"
                        aria-hidden="true"
                    />
                </DashTd>
                <DashTd>
                    <TintBadge
                        v-if="pkg.security"
                        tone="red"
                        :icon="ShieldAlert"
                        :label="$t('Security')"
                    />
                    <TintBadge
                        v-else
                        tone="grey"
                        :icon="CircleArrowUp"
                        :label="$t('Update')"
                    />
                </DashTd>
                <DashTd>
                    <span
                        v-if="pkg.versions.length"
                        class="max-w-[164px] truncate font-ui-mono text-[12px]/4 text-fg-secondary"
                        :title="pkg.versions[0].version"
                        >{{ pkg.versions[0].version }}</span
                    >
                    <span
                        v-if="pkg.versions.length > 1"
                        class="shrink-0 font-ui-mono text-[11.5px]/4 text-fg-faint"
                        :title="
                            pkg.versions
                                .map(
                                    (v) =>
                                        `${v.version} (${v.os ?? v.release})`,
                                )
                                .join('\n')
                        "
                        >+{{ pkg.versions.length - 1 }}</span
                    >
                    <span
                        v-if="!pkg.versions.length"
                        class="text-[13px]/5 text-fg-faint"
                        >{{ $t('Nothing waiting') }}</span
                    >
                </DashTd>
                <DashTd>
                    <LogoStack :names="providers(pkg)" />
                    <span class="font-medium tracking-[-0.01em]">{{
                        machineCount(pkg.machineIds.length)
                    }}</span>
                </DashTd>
                <DashTd>
                    <template v-if="pkg.fixes">
                        <span
                            class="size-1.5 shrink-0 rounded-full"
                            :style="{
                                backgroundColor:
                                    severityDot[pkg.fixes.severity],
                            }"
                            aria-hidden="true"
                        />
                        <span
                            class="font-medium tracking-[-0.01em] text-fg-primary"
                            >{{ severityLabel(pkg.fixes.severity) }}</span
                        >
                        <DropdownMenu>
                            <DropdownMenuTrigger as-child>
                                <button
                                    type="button"
                                    class="cursor-pointer rounded-[4px] text-fg-tertiary underline decoration-transparent underline-offset-2 transition-colors hover:text-fg-secondary hover:decoration-current focus-visible:outline-2 focus-visible:outline-(--ds-ring)"
                                    @click.stop
                                >
                                    {{ cveCount(pkg.fixes.count) }}
                                </button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent
                                align="start"
                                class="w-80 font-ui"
                                @click.stop
                            >
                                <DropdownMenuLabel
                                    class="text-[12px] font-medium text-fg-muted"
                                >
                                    {{
                                        $t('Fixed by updating :name', {
                                            name: pkg.name,
                                        })
                                    }}
                                </DropdownMenuLabel>
                                <DropdownMenuItem
                                    v-for="cve in pkg.fixes.cves"
                                    :key="cve.cve"
                                    as-child
                                >
                                    <a
                                        :href="cve.url"
                                        target="_blank"
                                        rel="noopener"
                                        class="flex flex-col items-start gap-0.5"
                                    >
                                        <span
                                            class="flex w-full items-center gap-2"
                                        >
                                            <span
                                                class="size-1.5 shrink-0 rounded-full"
                                                :style="{
                                                    backgroundColor:
                                                        severityDot[
                                                            cve.severity
                                                        ],
                                                }"
                                            />
                                            <span
                                                class="font-ui-mono text-[12px] text-fg-primary"
                                                >{{ cve.cve }}</span
                                            >
                                            <span
                                                class="text-[12px] text-fg-tertiary"
                                                >{{
                                                    cve.cvss
                                                        ? `${severityLabel(cve.severity)} ${cve.cvss.toFixed(1)}`
                                                        : severityLabel(
                                                              cve.severity,
                                                          )
                                                }}</span
                                            >
                                            <span
                                                v-if="cve.exploited"
                                                class="ml-auto rounded-[4px] bg-[color-mix(in_srgb,var(--dash-down)_14%,transparent)] px-[5px] text-[11px]/[18px] font-medium text-status-down"
                                                >{{ $t('Exploited') }}</span
                                            >
                                        </span>
                                        <span
                                            v-if="cve.title"
                                            class="line-clamp-2 pl-3.5 text-[12px]/4 text-fg-secondary"
                                            >{{ cve.title }}</span
                                        >
                                        <span
                                            v-if="epss(cve.epss)"
                                            class="pl-3.5 text-[11.5px]/4 text-fg-muted"
                                            >{{ epss(cve.epss) }}</span
                                        >
                                    </a>
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                        <span
                            v-if="pkg.fixes.exploited"
                            class="flex h-[18px] shrink-0 items-center rounded-[4px] bg-[color-mix(in_srgb,var(--dash-down)_14%,transparent)] px-[5px] text-[11px]/[14px] font-medium tracking-[-0.01em] text-status-down"
                            :title="
                                $t(
                                    'On CISA’s list of vulnerabilities attackers are using now',
                                )
                            "
                            >{{ $t('Exploited') }}</span
                        >
                    </template>
                    <span v-else class="font-medium text-fg-faint">–</span>
                </DashTd>
                <DashTd class="border-r-0!">
                    <template v-if="pkg.restart">
                        <RotateCw
                            class="size-[13px] shrink-0 text-status-pending"
                            :stroke-width="2.2"
                            aria-hidden="true"
                        />
                        <span
                            class="font-medium tracking-[-0.01em] text-status-pending"
                            >{{ $t('Restart needed') }}</span
                        >
                    </template>
                    <template v-else-if="pkg.heldOn.length">
                        <Lock
                            class="size-[13px] shrink-0 text-fg-tertiary"
                            :stroke-width="2.2"
                            aria-hidden="true"
                        />
                        <span
                            class="font-medium tracking-[-0.01em] text-fg-tertiary"
                            >{{ heldNote(pkg) }}</span
                        >
                    </template>
                    <span v-else class="font-medium text-fg-faint">–</span>
                </DashTd>
            </DashTr>

            <template v-if="expanded.includes(pkg.name)">
                <tr
                    v-for="row in rows(pkg)"
                    :key="`${pkg.name}-${row.id}`"
                    class="bg-surface-card-footer"
                >
                    <DashTd :height="36" class="[&>div]:pl-[47px]!">
                        <span class="flex min-w-0 items-center gap-3">
                            <BrandLogo :name="row.provider" :size="14" />
                            <Link
                                :href="machineHref(row.id)"
                                class="truncate font-medium tracking-[-0.01em] text-fg-secondary hover:text-fg-primary hover:underline hover:underline-offset-2"
                                >{{ row.name }}</Link
                            >
                            <span
                                v-if="row.provider"
                                class="truncate text-[12px]/4 tracking-[-0.005em] text-fg-tertiary"
                                >{{ row.provider }}</span
                            >
                        </span>
                    </DashTd>
                    <DashTd :height="36" />
                    <DashTd :height="36">
                        <span
                            v-if="row.installed"
                            class="max-w-[180px] truncate font-ui-mono text-[12px]/4 text-fg-faint"
                            >{{
                                row.candidate &&
                                pkg.versions.length > 1 &&
                                row.candidate !== pkg.versions[0].version
                                    ? $t('from :from to :to', {
                                          from: row.installed,
                                          to: row.candidate,
                                      })
                                    : $t('from :version', {
                                          version: row.installed,
                                      })
                            }}</span
                        >
                    </DashTd>
                    <DashTd :height="36">
                        <span
                            class="size-1.5 shrink-0 rounded-full"
                            :class="
                                row.online ? 'bg-status-live' : 'bg-fg-muted'
                            "
                            aria-hidden="true"
                        />
                        <span
                            class="text-[12px]/4 tracking-[-0.005em] text-fg-tertiary"
                            >{{
                                row.online ? $t('Online') : $t('Offline')
                            }}</span
                        >
                    </DashTd>
                    <DashTd :height="36" />
                    <DashTd :height="36" class="border-r-0!">
                        <span
                            v-if="row.held"
                            class="flex items-center gap-1.5 text-[12px]/4 text-fg-tertiary"
                        >
                            <Lock
                                class="size-3"
                                :stroke-width="2.2"
                                aria-hidden="true"
                            />
                            {{ $t('Held') }}
                        </span>
                    </DashTd>
                </tr>
                <tr v-if="hidden(pkg)" class="bg-surface-card-footer">
                    <DashTd :height="32" class="[&>div]:pl-[47px]!">
                        <button
                            type="button"
                            class="cursor-pointer text-[12px]/4 font-medium tracking-[-0.005em] text-fg-tertiary hover:text-fg-secondary focus-visible:outline-2 focus-visible:outline-(--ds-ring)"
                            @click="showAll = [...showAll, pkg.name]"
                        >
                            {{
                                hidden(pkg) === 1
                                    ? $t('1 more machine')
                                    : $t(':count more machines', {
                                          count: String(hidden(pkg)),
                                      })
                            }}
                        </button>
                    </DashTd>
                    <DashTd :height="32" />
                    <DashTd :height="32" />
                    <DashTd :height="32" />
                    <DashTd :height="32" />
                    <DashTd :height="32" class="border-r-0!" />
                </tr>
            </template>
        </template>
    </DashTable>
</template>
