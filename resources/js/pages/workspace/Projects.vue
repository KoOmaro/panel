<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { Head, router, usePage } from '@inertiajs/vue3';
import { Check, Plus, Trash2 } from '@lucide/vue';
import { computed, onMounted, ref, watch } from 'vue';
import BrandLogo from '@/components/dash/BrandLogo.vue';
import DashAvatar from '@/components/dash/DashAvatar.vue';
import DashButton from '@/components/dash/DashButton.vue';
import DashCheckbox from '@/components/dash/DashCheckbox.vue';
import DashHeader from '@/components/dash/DashHeader.vue';
import DashTab from '@/components/dash/DashTab.vue';
import DashTable from '@/components/dash/DashTable.vue';
import DashTabs from '@/components/dash/DashTabs.vue';
import DashTd from '@/components/dash/DashTd.vue';
import DashTh from '@/components/dash/DashTh.vue';
import DashTr from '@/components/dash/DashTr.vue';
import EmptyArt from '@/components/dash/EmptyArt.vue';
import LogoStack from '@/components/dash/LogoStack.vue';
import SearchWell from '@/components/dash/SearchWell.vue';
import StatusBadge from '@/components/dash/StatusBadge.vue';
import ToolbarButton from '@/components/dash/ToolbarButton.vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import { ago, logoName, stackLabel } from '@/components/projects/format';
import type { DashStatus, Summary } from '@/components/projects/types';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { toast } from '@/lib/toast';
import { onWorkspace } from '@/lib/workspaceRoute';
import machineRoutes from '@/routes/workspace/machines';
import projectRoutes from '@/routes/workspace/projects';
import type { Workspace, WorkspaceAccess } from '@/types';

type EnvironmentRow = Summary & {
    id: number | null;
    name: string;
    preview: boolean;
};
type ProjectRow = {
    uid: string;
    name: string;
    network: 'none' | 'pending' | 'up' | 'failed';
    createdAt: string | null;
    environments: EnvironmentRow[];
};
type Row = { project: ProjectRow; env: EnvironmentRow };
type SortKey = 'newest' | 'updated' | 'name' | 'status';

const props = defineProps<{
    workspace: Workspace;
    projects: ProjectRow[];
}>();

const page = usePage<{ workspaceAccess: WorkspaceAccess | null }>();
const canManage = computed(
    () =>
        page.props.workspaceAccess?.permissions.includes('manage-machines') ??
        false,
);
const connectUrl = computed(
    () =>
        onWorkspace(
            machineRoutes.index(props.workspace.slug, {
                query: { connect: 1 },
            }),
        ).url,
);

function projectUrl(row: Row): string {
    const first = row.project.environments[0];

    return onWorkspace(
        projectRoutes.show(
            { workspace: props.workspace.slug, project: row.project.uid },
            row.env.id !== null && row.env.id !== first?.id
                ? { query: { env: row.env.id } }
                : undefined,
        ),
    ).url;
}

const tabs = computed(() => {
    const names: string[] = [];
    for (const project of props.projects) {
        for (const env of project.environments) {
            if (!env.preview && !names.includes(env.name)) names.push(env.name);
        }
    }

    return names;
});
const tab = ref<string | null>(null);
const query = ref('');
const statuses = ref<DashStatus[]>([]);
const machineFilter = ref<string[]>([]);
const sort = ref<SortKey>('newest');

const statusOptions: { value: DashStatus; label: string }[] = [
    { value: 'live', label: 'Live' },
    { value: 'deploying', label: 'Deploying' },
    { value: 'pending', label: 'Pending' },
    { value: 'down', label: 'Down' },
    { value: 'stopped', label: 'Stopped' },
];
const sortOptions: { value: SortKey; label: string }[] = [
    { value: 'newest', label: 'Newest first' },
    { value: 'updated', label: 'Last deployed' },
    { value: 'name', label: 'Name' },
    { value: 'status', label: 'Status' },
];
const statusOrder: DashStatus[] = [
    'down',
    'deploying',
    'pending',
    'stopped',
    'live',
];

const tabRows = computed<Row[]>(() =>
    props.projects.flatMap((project) => {
        const env =
            tab.value === null
                ? project.environments[0]
                : project.environments.find((e) => e.name === tab.value);

        return env ? [{ project, env }] : [];
    }),
);

const machineOptions = computed(() =>
    [
        ...new Set(
            tabRows.value.flatMap((r) =>
                r.env.machine ? [r.env.machine.name] : [],
            ),
        ),
    ].sort(),
);

const rows = computed<Row[]>(() => {
    const needle = query.value.trim().toLowerCase();
    const shown = tabRows.value.filter(({ project, env }) => {
        if (
            needle &&
            ![project.name, env.domain, env.machine?.name, env.kind?.name]
                .filter(Boolean)
                .some((text) => String(text).toLowerCase().includes(needle))
        ) {
            return false;
        }
        if (
            statuses.value.length &&
            !(env.state && statuses.value.includes(env.state))
        ) {
            return false;
        }

        return !(
            machineFilter.value.length &&
            !(env.machine && machineFilter.value.includes(env.machine.name))
        );
    });

    if (sort.value === 'newest') return shown;

    return [...shown].sort((a, b) => {
        if (sort.value === 'name') {
            return a.project.name.localeCompare(b.project.name);
        }
        if (sort.value === 'status') {
            return (
                statusOrder.indexOf(a.env.state ?? 'pending') -
                    statusOrder.indexOf(b.env.state ?? 'pending') ||
                a.project.name.localeCompare(b.project.name)
            );
        }

        return (
            new Date(b.env.at ?? b.project.createdAt ?? 0).getTime() -
            new Date(a.env.at ?? a.project.createdAt ?? 0).getTime()
        );
    });
});

const filtering = computed(
    () => statuses.value.length + machineFilter.value.length,
);

function toggle<T>(list: T[], value: T): T[] {
    return list.includes(value)
        ? list.filter((v) => v !== value)
        : [...list, value];
}

const picked = ref<Record<string, boolean>>({});
const pickedUids = computed(() =>
    Object.entries(picked.value)
        .filter(([uid, on]) => on && props.projects.some((p) => p.uid === uid))
        .map(([uid]) => uid),
);
const allPicked = computed({
    get: () =>
        rows.value.length > 0 &&
        rows.value.every((r) => picked.value[r.project.uid]),
    set: (on: boolean) => {
        picked.value = on
            ? Object.fromEntries(rows.value.map((r) => [r.project.uid, true]))
            : {};
    },
});
const somePicked = computed(
    () => pickedUids.value.length > 0 && !allPicked.value,
);

watch(
    () => props.projects,
    () => {
        picked.value = Object.fromEntries(
            Object.entries(picked.value).filter(([uid]) =>
                props.projects.some((p) => p.uid === uid),
            ),
        );
    },
);

const creating = ref(false);
const name = ref('');
const saving = ref(false);

onMounted(() => {
    if (
        new URLSearchParams(window.location.search).has('new') &&
        canManage.value
    ) {
        creating.value = true;
    }
});

function create(): void {
    if (!name.value.trim()) return;
    saving.value = true;
    router.post(
        onWorkspace(projectRoutes.store(props.workspace.slug)).url,
        { name: name.value },
        {
            onError: (errors) =>
                toast.error(
                    Object.values(errors)[0] ?? trans('Couldn’t create it.'),
                ),
            onFinish: () => (saving.value = false),
        },
    );
}

const removing = ref(false);
const removingBusy = ref(false);

function removePicked(): void {
    const queue = [...pickedUids.value];
    removingBusy.value = true;
    const next = (): void => {
        const uid = queue.shift();
        if (!uid) {
            removingBusy.value = false;
            removing.value = false;
            picked.value = {};

            return;
        }
        router.delete(
            onWorkspace(
                projectRoutes.destroy({
                    workspace: props.workspace.slug,
                    project: uid,
                }),
            ).url,
            {
                preserveScroll: true,
                onError: (errors) => {
                    queue.length = 0;
                    toast.error(
                        Object.values(errors)[0] ?? trans('That didn’t work.'),
                    );
                },
                onFinish: next,
            },
        );
    };
    next();
}

function stackText(env: EnvironmentRow): string {
    const labels = env.stack.map(stackLabel);
    const unique = [...new Set(labels)];
    if (unique.length <= 2) return unique.join(', ');

    return trans(':items +:more', {
        items: unique.slice(0, 2).join(', '),
        more: String(unique.length - 2),
    });
}

function stackLogos(env: EnvironmentRow): string[] {
    return env.stack.map((item) =>
        logoName(
            null,
            item.template,
            null,
            `${item.label ?? ''} ${item.name}`.trim(),
        ),
    );
}

function projectLogo(row: Row): string {
    const env = row.env.kind ? row.env : row.project.environments[0];

    return logoName(env?.kind ?? null, null, env?.image ?? null);
}

function soon(): void {
    toast.info(trans('Saved views are coming soon.'));
}

const countLabel = computed(() => {
    const count = rows.value.length;

    return count === 1
        ? trans('1 project')
        : trans(':count projects', { count: String(count) });
});
</script>

<template>
    <Head :title="$t('Projects')" />

    <DashHeader :crumbs="[{ label: $t('Projects') }]">
        <template v-if="canManage" #actions>
            <DashButton
                icon="plug"
                :label="$t('Connect machine')"
                :href="connectUrl"
            />
            <DashButton
                variant="primary"
                :icon="Plus"
                :label="$t('New project')"
                @click="creating = true"
            />
        </template>
    </DashHeader>

    <div class="flex min-h-full flex-col">
        <DashTabs>
            <DashTab
                :label="$t('All projects')"
                icon="table-fill"
                :active="tab === null"
                @click="tab = null"
            />
            <DashTab
                v-for="env in tabs"
                :key="env"
                :label="env"
                icon="branch-fill"
                :active="tab === env"
                @click="tab = env"
            />
            <template #after>
                <button
                    type="button"
                    class="flex size-6 cursor-pointer items-center justify-center rounded-[6px] text-fg-muted transition-colors hover:text-fg-secondary focus-visible:outline-2 focus-visible:outline-(--ds-ring)"
                    :aria-label="$t('Add a view')"
                    @click="soon"
                >
                    <FilledIcon name="plus-fill" />
                </button>
            </template>
        </DashTabs>

        <div
            v-if="projects.length"
            class="flex h-14 shrink-0 items-center gap-2 px-5"
        >
            <SearchWell
                v-model="query"
                class="w-full max-w-[300px]"
                :placeholder="$t('Search by name, domain…')"
            />
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <ToolbarButton
                        icon="filter-fill"
                        :active="filtering > 0"
                        :label="
                            filtering
                                ? $t('Filter · :count', {
                                      count: String(filtering),
                                  })
                                : $t('Filter')
                        "
                    />
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    class="ds-modal min-w-52 font-ui"
                    align="start"
                >
                    <DropdownMenuLabel
                        class="text-xs font-normal text-fg-muted"
                        >{{ $t('Status') }}</DropdownMenuLabel
                    >
                    <DropdownMenuItem
                        v-for="option in statusOptions"
                        :key="option.value"
                        role="menuitemcheckbox"
                        :aria-checked="statuses.includes(option.value)"
                        @select.prevent="
                            statuses = toggle(statuses, option.value)
                        "
                    >
                        <Check
                            class="size-4"
                            :class="
                                statuses.includes(option.value)
                                    ? 'opacity-100'
                                    : 'opacity-0'
                            "
                        />
                        {{ $t(option.label) }}
                    </DropdownMenuItem>
                    <template v-if="machineOptions.length">
                        <DropdownMenuSeparator />
                        <DropdownMenuLabel
                            class="text-xs font-normal text-fg-muted"
                            >{{ $t('Machine') }}</DropdownMenuLabel
                        >
                        <DropdownMenuItem
                            v-for="machine in machineOptions"
                            :key="machine"
                            role="menuitemcheckbox"
                            :aria-checked="machineFilter.includes(machine)"
                            @select.prevent="
                                machineFilter = toggle(machineFilter, machine)
                            "
                        >
                            <Check
                                class="size-4"
                                :class="
                                    machineFilter.includes(machine)
                                        ? 'opacity-100'
                                        : 'opacity-0'
                                "
                            />
                            {{ machine }}
                        </DropdownMenuItem>
                    </template>
                    <template v-if="filtering">
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            @select="
                                () => {
                                    statuses = [];
                                    machineFilter = [];
                                }
                            "
                        >
                            {{ $t('Clear filters') }}
                        </DropdownMenuItem>
                    </template>
                </DropdownMenuContent>
            </DropdownMenu>
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <ToolbarButton
                        icon="sort-fill"
                        :active="sort !== 'newest'"
                        :label="$t('Sort by')"
                    />
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    class="ds-modal min-w-44 font-ui"
                    align="start"
                >
                    <DropdownMenuItem
                        v-for="option in sortOptions"
                        :key="option.value"
                        role="menuitemradio"
                        :aria-checked="sort === option.value"
                        @select="sort = option.value"
                    >
                        <Check
                            class="size-4"
                            :class="
                                sort === option.value
                                    ? 'opacity-100'
                                    : 'opacity-0'
                            "
                        />
                        {{ $t(option.label) }}
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
            <span class="grow" />
            <template v-if="pickedUids.length && canManage">
                <span class="text-[12px]/4 whitespace-nowrap text-fg-muted">{{
                    pickedUids.length === 1
                        ? $t('1 selected')
                        : $t(':count selected', {
                              count: String(pickedUids.length),
                          })
                }}</span>
                <DashButton
                    size="sm"
                    :icon="Trash2"
                    :label="$t('Delete')"
                    @click="removing = true"
                />
            </template>
            <span
                v-else
                class="hidden text-[12px]/4 whitespace-nowrap text-fg-muted sm:block"
                >{{ countLabel }}</span
            >
        </div>

        <div
            v-if="!projects.length"
            class="flex grow flex-col items-center justify-center border-t-[0.5px] border-edge-tabs px-6 pt-10 pb-16 text-center"
        >
            <EmptyArt kind="project-graph" />
            <h2
                class="mt-6 text-[15px]/6 font-medium tracking-[-0.01em] text-fg-primary"
            >
                {{ $t('Put an app and its services together') }}
            </h2>
            <p class="mt-1 max-w-md text-[13px]/5 text-pretty text-fg-tertiary">
                {{
                    $t(
                        'Add an app you’ve deployed, then a database, a cache or storage. They can sit on different machines and still reach each other privately.',
                    )
                }}
            </p>
            <DashButton
                v-if="canManage"
                class="mt-5"
                :icon="Plus"
                :label="$t('New project')"
                @click="creating = true"
            />
        </div>

        <div v-else class="border-t-[0.5px] border-edge-tabs">
            <DashTable>
                <template #head>
                    <DashTh icon="projects-fill" :width="224" first>
                        <template v-if="canManage" #lead>
                            <DashCheckbox
                                v-model="allPicked"
                                :indeterminate="somePicked"
                                :label="$t('Select all')"
                            />
                        </template>
                        {{ $t('Project') }}
                        <template v-if="canManage" #trail>
                            <button
                                type="button"
                                class="flex cursor-pointer text-fg-muted transition-colors hover:text-fg-secondary"
                                :aria-label="$t('New project')"
                                @click="creating = true"
                            >
                                <FilledIcon name="plus-fill" />
                            </button>
                        </template>
                    </DashTh>
                    <DashTh icon="layers-fill" :width="216">{{
                        $t('Stack')
                    }}</DashTh>
                    <DashTh icon="machines-fill" :width="176">{{
                        $t('Machine')
                    }}</DashTh>
                    <DashTh icon="globe-fill" :width="168">{{
                        $t('Domain')
                    }}</DashTh>
                    <DashTh icon="monitors-fill" :width="132">{{
                        $t('Status')
                    }}</DashTh>
                    <DashTh icon="user-fill" :width="160">{{
                        $t('Deployed by')
                    }}</DashTh>
                    <DashTh icon="activity-fill" fill>{{
                        $t('Updated')
                    }}</DashTh>
                </template>

                <DashTr
                    v-for="row in rows"
                    :key="`${row.project.uid}-${row.env.id}`"
                    clickable
                    :selected="!!picked[row.project.uid]"
                    @click="router.visit(projectUrl(row))"
                >
                    <DashTd first>
                        <template v-if="canManage" #lead>
                            <span class="flex" @click.stop>
                                <DashCheckbox
                                    v-model="picked[row.project.uid]"
                                    :label="
                                        $t('Select :name', {
                                            name: row.project.name,
                                        })
                                    "
                                />
                            </span>
                        </template>
                        <span class="flex min-w-0 items-center gap-2">
                            <BrandLogo :name="projectLogo(row)" />
                            <a
                                :href="projectUrl(row)"
                                class="truncate font-medium tracking-[-0.01em] text-fg-primary focus-visible:outline-2 focus-visible:outline-(--ds-ring)"
                                @click.prevent
                                >{{ row.project.name }}</a
                            >
                        </span>
                    </DashTd>
                    <DashTd>
                        <template v-if="row.env.stack.length">
                            <LogoStack :names="stackLogos(row.env)" />
                            <span
                                class="truncate font-medium tracking-[-0.01em]"
                                >{{ stackText(row.env) }}</span
                            >
                        </template>
                        <span v-else class="text-fg-faint">–</span>
                    </DashTd>
                    <DashTd>
                        <template v-if="row.env.machine">
                            <BrandLogo
                                :name="row.env.machine.provider"
                                :fallback="false"
                            />
                            <span
                                class="truncate font-medium tracking-[-0.01em]"
                                >{{ row.env.machine.name }}</span
                            >
                            <span
                                v-if="row.env.machine.provider"
                                class="truncate text-[12px]/4 tracking-[-0.005em] text-fg-tertiary"
                                >{{ row.env.machine.provider }}</span
                            >
                        </template>
                        <span v-else class="text-fg-faint">–</span>
                    </DashTd>
                    <DashTd>
                        <span v-if="row.env.domain" class="truncate">{{
                            row.env.domain
                        }}</span>
                        <span v-else class="text-fg-faint">–</span>
                    </DashTd>
                    <DashTd>
                        <StatusBadge
                            v-if="row.env.state"
                            :status="row.env.state"
                        />
                        <span v-else class="text-fg-faint">–</span>
                    </DashTd>
                    <DashTd>
                        <template v-if="row.env.by">
                            <DashAvatar
                                :src="row.env.by.avatar"
                                :name="row.env.by.name"
                            />
                            <span
                                class="truncate font-medium tracking-[-0.01em]"
                                >{{ row.env.by.name }}</span
                            >
                        </template>
                        <span v-else class="text-fg-faint">–</span>
                    </DashTd>
                    <DashTd>
                        <span class="text-fg-muted">{{
                            ago(row.env.at ?? row.project.createdAt)
                        }}</span>
                    </DashTd>
                </DashTr>

                <tr v-if="!rows.length">
                    <td colspan="7" class="px-5 py-10 text-center">
                        <p class="text-[13px]/5 text-fg-tertiary">
                            {{
                                tab && !tabRows.length
                                    ? $t('No project has :name yet.', {
                                          name: tab,
                                      })
                                    : $t('No projects match.')
                            }}
                        </p>
                    </td>
                </tr>
            </DashTable>
        </div>

        <Dialog v-model:open="creating">
            <DialogContent
                class="workspace-panel ds-modal gap-0 p-0 font-ui text-foreground sm:max-w-md"
            >
                <form class="flex flex-col gap-4 p-5" @submit.prevent="create">
                    <div class="flex flex-col gap-1">
                        <DialogTitle class="text-base/6 font-semibold">{{
                            $t('New project')
                        }}</DialogTitle>
                        <DialogDescription
                            class="text-[13px]/5 text-(--ds-text-muted)"
                        >
                            {{
                                $t(
                                    'Name it after the app, like Shop or Shop staging. You add what’s in it next.',
                                )
                            }}
                        </DialogDescription>
                    </div>
                    <Input
                        v-model="name"
                        maxlength="80"
                        required
                        autofocus
                        :placeholder="$t('Shop')"
                        :aria-label="$t('Project name')"
                    />
                    <div class="flex justify-end gap-2">
                        <DashButton
                            :label="$t('Cancel')"
                            @click="creating = false"
                        />
                        <DashButton
                            type="submit"
                            variant="primary"
                            :label="$t('Create project')"
                            :disabled="saving || !name.trim()"
                        />
                    </div>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="removing">
            <DialogContent
                class="workspace-panel ds-modal gap-0 p-0 font-ui text-foreground sm:max-w-md"
            >
                <div class="flex flex-col gap-4 p-5">
                    <div class="flex flex-col gap-1">
                        <DialogTitle class="text-base/6 font-semibold">{{
                            pickedUids.length === 1
                                ? $t('Delete 1 project?')
                                : $t('Delete :count projects?', {
                                      count: String(pickedUids.length),
                                  })
                        }}</DialogTitle>
                        <DialogDescription
                            class="text-[13px]/5 text-pretty text-(--ds-text-muted)"
                        >
                            {{
                                $t(
                                    'Everything in them keeps running, and apps keep the settings they were given. Their private networks are taken down, so apps on other machines lose the way to reach those services.',
                                )
                            }}
                        </DialogDescription>
                    </div>
                    <div class="flex justify-end gap-2">
                        <DashButton
                            :label="$t('Cancel')"
                            @click="removing = false"
                        />
                        <DashButton
                            variant="danger"
                            :label="$t('Delete')"
                            :loading="removingBusy"
                            @click="removePicked"
                        />
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    </div>
</template>
