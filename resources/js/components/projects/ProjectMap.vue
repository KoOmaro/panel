<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { DatabaseBackup, Plus } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    useTemplateRef,
    watch,
} from 'vue';
import BrandLogo from '@/components/dash/BrandLogo.vue';
import DashButton from '@/components/dash/DashButton.vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    ago,
    kindLabels,
    linkSettings,
    nodeLogo,
    nodeSoftware,
    softwareName,
    statusColours,
} from './format';
import NodeMenuItems from './NodeMenuItems.vue';
import type {
    MachineGroup,
    NodeKind,
    NodeLink,
    ProjectLink,
    ProjectNode,
} from './types';

const {
    groups,
    links,
    canManage = false,
    busy = false,
    resourceKinds = [],
    canDeploy = true,
    emptyTitle,
} = defineProps<{
    groups: MachineGroup[];
    links: ProjectLink[];
    canManage?: boolean;
    busy?: boolean;
    resourceKinds?: { kind: Exclude<NodeKind, 'app'>; template: string }[];
    canDeploy?: boolean;
    emptyTitle: string;
}>();

const emit = defineEmits<{
    settings: [node: ProjectNode];
    connect: [app: ProjectNode, resource: ProjectNode];
    disconnect: [link: ProjectLink];
    remove: [node: ProjectNode];
    delete: [node: ProjectNode];
    addApp: [];
    addExisting: [];
    addResource: [kind: Exclude<NodeKind, 'app'>];
}>();

const nodes = computed(() => groups.flatMap((g) => g.nodes));
const nodeById = computed(() => new Map(nodes.value.map((n) => [n.id, n])));
const left = computed(() =>
    groups.filter((g) => g.nodes.some((n) => n.kind === 'app')),
);
const right = computed(() =>
    groups.filter((g) => !g.nodes.some((n) => n.kind === 'app')),
);
const resources = computed(() => nodes.value.filter((n) => n.kind !== 'app'));

const summary = computed(() => {
    const services =
        nodes.value.length === 1
            ? trans('1 service')
            : trans(':count services', { count: String(nodes.value.length) });
    const machines =
        groups.length === 1
            ? trans('1 machine')
            : trans(':count machines', { count: String(groups.length) });

    return trans(':services on :machines', { services, machines });
});

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

    return resources.value.filter((r) => !used.has(r.id));
}

function linked(a: ProjectNode, b: ProjectNode): boolean {
    return links.some(
        (l) =>
            (l.app === a.id && l.resource === b.id) ||
            (l.app === b.id && l.resource === a.id),
    );
}

function gapBefore(group: MachineGroup, index: number): string | undefined {
    if (index === 0) return undefined;

    return linked(group.nodes[index - 1], group.nodes[index]) ? '54px' : '16px';
}

function sub(node: ProjectNode): string {
    const software = nodeSoftware(node);
    if (node.kind === 'app') {
        return node.domain ? `${software} · ${node.domain}` : software;
    }

    return node.port ? `${software} · :${node.port}` : software;
}

function stateLabel(node: ProjectNode): string {
    const labels = {
        live: 'Running',
        deploying: 'Deploying',
        pending: 'Starting',
        stopped: 'Stopped',
        down: 'Its deploy failed',
    } as const;

    return trans(labels[node.state]);
}

function dataLabel(node: ProjectNode): string {
    if (!node.data) return '';
    if (node.data.state === 'copied') {
        return trans('Data copied :when', { when: ago(node.data.at) });
    }
    if (node.data.state === 'copying') return trans('Copying data…');
    if (node.data.state === 'waiting') return trans('Data copy waiting');

    return node.data.error ?? trans('Couldn’t copy data');
}

function memberTitle(group: MachineGroup): string | undefined {
    if (!group.member) return undefined;
    const labels = {
        pending: 'Joining',
        up: 'On the network',
        failed: 'Couldn’t join',
        offline: 'Offline',
    } as const;

    return group.member.error ?? trans(labels[group.member.state]);
}

const memberDot: Record<string, string> = {
    pending: 'bg-status-deploying animate-pulse',
    failed: 'bg-status-down',
    offline: 'bg-status-stopped',
};

const viewport = useTemplateRef<HTMLElement>('viewport');
const content = useTemplateRef<HTMLElement>('content');
const cards = new Map<number, HTMLElement>();
const scale = ref(1);
const offset = ref({ x: 0, y: 0 });
const natural = ref({ width: 0, height: 0 });
const touched = ref(false);

type Wire = {
    id: number;
    d: string;
    state: ProjectLink['state'];
    from: { x: number; y: number };
    to: { x: number; y: number };
    label: string;
    labelAt: { x: number; y: number };
    start: boolean;
    error: string | null;
};
const wires = ref<Wire[]>([]);

function cardRef(id: number) {
    return (el: unknown) => {
        if (el instanceof HTMLElement) cards.set(id, el);
        else cards.delete(id);
    };
}

type Box = {
    left: number;
    top: number;
    right: number;
    bottom: number;
    cx: number;
    cy: number;
};

function box(el: HTMLElement, origin: DOMRect): Box {
    const r = el.getBoundingClientRect();
    const s = scale.value;
    const left = (r.left - origin.left) / s;
    const top = (r.top - origin.top) / s;
    const width = r.width / s;
    const height = r.height / s;

    return {
        left,
        top,
        right: left + width,
        bottom: top + height,
        cx: left + width / 2,
        cy: top + height / 2,
    };
}

type Side = 'left' | 'right' | 'top' | 'bottom';
type Route = 'straight' | 'bracket';

function measure(): void {
    const root = content.value;
    if (!root) return;
    const origin = root.getBoundingClientRect();
    natural.value = {
        width: root.offsetWidth,
        height: root.offsetHeight,
    };

    const machineOf = new Map(
        groups.flatMap((g) => g.nodes.map((n) => [n.id, g.id] as const)),
    );
    const plans = links.flatMap((link) => {
        const appEl = cards.get(link.app);
        const resEl = cards.get(link.resource);
        if (!appEl || !resEl) return [];
        const a = box(appEl, origin);
        const r = box(resEl, origin);
        let from: Side;
        let to: Side;
        let route: Route = 'straight';
        const group = groups.find((g) => g.id === machineOf.get(link.app));
        const gap = group
            ? Math.abs(
                  group.nodes.findIndex((n) => n.id === link.app) -
                      group.nodes.findIndex((n) => n.id === link.resource),
              )
            : 0;
        if (
            machineOf.get(link.app) === machineOf.get(link.resource) &&
            gap > 1
        ) {
            [from, to, route] = ['right', 'right', 'bracket'];
        } else if (
            machineOf.get(link.app) === machineOf.get(link.resource) ||
            (r.left < a.right && r.right > a.left)
        ) {
            [from, to] =
                r.top >= a.bottom ? ['bottom', 'top'] : ['top', 'bottom'];
        } else {
            [from, to] =
                r.left >= a.right ? ['right', 'left'] : ['left', 'right'];
        }

        return [{ link, a, r, from, to, route }];
    });

    const spread = new Map<string, number>();
    const ends = (
        key: (p: (typeof plans)[number]) => string,
        along: (p: (typeof plans)[number]) => number,
        tag: string,
    ) => {
        const buckets = new Map<string, typeof plans>();
        for (const plan of plans) {
            const k = key(plan);
            buckets.set(k, [...(buckets.get(k) ?? []), plan]);
        }
        for (const bucket of buckets.values()) {
            bucket
                .sort((p, q) => along(p) - along(q))
                .forEach((plan, i) =>
                    spread.set(
                        `${tag}${plan.link.id}`,
                        (i - (bucket.length - 1) / 2) * 16,
                    ),
                );
        }
    };
    ends(
        (p) => `${p.link.app}:${p.from}`,
        (p) => (p.from === 'left' || p.from === 'right' ? p.r.cy : p.r.cx),
        'a',
    );
    ends(
        (p) => `${p.link.resource}:${p.to}`,
        (p) => (p.to === 'left' || p.to === 'right' ? p.a.cy : p.a.cx),
        'r',
    );

    const point = (b: Box, side: Side, shift: number) =>
        side === 'left'
            ? { x: b.left, y: b.cy + shift }
            : side === 'right'
              ? { x: b.right, y: b.cy + shift }
              : side === 'top'
                ? { x: b.cx + shift, y: b.top }
                : { x: b.cx + shift, y: b.bottom };

    const ordered = [...plans].sort(
        (p, q) =>
            Number(p.link.state === 'applied') -
            Number(q.link.state === 'applied'),
    );
    wires.value = ordered.map(({ link, a, r, from, to, route }) => {
        const p1 = point(a, from, spread.get(`a${link.id}`) ?? 0);
        const p2 = point(r, to, spread.get(`r${link.id}`) ?? 0);
        const horizontal = from === 'left' || from === 'right';
        let d: string;
        let labelAt = { x: (p1.x + p2.x) / 2, y: (p1.y + p2.y) / 2 };
        let start = false;
        if (route === 'bracket') {
            const edge = Math.max(p1.x, p2.x) + 48;
            d = `M ${p1.x} ${p1.y} C ${edge} ${p1.y} ${edge} ${p2.y} ${p2.x} ${p2.y}`;
            labelAt = { x: Math.max(p1.x, p2.x) + 42, y: labelAt.y };
            start = true;
        } else if (horizontal) {
            const dx =
                Math.max(24, Math.abs(p2.x - p1.x) * 0.27) *
                (p2.x >= p1.x ? 1 : -1);
            d = `M ${p1.x} ${p1.y} C ${p1.x + dx} ${p1.y} ${p2.x - dx} ${p2.y} ${p2.x} ${p2.y}`;
        } else if (Math.abs(p1.x - p2.x) < 0.5) {
            d = `M ${p1.x} ${p1.y} L ${p2.x} ${p2.y}`;
        } else {
            const dy = Math.abs(p2.y - p1.y) * 0.4 * (p2.y >= p1.y ? 1 : -1);
            d = `M ${p1.x} ${p1.y} C ${p1.x} ${p1.y + dy} ${p2.x} ${p2.y - dy} ${p2.x} ${p2.y}`;
        }
        const resource = nodeById.value.get(link.resource);

        return {
            id: link.id,
            d,
            state: link.state,
            from: p1,
            to: p2,
            label:
                link.env[0] ??
                (resource?.template ? linkSettings[resource.template] : '') ??
                '',
            labelAt,
            start,
            error: link.error,
        };
    });
}

const viewportHeight = computed(() =>
    Math.min(560, Math.max(279, Math.ceil(natural.value.height))),
);

function centre(): void {
    const el = viewport.value;
    if (!el) return;
    const s = scale.value;
    offset.value = {
        x: Math.round(
            Math.max(0, (el.clientWidth - natural.value.width * s) / 2),
        ),
        y: Math.round(
            Math.max(0, (el.clientHeight - natural.value.height * s) / 2),
        ),
    };
}

function fit(): void {
    const el = viewport.value;
    if (!el || !natural.value.width) return;
    scale.value = Math.max(
        0.4,
        Math.min(
            1,
            el.clientWidth / natural.value.width,
            el.clientHeight / natural.value.height,
        ),
    );
    touched.value = true;
    centre();
}

function zoom(step: number): void {
    const el = viewport.value;
    if (!el) return;
    const before = scale.value;
    const next =
        Math.round(Math.min(1.6, Math.max(0.4, before + step)) * 10) / 10;
    const cx = el.clientWidth / 2;
    const cy = el.clientHeight / 2;
    offset.value = {
        x: cx - ((cx - offset.value.x) / before) * next,
        y: cy - ((cy - offset.value.y) / before) * next,
    };
    scale.value = next;
    touched.value = true;
}

let drag: { x: number; y: number; ox: number; oy: number; id: number } | null =
    null;

function startPan(event: PointerEvent): void {
    if (event.button !== 0) return;
    if ((event.target as HTMLElement).closest('[data-map-item]')) return;
    drag = {
        x: event.clientX,
        y: event.clientY,
        ox: offset.value.x,
        oy: offset.value.y,
        id: event.pointerId,
    };
    viewport.value?.setPointerCapture(event.pointerId);
}

function pan(event: PointerEvent): void {
    if (!drag || drag.id !== event.pointerId) return;
    offset.value = {
        x: drag.ox + event.clientX - drag.x,
        y: drag.oy + event.clientY - drag.y,
    };
    touched.value = true;
}

function endPan(event: PointerEvent): void {
    if (!drag || drag.id !== event.pointerId) return;
    viewport.value?.releasePointerCapture(event.pointerId);
    drag = null;
}

function relayout(): void {
    nextTick(() => {
        measure();
        if (!touched.value && scale.value === 1) offset.value = { x: 0, y: 0 };
    });
}

let observer: ResizeObserver | null = null;

watch(() => [groups, links], relayout, { deep: true });

onMounted(() => {
    relayout();
    observer = new ResizeObserver(relayout);
    if (viewport.value) observer.observe(viewport.value);
    watch(
        content,
        (el, old) => {
            if (old) observer?.unobserve(old);
            if (el) observer?.observe(el);
            relayout();
        },
        { immediate: true },
    );
});

onBeforeUnmount(() => observer?.disconnect());

const control =
    'flex h-6 cursor-pointer items-center justify-center rounded-[6px] border-[0.5px] border-edge-control bg-white px-2 text-[12px]/4 font-medium text-fg-tertiary transition-colors hover:text-fg-primary disabled:cursor-default disabled:opacity-50 dark:bg-[#1d1d1d]';
const card =
    'flex h-[60px] w-[228px] items-center gap-2.5 rounded-[10px] border-[0.5px] border-[#dcdcdc] bg-white px-3 text-left shadow-[0_3px_4px_-2px_rgb(0_0_0/0.12),0_1px_2px_rgb(0_0_0/0.12)] transition-colors dark:border-[#323232] dark:bg-[#1e1e1e] dark:shadow-[0_3px_4px_-2px_rgb(0_0_0/0.4),0_1px_2px_rgb(0_0_0/0.5)]';
const ghost =
    'flex h-[60px] w-[228px] cursor-pointer items-center justify-center gap-1.5 rounded-[10px] border border-dashed border-edge-strong text-[13px]/5 font-medium text-fg-muted transition-colors hover:border-edge-tile hover:text-fg-secondary disabled:pointer-events-none disabled:opacity-50';

defineExpose({ fit });
</script>

<template>
    <section
        class="flex min-w-0 flex-col overflow-hidden rounded-[10px] border-[0.5px] border-edge-card bg-[#fafafa] shadow-card dark:bg-[#151515]"
        :aria-label="$t('Map')"
    >
        <header
            class="flex h-10 shrink-0 items-center gap-2 border-b-[0.5px] border-edge-cell pr-2.5 pl-3.5"
        >
            <h2
                class="text-[13px]/5 font-medium tracking-[-0.01em] text-fg-primary"
            >
                {{ $t('Map') }}
            </h2>
            <p
                v-if="nodes.length"
                class="truncate text-[13px]/5 tracking-[-0.005em] text-fg-tertiary"
            >
                {{ summary }}
            </p>
            <span class="grow" />
            <button
                type="button"
                :class="control"
                :disabled="!nodes.length"
                :aria-label="$t('Zoom out')"
                @click="zoom(-0.1)"
            >
                −
            </button>
            <button
                type="button"
                :class="control"
                :disabled="!nodes.length"
                :aria-label="$t('Zoom in')"
                @click="zoom(0.1)"
            >
                +
            </button>
            <button
                type="button"
                :class="control"
                :disabled="!nodes.length"
                @click="fit"
            >
                {{ $t('Fit') }}
            </button>
        </header>

        <div
            v-if="!nodes.length"
            class="flex min-h-[279px] flex-col items-center justify-center bg-[radial-gradient(circle,var(--map-dot)_0.75px,transparent_0.9px)] [background-size:20px_20px] px-6 py-10 text-center [--map-dot:rgb(0_0_0/0.1)] dark:[--map-dot:rgb(255_255_255/0.09)]"
        >
            <p class="text-[13px]/5 font-medium text-fg-primary">
                {{ emptyTitle }}
            </p>
            <p class="mt-1 max-w-sm text-[13px]/5 text-pretty text-fg-tertiary">
                {{
                    $t(
                        'Start with an app you’ve deployed, then add a database, a cache or storage.',
                    )
                }}
            </p>
            <DashButton
                v-if="canManage"
                class="mt-4"
                :icon="Plus"
                :label="$t('Add an app')"
                @click="emit('addApp')"
            />
        </div>

        <div
            v-else
            ref="viewport"
            class="relative cursor-grab touch-none overflow-hidden select-none [--map-dot:rgb(0_0_0/0.1)] active:cursor-grabbing dark:[--map-dot:rgb(255_255_255/0.09)]"
            :style="{
                height: `${viewportHeight}px`,
                backgroundImage:
                    'radial-gradient(circle, var(--map-dot) 0.75px, transparent 0.9px)',
                backgroundSize: `${20 * scale}px ${20 * scale}px`,
                backgroundPosition: `${offset.x}px ${offset.y}px`,
            }"
            @pointerdown="startPan"
            @pointermove="pan"
            @pointerup="endPan"
            @pointercancel="endPan"
        >
            <div
                ref="content"
                class="absolute top-0 left-0 flex w-max origin-top-left items-stretch gap-[177px] px-6 pt-[18px] pb-[17px]"
                :style="{
                    transform: `translate(${offset.x}px, ${offset.y}px) scale(${scale})`,
                }"
            >
                <svg
                    class="pointer-events-none absolute top-0 left-0 overflow-visible"
                    :width="natural.width"
                    :height="natural.height"
                    aria-hidden="true"
                >
                    <g v-for="wire in wires" :key="wire.id">
                        <path
                            :d="wire.d"
                            fill="none"
                            stroke-width="1.2"
                            :stroke-dasharray="
                                wire.state === 'pending' ? '3 4' : undefined
                            "
                            :class="[
                                wire.state === 'failed'
                                    ? 'stroke-status-down'
                                    : wire.state === 'pending'
                                      ? 'animate-[dash-flow_1.4s_linear_infinite] stroke-status-deploying motion-reduce:animate-none'
                                      : 'stroke-[#c4c4c4] dark:stroke-[#4a4a4a]',
                            ]"
                        />
                        <circle
                            v-for="(p, i) in [wire.from, wire.to]"
                            :key="i"
                            :cx="p.x"
                            :cy="p.y"
                            r="2.5"
                            class="fill-[#b0b0b0] dark:fill-[#6a6a6a]"
                        />
                    </g>
                </svg>

                <div
                    v-for="(column, c) in [left, right]"
                    :key="c"
                    class="flex w-[270px] flex-col gap-5"
                >
                    <div
                        v-for="group in column"
                        :key="group.id"
                        class="relative flex grow flex-col justify-center rounded-[12px] border border-dashed border-[#d4d4d4] bg-black/[0.01] px-[20.5px] pt-[35px] pb-[15px] dark:border-[#343434] dark:bg-white/[0.01]"
                    >
                        <p
                            class="absolute top-2 left-[11px] flex items-center gap-1.5"
                            :title="memberTitle(group)"
                        >
                            <BrandLogo
                                :name="group.provider"
                                :size="12"
                                :fallback="false"
                            />
                            <span
                                class="text-[12px]/4 font-medium tracking-[-0.005em] text-fg-secondary"
                                >{{ group.name }}</span
                            >
                            <span
                                v-if="group.member"
                                class="font-ui-mono text-[11px]/4 text-fg-tertiary"
                                >{{ group.member.address }}</span
                            >
                            <span
                                v-if="
                                    group.member && group.member.state !== 'up'
                                "
                                class="size-1.5 rounded-full"
                                :class="memberDot[group.member.state]"
                            />
                        </p>

                        <div
                            v-for="(node, i) in group.nodes"
                            :key="node.id"
                            :ref="cardRef(node.id)"
                            data-map-item
                            class="w-[228px]"
                            :style="{ marginTop: gapBefore(group, i) }"
                        >
                            <DropdownMenu v-if="canManage">
                                <DropdownMenuTrigger as-child>
                                    <button
                                        type="button"
                                        :class="card"
                                        class="cursor-pointer hover:border-edge-tile focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring) data-[state=open]:border-edge-tile"
                                        :aria-label="
                                            $t('Options for :name', {
                                                name: node.name,
                                            })
                                        "
                                    >
                                        <span
                                            class="flex size-8 shrink-0 items-center justify-center rounded-[8px] border-[0.5px] border-[#e0e0e0] bg-[#f4f4f4] dark:border-[#383838] dark:bg-[#262626]"
                                        >
                                            <BrandLogo
                                                :name="nodeLogo(node)"
                                                :size="18"
                                            />
                                        </span>
                                        <span
                                            class="flex min-w-0 grow flex-col"
                                        >
                                            <span
                                                class="truncate text-[13px]/[18px] font-medium tracking-[-0.01em] text-fg-primary"
                                                >{{ node.name }}</span
                                            >
                                            <span
                                                class="truncate text-[12px]/4 tracking-[-0.005em] text-fg-tertiary"
                                                >{{ sub(node) }}</span
                                            >
                                        </span>
                                        <DatabaseBackup
                                            v-if="node.data"
                                            class="size-3 shrink-0"
                                            :class="
                                                node.data.state === 'failed'
                                                    ? 'text-status-down'
                                                    : 'text-fg-faint'
                                            "
                                            :stroke-width="1.75"
                                            :aria-label="dataLabel(node)"
                                        />
                                        <span
                                            class="size-[7px] shrink-0 rounded-full"
                                            :class="
                                                node.state === 'deploying' &&
                                                'animate-pulse'
                                            "
                                            :style="{
                                                background:
                                                    statusColours[node.state],
                                                boxShadow: `0 0 4px color-mix(in srgb, ${statusColours[node.state]} 50%, transparent)`,
                                            }"
                                            :title="stateLabel(node)"
                                        />
                                    </button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent
                                    class="ds-modal min-w-52 font-ui"
                                    align="start"
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
                            <component
                                :is="node.href ? Link : 'div'"
                                v-else
                                :href="node.href ?? undefined"
                                :class="card"
                            >
                                <span
                                    class="flex size-8 shrink-0 items-center justify-center rounded-[8px] border-[0.5px] border-[#e0e0e0] bg-[#f4f4f4] dark:border-[#383838] dark:bg-[#262626]"
                                >
                                    <BrandLogo
                                        :name="nodeLogo(node)"
                                        :size="18"
                                    />
                                </span>
                                <span class="flex min-w-0 grow flex-col">
                                    <span
                                        class="truncate text-[13px]/[18px] font-medium tracking-[-0.01em] text-fg-primary"
                                        >{{ node.name }}</span
                                    >
                                    <span
                                        class="truncate text-[12px]/4 tracking-[-0.005em] text-fg-tertiary"
                                        >{{ sub(node) }}</span
                                    >
                                </span>
                                <span
                                    class="size-[7px] shrink-0 rounded-full"
                                    :style="{
                                        background: statusColours[node.state],
                                        boxShadow: `0 0 4px color-mix(in srgb, ${statusColours[node.state]} 50%, transparent)`,
                                    }"
                                    :title="stateLabel(node)"
                                />
                            </component>
                        </div>
                    </div>

                    <template
                        v-if="
                            canManage &&
                            !column.length &&
                            (c === 0 || !resources.length)
                        "
                    >
                        <button
                            v-if="c === 0"
                            type="button"
                            data-map-item
                            :class="ghost"
                            class="mx-[21px]"
                            @click="emit('addApp')"
                        >
                            <Plus class="size-3.5" :stroke-width="1.75" />
                            {{ $t('Add an app') }}
                        </button>
                        <DropdownMenu v-else>
                            <DropdownMenuTrigger as-child>
                                <button
                                    type="button"
                                    data-map-item
                                    :class="ghost"
                                    class="mx-[21px]"
                                    :disabled="busy || !canDeploy"
                                >
                                    <Plus
                                        class="size-3.5"
                                        :stroke-width="1.75"
                                    />
                                    {{ $t('Add a database, cache or storage') }}
                                </button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent
                                class="ds-modal min-w-56 font-ui"
                                align="start"
                            >
                                <DropdownMenuItem
                                    v-for="option in resourceKinds"
                                    :key="option.kind"
                                    @select="emit('addResource', option.kind)"
                                >
                                    <BrandLogo :name="option.template" />
                                    {{ $t(kindLabels[option.kind]) }}
                                    <span
                                        class="ml-auto text-xs text-fg-muted"
                                        >{{
                                            softwareName(null, option.template)
                                        }}</span
                                    >
                                </DropdownMenuItem>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem @select="emit('addExisting')">
                                    <Plus class="size-4" :stroke-width="1.75" />
                                    {{ $t('A service you’ve deployed') }}
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </template>
                </div>

                <span
                    v-for="wire in wires.filter((w) => w.label)"
                    :key="`label-${wire.id}`"
                    class="pointer-events-none absolute -translate-y-1/2 rounded-[5px] border-[0.5px] border-edge-strong bg-white px-1.5 py-0.5 font-ui-mono text-[10.5px]/[14px] whitespace-nowrap dark:bg-[#191919]"
                    :class="[
                        wire.state === 'failed'
                            ? 'text-status-down'
                            : 'text-fg-tertiary',
                        !wire.start && '-translate-x-1/2',
                    ]"
                    :style="{
                        left: `${wire.labelAt.x}px`,
                        top: `${wire.labelAt.y}px`,
                    }"
                    :title="wire.error ?? undefined"
                    >{{ wire.label }}</span
                >
            </div>
        </div>
    </section>
</template>
