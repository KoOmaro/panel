<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Check, Ellipsis } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import serverosMark from '@/components/dash/assets/serveros-mark.svg?raw';
import BrandLogo from '@/components/dash/BrandLogo.vue';
import DashAvatar from '@/components/dash/DashAvatar.vue';
import DashHeader from '@/components/dash/DashHeader.vue';
import DashTable from '@/components/dash/DashTable.vue';
import DashTd from '@/components/dash/DashTd.vue';
import DashTh from '@/components/dash/DashTh.vue';
import DashTr from '@/components/dash/DashTr.vue';
import SearchWell from '@/components/dash/SearchWell.vue';
import ToolbarButton from '@/components/dash/ToolbarButton.vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import type { FilledIconName } from '@/components/icons/filledIcons';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { onWorkspace } from '@/lib/workspaceRoute';
import activityRoutes from '@/routes/workspace/activity';
import machinesRoutes from '@/routes/workspace/machines';
import type { Workspace } from '@/types';

type Entry = {
    id: number;
    at: string;
    user: { name: string; avatar: string | null; support: boolean } | null;
    machine: { id: number; name: string; provider: string | null } | null;
    action: string;
    verb: string;
    subject: string | null;
    after: string | null;
    detail: string | null;
    mono: boolean;
    tone: 'neutral' | 'good' | 'warn' | 'bad';
    leads: boolean;
    via: {
        source: string | null;
        client: string | null;
        workflow: string | null;
    };
    origin: {
        source: string | null;
        client: string | null;
        device: string | null;
        ip: string | null;
        userAgent: string | null;
        requestId: string | null;
    } | null;
};

type Person = {
    value: string;
    name: string;
    avatar: string | null;
    left: boolean;
};

type When = 'today' | '7d' | '30d';

type Filters = {
    who: 'people' | 'system' | 'support' | null;
    person: string | null;
    group: string | null;
    machine: number | null;
    when: When | null;
    from: string | null;
    to: string | null;
    q: string | null;
};

const props = defineProps<{
    workspace: Workspace;
    machines: { id: number; name: string; provider: string | null }[];
    people: Person[];
    groups: { value: string; label: string }[];
    filters: Filters;
    entries: Entry[];
    hasMore: boolean;
    keepDays: number;
    canExport: boolean;
}>();

const entries = ref<Entry[]>([...props.entries]);
const more = ref(props.hasMore);
const loading = ref(false);
const search = ref(props.filters.q ?? '');
const custom = ref(props.filters.from !== null || props.filters.to !== null);

const activityUrl = computed(
    () => onWorkspace(activityRoutes.index(props.workspace.slug)).url,
);

watch(
    () => props.entries,
    (fresh) => {
        if (loading.value) {
            entries.value = [...entries.value, ...fresh];
            loading.value = false;
        } else {
            entries.value = [...fresh];
        }
        more.value = props.hasMore;
    },
);

function query(
    changes: Partial<Filters> & { before?: number },
): Record<string, string | number> {
    const next = { ...props.filters, ...changes };
    const out: Record<string, string | number> = {};
    for (const [key, value] of Object.entries(next)) {
        if (value !== null && value !== undefined && value !== '') {
            out[key] = value;
        }
    }
    if (next.when || next.from || next.to) {
        out.tz = Intl.DateTimeFormat().resolvedOptions().timeZone;
    }

    return out;
}

const filtered = computed(() =>
    Object.values(props.filters).some((value) => value !== null),
);

const exportHref = computed(() => {
    const params = new URLSearchParams(
        Object.entries(query({})).map(([k, v]) => [k, String(v)]),
    ).toString();
    const base = onWorkspace(activityRoutes.export(props.workspace.slug)).url;

    return params ? `${base}?${params}` : base;
});

function apply(changes: Partial<Filters>): void {
    router.get(activityUrl.value, query(changes), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['entries', 'hasMore', 'filters'],
    });
}

function clearFilters(): void {
    custom.value = false;
    search.value = '';
    apply({
        who: null,
        person: null,
        group: null,
        machine: null,
        when: null,
        from: null,
        to: null,
        q: null,
    });
}

let searchTimer: ReturnType<typeof setTimeout> | undefined;
watch(search, (value) => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        const q = value.trim() || null;
        if (q !== props.filters.q) apply({ q });
    }, 300);
});
onBeforeUnmount(() => clearTimeout(searchTimer));

function loadMore(): void {
    const last = entries.value.at(-1);
    if (!last || loading.value) return;
    loading.value = true;
    router.get(activityUrl.value, query({ before: last.id }), {
        preserveUrl: true,
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['entries', 'hasMore'],
        onError: () => (loading.value = false),
    });
}

const members = computed(() => props.people.filter((p) => !p.left));
const former = computed(() => props.people.filter((p) => p.left));

const whoLabel = computed(() => {
    const person = props.people.find((p) => p.value === props.filters.person);
    if (person) return person.name;

    return props.filters.who === 'people'
        ? trans('People')
        : props.filters.who === 'system'
          ? trans('Automatic')
          : props.filters.who === 'support'
            ? trans('ServerOS Support')
            : trans('Everyone');
});

const groupLabel = computed(
    () =>
        props.groups.find((g) => g.value === props.filters.group)?.label ??
        trans('Everything'),
);

const whenOptions = computed(
    () =>
        [
            { value: null, label: trans('Any time') },
            { value: 'today', label: trans('Today') },
            { value: '7d', label: trans('Last 7 days') },
            { value: '30d', label: trans('Last 30 days') },
        ] as const,
);

const whenLabel = computed(() =>
    custom.value
        ? trans('Custom range')
        : (whenOptions.value.find((o) => o.value === props.filters.when)
              ?.label ?? trans('Any time')),
);

const machineLabel = computed(
    () =>
        props.machines.find((m) => m.id === props.filters.machine)?.name ??
        trans('All machines'),
);

function chooseWhen(when: When | null): void {
    custom.value = false;
    apply({ when, from: null, to: null });
}

function chooseCustom(): void {
    custom.value = true;
    if (props.filters.when) apply({ when: null });
}

const rows = computed(() => {
    const out: (
        | { type: 'day'; key: string; label: string; count: number }
        | { type: 'entry'; key: number; entry: Entry }
    )[] = [];
    let previous = '';
    let day: { count: number } | null = null;
    const today = new Date().toDateString();
    const yesterday = new Date(Date.now() - 86_400_000).toDateString();
    const thisYear = new Date().getFullYear();

    for (const entry of entries.value) {
        const date = new Date(entry.at);
        const key = date.toDateString();
        if (key !== previous) {
            const row = {
                type: 'day' as const,
                key: `day-${entry.id}`,
                count: 0,
                label:
                    key === today
                        ? trans('Today')
                        : key === yesterday
                          ? trans('Yesterday')
                          : date.toLocaleDateString(undefined, {
                                weekday: 'long',
                                day: 'numeric',
                                month: 'long',
                                year:
                                    date.getFullYear() === thisYear
                                        ? undefined
                                        : 'numeric',
                            }),
            };
            out.push(row);
            day = row;
            previous = key;
        }
        if (day) day.count++;
        out.push({ type: 'entry', key: entry.id, entry });
    }

    return out;
});

const timeFormat = new Intl.DateTimeFormat(undefined, {
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
});
const fullFormat = new Intl.DateTimeFormat(undefined, {
    year: 'numeric',
    month: 'numeric',
    day: 'numeric',
    hour: 'numeric',
    minute: 'numeric',
    second: 'numeric',
});

const toneDot: Record<Entry['tone'], string> = {
    neutral: '',
    good: 'bg-status-live',
    warn: 'bg-status-pending',
    bad: 'bg-status-down',
};

function capital(text: string): string {
    return text.charAt(0).toUpperCase() + text.slice(1);
}

type Who =
    | { kind: 'person'; name: string; avatar: string | null }
    | { kind: 'mark'; name: string }
    | { kind: 'workflow'; name: string };

function who(entry: Entry): Who {
    if (entry.user?.support) return { kind: 'mark', name: entry.user.name };
    if (entry.user) {
        return {
            kind: 'person',
            name: entry.user.name,
            avatar: entry.user.avatar,
        };
    }
    if (entry.via.workflow) {
        return { kind: 'workflow', name: entry.via.workflow };
    }

    return { kind: 'mark', name: trans('Automatic') };
}

type From = {
    icon: FilledIconName | null;
    logo: string | null;
    label: string;
    extra: string | null;
};

function from(entry: Entry): From {
    const { source, client } = entry.via;
    const origin = entry.origin;
    const base = { icon: null, logo: null, extra: null };

    switch (source) {
        case 'support':
            return {
                ...base,
                icon: 'globe-fill',
                label: trans('Support access'),
            };
        case 'mcp':
            return {
                ...base,
                icon: client?.toLowerCase().includes('claude')
                    ? null
                    : 'sparkle-fill',
                logo: client?.toLowerCase().includes('claude')
                    ? 'claude'
                    : null,
                label: client
                    ? trans('MCP (:client)', { client })
                    : trans('MCP'),
            };
        case 'api':
            return {
                ...base,
                icon: 'terminal-fill',
                label: client
                    ? trans('API (:client)', { client })
                    : trans('API'),
            };
        case 'workflow':
            return { ...base, icon: 'branch-fill', label: trans('Workflow') };
        case 'machine':
            return { ...base, icon: 'machines-fill', label: trans('Machine') };
        case 'web':
            return {
                ...base,
                icon: 'globe-fill',
                label: origin?.device ?? trans('Web'),
                extra: origin?.ip ?? null,
            };
    }
    if (entry.action.startsWith('monitor.')) {
        return { ...base, icon: 'monitors-fill', label: trans('Monitor') };
    }
    if (entry.action.startsWith('backup')) {
        return {
            ...base,
            icon: 'activity-fill',
            label: trans('Backup schedule'),
        };
    }
    if (/^(service|machine)\./.test(entry.action)) {
        return {
            ...base,
            icon: 'activity-fill',
            label: trans('Health checks'),
        };
    }

    return { ...base, icon: 'activity-fill', label: trans('Automatic') };
}

function machineHref(id: number): string {
    return onWorkspace(
        machinesRoutes.show({ workspace: props.workspace.slug, machine: id }),
    ).url;
}

async function copy(text: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(text);
    } catch {
        return;
    }
}

const dateField =
    'h-8 cursor-text rounded-[8px] border-[0.5px] border-edge-control bg-surface-control px-2 font-ui text-[13px]/5 text-fg-secondary outline-none focus-visible:outline-2 focus-visible:outline-(--ds-ring) [color-scheme:dark]';
</script>

<template>
    <Head :title="$t('Activity')" />

    <DashHeader :crumbs="[{ label: $t('Activity') }]">
        <template #actions>
            <button
                v-if="filtered"
                type="button"
                class="h-8 cursor-pointer rounded-[8px] px-2.5 text-[13px]/5 font-medium text-fg-tertiary hover:text-fg-primary focus-visible:outline-2 focus-visible:outline-(--ds-ring)"
                @click="clearFilters"
            >
                {{ $t('Clear filters') }}
            </button>
            <a
                v-if="canExport"
                :href="exportHref"
                class="dash-button dash-button--secondary"
                :title="$t('Download what’s shown as a CSV')"
                download
            >
                <FilledIcon name="download" />
                {{ $t('Export CSV') }}
            </a>
        </template>
    </DashHeader>

    <div class="flex min-h-full flex-col">
        <div class="flex min-h-14 flex-wrap items-center gap-2 px-5 py-3">
            <SearchWell
                v-model="search"
                :placeholder="$t('Search activity, like “restarted” or “db-1”')"
                :width="340"
                class="max-sm:w-full!"
            />

            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <ToolbarButton
                        icon="user-fill"
                        :label="whoLabel"
                        menu
                        :active="
                            filters.who !== null || filters.person !== null
                        "
                    />
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    class="max-h-96 min-w-52 overflow-y-auto font-ui"
                    align="start"
                >
                    <DropdownMenuItem
                        v-for="option in [
                            { value: null, label: $t('Everyone') },
                            { value: 'people', label: $t('People') },
                            { value: 'system', label: $t('Automatic') },
                            { value: 'support', label: $t('ServerOS Support') },
                        ] as const"
                        :key="option.label"
                        @select="apply({ who: option.value, person: null })"
                    >
                        {{ option.label }}
                        <Check
                            v-if="
                                filters.who === option.value &&
                                filters.person === null
                            "
                            class="ml-auto size-3.5"
                        />
                    </DropdownMenuItem>
                    <template v-if="members.length">
                        <DropdownMenuSeparator />
                        <DropdownMenuLabel
                            class="text-xs font-medium text-fg-muted"
                            >{{ $t('Members') }}</DropdownMenuLabel
                        >
                        <DropdownMenuItem
                            v-for="person in members"
                            :key="person.value"
                            @select="apply({ who: null, person: person.value })"
                        >
                            <DashAvatar
                                :src="person.avatar"
                                :name="person.name"
                            />
                            <span class="truncate">{{ person.name }}</span>
                            <Check
                                v-if="filters.person === person.value"
                                class="ml-auto size-3.5"
                            />
                        </DropdownMenuItem>
                    </template>
                    <template v-if="former.length">
                        <DropdownMenuSeparator />
                        <DropdownMenuLabel
                            class="text-xs font-medium text-fg-muted"
                            >{{ $t('Former members') }}</DropdownMenuLabel
                        >
                        <DropdownMenuItem
                            v-for="person in former"
                            :key="person.value"
                            @select="apply({ who: null, person: person.value })"
                        >
                            <DashAvatar
                                :src="person.avatar"
                                :name="person.name"
                            />
                            <span class="truncate">{{
                                $t(':name (left)', { name: person.name })
                            }}</span>
                            <Check
                                v-if="filters.person === person.value"
                                class="ml-auto size-3.5"
                            />
                        </DropdownMenuItem>
                    </template>
                </DropdownMenuContent>
            </DropdownMenu>

            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <ToolbarButton
                        icon="layers-fill"
                        :label="groupLabel"
                        menu
                        :active="filters.group !== null"
                    />
                </DropdownMenuTrigger>
                <DropdownMenuContent class="min-w-52 font-ui" align="start">
                    <DropdownMenuItem @select="apply({ group: null })">
                        {{ $t('Everything') }}
                        <Check
                            v-if="filters.group === null"
                            class="ml-auto size-3.5"
                        />
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        v-for="group in groups"
                        :key="group.value"
                        @select="apply({ group: group.value })"
                    >
                        {{ group.label }}
                        <Check
                            v-if="filters.group === group.value"
                            class="ml-auto size-3.5"
                        />
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>

            <DropdownMenu v-if="machines.length > 1">
                <DropdownMenuTrigger as-child>
                    <ToolbarButton
                        icon="machines-fill"
                        :label="machineLabel"
                        menu
                        :active="filters.machine !== null"
                    />
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    class="max-h-80 min-w-44 overflow-y-auto font-ui"
                    align="start"
                >
                    <DropdownMenuItem @select="apply({ machine: null })">
                        {{ $t('All machines') }}
                        <Check
                            v-if="filters.machine === null"
                            class="ml-auto size-3.5"
                        />
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                        v-for="machine in machines"
                        :key="machine.id"
                        @select="apply({ machine: machine.id })"
                    >
                        <BrandLogo :name="machine.provider" />
                        {{ machine.name }}
                        <Check
                            v-if="filters.machine === machine.id"
                            class="ml-auto size-3.5"
                        />
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>

            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <ToolbarButton
                        icon="activity-fill"
                        :label="whenLabel"
                        menu
                        :active="custom || filters.when !== null"
                    />
                </DropdownMenuTrigger>
                <DropdownMenuContent class="min-w-44 font-ui" align="start">
                    <DropdownMenuItem
                        v-for="option in whenOptions"
                        :key="option.label"
                        @select="chooseWhen(option.value)"
                    >
                        {{ option.label }}
                        <Check
                            v-if="!custom && filters.when === option.value"
                            class="ml-auto size-3.5"
                        />
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem @select="chooseCustom">
                        {{ $t('Custom range…') }}
                        <Check v-if="custom" class="ml-auto size-3.5" />
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>

            <template v-if="custom">
                <label class="sr-only" for="activity-from">{{
                    $t('From')
                }}</label>
                <input
                    id="activity-from"
                    type="date"
                    :class="dateField"
                    :title="$t('From')"
                    :value="filters.from ?? ''"
                    :max="filters.to ?? undefined"
                    @change="
                        apply({
                            from:
                                ($event.target as HTMLInputElement).value ||
                                null,
                        })
                    "
                />
                <label class="sr-only" for="activity-to">{{ $t('To') }}</label>
                <input
                    id="activity-to"
                    type="date"
                    :class="dateField"
                    :title="$t('To')"
                    :value="filters.to ?? ''"
                    :min="filters.from ?? undefined"
                    @change="
                        apply({
                            to:
                                ($event.target as HTMLInputElement).value ||
                                null,
                        })
                    "
                />
            </template>

            <span class="min-w-0 flex-1" />
            <span class="text-[12px]/4 text-fg-muted">{{
                $t('Kept for :days days', { days: String(keepDays) })
            }}</span>
        </div>

        <div
            v-if="entries.length === 0"
            class="flex flex-1 flex-col items-center justify-center border-t-[0.5px] border-edge-tabs px-6 py-20 text-center"
        >
            <span
                class="flex size-10 items-center justify-center rounded-[10px] border-[0.5px] border-edge-control bg-surface-raised text-fg-tertiary shadow-tile"
                aria-hidden="true"
            >
                <FilledIcon name="activity-fill" :size="20" />
            </span>
            <h2
                class="mt-4 text-[15px]/6 font-medium tracking-[-0.01em] text-fg-primary"
            >
                {{ filtered ? $t('Nothing matches') : $t('Nothing yet') }}
            </h2>
            <p class="mt-1 max-w-sm text-[13px]/5 text-fg-tertiary">
                {{
                    $t(
                        'Deploys, restarts, console commands, settings changes, machines going quiet and monitors going down all show up here.',
                    )
                }}
            </p>
        </div>

        <template v-else>
            <DashTable class="border-t-[0.5px] border-edge-tabs">
                <template #head>
                    <DashTh icon="activity-fill" :width="84" first>{{
                        $t('When')
                    }}</DashTh>
                    <DashTh icon="user-fill" :width="200">{{
                        $t('Who')
                    }}</DashTh>
                    <DashTh icon="activity-fill" :width="398">{{
                        $t('What')
                    }}</DashTh>
                    <DashTh icon="machines-fill" :width="136">{{
                        $t('Machine')
                    }}</DashTh>
                    <DashTh icon="globe-fill" fill>{{ $t('From') }}</DashTh>
                </template>

                <template v-for="row in rows" :key="row.key">
                    <tr v-if="row.type === 'day'">
                        <td
                            colspan="5"
                            class="h-8 border-b-[0.5px] border-edge bg-surface-card-footer p-0 pl-5"
                        >
                            <span
                                class="flex items-center gap-2 text-[12.5px]/4"
                            >
                                <span
                                    class="font-medium tracking-[-0.005em] text-fg-primary"
                                    >{{ row.label }}</span
                                >
                                <span class="text-[12px] text-fg-faint">{{
                                    row.count === 1
                                        ? $t('1 event')
                                        : $t(':count events', {
                                              count: String(row.count),
                                          })
                                }}</span>
                            </span>
                        </td>
                    </tr>
                    <DashTr v-else>
                        <DashTd first>
                            <time
                                class="font-ui-mono text-[12px]/4 text-fg-tertiary"
                                :datetime="row.entry.at"
                                :title="
                                    fullFormat.format(new Date(row.entry.at))
                                "
                                >{{
                                    timeFormat.format(new Date(row.entry.at))
                                }}</time
                            >
                        </DashTd>
                        <DashTd>
                            <template
                                v-for="actor in [who(row.entry)]"
                                :key="actor.kind"
                            >
                                <DashAvatar
                                    v-if="actor.kind === 'person'"
                                    :src="actor.avatar"
                                    :name="actor.name"
                                />
                                <span
                                    v-else
                                    class="flex size-5 shrink-0 items-center justify-center rounded-[5px] bg-[#e9e9e9] text-fg-primary shadow-[0_1px_1.5px_rgb(0_0_0/0.45),inset_0_1px_0_rgb(255_255_255/0.05)] dark:bg-[#262626]"
                                    aria-hidden="true"
                                >
                                    <FilledIcon
                                        v-if="actor.kind === 'workflow'"
                                        name="branch-fill"
                                        :size="12"
                                        class="text-fg-tertiary"
                                    />
                                    <span
                                        v-else
                                        class="flex h-[13.5px] w-3 [&>svg]:size-full"
                                        v-html="serverosMark"
                                    />
                                </span>
                                <span
                                    class="max-w-[150px] truncate font-medium tracking-[-0.01em] text-fg-primary"
                                    :title="actor.name"
                                    >{{ actor.name }}</span
                                >
                            </template>
                        </DashTd>
                        <DashTd>
                            <span
                                class="flex max-w-[370px] min-w-0 items-center"
                                :title="row.entry.detail ?? undefined"
                            >
                                <span
                                    v-if="toneDot[row.entry.tone]"
                                    class="mr-2 size-1.5 shrink-0 rounded-full"
                                    :class="toneDot[row.entry.tone]"
                                    aria-hidden="true"
                                />
                                <span
                                    class="min-w-0 truncate tracking-[-0.005em] text-fg-tertiary"
                                >
                                    <template v-if="row.entry.leads">
                                        <span
                                            class="font-medium text-fg-primary"
                                            >{{ row.entry.subject }}</span
                                        >
                                        {{ ' ' + row.entry.verb }}
                                    </template>
                                    <template v-else>
                                        {{ capital(row.entry.verb) }}
                                        <span
                                            v-if="row.entry.subject"
                                            class="font-medium text-fg-primary"
                                            >{{ row.entry.subject }}</span
                                        >
                                    </template>
                                    <template v-if="row.entry.after">{{
                                        ` ${row.entry.after}`
                                    }}</template>
                                </span>
                                <span
                                    v-if="row.entry.detail"
                                    class="ml-2 flex h-[18px] max-w-[140px] min-w-0 shrink-0 items-center rounded-[4px] border-[0.5px] border-edge-control bg-surface-raised px-[5px] font-ui-mono text-[11px]/3.5 text-fg-tertiary"
                                >
                                    <span class="truncate">{{
                                        row.entry.detail
                                    }}</span>
                                </span>
                            </span>
                        </DashTd>
                        <DashTd>
                            <Link
                                v-if="row.entry.machine"
                                :href="machineHref(row.entry.machine.id)"
                                class="flex min-w-0 items-center gap-2 font-medium tracking-[-0.01em] hover:text-fg-primary"
                            >
                                <BrandLogo :name="row.entry.machine.provider" />
                                <span class="max-w-[90px] truncate">{{
                                    row.entry.machine.name
                                }}</span>
                            </Link>
                            <span v-else class="font-medium text-fg-faint"
                                >–</span
                            >
                        </DashTd>
                        <DashTd>
                            <template
                                v-for="source in [from(row.entry)]"
                                :key="source.label"
                            >
                                <span
                                    class="flex min-w-0 items-center gap-[7px]"
                                >
                                    <BrandLogo
                                        v-if="source.logo"
                                        :name="source.logo"
                                        :size="14"
                                    />
                                    <FilledIcon
                                        v-else-if="source.icon"
                                        :name="source.icon"
                                        :size="14"
                                        class="text-fg-muted"
                                    />
                                    <span
                                        class="truncate text-[12.5px]/4 font-medium tracking-[-0.005em]"
                                        :title="
                                            row.entry.origin?.userAgent ??
                                            undefined
                                        "
                                        >{{ source.label }}</span
                                    >
                                    <span
                                        v-if="source.extra"
                                        class="font-ui-mono text-[11.5px]/4 text-fg-faint"
                                        >{{ source.extra }}</span
                                    >
                                </span>
                            </template>
                            <span class="min-w-0 flex-1" />
                            <DropdownMenu v-if="row.entry.origin">
                                <DropdownMenuTrigger as-child>
                                    <button
                                        type="button"
                                        class="flex size-6 shrink-0 cursor-pointer items-center justify-center rounded-[6px] text-fg-muted hover:bg-surface-raised hover:text-fg-primary focus-visible:outline-2 focus-visible:outline-(--ds-ring) data-[state=open]:bg-surface-raised"
                                        :aria-label="$t('Where this came from')"
                                    >
                                        <Ellipsis class="size-4" />
                                    </button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent
                                    class="max-w-80 min-w-56 font-ui"
                                    align="end"
                                >
                                    <DropdownMenuLabel
                                        class="flex flex-col gap-0.5 text-[12px]/4 font-normal text-fg-tertiary"
                                    >
                                        <span
                                            class="font-medium text-fg-secondary"
                                            >{{
                                                fullFormat.format(
                                                    new Date(row.entry.at),
                                                )
                                            }}</span
                                        >
                                        <span
                                            v-if="row.entry.origin.userAgent"
                                            class="break-words"
                                            >{{
                                                row.entry.origin.userAgent
                                            }}</span
                                        >
                                        <span
                                            v-if="
                                                !row.entry.origin.userAgent &&
                                                !row.entry.origin.ip &&
                                                !row.entry.origin.requestId
                                            "
                                            >{{
                                                $t(
                                                    'Nothing recorded about where this came from.',
                                                )
                                            }}</span
                                        >
                                    </DropdownMenuLabel>
                                    <DropdownMenuSeparator
                                        v-if="
                                            row.entry.origin.ip ||
                                            row.entry.origin.requestId ||
                                            row.entry.machine
                                        "
                                    />
                                    <DropdownMenuItem
                                        v-if="row.entry.origin.ip"
                                        @select="copy(row.entry.origin.ip)"
                                    >
                                        {{
                                            $t('Copy IP address :ip', {
                                                ip: row.entry.origin.ip,
                                            })
                                        }}
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        v-if="row.entry.origin.requestId"
                                        @select="
                                            copy(row.entry.origin.requestId)
                                        "
                                    >
                                        {{
                                            $t('Copy reference :id', {
                                                id: row.entry.origin.requestId.slice(
                                                    0,
                                                    8,
                                                ),
                                            })
                                        }}
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        v-if="row.entry.machine"
                                        @select="
                                            router.visit(
                                                machineHref(
                                                    row.entry.machine.id,
                                                ),
                                            )
                                        "
                                    >
                                        {{
                                            $t('Open :name', {
                                                name: row.entry.machine.name,
                                            })
                                        }}
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </DashTd>
                    </DashTr>
                </template>
            </DashTable>

            <div v-if="more" class="flex justify-center py-3.5">
                <button
                    type="button"
                    class="dash-button dash-button--secondary"
                    :disabled="loading"
                    @click="loadMore"
                >
                    {{ loading ? $t('Loading…') : $t('Show older') }}
                </button>
            </div>
        </template>
    </div>
</template>
