<script setup lang="ts">
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
import FilledIcon from '@/components/icons/FilledIcon.vue';
import {
    GRID_SIZE,
    MAX_ZOOM,
    MIN_ZOOM,
    NODE_HEIGHT,
    NODE_WIDTH,
    clampZoom,
    freePlacement,
    hasInput,
    inAnchor,
    nodeHeight,
    openPorts,
    outAnchor,
    placementFor,
    portFromPointer,
    snapToNodes,
    tidy,
    toWorld,
    zoomAround,
} from './geometry';
import type { OpenPort, Point, View } from './geometry';
import { EVENTS, FROM_TRIGGER, STEPS } from './kinds';
import type { Options, PortId, StepKind, WorkflowNode } from './kinds';
import NodePanel from './NodePanel.vue';
import StepPicker from './StepPicker.vue';
import { useWorkflowEditor } from './useWorkflowEditor';
import { nodeSubtitle, nodeVisual } from './visual';
import type { NodeVisual } from './visual';
import WorkflowEdges from './WorkflowEdges.vue';
import type { EdgePreview } from './WorkflowEdges.vue';
import WorkflowNodeCard from './WorkflowNode.vue';

const props = defineProps<{
    options: Options;
    webhookUrl: string | null;
    twoFactor: boolean;
    problems: Record<string, string>;
    insetLeft?: number;
}>();

const emit = defineEmits<{ rotateWebhook: [] }>();

defineSlots<{ default?: () => unknown; dock?: () => unknown }>();

const editor = useWorkflowEditor();
const { nodes, edges, sizes, view, selection, readOnly } = editor;

const container = useTemplateRef<HTMLDivElement>('container');
const pickerEl = ref<HTMLElement | null>(null);

function setPicker(component: unknown): void {
    pickerEl.value = (component as { $el?: HTMLElement } | null)?.$el ?? null;
}

const SNAP_RADIUS = 48;
const DRAG_THRESHOLD = 4;
const ZOOM_STEP = 1.25;
const PICKER_INSET = 320;
const INSPECTOR_INSET = 480;
const SNAP_TOLERANCE = 10;
const MARGIN = 60;

type PaletteDrag = {
    kind: StepKind;
    clientX: number;
    clientY: number;
    snap: OpenPort | null;
    placement: Point | null;
};

type LinkDrag = {
    source: string;
    port: PortId | null;
    pointer: Point;
    target: string | null;
};

const palette = ref<PaletteDrag | null>(null);
const link = ref<LinkDrag | null>(null);
const draggingNode = ref<string | null>(null);
const panning = ref(false);

let stopTracking: (() => void) | null = null;
let animation = 0;

function bounds(): DOMRect {
    return container.value!.getBoundingClientRect();
}

function track(
    onMove: (event: PointerEvent) => void,
    onEnd: (event: PointerEvent, cancelled: boolean) => void,
): void {
    stopTracking?.();
    const up = (event: PointerEvent) => {
        stop();
        onEnd(event, false);
    };
    const cancel = (event: PointerEvent) => {
        stop();
        onEnd(event, true);
    };
    function stop() {
        window.removeEventListener('pointermove', onMove);
        window.removeEventListener('pointerup', up);
        window.removeEventListener('pointercancel', cancel);
        stopTracking = null;
    }
    window.addEventListener('pointermove', onMove);
    window.addEventListener('pointerup', up);
    window.addEventListener('pointercancel', cancel);
    stopTracking = () => cancel(new PointerEvent('pointercancel'));
}

function animateView(update: (current: View) => View): void {
    cancelAnimationFrame(animation);
    const from = { ...view.value };
    const to = update(from);
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        view.value = to;

        return;
    }
    const start = performance.now();
    const ease = (t: number) =>
        t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2;
    const frame = (now: number) => {
        const t = Math.min(1, (now - start) / 250);
        const p = ease(t);
        view.value = {
            x: from.x + (to.x - from.x) * p,
            y: from.y + (to.y - from.y) * p,
            zoom: from.zoom + (to.zoom - from.zoom) * p,
        };
        if (t < 1) animation = requestAnimationFrame(frame);
    };
    animation = requestAnimationFrame(frame);
}

function rightInset(width: number): number {
    if (editor.inspector.value && width >= 1100) return INSPECTOR_INSET;

    return !readOnly.value && width >= 900 ? PICKER_INSET : 0;
}

function findSnap(world: Point, zoom: number) {
    let best: { port: OpenPort; placement: Point } | null = null;
    let bestDistance = Infinity;
    for (const port of openPorts(nodes.value, edges.value, sizes)) {
        const placement = placementFor(port.source, port.port, sizes);
        const distance =
            Math.hypot(world.x - port.point.x, world.y - port.point.y) * zoom;
        const inside =
            world.x >= placement.x &&
            world.x <= placement.x + NODE_WIDTH &&
            world.y >= placement.y &&
            world.y <= placement.y + NODE_HEIGHT;
        if ((distance <= SNAP_RADIUS || inside) && distance < bestDistance) {
            best = {
                port,
                placement: freePlacement(placement, nodes.value, sizes),
            };
            bestDistance = distance;
        }
    }

    return best;
}

function nodeAt(world: Point, exclude: string): string | null {
    for (let i = nodes.value.length - 1; i >= 0; i--) {
        const node = nodes.value[i];
        if (node.id === exclude || !hasInput(node)) continue;
        const height = nodeHeight(node, sizes);
        if (
            world.x >= node.x - 8 &&
            world.x <= node.x + NODE_WIDTH + 8 &&
            world.y >= node.y - 16 &&
            world.y <= node.y + height
        )
            return node.id;
    }

    return null;
}

function reveal(node: WorkflowNode): void {
    const rect = bounds();
    const current = view.value;
    const right = rightInset(rect.width) || 16;
    const left = rect.width / 2 + current.x + node.x * current.zoom;
    const top = current.y + node.y * current.zoom;
    const visible =
        left >= 16 &&
        left + NODE_WIDTH * current.zoom <= rect.width - right &&
        top >= 16 &&
        top + (NODE_HEIGHT + 60) * current.zoom <= rect.height - 80;
    if (visible) return;
    const centerX = (rect.width - right) / 2;
    animateView((value) => ({
        ...value,
        x: centerX - rect.width / 2 - (node.x + NODE_WIDTH / 2) * value.zoom,
        y: rect.height / 2 - (node.y + NODE_HEIGHT / 2) * value.zoom,
    }));
}

function create(
    kind: StepKind,
    position: Point,
    source: { source: string; port: PortId } | null,
): WorkflowNode {
    const node = editor.createNode(kind, position.x, position.y);
    const event = EVENTS.find(
        (e) =>
            e.id === nodes.value.find((n) => n.kind === 'trigger')?.rules.event,
    );
    if (
        event?.machine &&
        ['restart', 'start', 'stop', 'backup', 'command', 'redeploy'].includes(
            kind,
        )
    ) {
        node.rules.machine_id = FROM_TRIGGER;
        if (
            event.service === (kind === 'redeploy' ? 'deploy' : 'key') &&
            kind !== 'backup'
        )
            node.rules.service = FROM_TRIGGER;
    }
    editor.addNode(node, source);

    return node;
}

const PORT_ORDER: Record<PortId, number> = {
    true: 0,
    out: 1,
    false: 2,
    failed: 3,
};

const target = computed<OpenPort | null>(() => {
    const ports = openPorts(nodes.value, edges.value, sizes);
    const pending = editor.pendingPort.value;
    if (pending) {
        const port = ports.find(
            (p) => p.source.id === pending.source && p.port === pending.port,
        );
        if (port) return port;
    }
    if (selection.value?.type === 'node') {
        const id = selection.value.id;
        const port = ports.find(
            (p) => p.source.id === id && p.port !== 'failed',
        );
        if (port) return port;
    }
    const root = nodes.value.find((n) => n.kind === 'trigger');
    const order: string[] = [];
    if (root) {
        const queue = [root.id];
        const seen = new Set(queue);
        while (queue.length) {
            const id = queue.shift()!;
            order.push(id);
            for (const edge of edges.value
                .filter((e) => e.source === id)
                .sort((a, b) => PORT_ORDER[a.port] - PORT_ORDER[b.port])) {
                if (seen.has(edge.target)) continue;
                seen.add(edge.target);
                queue.push(edge.target);
            }
        }
    }
    const rank = (id: string) => {
        const i = order.indexOf(id);

        return i === -1 ? Infinity : i;
    };
    const candidates = ports
        .filter((p) => p.port !== 'failed')
        .sort(
            (a, b) =>
                rank(a.source.id) - rank(b.source.id) ||
                PORT_ORDER[a.port] - PORT_ORDER[b.port],
        );
    const leaf = candidates.find(
        (p) => !edges.value.some((e) => e.source === p.source.id),
    );

    return leaf ?? candidates[0] ?? null;
});

const targetKey = computed(() =>
    target.value ? `${target.value.source.id}:${target.value.port}` : null,
);

const targetLabel = computed(() => {
    const port = target.value;
    if (!port) return null;
    const title = port.source.title || STEPS[port.source.kind].title;

    return port.port === 'true' || port.port === 'false'
        ? `${title} (${port.port === 'true' ? 'yes' : 'no'})`
        : title;
});

function addToNextSlot(kind: StepKind): void {
    if (readOnly.value) return;
    const port = target.value;
    if (port) {
        const node = create(
            kind,
            freePlacement(
                placementFor(port.source, port.port, sizes),
                nodes.value,
                sizes,
            ),
            { source: port.source.id, port: port.port },
        );
        editor.openInspector(node.id, 'build');
        void nextTick(() => reveal(node));

        return;
    }
    const rect = bounds();
    const middle = toWorld(
        rect.left + (rect.width - rightInset(rect.width)) / 2,
        rect.top + rect.height / 3,
        rect,
        view.value,
    );
    const node = create(
        kind,
        freePlacement(
            { x: middle.x - NODE_WIDTH / 2, y: middle.y },
            nodes.value,
            sizes,
        ),
        null,
    );
    editor.openInspector(node.id, 'build');
    void nextTick(() => reveal(node));
}

function resolvePalette(
    kind: StepKind,
    clientX: number,
    clientY: number,
): PaletteDrag {
    const base: PaletteDrag = {
        kind,
        clientX,
        clientY,
        snap: null,
        placement: null,
    };
    const rect = bounds();
    const panel = pickerEl.value?.getBoundingClientRect();
    const overPanel =
        panel &&
        clientX >= panel.left &&
        clientX <= panel.right &&
        clientY >= panel.top &&
        clientY <= panel.bottom;
    const inside =
        clientX >= rect.left &&
        clientX <= rect.right &&
        clientY >= rect.top &&
        clientY <= rect.bottom;
    if (overPanel || !inside) return base;
    const world = toWorld(clientX, clientY, rect, view.value);
    const snap = findSnap(world, view.value.zoom);
    if (snap) return { ...base, snap: snap.port, placement: snap.placement };

    return {
        ...base,
        placement: {
            x: world.x - NODE_WIDTH / 2,
            y: world.y - NODE_HEIGHT / 2,
        },
    };
}

function onItemPointerdown(kind: StepKind, event: PointerEvent): void {
    if (event.button !== 0 || readOnly.value) return;
    const startX = event.clientX;
    const startY = event.clientY;
    let active = false;
    track(
        (move) => {
            if (
                !active &&
                Math.hypot(move.clientX - startX, move.clientY - startY) <
                    DRAG_THRESHOLD
            )
                return;
            active = true;
            palette.value = resolvePalette(kind, move.clientX, move.clientY);
        },
        (end, cancelled) => {
            palette.value = null;
            if (cancelled) return;
            if (!active) {
                addToNextSlot(kind);

                return;
            }
            const drop = resolvePalette(kind, end.clientX, end.clientY);
            if (!drop.placement) return;
            const node = create(
                kind,
                drop.placement,
                drop.snap
                    ? { source: drop.snap.source.id, port: drop.snap.port }
                    : null,
            );
            editor.openInspector(node.id, 'build');
        },
    );
}

function onNodePointerdown(id: string, event: PointerEvent): void {
    if (event.button !== 0) return;
    event.stopPropagation();
    selection.value = { type: 'node', id };
    editor.pendingPort.value = null;
    if (readOnly.value) {
        editor.openInspector(id);

        return;
    }
    const node = nodes.value.find((n) => n.id === id);
    if (!node) return;
    const zoom = view.value.zoom;
    const startX = event.clientX;
    const startY = event.clientY;
    const origin = { x: node.x, y: node.y };
    let active = false;
    track(
        (move) => {
            const dx = move.clientX - startX;
            const dy = move.clientY - startY;
            if (!active) {
                if (Math.hypot(dx, dy) < DRAG_THRESHOLD) return;
                active = true;
                editor.checkpoint();
                draggingNode.value = id;
            }
            const moving = {
                x: origin.x + dx / zoom,
                y: origin.y + dy / zoom,
                width: NODE_WIDTH,
                height: nodeHeight(node, sizes),
            };
            const others = nodes.value
                .filter((n) => n.id !== id)
                .map((n) => ({
                    x: n.x,
                    y: n.y,
                    width: NODE_WIDTH,
                    height: nodeHeight(n, sizes),
                }));
            const snapped = snapToNodes(moving, others, SNAP_TOLERANCE / zoom);
            editor.moveNode(id, Math.round(snapped.x), Math.round(snapped.y));
        },
        () => (draggingNode.value = null),
    );
}

function startLink(
    source: string,
    port: PortId | null,
    event: PointerEvent,
): void {
    if (event.button !== 0 || readOnly.value) return;
    event.stopPropagation();
    const startX = event.clientX;
    const startY = event.clientY;
    let moved = false;
    const measure = (clientX: number, clientY: number): LinkDrag => {
        const pointer = toWorld(clientX, clientY, bounds(), view.value);

        return { source, port, pointer, target: nodeAt(pointer, source) };
    };
    track(
        (move) => {
            if (
                !moved &&
                Math.hypot(move.clientX - startX, move.clientY - startY) <
                    DRAG_THRESHOLD
            )
                return;
            moved = true;
            link.value = measure(move.clientX, move.clientY);
        },
        (end, cancelled) => {
            link.value = null;
            if (cancelled) return;
            if (!moved) {
                if (port) {
                    editor.pendingPort.value = { source, port };
                    editor.inspector.value = null;
                }

                return;
            }
            const result = measure(end.clientX, end.clientY);
            if (!result.target) return;
            const sourceNode = nodes.value.find((n) => n.id === source);
            if (!sourceNode) return;
            editor.connect(
                source,
                port ?? portFromPointer(sourceNode, result.pointer.x),
                result.target,
            );
        },
    );
}

function onEdgePointerdown(id: string, event: PointerEvent): void {
    if (event.button !== 0 || readOnly.value) return;
    event.stopPropagation();
    selection.value = { type: 'edge', id };
}

function onCanvasPointerdown(event: PointerEvent): void {
    if (event.button !== 0 && event.button !== 1) return;
    cancelAnimationFrame(animation);
    const start = { ...view.value };
    const startX = event.clientX;
    const startY = event.clientY;
    let active = false;
    track(
        (move) => {
            const dx = move.clientX - startX;
            const dy = move.clientY - startY;
            if (!active) {
                if (Math.hypot(dx, dy) < DRAG_THRESHOLD) return;
                active = true;
                panning.value = true;
            }
            view.value = { ...start, x: start.x + dx, y: start.y + dy };
        },
        () => {
            panning.value = false;
            if (!active) {
                selection.value = null;
                editor.pendingPort.value = null;
            }
        },
    );
}

function onWheel(event: WheelEvent): void {
    if (
        event.target instanceof Element &&
        event.target.closest('[data-canvas-overlay]')
    )
        return;
    event.preventDefault();
    cancelAnimationFrame(animation);
    const scale = event.deltaMode === 1 ? 16 : 1;
    const rect = bounds();
    if (event.ctrlKey || event.metaKey) {
        const factor = Math.exp(-event.deltaY * scale * 0.01);
        view.value = zoomAround(
            view.value,
            view.value.zoom * factor,
            event.clientX - rect.left,
            event.clientY - rect.top,
            rect.width,
        );

        return;
    }
    view.value = {
        ...view.value,
        x: view.value.x - event.deltaX * scale,
        y: view.value.y - event.deltaY * scale,
    };
}

function onKeydown(event: KeyboardEvent): void {
    const element = event.target;
    if (
        element instanceof Element &&
        element.closest("input, textarea, select, [contenteditable='true']")
    )
        return;
    const key = event.key.toLowerCase();
    if ((event.metaKey || event.ctrlKey) && (key === 'z' || key === 'y')) {
        event.preventDefault();
        if (key === 'y' || event.shiftKey) editor.redo();
        else editor.undo();

        return;
    }
    if (
        (key === 'delete' || key === 'backspace') &&
        selection.value &&
        !readOnly.value
    ) {
        event.preventDefault();
        editor.removeSelection();

        return;
    }
    if (key === 'escape') {
        stopTracking?.();
        palette.value = null;
        link.value = null;
        editor.pendingPort.value = null;
        if (editor.inspector.value) editor.inspector.value = null;
        else selection.value = null;
    }
}

function zoomBy(factor: number): void {
    const rect = bounds();
    animateView((current) =>
        zoomAround(
            current,
            current.zoom * factor,
            rect.width / 2,
            rect.height / 2,
            rect.width,
        ),
    );
}

function overlayRect(
    rect: DOMRect,
): { left: number; top: number; bottom: number } | null {
    const element = editor.inspector.value
        ? container.value?.querySelector('.wf-inspector')
        : pickerEl.value;
    if (!element) return null;
    const box = element.getBoundingClientRect();

    return {
        left: box.left - rect.left - 16,
        top: box.top - rect.top - 16,
        bottom: box.bottom - rect.top + 16,
    };
}

function fitView(options: { animate?: boolean } = {}): void {
    if (!nodes.value.length || !container.value) return;
    const rect = bounds();
    const minX = Math.min(...nodes.value.map((n) => n.x));
    const maxX = Math.max(...nodes.value.map((n) => n.x + NODE_WIDTH));
    const minY = Math.min(...nodes.value.map((n) => n.y));
    const maxY = Math.max(
        ...nodes.value.map((n) => n.y + nodeHeight(n, sizes)),
    );
    const inset = rightInset(rect.width);
    const left = rect.width >= 900 ? (props.insetLeft ?? 0) : 0;
    const panel = overlayRect(rect);
    const place = (room: number): View => {
        const zoom = clampZoom(
            Math.min(
                1,
                (room - MARGIN * 2) / (maxX - minX),
                (rect.height - 200) / (maxY - minY),
            ),
        );
        const width = (maxX - minX) * zoom;
        const height = (maxY - minY) * zoom;
        const middle = Math.max(
            left + (rect.width - left - inset) / 2,
            left + MARGIN + width / 2,
        );
        const top = Math.max(40, (rect.height - height) / 2 - 8);

        return {
            zoom,
            x: middle - rect.width / 2 - ((minX + maxX) / 2) * zoom,
            y: top - minY * zoom,
        };
    };
    const covered = (candidate: View): boolean =>
        panel !== null &&
        nodes.value.some((n) => {
            const x = rect.width / 2 + candidate.x + n.x * candidate.zoom;
            const y = candidate.y + n.y * candidate.zoom;

            return (
                x + NODE_WIDTH * candidate.zoom > panel.left &&
                y < panel.bottom &&
                y + nodeHeight(n, sizes) * candidate.zoom > panel.top
            );
        });
    let next = place(rect.width - left);
    if (covered(next)) next = place(rect.width - left - inset);
    if (options.animate === false) view.value = next;
    else animateView(() => next);
}

function tidyUp(): void {
    if (readOnly.value || !nodes.value.length) return;
    editor.checkpoint();
    const positions = tidy(nodes.value, edges.value);
    for (const [id, point] of Object.entries(positions)) {
        editor.moveNode(id, Math.round(point.x), Math.round(point.y));
    }
    requestAnimationFrame(() => fitView());
}

function focusNode(id: string): void {
    const node = nodes.value.find((n) => n.id === id);
    if (node) reveal(node);
}

function appendTo(sourceId: string, kind: StepKind): void {
    const source = nodes.value.find((n) => n.id === sourceId);
    if (!source) return;
    const port =
        openPorts(nodes.value, edges.value, sizes).find(
            (p) => p.source.id === sourceId && p.port !== 'failed',
        )?.port ?? STEPS[source.kind].ports[0];
    const node = create(
        kind,
        freePlacement(placementFor(source, port, sizes), nodes.value, sizes),
        { source: sourceId, port },
    );
    editor.openInspector(node.id, 'build');
    void nextTick(() => reveal(node));
}

function openNode(id: string): void {
    editor.openInspector(id);
    focusNode(id);
}

defineExpose({ fitView });

onMounted(() => {
    container.value?.addEventListener('wheel', onWheel, { passive: false });
    window.addEventListener('keydown', onKeydown);
    requestAnimationFrame(() => fitView({ animate: false }));
});

onBeforeUnmount(() => {
    container.value?.removeEventListener('wheel', onWheel);
    window.removeEventListener('keydown', onKeydown);
    stopTracking?.();
    cancelAnimationFrame(animation);
});

watch(readOnly, () => requestAnimationFrame(() => fitView()));

const snapKey = computed(() =>
    palette.value?.snap
        ? `${palette.value.snap.source.id}:${palette.value.snap.port}`
        : null,
);

const preview = computed<EdgePreview | null>(() => {
    if (link.value) {
        const source = nodes.value.find((n) => n.id === link.value!.source);
        if (!source) return null;
        const goal = link.value.target
            ? nodes.value.find((n) => n.id === link.value!.target)
            : undefined;
        const port =
            link.value.port ?? portFromPointer(source, link.value.pointer.x);

        return {
            from: outAnchor(source, nodeHeight(source, sizes), port).point,
            to: goal
                ? inAnchor(goal, nodeHeight(goal, sizes)).point
                : link.value.pointer,
        };
    }
    if (palette.value?.snap && palette.value.placement) {
        return {
            from: palette.value.snap.point,
            to: {
                x: palette.value.placement.x + NODE_WIDTH / 2,
                y: palette.value.placement.y,
            },
        };
    }

    return null;
});

const interacting = computed(() =>
    Boolean(palette.value || link.value || draggingNode.value || panning.value),
);

const gridStyle = computed(() => {
    const size = GRID_SIZE * view.value.zoom * (view.value.zoom < 0.5 ? 2 : 1);

    return {
        backgroundSize: `${size}px ${size}px`,
        backgroundPosition: `calc(50% + ${view.value.x + 11 * view.value.zoom}px) ${view.value.y + 11 * view.value.zoom}px`,
    };
});

const visuals = computed(() => {
    const out: Record<string, { visual: NodeVisual; subtitle: string }> = {};
    for (const node of nodes.value) {
        out[node.id] = {
            visual: nodeVisual(node, props.options),
            subtitle: nodeSubtitle(node, props.options),
        };
    }

    return out;
});

const showPicker = computed(
    () => !readOnly.value && editor.inspector.value === null,
);

const dockButton =
    'flex h-8 shrink-0 cursor-pointer items-center justify-center rounded-[8px] px-3 text-[13px]/5 font-medium tracking-[-0.01em] text-fg-secondary transition-colors hover:bg-black/[0.05] hover:text-fg-primary focus-visible:outline-2 focus-visible:outline-(--ds-ring) disabled:cursor-default disabled:opacity-40 disabled:hover:bg-transparent dark:hover:bg-[#242424]';
</script>

<template>
    <div
        ref="container"
        role="application"
        aria-roledescription="workflow canvas"
        :aria-label="$t('Workflow canvas')"
        class="wf-root relative min-h-0 flex-1 touch-none overflow-hidden bg-surface-main font-ui select-none [--wf-dot:rgb(0_0_0/0.12)] [--wf-handle:#ffffff] [--wf-port-ring:#f4f4f4] [--wf-port:#b8b8b8] [--wf-wire-hover:#9a9a9a] [--wf-wire:#c6c6c6] dark:[--wf-dot:rgb(255_255_255/0.1)] dark:[--wf-handle:#1f1f1f] dark:[--wf-port-ring:#131313] dark:[--wf-port:#4a4a4a] dark:[--wf-wire-hover:#5c5c5c] dark:[--wf-wire:#3c3c3c]"
        @pointerdown="onCanvasPointerdown"
    >
        <div
            class="pointer-events-none absolute inset-0 bg-[radial-gradient(circle,var(--wf-dot)_0.8px,transparent_1.2px)]"
            :style="gridStyle"
            aria-hidden="true"
        />

        <div
            class="absolute top-0 left-1/2 origin-top-left"
            :style="{
                transform: `translate(${view.x}px, ${view.y}px) scale(${view.zoom})`,
            }"
        >
            <WorkflowEdges
                :nodes="nodes"
                :edges="edges"
                :sizes="sizes"
                :selection="selection"
                :snap-key="snapKey"
                :target-key="interacting ? null : targetKey"
                :preview="preview"
                :run="editor.run.value"
                :read-only="readOnly"
                @edge-pointerdown="onEdgePointerdown"
                @endpoint-pointerdown="startLink"
                @delete-edge="editor.removeSelection()"
            />

            <div
                v-if="palette?.snap && palette.placement"
                aria-hidden="true"
                class="pointer-events-none absolute top-0 left-0 h-[86px] w-[214px] rounded-[19px] border border-dashed border-edge-checkbox-hover bg-black/[0.03] dark:bg-white/[0.03]"
                :style="{
                    translate: `${palette.placement.x}px ${palette.placement.y}px`,
                }"
            />

            <WorkflowNodeCard
                v-for="node in nodes"
                :key="node.id"
                :node="node"
                :selected="
                    selection?.type === 'node' && selection.id === node.id
                "
                :targeted="link?.target === node.id"
                :dragging="draggingNode === node.id"
                :run-state="editor.runState(node.id)"
                :problem="problems[node.id] ?? null"
                :editing="editor.inspector.value?.nodeId === node.id"
                :read-only="readOnly"
                :visual="visuals[node.id].visual"
                :subtitle="visuals[node.id].subtitle"
                @node-pointerdown="onNodePointerdown"
                @open="openNode"
            />
        </div>

        <StepPicker
            v-if="showPicker"
            :ref="setPicker"
            :target-label="targetLabel"
            :dragging-kind="palette?.kind ?? null"
            @item-pointerdown="onItemPointerdown"
            @add="addToNextSlot"
        />

        <NodePanel
            :options="options"
            :webhook-url="webhookUrl"
            :two-factor="twoFactor"
            :problems="problems"
            @focus="focusNode"
            @append="appendTo"
            @rotate-webhook="emit('rotateWebhook')"
        />

        <div
            role="toolbar"
            :aria-label="$t('Canvas')"
            data-canvas-overlay
            class="absolute bottom-5 left-1/2 z-20 flex max-w-[calc(100%-40px)] -translate-x-1/2 items-center gap-1 overflow-x-auto rounded-[12px] border-[0.5px] border-[#e0e0e0] bg-white p-1.5 shadow-[0_10px_24px_-6px_rgb(0_0_0/0.45),0_1px_2px_rgb(0_0_0/0.5)] dark:border-[#2c2c2c] dark:bg-[#1a1a1a]"
            @pointerdown.stop
        >
            <template v-if="$slots.dock">
                <slot name="dock" />
                <span
                    class="h-[18px] w-[0.5px] shrink-0 bg-edge-control"
                    aria-hidden="true"
                />
            </template>
            <button
                type="button"
                :class="[dockButton, 'w-8 px-0!']"
                :aria-label="$t('Zoom out')"
                :disabled="view.zoom <= MIN_ZOOM + 0.001"
                @click="zoomBy(1 / ZOOM_STEP)"
            >
                −
            </button>
            <button
                type="button"
                :class="[dockButton, 'min-w-[60px] tabular-nums']"
                :title="$t('Back to 100%')"
                @click="
                    animateView((current) => {
                        const rect = bounds();

                        return zoomAround(
                            current,
                            1,
                            rect.width / 2,
                            rect.height / 2,
                            rect.width,
                        );
                    })
                "
            >
                {{ Math.round(view.zoom * 100) }}%
            </button>
            <button
                type="button"
                :class="[dockButton, 'w-8 px-0!']"
                :aria-label="$t('Zoom in')"
                :disabled="view.zoom >= MAX_ZOOM - 0.001"
                @click="zoomBy(ZOOM_STEP)"
            >
                +
            </button>
            <span
                class="h-[18px] w-[0.5px] shrink-0 bg-edge-control"
                aria-hidden="true"
            />
            <button
                type="button"
                :class="dockButton"
                :title="$t('Fit the workflow on screen')"
                @click="fitView()"
            >
                {{ $t('Fit') }}
            </button>
            <button
                v-if="!readOnly"
                type="button"
                :class="dockButton"
                :title="$t('Line the steps up')"
                @click="tidyUp"
            >
                {{ $t('Tidy up') }}
            </button>
        </div>

        <slot />

        <div
            v-if="interacting"
            aria-hidden="true"
            class="fixed inset-0 z-40"
            :class="link ? 'cursor-crosshair' : 'cursor-grabbing'"
        />

        <div
            v-if="palette"
            aria-hidden="true"
            class="pointer-events-none fixed top-0 left-0 z-50"
            :style="{
                translate: `${palette.clientX - (NODE_WIDTH / 2) * view.zoom}px ${palette.clientY - (NODE_HEIGHT / 2) * view.zoom}px`,
            }"
        >
            <div
                class="flex h-[86px] w-[214px] origin-top-left flex-col rounded-[19px] border-[0.5px] border-[#262626] bg-[#101010] p-[5px] opacity-90 shadow-[0_12px_32px_rgb(0_0_0/0.5)] transition-opacity duration-150"
                :class="{ 'opacity-50!': palette.snap }"
                :style="{ scale: String(view.zoom) }"
            >
                <div
                    class="flex flex-1 items-center gap-3 rounded-[14px] border-[0.5px] border-[#2e2e2e] bg-[linear-gradient(164.5deg,#222222_14.286%,#161616_85.714%)] px-4"
                >
                    <BrandLogo
                        v-if="STEPS[palette.kind].kind === 'agent'"
                        name="claude"
                        :size="22"
                    />
                    <FilledIcon
                        v-else
                        :name="
                            nodeVisual(
                                {
                                    id: '',
                                    kind: palette.kind,
                                    x: 0,
                                    y: 0,
                                    title: '',
                                    description: '',
                                    rules: {},
                                },
                                options,
                            ).icon
                        "
                        :size="22"
                        class="text-[#ededed]"
                    />
                    <span class="flex min-w-0 flex-col">
                        <span
                            class="truncate text-[13.5px]/[18px] font-medium text-[#ededed]"
                            >{{ STEPS[palette.kind].title }}</span
                        >
                        <span class="truncate text-[12px]/4 text-[#9a9a9a]">{{
                            STEPS[palette.kind].description
                        }}</span>
                    </span>
                </div>
            </div>
        </div>
    </div>
</template>
