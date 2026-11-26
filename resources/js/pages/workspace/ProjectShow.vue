<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import type { RequestPayload } from '@inertiajs/core';
import { Head, router, usePage } from '@inertiajs/vue3';
import {
    AppWindow,
    ArrowUpFromLine,
    ArrowUpRight,
    Check,
    ChevronDown,
    Code,
    DatabaseBackup,
    Ellipsis,
    GitPullRequest,
    Pencil,
    Plus,
    RefreshCw,
    Trash2,
} from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import AppVariablesDialog from '@/components/machines/AppVariablesDialog.vue';
import BrandLogo from '@/components/dash/BrandLogo.vue';
import DashButton from '@/components/dash/DashButton.vue';
import DashHeader from '@/components/dash/DashHeader.vue';
import DashTab from '@/components/dash/DashTab.vue';
import DashTabs from '@/components/dash/DashTabs.vue';
import StatusBadge from '@/components/dash/StatusBadge.vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import {
    kindLabels,
    listSentence,
    logoName,
    softwareName,
    stackLabel,
    ago,
} from '@/components/projects/format';
import ProjectMap from '@/components/projects/ProjectMap.vue';
import ProjectRail from '@/components/projects/ProjectRail.vue';
import ProjectServices from '@/components/projects/ProjectServices.vue';
import type {
    Environment,
    MachineGroup,
    Member,
    NodeKind,
    ProjectLink,
    ProjectNode,
    Summary,
} from '@/components/projects/types';
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
import { SelectMenu } from '@/components/ui/select';
import DangerButton from '@/components/workspace/DangerButton.vue';
import TickBox from '@/components/workspace/TickBox.vue';
import { toast } from '@/lib/toast';
import { onWorkspace } from '@/lib/workspaceRoute';
import codeRoutes from '@/routes/workspace/code';
import githubRoutes from '@/routes/workspace/github';
import projectRoutes from '@/routes/workspace/projects';
import type { Workspace, WorkspaceAccess } from '@/types';

type Kind = NodeKind;
type PromotionRow = {
    app: string;
    machine: string;
    from: string | null;
    to: string | null;
    repo: boolean;
    missing: string[];
    ready: boolean;
    reason: string | null;
};
type Candidate = {
    name: string;
    kind: Kind;
    template: string | null;
    machine: { id: number; name: string };
};

const props = defineProps<{
    workspace: Workspace;
    project: { uid: string; name: string; cidr: string };
    environments: Environment[];
    nodes: ProjectNode[];
    overview: Record<string, Summary>;
    links: ProjectLink[];
    members: Member[];
    candidates?: Candidate[];
    machines: { id: number; name: string; provider: string | null }[];
    resourceKinds: { kind: Exclude<Kind, 'app'>; template: string }[];
    copyable: string[];
    notes: string[];
    previews: {
        enabled: boolean;
        repo: string;
        machine: number | null;
        copyData: boolean;
        domain: string | null;
        limit: number;
        open: number;
        max: number;
        github: boolean;
    };
    repos?: string[];
    promotion?: PromotionRow[];
}>();

const page = usePage<{ workspaceAccess: WorkspaceAccess | null }>();
const canManage = computed(
    () =>
        page.props.workspaceAccess?.permissions.includes('manage-machines') ??
        false,
);
const base = computed(
    () =>
        onWorkspace(
            projectRoutes.show({
                workspace: props.workspace.slug,
                project: props.project.uid,
            }),
        ).url,
);
const listUrl = computed(
    () => onWorkspace(projectRoutes.index(props.workspace.slug)).url,
);
const codeUrl = computed(
    () =>
        onWorkspace(
            codeRoutes.setup(props.workspace.slug, {
                query: { project: props.project.uid },
            }),
        ).url,
);
const githubUrl = computed(
    () => onWorkspace(githubRoutes.app(props.workspace.slug)).url,
);

const envId = ref<number>(
    (() => {
        const asked = Number(
            new URLSearchParams(window.location.search).get('env'),
        );

        return props.environments.some((e) => e.id === asked)
            ? asked
            : (props.environments[0]?.id ?? 0);
    })(),
);
const env = computed(
    () =>
        props.environments.find((e) => e.id === envId.value) ??
        props.environments[0],
);
const sourceEnv = computed(() =>
    env.value?.source
        ? (props.environments.find((e) => e.id === env.value?.source) ?? null)
        : null,
);
const isFirst = computed(() => env.value?.id === props.environments[0]?.id);
const envNodes = computed(() =>
    props.nodes.filter((n) => n.environment === env.value?.id),
);
const envLinks = computed(() => {
    const ids = new Set(envNodes.value.map((n) => n.id));

    return props.links.filter((l) => ids.has(l.app));
});
const summary = computed<Summary | null>(() =>
    env.value ? (props.overview[String(env.value.id)] ?? null) : null,
);
const production = computed<Summary | null>(() =>
    props.environments[0]
        ? (props.overview[String(props.environments[0].id)] ?? null)
        : null,
);

function showEnv(id: number): void {
    envId.value = id;
    const url = new URL(window.location.href);
    if (id === props.environments[0]?.id) url.searchParams.delete('env');
    else url.searchParams.set('env', String(id));
    window.history.replaceState(window.history.state, '', url);
}

const groups = computed<MachineGroup[]>(() => {
    const byMachine = new Map<number, MachineGroup>();
    for (const node of envNodes.value) {
        const group = byMachine.get(node.machine.id) ?? {
            id: node.machine.id,
            name: node.machine.name,
            provider: node.machine.provider,
            nodes: [],
            member:
                props.members.find((m) => m.machine === node.machine.id) ??
                null,
        };
        group.nodes.push(node);
        byMachine.set(node.machine.id, group);
    }

    return [...byMachine.values()]
        .map((group) => ({
            ...group,
            nodes: [...group.nodes].sort(
                (a, b) =>
                    Number(b.kind === 'app') - Number(a.kind === 'app') ||
                    a.id - b.id,
            ),
        }))
        .sort(
            (a, b) =>
                Math.min(...a.nodes.map((n) => n.id)) -
                Math.min(...b.nodes.map((n) => n.id)),
        );
});

const mainLogo = computed(() =>
    logoName(
        (summary.value ?? production.value)?.kind ?? null,
        null,
        (summary.value ?? production.value)?.image ?? null,
    ),
);
const domain = computed(() => summary.value?.domain ?? null);

const description = computed(() => {
    const s = summary.value;
    if (!s || s.app === null) return null;
    const main = envNodes.value.find((n) => n.id === s.app);
    const what = softwareName(s.kind) ?? null;
    const parts = s.stack.map((item) =>
        item.kind === 'storage' ? trans('storage') : stackLabel(item),
    );
    const unique = [...new Set(parts)];
    if (main?.kind !== 'app') {
        return unique.length
            ? trans(':what with :rest', {
                  what: what ?? main?.name ?? '',
                  rest: listSentence(unique),
              })
            : what;
    }
    if (!unique.length) {
        return what ? trans(':what app', { what }) : null;
    }

    return what
        ? trans(':what app with :rest', { what, rest: listSentence(unique) })
        : trans('An app with :rest', { rest: listSentence(unique) });
});

const network = computed(() => {
    const count = props.members.length;
    if (count === 0) return null;

    return count === 1
        ? trans('Private network :cidr on 1 machine', {
              cidr: props.project.cidr,
          })
        : trans('Private network :cidr across :count machines', {
              cidr: props.project.cidr,
              count: String(count),
          });
});

const titleParts = computed(() => {
    const parts: { text: string; tone: string }[] = [];
    if (domain.value) {
        parts.push({ text: domain.value, tone: 'text-fg-secondary' });
    }
    if (description.value) {
        parts.push({ text: description.value, tone: 'text-fg-tertiary' });
    }
    if (network.value) {
        parts.push({ text: network.value, tone: 'text-fg-tertiary' });
    }

    return parts;
});

let poll: number | undefined;

const settling = computed(
    () =>
        props.members.some((m) => m.state === 'pending') ||
        props.links.some((l) => l.state === 'pending') ||
        props.nodes.some(
            (n) =>
                n.state === 'deploying' ||
                n.data?.state === 'waiting' ||
                n.data?.state === 'copying',
        ),
);

onMounted(() => {
    poll = window.setInterval(() => {
        if (settling.value && document.visibilityState === 'visible') {
            router.reload({
                only: ['nodes', 'links', 'members', 'environments', 'overview'],
            });
        }
    }, 8000);
});

watch(
    () => props.notes,
    (notes) => {
        if (notes.length) toast.info(notes.join(' '));
    },
    { immediate: true },
);

onBeforeUnmount(() => window.clearInterval(poll));

const busy = ref(false);

function send(
    method: 'post' | 'patch' | 'delete',
    url: string,
    data: RequestPayload = {},
    done?: () => void,
): void {
    busy.value = true;
    router.visit(url, {
        method,
        data,
        preserveScroll: true,
        onSuccess: () => done?.(),
        onError: (errors) =>
            toast.error(Object.values(errors)[0] ?? trans('That didn’t work.')),
        onFinish: () => (busy.value = false),
    });
}

const adding = ref(false);
const addFilter = ref<'app' | 'resource' | null>(null);
const shownCandidates = computed(() =>
    (props.candidates ?? []).filter((c) =>
        addFilter.value === null
            ? true
            : addFilter.value === 'app'
              ? c.kind === 'app'
              : c.kind !== 'app',
    ),
);

function openAdd(filter: 'app' | 'resource' | null): void {
    addFilter.value = filter;
    adding.value = true;
    router.reload({ only: ['candidates'] });
}

function addExisting(candidate: Candidate): void {
    send(
        'post',
        `${base.value}/nodes`,
        {
            machine: candidate.machine.id,
            name: candidate.name,
            environment: envId.value,
        },
        () => (adding.value = false),
    );
}

function remove(node: ProjectNode): void {
    send('delete', `${base.value}/nodes/${node.id}`);
}

const deletingNode = ref<ProjectNode | null>(null);
const deletingOpen = computed({
    get: () => deletingNode.value !== null,
    set: (open: boolean) => {
        if (!open) deletingNode.value = null;
    },
});
const deleteConfirm = ref('');
const deleteData = ref(false);

function openDelete(node: ProjectNode): void {
    deletingNode.value = node;
    deleteConfirm.value = '';
    deleteData.value = false;
}

function confirmDelete(): void {
    const node = deletingNode.value;
    if (!node) return;
    send(
        'delete',
        `${base.value}/nodes/${node.id}`,
        {
            delete: true,
            delete_data: deleteData.value,
            confirm: deleteConfirm.value,
        },
        () => {
            deletingNode.value = null;
            if (node.href) {
                toast.success(
                    trans('Deleting :name from :machine.', {
                        name: node.name,
                        machine: node.machine.name,
                    }),
                );
            }
        },
    );
}

function connect(app: ProjectNode, resource: ProjectNode): void {
    send('post', `${base.value}/links`, { app: app.id, resource: resource.id });
}

function disconnect(link: ProjectLink): void {
    send('delete', `${base.value}/links/${link.id}`);
}

const placing = ref<Exclude<Kind, 'app'> | null>(null);
const placeOn = ref<number | null>(null);

function addResource(kind: Exclude<Kind, 'app'>): void {
    if (props.machines.length === 1) {
        send('post', `${base.value}/resources`, {
            kind,
            machine: props.machines[0].id,
            environment: envId.value,
        });

        return;
    }
    placeOn.value = groups.value[0]?.id ?? props.machines[0]?.id ?? null;
    placing.value = kind;
}

function confirmPlace(): void {
    if (!placing.value || placeOn.value === null) return;
    send(
        'post',
        `${base.value}/resources`,
        {
            kind: placing.value,
            machine: placeOn.value,
            environment: envId.value,
        },
        () => (placing.value = null),
    );
}

const renaming = ref(false);
const newName = ref(props.project.name);
const deleting = ref(false);

function rename(): void {
    send('patch', base.value, { name: newName.value }, () => {
        renaming.value = false;
    });
}

function destroy(): void {
    send('delete', base.value);
}

const making = ref(false);
const envName = ref('');
const environmentOptions = computed(() =>
    props.environments.map((e) => ({ value: e.id, label: e.name })),
);
const machineOptions = computed(() =>
    props.machines.map((m) => ({ value: m.id, label: m.name })),
);
const previewLimitOptions = computed(() =>
    Array.from({ length: props.previews.max }, (_, i) => ({
        value: i + 1,
        label: String(i + 1),
    })),
);
const envSource = ref<number>(0);
const envMachines = ref<Record<number, number>>({});
const envCopyData = ref(true);
const sourceMachines = computed(() => {
    const seen = new Map<number, string>();
    for (const n of props.nodes) {
        if (n.environment === envSource.value) {
            seen.set(n.machine.id, n.machine.name);
        }
    }

    return [...seen].map(([id, name]) => ({ id, name }));
});
const sourceHasData = computed(() =>
    props.nodes.some(
        (n) =>
            n.environment === envSource.value &&
            n.template !== null &&
            props.copyable.includes(n.template),
    ),
);

function openMake(): void {
    envName.value = props.environments.some((e) => e.name === 'Staging')
        ? ''
        : 'Staging';
    envSource.value = props.environments[0]?.id ?? 0;
    envCopyData.value = true;
    making.value = true;
}

watch(
    () => [making.value, envSource.value] as const,
    ([open]) => {
        if (!open) return;
        envMachines.value = Object.fromEntries(
            sourceMachines.value.map((m) => [m.id, m.id]),
        );
    },
    { immediate: true },
);

function makeEnv(): void {
    if (!envName.value.trim()) return;
    send(
        'post',
        `${base.value}/environments`,
        {
            name: envName.value.trim(),
            source: envSource.value,
            machines: envMachines.value,
            copy_data: envCopyData.value && sourceHasData.value,
        },
        () => {
            making.value = false;
            const made = props.environments.find(
                (e) => e.name === envName.value.trim(),
            );
            if (made) showEnv(made.id);
        },
    );
}

function refreshData(): void {
    if (!env.value) return;
    send('post', `${base.value}/environments/${env.value.id}/refresh`, {}, () =>
        toast.success(trans('Copying its data again.')),
    );
}

function setRefresh(refresh: 'daily' | 'weekly' | null): void {
    if (!env.value) return;
    send('patch', `${base.value}/environments/${env.value.id}`, { refresh });
}

const renamingEnv = ref(false);
const newEnvName = ref('');

function renameEnv(): void {
    if (!env.value) return;
    send(
        'patch',
        `${base.value}/environments/${env.value.id}`,
        { name: newEnvName.value },
        () => (renamingEnv.value = false),
    );
}

const deletingEnv = ref(false);
const removeServices = ref(true);

function deleteEnv(): void {
    if (!env.value) return;
    send(
        'delete',
        `${base.value}/environments/${env.value.id}`,
        { remove_services: removeServices.value },
        () => {
            deletingEnv.value = false;
            showEnv(props.environments[0]?.id ?? 0);
        },
    );
}

const promoting = ref(false);
const promotionFor = ref<string | null>(null);
const promotionRows = computed(() =>
    promotionFor.value === `${env.value?.id}>${sourceEnv.value?.id}`
        ? (props.promotion ?? [])
        : undefined,
);
const promotionReady = computed(() =>
    (promotionRows.value ?? []).some((row) => row.ready),
);

function cleanAddress(): void {
    const url = new URL(window.location.href);
    for (const key of ['from', 'to', 'app']) url.searchParams.delete(key);
    window.history.replaceState(window.history.state, '', url);
}

function openPromote(): void {
    if (!env.value || !sourceEnv.value) return;
    const key = `${env.value.id}>${sourceEnv.value.id}`;
    promotionFor.value = null;
    promoting.value = true;
    router.reload({
        only: ['promotion'],
        data: { from: env.value.id, to: sourceEnv.value.id },
        onSuccess: () => (promotionFor.value = key),
        onFinish: cleanAddress,
    });
}

function promote(): void {
    if (!env.value || !sourceEnv.value) return;
    send(
        'post',
        `${base.value}/promote`,
        { from: env.value.id, to: sourceEnv.value.id },
        () => {
            promoting.value = false;
            toast.success(trans('Deploying the new code.'));
        },
    );
}

const editing = ref<ProjectNode | null>(null);
const editingOpen = computed({
    get: () => editing.value !== null,
    set: (open: boolean) => {
        if (!open) editing.value = null;
    },
});

function openSettings(node: ProjectNode): void {
    editing.value = node;
}

const previewing = ref(false);
const previewForm = ref({
    enabled: false,
    repo: '',
    machine: null as number | null,
    copy_data: false,
    domain: '',
    limit: 3,
});

function openPreviews(enabled?: boolean): void {
    previewForm.value = {
        enabled: enabled ?? (props.previews.enabled || !props.previews.repo),
        repo: props.previews.repo,
        machine: props.previews.machine ?? props.machines[0]?.id ?? null,
        copy_data: props.previews.copyData,
        domain: props.previews.domain ?? '',
        limit: props.previews.limit,
    };
    previewing.value = true;
    router.reload({
        only: ['repos'],
        onSuccess: () => {
            if (!previewForm.value.repo && props.repos?.length) {
                previewForm.value.repo = props.repos[0];
            }
        },
    });
}

function savePreviews(): void {
    const f = previewForm.value;
    send(
        'patch',
        `${base.value}/previews`,
        {
            enabled: f.enabled,
            repo: f.repo.trim(),
            machine: f.machine,
            copy_data: f.copy_data,
            domain: f.domain.trim(),
            limit: f.limit,
        },
        () => {
            previewing.value = false;
            toast.success(
                f.enabled
                    ? trans('Pull requests on :repo get a preview now.', {
                          repo: f.repo.trim(),
                      })
                    : trans('Previews are off.'),
            );
        },
    );
}

function togglePreviews(on: boolean): void {
    if (on && (!props.previews.repo || props.previews.machine === null)) {
        openPreviews(true);

        return;
    }
    send(
        'patch',
        `${base.value}/previews`,
        {
            enabled: on,
            repo: props.previews.repo,
            machine: props.previews.machine,
            copy_data: props.previews.copyData,
            domain: props.previews.domain ?? '',
            limit: props.previews.limit,
        },
        () =>
            toast.success(
                on
                    ? trans('Pull requests on :repo get a preview now.', {
                          repo: props.previews.repo,
                      })
                    : trans('Previews are off.'),
            ),
    );
}

const pullRequestUrl = computed(() =>
    env.value?.pullRequest && props.previews.repo
        ? `https://github.com/${props.previews.repo}/pull/${env.value.pullRequest}`
        : null,
);
</script>

<template>
    <Head :title="project.name" />

    <DashHeader
        :crumbs="[
            { label: $t('Projects'), href: listUrl },
            { label: project.name, logo: mainLogo || undefined },
        ]"
    >
        <template #actions>
            <DropdownMenu v-if="canManage">
                <DropdownMenuTrigger as-child>
                    <DashButton :icon="Ellipsis" :aria-label="$t('More')" />
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    class="ds-modal min-w-48 font-ui"
                    align="end"
                >
                    <DropdownMenuItem @select="openPreviews()">
                        <GitPullRequest class="size-4" :stroke-width="1.75" />
                        {{ $t('Pull request previews…') }}
                        <span
                            v-if="previews.enabled"
                            class="ml-auto text-xs text-status-live"
                            >{{ $t('On') }}</span
                        >
                    </DropdownMenuItem>
                    <DropdownMenuItem @select="router.visit(codeUrl)">
                        <Code class="size-4" :stroke-width="1.75" />
                        {{ $t('Manage with code') }}
                    </DropdownMenuItem>
                    <DropdownMenuItem @select="renaming = true">
                        <Pencil class="size-4" :stroke-width="1.75" />
                        {{ $t('Rename project') }}
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                        variant="destructive"
                        @select="deleting = true"
                    >
                        <Trash2 class="size-4" :stroke-width="1.75" />
                        {{ $t('Delete project') }}
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
            <DashButton
                :icon="ArrowUpRight"
                :label="$t('Open site')"
                :href="domain ? `https://${domain}` : undefined"
                :external="!!domain"
                :disabled="!domain"
                :title="
                    domain
                        ? undefined
                        : $t('This environment has no domain yet.')
                "
            />
            <DropdownMenu v-if="canManage">
                <DropdownMenuTrigger as-child>
                    <DashButton
                        variant="primary"
                        :icon="Plus"
                        :label="$t('Add to project')"
                        :disabled="busy"
                    />
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    class="ds-modal min-w-60 font-ui"
                    align="end"
                >
                    <DropdownMenuItem @select="openAdd('app')">
                        <AppWindow class="size-4" :stroke-width="1.75" />
                        {{ $t('An app you’ve deployed') }}
                    </DropdownMenuItem>
                    <DropdownMenuItem @select="openAdd('resource')">
                        <FilledIcon name="layers-fill" class="text-fg-muted" />
                        {{ $t('A service you’ve deployed') }}
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuLabel
                        class="text-xs font-normal text-fg-muted"
                        >{{
                            $t('Deploy a new one and connect it')
                        }}</DropdownMenuLabel
                    >
                    <DropdownMenuItem
                        v-for="option in resourceKinds"
                        :key="option.kind"
                        :disabled="!machines.length"
                        @select="addResource(option.kind)"
                    >
                        <BrandLogo :name="option.template" />
                        {{ $t(kindLabels[option.kind]) }}
                        <span class="ml-auto text-xs text-fg-muted">{{
                            softwareName(null, option.template)
                        }}</span>
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </template>
    </DashHeader>

    <div class="flex min-h-full flex-col">
        <div class="flex items-center gap-3.5 px-6 pt-5 pb-[18px]">
            <span
                class="flex size-10 shrink-0 items-center justify-center rounded-[10px] border-[0.5px] border-edge-strong bg-surface-raised shadow-tile"
            >
                <BrandLogo :name="mainLogo" :size="22" />
            </span>
            <div class="flex min-w-0 flex-col gap-0.5">
                <div class="flex items-center gap-2.5">
                    <h1
                        class="truncate text-[18px]/6 font-medium tracking-[-0.015em] text-fg-primary"
                    >
                        {{ project.name }}
                    </h1>
                    <StatusBadge
                        v-if="summary?.state"
                        :status="summary.state"
                    />
                </div>
                <p
                    class="flex min-w-0 items-center gap-2 overflow-hidden text-[13px]/5 tracking-[-0.005em] whitespace-nowrap"
                >
                    <template v-for="(part, i) in titleParts" :key="i">
                        <span v-if="i > 0" class="font-medium text-fg-faint"
                            >·</span
                        >
                        <span class="truncate" :class="part.tone">{{
                            part.text
                        }}</span>
                    </template>
                    <span v-if="!titleParts.length" class="text-fg-tertiary">{{
                        $t(
                            'Add an app, then what it uses. They’ll share a private network: :cidr.',
                            { cidr: project.cidr },
                        )
                    }}</span>
                </p>
            </div>
        </div>

        <div class="flex items-center border-b-[0.5px] border-edge-tabs">
            <DashTabs class="min-w-0 grow border-b-0!">
                <DashTab
                    v-for="(e, i) in environments"
                    :key="e.id"
                    :label="e.name"
                    :icon="i === 0 ? 'globe-fill' : 'branch-fill'"
                    :active="e.id === env?.id"
                    @click="showEnv(e.id)"
                />
                <template v-if="canManage" #after>
                    <button
                        type="button"
                        class="flex h-8 cursor-pointer items-center gap-1.5 rounded-[7px] pr-2 pl-0.5 text-[13px]/5 font-medium tracking-[-0.01em] whitespace-nowrap text-fg-faint transition-colors hover:text-fg-secondary focus-visible:outline-2 focus-visible:outline-(--ds-ring)"
                        @click="openMake"
                    >
                        <FilledIcon name="plus-fill" />
                        {{ $t('New environment') }}
                    </button>
                </template>
            </DashTabs>
            <div
                v-if="canManage && (sourceEnv || !isFirst)"
                class="flex shrink-0 items-center gap-2 pr-5 pl-3"
            >
                <DropdownMenu v-if="sourceEnv">
                    <DropdownMenuTrigger as-child>
                        <DashButton
                            size="sm"
                            :icon="DatabaseBackup"
                            :disabled="busy"
                        >
                            {{ $t('Data') }}
                            <ChevronDown
                                class="-mr-0.5 size-3.5 opacity-60"
                                :stroke-width="1.75"
                            />
                        </DashButton>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        class="ds-modal min-w-64 font-ui"
                        align="end"
                    >
                        <DropdownMenuLabel
                            class="text-xs font-normal text-pretty text-fg-muted"
                        >
                            {{
                                env?.refreshedAt
                                    ? $t(
                                          'Its databases were last copied from :source :when.',
                                          {
                                              source: sourceEnv.name,
                                              when: ago(env.refreshedAt),
                                          },
                                      )
                                    : $t(
                                          'Its databases hold their own data. Copy :source’s in to test against real data.',
                                          { source: sourceEnv.name },
                                      )
                            }}
                        </DropdownMenuLabel>
                        <DropdownMenuItem @select="refreshData">
                            <RefreshCw class="size-4" :stroke-width="1.75" />
                            {{
                                $t('Copy data from :source now', {
                                    source: sourceEnv.name,
                                })
                            }}
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                        <DropdownMenuLabel
                            class="text-xs font-normal text-fg-muted"
                            >{{
                                $t('Copy it again automatically')
                            }}</DropdownMenuLabel
                        >
                        <DropdownMenuItem
                            v-for="option in [
                                { value: null, label: 'Never' },
                                { value: 'daily', label: 'Every night' },
                                { value: 'weekly', label: 'Every week' },
                            ] as const"
                            :key="option.label"
                            role="menuitemradio"
                            :aria-checked="
                                (env?.refresh ?? null) === option.value
                            "
                            @select="setRefresh(option.value)"
                        >
                            <Check
                                class="size-4"
                                :class="
                                    (env?.refresh ?? null) === option.value
                                        ? 'opacity-100'
                                        : 'opacity-0'
                                "
                            />
                            {{ $t(option.label) }}
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
                <DashButton
                    v-if="sourceEnv && pullRequestUrl"
                    size="sm"
                    :icon="GitPullRequest"
                    :href="pullRequestUrl"
                    external
                >
                    {{
                        $t('Pull request #:n', { n: String(env?.pullRequest) })
                    }}
                    <span
                        v-if="env?.head"
                        class="font-ui-mono text-[11px] text-fg-muted"
                        >{{ env.head }}</span
                    >
                </DashButton>
                <DashButton
                    v-else-if="sourceEnv"
                    size="sm"
                    :icon="ArrowUpFromLine"
                    :label="$t('Promote to :name', { name: sourceEnv.name })"
                    :disabled="busy"
                    @click="openPromote"
                />
                <DropdownMenu v-if="!isFirst">
                    <DropdownMenuTrigger as-child>
                        <DashButton
                            size="sm"
                            :icon="Ellipsis"
                            :aria-label="
                                $t('Options for :name', {
                                    name: env?.name ?? '',
                                })
                            "
                        />
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        class="ds-modal min-w-44 font-ui"
                        align="end"
                    >
                        <DropdownMenuItem
                            @select="
                                () => {
                                    newEnvName = env?.name ?? '';
                                    renamingEnv = true;
                                }
                            "
                        >
                            <Pencil class="size-4" :stroke-width="1.75" />
                            {{ $t('Rename :name', { name: env?.name ?? '' }) }}
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            class="text-status-down focus:bg-status-down/10 focus:text-status-down"
                            @select="
                                () => {
                                    removeServices = true;
                                    deletingEnv = true;
                                }
                            "
                        >
                            <Trash2 class="size-4" :stroke-width="1.75" />
                            {{ $t('Delete :name', { name: env?.name ?? '' }) }}
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </div>

        <div class="flex flex-col gap-4 px-5 pt-[18px] pb-6">
            <div class="flex flex-col gap-4 xl:flex-row xl:items-start">
                <ProjectMap
                    class="min-w-0 grow"
                    :groups="groups"
                    :links="envLinks"
                    :can-manage="canManage"
                    :busy="busy"
                    :can-deploy="machines.length > 0"
                    :resource-kinds="resourceKinds"
                    :empty-title="
                        isFirst
                            ? $t('Nothing in this project yet')
                            : $t('Nothing in :name yet', {
                                  name: env?.name ?? '',
                              })
                    "
                    @settings="openSettings"
                    @connect="connect"
                    @disconnect="disconnect"
                    @remove="remove"
                    @delete="openDelete"
                    @add-app="openAdd('app')"
                    @add-existing="openAdd('resource')"
                    @add-resource="addResource"
                />
                <ProjectRail
                    class="shrink-0 xl:w-[300px]"
                    :cidr="project.cidr"
                    :members="members"
                    :previews="previews"
                    :production="environments[0]?.name ?? $t('Production')"
                    :can-manage="canManage"
                    :busy="busy"
                    @toggle="togglePreviews"
                    @configure="openPreviews()"
                />
            </div>

            <section v-if="envNodes.length" class="flex flex-col gap-2.5">
                <h2
                    class="flex items-center gap-2 text-[14px]/5 font-medium tracking-[-0.01em] text-fg-primary"
                >
                    {{ $t('Services') }}
                    <span class="text-fg-faint">{{ envNodes.length }}</span>
                </h2>
                <ProjectServices
                    :nodes="envNodes"
                    :links="envLinks"
                    :members="members"
                    :can-manage="canManage"
                    @settings="openSettings"
                    @connect="connect"
                    @disconnect="disconnect"
                    @remove="remove"
                    @delete="openDelete"
                />
            </section>
        </div>
    </div>

    <Dialog v-model:open="adding">
        <DialogContent
            class="workspace-panel ds-modal gap-0 p-0 font-ui text-foreground sm:max-w-lg"
        >
            <div class="flex flex-col gap-1 px-5 pt-5 pb-3">
                <DialogTitle class="text-base/6 font-semibold">{{
                    addFilter === 'resource'
                        ? $t('Add a service you’ve deployed')
                        : $t('Add an app you’ve deployed')
                }}</DialogTitle>
                <DialogDescription class="text-[13px]/5 text-(--ds-text-muted)">
                    {{
                        ' ' +
                        $t(
                            'It keeps running where it is. Its machine joins the project’s private network.',
                        ) +
                        ' '
                    }}
                </DialogDescription>
            </div>
            <div class="max-h-[50vh] overflow-y-auto px-2 pb-3">
                <p
                    v-if="candidates === undefined"
                    class="px-3 py-6 text-center text-[13px] text-(--ds-text-muted)"
                >
                    {{ $t('Loading…') }}
                </p>
                <p
                    v-else-if="!shownCandidates.length"
                    class="px-3 py-6 text-center text-[13px] text-pretty text-(--ds-text-muted)"
                >
                    {{
                        addFilter === 'resource'
                            ? $t(
                                  'No databases, caches or storage deployed from ServerOS are left to add. Use New in the bar to deploy one.',
                              )
                            : $t(
                                  'No deployed apps are left to add. Deploy one from Services first.',
                              )
                    }}
                </p>
                <button
                    v-for="c in shownCandidates"
                    :key="`${c.machine.id}/${c.name}`"
                    type="button"
                    class="flex w-full cursor-pointer items-center gap-3 rounded-[8px] px-3 py-2.5 text-left text-[13px]/5 hover:bg-(--ds-hover) disabled:opacity-50"
                    :disabled="busy"
                    @click="addExisting(c)"
                >
                    <BrandLogo :name="c.template ?? c.name" />
                    <span class="min-w-0 truncate font-medium">{{
                        c.name
                    }}</span>
                    <span class="text-(--ds-text-muted)">{{
                        c.template
                            ? (softwareName(null, c.template) ?? c.template)
                            : $t('App')
                    }}</span>
                    <span
                        class="ml-auto shrink-0 text-xs text-(--ds-text-muted)"
                        >{{ c.machine.name }}</span
                    >
                </button>
            </div>
        </DialogContent>
    </Dialog>

    <Dialog
        :open="placing !== null"
        @update:open="(open) => !open && (placing = null)"
    >
        <DialogContent
            class="workspace-panel ds-modal gap-0 p-0 font-ui text-foreground sm:max-w-md"
        >
            <form
                class="flex flex-col gap-4 p-5"
                @submit.prevent="confirmPlace"
            >
                <div class="flex flex-col gap-1">
                    <DialogTitle class="text-base/6 font-semibold">{{
                        placing
                            ? $t('New :kind', {
                                  kind: $t(kindLabels[placing]),
                              })
                            : ''
                    }}</DialogTitle>
                    <DialogDescription
                        class="text-[13px]/5 text-(--ds-text-muted)"
                    >
                        {{
                            ' ' +
                            $t(
                                'Pick the machine it runs on. Every app in the project is connected to it once it’s up, wherever they run.',
                            ) +
                            ' '
                        }}
                    </DialogDescription>
                </div>
                <div class="flex flex-col gap-1">
                    <label
                        v-for="m in machines"
                        :key="m.id"
                        class="flex cursor-pointer items-center gap-2.5 rounded-[8px] px-3 py-2 text-[13px] hover:bg-(--ds-hover)"
                    >
                        <input
                            v-model="placeOn"
                            type="radio"
                            name="machine"
                            :value="m.id"
                            class="accent-(--ds-blue)"
                        />
                        <BrandLogo :name="m.provider" :fallback="false" />
                        {{ m.name }}
                    </label>
                </div>
                <div class="flex justify-end gap-2">
                    <DashButton
                        type="button"
                        @click="placing = null"
                        :label="$t('Cancel')"
                    />
                    <DashButton
                        variant="primary"
                        type="submit"
                        :disabled="busy || placeOn === null"
                        :label="$t('Deploy and connect')"
                    />
                </div>
            </form>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="renaming">
        <DialogContent
            class="workspace-panel ds-modal gap-0 p-0 font-ui text-foreground sm:max-w-md"
        >
            <form class="flex flex-col gap-4 p-5" @submit.prevent="rename">
                <DialogTitle class="text-base/6 font-semibold">{{
                    $t('Rename project')
                }}</DialogTitle>
                <DialogDescription class="sr-only">{{
                    $t('Rename project')
                }}</DialogDescription>
                <Input
                    v-model="newName"
                    maxlength="80"
                    required
                    :aria-label="$t('Project name')"
                />
                <div class="flex justify-end gap-2">
                    <DashButton
                        type="button"
                        @click="renaming = false"
                        :label="$t('Cancel')"
                    />
                    <DashButton
                        variant="primary"
                        type="submit"
                        :disabled="busy || !newName.trim()"
                        :label="$t('Save')"
                    />
                </div>
            </form>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="deleting">
        <DialogContent
            class="workspace-panel ds-modal gap-0 p-0 font-ui text-foreground sm:max-w-md"
        >
            <div class="flex flex-col gap-4 p-5">
                <div class="flex flex-col gap-1">
                    <DialogTitle class="text-base/6 font-semibold">{{
                        $t('Delete :name?', { name: project.name })
                    }}</DialogTitle>
                    <DialogDescription
                        class="text-[13px]/5 text-pretty text-(--ds-text-muted)"
                    >
                        {{
                            ' ' +
                            $t(
                                'Everything in it keeps running, and apps keep the settings they were given. The private network is taken down, so apps on other machines lose the way to reach these services.',
                            ) +
                            ' '
                        }}
                    </DialogDescription>
                </div>
                <div class="flex justify-end gap-2">
                    <DashButton
                        type="button"
                        @click="deleting = false"
                        :label="$t('Cancel')"
                    />
                    <DashButton
                        variant="danger"
                        type="button"
                        :disabled="busy"
                        @click="destroy"
                        :label="$t('Delete project')"
                    />
                </div>
            </div>
        </DialogContent>
    </Dialog>
    <Dialog v-model:open="making">
        <DialogContent
            class="workspace-panel ds-modal gap-0 p-0 font-ui text-foreground sm:max-w-lg"
        >
            <form class="flex flex-col gap-5 p-5" @submit.prevent="makeEnv">
                <div class="flex flex-col gap-1">
                    <DialogTitle class="text-base/6 font-semibold">{{
                        $t('New environment')
                    }}</DialogTitle>
                    <DialogDescription
                        class="text-[13px]/5 text-pretty text-(--ds-text-muted)"
                    >
                        {{
                            ' ' +
                            $t(
                                'A copy to try things in before they reach your users. Its apps run the same code with no domain, and its databases, caches and storage are new ones with passwords of their own.',
                            ) +
                            ' '
                        }}
                    </DialogDescription>
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="flex flex-col gap-1.5 text-[13px]">
                        <span class="font-medium">{{ $t('Name') }}</span>
                        <Input
                            v-model="envName"
                            maxlength="40"
                            required
                            :placeholder="$t('Staging')"
                        />
                    </label>
                    <label class="flex flex-col gap-1.5 text-[13px]">
                        <span class="font-medium">{{ $t('A copy of') }}</span>
                        <SelectMenu
                            v-model="envSource"
                            :options="environmentOptions"
                            class="ds-raised-button h-9 w-full text-[13px]"
                        />
                    </label>
                </div>

                <div v-if="sourceMachines.length" class="flex flex-col gap-2">
                    <p class="text-[13px] font-medium">
                        {{ $t('Where it runs') }}
                    </p>
                    <div
                        class="flex flex-col divide-y divide-(--ds-divider) rounded-[8px] border border-(color:--ds-divider)"
                    >
                        <div
                            v-for="m in sourceMachines"
                            :key="m.id"
                            class="flex items-center gap-3 px-3 py-2 text-[13px]"
                        >
                            <span
                                class="min-w-0 flex-1 truncate text-(--ds-text-secondary)"
                                >{{
                                    $t('What’s on :name goes on', {
                                        name: m.name,
                                    })
                                }}</span
                            >
                            <SelectMenu
                                v-model="envMachines[m.id]"
                                :options="machineOptions"
                                :aria-label="
                                    $t('What’s on :name goes on', {
                                        name: m.name,
                                    })
                                "
                                class="ds-raised-button h-8 max-w-[45%] text-[13px]"
                            />
                        </div>
                    </div>
                    <p class="text-xs/4 text-(--ds-text-muted)">
                        {{
                            $t(
                                'A cheaper machine is fine: it only runs what you test.',
                            )
                        }}
                    </p>
                </div>

                <label
                    v-if="sourceHasData"
                    class="flex cursor-pointer items-start gap-2.5 rounded-[8px] border border-(color:--ds-divider) p-3 text-[13px]"
                >
                    <input
                        v-model="envCopyData"
                        type="checkbox"
                        class="mt-0.5 accent-(--ds-blue)"
                    />
                    <span class="flex flex-col gap-0.5">
                        <span class="font-medium">{{
                            $t('Copy the data in its databases')
                        }}</span>
                        <span class="text-xs/4 text-(--ds-text-muted)">{{
                            $t(
                                'Taken over the project’s private network once they’re up. Copy it again any time, or every night, from the Data menu.',
                            )
                        }}</span>
                    </span>
                </label>

                <div class="flex justify-end gap-2">
                    <DashButton
                        type="button"
                        @click="making = false"
                        :label="$t('Cancel')"
                    />
                    <DashButton
                        variant="primary"
                        type="submit"
                        :disabled="busy || !envName.trim()"
                        :label="$t('Make the environment')"
                    />
                </div>
            </form>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="promoting">
        <DialogContent
            class="workspace-panel ds-modal gap-0 p-0 font-ui text-foreground sm:max-w-lg"
        >
            <div class="flex flex-col gap-4 p-5">
                <div class="flex flex-col gap-1">
                    <DialogTitle class="text-base/6 font-semibold">{{
                        $t('Promote :from to :to', {
                            from: env?.name ?? '',
                            to: sourceEnv?.name ?? '',
                        })
                    }}</DialogTitle>
                    <DialogDescription
                        class="text-[13px]/5 text-pretty text-(--ds-text-muted)"
                    >
                        {{
                            ' ' +
                            $t(
                                'Each app in :to is deployed at the code its copy runs. Its own settings, domain and data stay as they are, and if a deploy fails its health check the live version keeps running.',
                                { to: sourceEnv?.name ?? '' },
                            ) +
                            ' '
                        }}
                    </DialogDescription>
                </div>
                <div
                    v-if="promotionRows === undefined"
                    class="py-6 text-center text-[13px] text-(--ds-text-muted)"
                >
                    {{ $t('Working out what changes…') }}
                </div>
                <p
                    v-else-if="!promotionRows.length"
                    class="py-4 text-[13px] text-(--ds-text-muted)"
                >
                    {{
                        $t('No app here is a copy of one in :to.', {
                            to: sourceEnv?.name ?? '',
                        })
                    }}
                </p>
                <ul
                    v-else
                    class="flex flex-col divide-y divide-(--ds-divider) rounded-[8px] border border-(color:--ds-divider)"
                >
                    <li
                        v-for="row in promotionRows"
                        :key="row.app"
                        class="flex flex-col gap-1 px-3 py-2.5 text-[13px]"
                    >
                        <div class="flex items-center gap-2">
                            <AppWindow
                                class="size-4 text-(--ds-text-secondary)"
                                :stroke-width="1.75"
                            />
                            <span class="font-medium">{{ row.app }}</span>
                            <span class="text-xs text-(--ds-text-faint)">{{
                                row.machine
                            }}</span>
                            <span
                                v-if="row.from && row.to"
                                class="ml-auto font-ui-mono text-xs"
                                :class="
                                    row.ready
                                        ? 'text-(--ds-text-secondary)'
                                        : 'text-(--ds-text-faint)'
                                "
                            >
                                {{ row.from }} →
                                <span
                                    :class="row.ready && 'text-(--ds-text)'"
                                    >{{ row.to }}</span
                                >
                            </span>
                        </div>
                        <p
                            v-if="row.reason"
                            class="pl-6 text-xs/4 text-(--ds-text-muted)"
                        >
                            {{ $t(row.reason) }}
                        </p>
                        <p
                            v-if="row.missing.length"
                            class="pl-6 text-xs/4 text-(--ds-amber)"
                        >
                            {{
                                $t(
                                    'Only the copy has :names. Add them to :app’s settings first if the new code needs them.',
                                    {
                                        names: row.missing.join(', '),
                                        app: row.app,
                                    },
                                )
                            }}
                        </p>
                    </li>
                </ul>
                <div class="flex justify-end gap-2">
                    <DashButton
                        type="button"
                        @click="promoting = false"
                        :label="$t('Cancel')"
                    />
                    <DashButton
                        variant="primary"
                        type="button"
                        :disabled="busy || !promotionReady"
                        @click="promote"
                        :label="$t('Promote')"
                    />
                </div>
            </div>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="renamingEnv">
        <DialogContent
            class="workspace-panel ds-modal gap-0 p-0 font-ui text-foreground sm:max-w-md"
        >
            <form class="flex flex-col gap-4 p-5" @submit.prevent="renameEnv">
                <DialogTitle class="text-base/6 font-semibold">{{
                    $t('Rename :name', { name: env?.name ?? '' })
                }}</DialogTitle>
                <DialogDescription class="sr-only">{{
                    $t('Rename the environment')
                }}</DialogDescription>
                <Input
                    v-model="newEnvName"
                    maxlength="40"
                    required
                    :aria-label="$t('Environment name')"
                />
                <div class="flex justify-end gap-2">
                    <DashButton
                        type="button"
                        @click="renamingEnv = false"
                        :label="$t('Cancel')"
                    />
                    <DashButton
                        variant="primary"
                        type="submit"
                        :disabled="busy || !newEnvName.trim()"
                        :label="$t('Save')"
                    />
                </div>
            </form>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="deletingEnv">
        <DialogContent
            class="workspace-panel ds-modal gap-0 p-0 font-ui text-foreground sm:max-w-md"
        >
            <div class="flex flex-col gap-4 p-5">
                <div class="flex flex-col gap-1">
                    <DialogTitle class="text-base/6 font-semibold">{{
                        $t('Delete :name?', { name: env?.name ?? '' })
                    }}</DialogTitle>
                    <DialogDescription
                        class="text-[13px]/5 text-pretty text-(--ds-text-muted)"
                    >
                        {{
                            ' ' +
                            $t(
                                'It comes out of the project. Nothing in any other environment is touched.',
                            ) +
                            ' '
                        }}
                    </DialogDescription>
                </div>
                <label
                    class="flex cursor-pointer items-start gap-2.5 rounded-[8px] border border-(color:--ds-divider) p-3 text-[13px]"
                >
                    <input
                        v-model="removeServices"
                        type="checkbox"
                        class="mt-0.5 accent-(--ds-red)"
                    />
                    <span class="flex flex-col gap-0.5">
                        <span class="font-medium">{{
                            $t('Remove its apps and services too')
                        }}</span>
                        <span class="text-xs/4 text-(--ds-text-muted)">{{
                            $t(
                                'They’re stopped and removed from their machines with their data. Leave this off to keep them running.',
                            )
                        }}</span>
                    </span>
                </label>
                <div class="flex justify-end gap-2">
                    <DashButton
                        type="button"
                        @click="deletingEnv = false"
                        :label="$t('Cancel')"
                    />
                    <DashButton
                        variant="danger"
                        type="button"
                        :disabled="busy"
                        @click="deleteEnv"
                        :label="$t('Delete environment')"
                    />
                </div>
            </div>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="deletingOpen">
        <DialogContent
            class="workspace-panel ds-modal gap-0 p-0 font-ui text-foreground sm:max-w-md"
        >
            <form
                v-if="deletingNode"
                class="flex flex-col gap-4 p-5"
                @submit.prevent="confirmDelete"
            >
                <div class="flex flex-col gap-1">
                    <DialogTitle class="text-base/6 font-semibold">{{
                        $t('Delete :name?', { name: deletingNode.name })
                    }}</DialogTitle>
                    <DialogDescription
                        class="text-[13px]/5 text-pretty text-(--ds-text-muted)"
                    >
                        {{
                            ' ' +
                            (deletingNode.href
                                ? $t(
                                      'Its containers on :machine stop and are deleted, every version of it, and it comes off the project. Data in volumes is kept unless you choose otherwise.',
                                      {
                                          machine: deletingNode.machine.name,
                                      },
                                  )
                                : $t(
                                      'Nothing of it is running on :machine, so this only takes it off the project.',
                                      {
                                          machine: deletingNode.machine.name,
                                      },
                                  )) +
                            ' '
                        }}
                    </DialogDescription>
                </div>
                <template v-if="deletingNode.href">
                    <div class="flex flex-col gap-1.5">
                        <label for="delete-confirm" class="text-[13px]/5">{{
                            $t('Type :name to confirm', {
                                name: deletingNode.name,
                            })
                        }}</label>
                        <Input
                            id="delete-confirm"
                            v-model="deleteConfirm"
                            autocomplete="off"
                            class="font-ui-mono"
                        />
                    </div>
                    <label class="flex items-start gap-2 text-[13px]/5">
                        <TickBox v-model="deleteData" class="mt-0.5" />
                        {{
                            $t(
                                'Also delete its data (volumes). This can’t be undone.',
                            )
                        }}
                    </label>
                </template>
                <div class="flex justify-end gap-2">
                    <DashButton
                        type="button"
                        size="sm"
                        @click="deletingNode = null"
                        :label="$t('Cancel')"
                    />
                    <DangerButton
                        :label="$t('Delete')"
                        :processing="busy"
                        :disabled="
                            !busy &&
                            !!deletingNode.href &&
                            deleteConfirm !== deletingNode.name
                        "
                    />
                </div>
            </form>
        </DialogContent>
    </Dialog>
    <AppVariablesDialog
        v-if="editing"
        v-model:open="editingOpen"
        :machine-id="editing.machine.id"
        :app="editing.name"
    />
    <Dialog v-model:open="previewing">
        <DialogContent
            class="workspace-panel ds-modal gap-0 p-0 font-ui text-foreground sm:max-w-lg"
        >
            <form
                class="flex flex-col gap-5 p-5"
                @submit.prevent="savePreviews"
            >
                <div class="flex flex-col gap-1">
                    <DialogTitle class="text-base/6 font-semibold">{{
                        $t('Pull request previews')
                    }}</DialogTitle>
                    <DialogDescription
                        class="text-[13px]/5 text-pretty text-(--ds-text-muted)"
                    >
                        {{
                            ' ' +
                            $t(
                                'Each pull request gets its own copy of :env on the machine you pick, running the pull request’s code. New commits deploy it again, and it’s removed when the pull request closes. Pull requests from forks never get one.',
                                { env: environments[0]?.name ?? '' },
                            ) +
                            ' ' +
                            $t(
                                'Nor does a project with an app that runs its repository’s own Compose file, since that file can control Docker on the machine.',
                            ) +
                            ' '
                        }}
                    </DialogDescription>
                </div>

                <p
                    v-if="!previews.github"
                    class="rounded-[8px] border border-(color:--ds-divider) p-3 text-[13px] text-pretty text-(--ds-text-secondary)"
                >
                    {{
                        $t(
                            'ServerOS hears about pull requests through its GitHub App.',
                        )
                    }}
                    <a
                        :href="githubUrl"
                        class="font-medium text-(--ds-blue) hover:underline"
                        >{{ $t('Connect GitHub in Settings') }}</a
                    >
                </p>

                <label
                    class="flex cursor-pointer items-center gap-2.5 text-[13px] font-medium"
                >
                    <input
                        v-model="previewForm.enabled"
                        type="checkbox"
                        class="accent-(--ds-blue)"
                    />
                    {{ $t('Make a preview for each pull request') }}
                </label>

                <template v-if="previewForm.enabled">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="flex flex-col gap-1.5 text-[13px]">
                            <span class="font-medium">{{
                                $t('Repository')
                            }}</span>
                            <Input
                                v-model="previewForm.repo"
                                list="project-repos"
                                required
                                placeholder="owner/name"
                                class="font-ui-mono text-xs"
                            />
                            <datalist id="project-repos">
                                <option
                                    v-for="r in repos ?? []"
                                    :key="r"
                                    :value="r"
                                />
                            </datalist>
                        </label>
                        <label class="flex flex-col gap-1.5 text-[13px]">
                            <span class="font-medium">{{ $t('Runs on') }}</span>
                            <SelectMenu
                                v-model="previewForm.machine"
                                :options="machineOptions"
                                class="ds-raised-button h-9 w-full text-[13px]"
                            />
                        </label>
                    </div>

                    <label class="flex flex-col gap-1.5 text-[13px]">
                        <span class="font-medium"
                            >{{ $t('Domain') }}
                            <span class="font-normal text-(--ds-text-muted)">{{
                                $t('(optional)')
                            }}</span></span
                        >
                        <Input
                            v-model="previewForm.domain"
                            placeholder="preview.example.com"
                            class="font-ui-mono text-xs"
                        />
                        <span class="text-xs/4 text-(--ds-text-muted)">{{
                            previewForm.domain.trim()
                                ? $t(
                                      'Each preview opens at an address like :example. Point *.:domain at the machine in your DNS.',
                                      {
                                          example: `shop-pr-12.${previewForm.domain.trim()}`,
                                          domain: previewForm.domain.trim(),
                                      },
                                  )
                                : $t(
                                      'Without one, previews have no address of their own. Add one to an app from its page.',
                                  )
                        }}</span>
                    </label>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="flex flex-col gap-1.5 text-[13px]">
                            <span class="font-medium">{{
                                $t('Open at once, at most')
                            }}</span>
                            <SelectMenu
                                v-model="previewForm.limit"
                                :options="previewLimitOptions"
                                class="ds-raised-button h-9 w-full text-[13px]"
                            />
                        </label>
                    </div>

                    <label
                        class="flex cursor-pointer items-start gap-2.5 rounded-[8px] border border-(color:--ds-divider) p-3 text-[13px]"
                    >
                        <input
                            v-model="previewForm.copy_data"
                            type="checkbox"
                            class="mt-0.5 accent-(--ds-blue)"
                        />
                        <span class="flex flex-col gap-0.5">
                            <span class="font-medium">{{
                                $t('Copy :env’s data into each preview', {
                                    env: environments[0]?.name ?? '',
                                })
                            }}</span>
                            <span class="text-xs/4 text-(--ds-text-muted)">{{
                                $t(
                                    'Off by default: anyone who can push a branch would see real data in their preview.',
                                )
                            }}</span>
                        </span>
                    </label>
                </template>

                <div class="flex justify-end gap-2">
                    <DashButton
                        type="button"
                        @click="previewing = false"
                        :label="$t('Cancel')"
                    />
                    <DashButton
                        variant="primary"
                        type="submit"
                        :disabled="
                            busy ||
                            (previewForm.enabled &&
                                (!previewForm.repo.trim() ||
                                    previewForm.machine === null))
                        "
                        :label="$t('Save')"
                    />
                </div>
            </form>
        </DialogContent>
    </Dialog>
</template>
