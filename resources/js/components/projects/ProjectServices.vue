<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';
import BrandLogo from '@/components/dash/BrandLogo.vue';
import StatusBadge from '@/components/dash/StatusBadge.vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    ago,
    kindLabels,
    linkSettings,
    nodeLogo,
    nodeSoftware,
} from './format';
import NodeMenuItems from './NodeMenuItems.vue';
import type { Member, NodeLink, ProjectLink, ProjectNode } from './types';

const {
    nodes,
    links,
    members,
    canManage = false,
} = defineProps<{
    nodes: ProjectNode[];
    links: ProjectLink[];
    members: Member[];
    canManage?: boolean;
}>();

const emit = defineEmits<{
    settings: [node: ProjectNode];
    connect: [app: ProjectNode, resource: ProjectNode];
    disconnect: [link: ProjectLink];
    remove: [node: ProjectNode];
    delete: [node: ProjectNode];
}>();

const nodeById = computed(() => new Map(nodes.map((n) => [n.id, n])));
const rows = computed(() =>
    [...nodes].sort(
        (a, b) =>
            Number(b.kind === 'app') - Number(a.kind === 'app') || a.id - b.id,
    ),
);

function linksOf(node: ProjectNode): NodeLink[] {
    return links
        .filter((l) =>
            node.kind === 'app' ? l.app === node.id : l.resource === node.id,
        )
        .map((link) => ({
            link,
            other: nodeById.value.get(
                node.kind === 'app' ? link.resource : link.app,
            ),
        }));
}

function unlinked(app: ProjectNode): ProjectNode[] {
    if (app.kind !== 'app') return [];
    const used = new Set(
        links.filter((l) => l.app === app.id).map((l) => l.resource),
    );

    return nodes.filter((r) => r.kind !== 'app' && !used.has(r.id));
}

function address(node: ProjectNode): string | null {
    const member = members.find((m) => m.machine === node.machine.id);
    if (!member) return null;

    return node.port ? `${member.address}:${node.port}` : member.address;
}

function setting(link: ProjectLink): string {
    const resource = nodeById.value.get(link.resource);

    return (
        link.env[0] ??
        (resource?.template ? linkSettings[resource.template] : null) ??
        resource?.name ??
        ''
    );
}

function sub(node: ProjectNode): string {
    const software = nodeSoftware(node);
    if (!node.data) return software;
    const data =
        node.data.state === 'copied'
            ? trans('Data copied :when', { when: ago(node.data.at) })
            : node.data.state === 'copying'
              ? trans('Copying data…')
              : node.data.state === 'waiting'
                ? trans('Data copy waiting')
                : trans('Couldn’t copy data');

    return `${software} · ${data}`;
}

const head =
    'p-0 text-left text-[12px]/4 font-medium whitespace-nowrap text-fg-tertiary';
const pill =
    'rounded-[5px] border-[0.5px] border-[#dcdcdc] bg-[#f4f4f4] px-1.5 py-0.5 font-ui-mono text-[10.5px]/4 whitespace-nowrap dark:border-[#323232] dark:bg-[#202020]';
</script>

<template>
    <div
        class="overflow-x-auto rounded-[10px] border-[0.5px] border-edge-card bg-surface-main shadow-card"
    >
        <table class="w-full min-w-[1000px] border-separate border-spacing-0">
            <thead>
                <tr
                    class="h-9 bg-[#f6f6f6] dark:bg-[#1b1b1b] [&>th]:border-b-[0.5px] [&>th]:border-edge-tabs"
                >
                    <th :class="head" class="w-[266px] pl-4">
                        {{ $t('Service') }}
                    </th>
                    <th :class="head" class="w-[110px]">{{ $t('Kind') }}</th>
                    <th :class="head" class="w-[170px]">
                        {{ $t('Machine') }}
                    </th>
                    <th :class="head" class="w-[180px]">
                        {{ $t('Private address') }}
                    </th>
                    <th :class="head" class="w-[270px]">
                        {{ $t('Connected to') }}
                    </th>
                    <th :class="head" class="pr-3">{{ $t('Status') }}</th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="node in rows"
                    :key="node.id"
                    class="h-12 transition-colors hover:bg-surface-hover [&:last-child>td]:border-b-0 [&>td]:border-b-[0.5px] [&>td]:border-edge-tabs"
                >
                    <td class="p-0 pl-4">
                        <div class="flex items-center gap-2 pr-3">
                            <span
                                class="flex size-7 shrink-0 items-center justify-center rounded-[7px] border-[0.5px] border-[#e0e0e0] bg-[#f4f4f4] dark:border-[#353535] dark:bg-[#232323]"
                            >
                                <BrandLogo :name="nodeLogo(node)" />
                            </span>
                            <span class="flex min-w-0 flex-col">
                                <component
                                    :is="node.href ? Link : 'span'"
                                    :href="node.href ?? undefined"
                                    class="truncate text-[13px]/[18px] font-medium tracking-[-0.01em] text-fg-primary"
                                    :class="
                                        node.href &&
                                        'hover:underline hover:underline-offset-4'
                                    "
                                    >{{ node.name }}</component
                                >
                                <span
                                    class="truncate text-[12px]/4 tracking-[-0.005em]"
                                    :class="
                                        node.data?.state === 'failed'
                                            ? 'text-status-down'
                                            : 'text-fg-tertiary'
                                    "
                                    :title="node.data?.error ?? undefined"
                                    >{{ sub(node) }}</span
                                >
                            </span>
                        </div>
                    </td>
                    <td class="p-0">
                        <span
                            class="inline-flex rounded-full border-[0.5px] border-[#dcdcdc] bg-[#f4f4f4] px-2 py-0.5 text-[12px]/4 font-medium tracking-[-0.005em] whitespace-nowrap text-fg-secondary dark:border-[#323232] dark:bg-[#202020]"
                            >{{ $t(kindLabels[node.kind]) }}</span
                        >
                    </td>
                    <td class="p-0">
                        <span class="flex items-center gap-2 pr-3">
                            <BrandLogo
                                :name="node.machine.provider"
                                :fallback="false"
                            />
                            <span
                                class="truncate text-[13px]/5 font-medium tracking-[-0.01em] text-fg-secondary"
                                >{{ node.machine.name }}</span
                            >
                        </span>
                    </td>
                    <td class="p-0">
                        <span
                            class="font-ui-mono text-[12px]/4 whitespace-nowrap"
                            :class="
                                address(node)
                                    ? 'text-fg-tertiary'
                                    : 'text-fg-faint'
                            "
                            >{{ address(node) ?? '–' }}</span
                        >
                    </td>
                    <td class="p-0">
                        <span
                            v-if="node.kind === 'app'"
                            class="flex items-center gap-1.5 pr-3"
                        >
                            <span
                                v-for="item in linksOf(node)"
                                :key="item.link.id"
                                :class="[
                                    pill,
                                    item.link.state === 'failed'
                                        ? 'text-status-down'
                                        : item.link.state === 'pending'
                                          ? 'text-status-deploying'
                                          : 'text-fg-tertiary',
                                ]"
                                :title="
                                    item.link.error ??
                                    (item.other
                                        ? $t('From :name', {
                                              name: item.other.name,
                                          })
                                        : undefined)
                                "
                                >{{ setting(item.link) }}</span
                            >
                            <span
                                v-if="!linksOf(node).length"
                                class="text-[13px]/5 text-fg-faint"
                                >–</span
                            >
                        </span>
                        <span v-else class="flex items-center gap-1.5 pr-3">
                            <template v-if="linksOf(node).length">
                                <span
                                    class="text-[13px]/5 tracking-[-0.005em] text-fg-tertiary"
                                    >{{ $t('Used by') }}</span
                                >
                                <template
                                    v-for="item in linksOf(node)"
                                    :key="item.link.id"
                                >
                                    <BrandLogo
                                        v-if="item.other"
                                        :name="nodeLogo(item.other)"
                                    />
                                    <span
                                        class="truncate text-[13px]/5 font-medium tracking-[-0.01em] text-fg-secondary"
                                        >{{ item.other?.name }}</span
                                    >
                                </template>
                            </template>
                            <span v-else class="text-[13px]/5 text-fg-faint"
                                >–</span
                            >
                        </span>
                    </td>
                    <td class="p-0 pr-3">
                        <span class="flex items-center gap-2">
                            <StatusBadge :status="node.state" />
                            <span class="grow" />
                            <DropdownMenu v-if="canManage">
                                <DropdownMenuTrigger as-child>
                                    <button
                                        type="button"
                                        class="flex h-6 w-7 cursor-pointer items-center justify-center rounded-[6px] text-[14px]/4 font-medium text-fg-faint transition-colors hover:bg-surface-hover hover:text-fg-secondary focus-visible:outline-2 focus-visible:outline-(--ds-ring) data-[state=open]:text-fg-secondary"
                                        :aria-label="
                                            $t('Options for :name', {
                                                name: node.name,
                                            })
                                        "
                                    >
                                        ···
                                    </button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent
                                    class="ds-modal min-w-52 font-ui"
                                    align="end"
                                >
                                    <NodeMenuItems
                                        :node="node"
                                        :unlinked="unlinked(node)"
                                        :links="linksOf(node)"
                                        @open="
                                            node.href && router.visit(node.href)
                                        "
                                        @settings="emit('settings', node)"
                                        @connect="
                                            (r) => emit('connect', node, r)
                                        "
                                        @disconnect="
                                            (l) => emit('disconnect', l)
                                        "
                                        @remove="emit('remove', node)"
                                        @delete="emit('delete', node)"
                                    />
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </span>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
