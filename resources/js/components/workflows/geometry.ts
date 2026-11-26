import { STEPS } from './kinds';
import type { PortId, WorkflowEdge, WorkflowNode } from './kinds';

export type Point = { x: number; y: number };

export type View = { x: number; y: number; zoom: number };

export type OpenPort = { source: WorkflowNode; port: PortId; point: Point };

export type Side = 'top' | 'bottom' | 'left' | 'right';

export type Anchor = { point: Point; side: Side };

export const NODE_WIDTH = 214;
export const NODE_HEIGHT = 86;
export const MIN_ZOOM = 0.25;
export const MAX_ZOOM = 1.5;
export const GRID_SIZE = 22;
export const DEFAULT_VIEW: View = { x: 0, y: 48, zoom: 1 };

const ROW_GAP = 86;
const STEP_GAP = 116;
const BRANCH_GAP = 128;
const SIBLING_GAP = 166;
const BRANCH_REACH = 147;

const PORT_X: Record<PortId, number> = {
    out: 0.5,
    true: 0.3,
    false: 0.7,
    failed: 0.84,
};

export function estimateHeight(_node: WorkflowNode): number {
    return NODE_HEIGHT;
}

export function nodeHeight(
    node: WorkflowNode,
    sizes: Record<string, number>,
): number {
    return sizes[node.id] ?? NODE_HEIGHT;
}

export function portsOf(node: WorkflowNode): PortId[] {
    return STEPS[node.kind].ports;
}

export function hasInput(node: WorkflowNode): boolean {
    return node.kind !== 'trigger';
}

export function center(node: WorkflowNode, height = NODE_HEIGHT): Point {
    return { x: node.x + NODE_WIDTH / 2, y: node.y + height / 2 };
}

export function besideOf(
    source: WorkflowNode,
    target: WorkflowNode,
): 'right' | 'left' | null {
    if (Math.abs(target.y - source.y) >= NODE_HEIGHT * 0.75) return null;
    if (target.x >= source.x + NODE_WIDTH + 16) return 'right';
    if (target.x + NODE_WIDTH <= source.x - 16) return 'left';

    return null;
}

export function portPoint(
    node: WorkflowNode,
    height: number,
    port: PortId,
): Point {
    return { x: node.x + NODE_WIDTH * PORT_X[port], y: node.y + height };
}

export function outAnchor(
    source: WorkflowNode,
    height: number,
    port: PortId,
    target?: WorkflowNode,
): Anchor {
    const beside = port === 'out' && target ? besideOf(source, target) : null;
    if (beside === 'right') {
        return {
            point: { x: source.x + NODE_WIDTH, y: source.y + height / 2 },
            side: 'right',
        };
    }
    if (beside === 'left') {
        return {
            point: { x: source.x, y: source.y + height / 2 },
            side: 'left',
        };
    }

    return { point: portPoint(source, height, port), side: 'bottom' };
}

export function inAnchor(
    target: WorkflowNode,
    height: number,
    source?: WorkflowNode,
    port: PortId = 'out',
): Anchor {
    const beside = port === 'out' && source ? besideOf(source, target) : null;
    if (beside === 'right') {
        return {
            point: { x: target.x, y: target.y + height / 2 },
            side: 'left',
        };
    }
    if (beside === 'left') {
        return {
            point: { x: target.x + NODE_WIDTH, y: target.y + height / 2 },
            side: 'right',
        };
    }

    return {
        point: { x: target.x + NODE_WIDTH / 2, y: target.y },
        side: 'top',
    };
}

const OUTWARD: Record<Side, Point> = {
    top: { x: 0, y: -1 },
    bottom: { x: 0, y: 1 },
    left: { x: -1, y: 0 },
    right: { x: 1, y: 0 },
};

export function wire(from: Anchor, to: Anchor): { path: string; mid: Point } {
    const a = from.point;
    const b = to.point;
    const horizontal = from.side === 'left' || from.side === 'right';
    const span = horizontal ? Math.abs(b.x - a.x) : Math.abs(b.y - a.y);
    const backwards = horizontal
        ? (b.x - a.x) * OUTWARD[from.side].x < 0
        : b.y < a.y;
    const reach = backwards
        ? 90
        : Math.min(
              horizontal ? 120 : 140,
              Math.max(horizontal ? 30 : 40, span * 0.58),
          );
    const out = OUTWARD[from.side];
    const into = OUTWARD[to.side];
    const c1 = { x: a.x + out.x * reach, y: a.y + out.y * reach };
    const c2 = { x: b.x + into.x * reach, y: b.y + into.y * reach };
    const mid = {
        x: (a.x + 3 * c1.x + 3 * c2.x + b.x) / 8,
        y: (a.y + 3 * c1.y + 3 * c2.y + b.y) / 8,
    };

    return {
        path: `M${a.x} ${a.y}C${c1.x} ${c1.y} ${c2.x} ${c2.y} ${b.x} ${b.y}`,
        mid,
    };
}

export function openPorts(
    nodes: WorkflowNode[],
    edges: WorkflowEdge[],
    sizes: Record<string, number>,
): OpenPort[] {
    return nodes.flatMap((node) =>
        portsOf(node)
            .filter(
                (port) =>
                    !edges.some(
                        (edge) => edge.source === node.id && edge.port === port,
                    ),
            )
            .map((port) => ({
                source: node,
                port,
                point: portPoint(node, nodeHeight(node, sizes), port),
            })),
    );
}

export function placementFor(
    source: WorkflowNode,
    port: PortId,
    sizes: Record<string, number>,
): Point {
    const height = nodeHeight(source, sizes);
    const from = portPoint(source, height, port);
    if (port === 'out') {
        return { x: source.x, y: source.y + height + STEP_GAP };
    }
    const reach = port === 'true' ? -BRANCH_REACH : BRANCH_REACH;

    return {
        x: from.x + reach - NODE_WIDTH / 2,
        y: source.y + height + BRANCH_GAP,
    };
}

export function freePlacement(
    point: Point,
    nodes: WorkflowNode[],
    sizes: Record<string, number>,
): Point {
    const overlaps = (candidate: Point) =>
        nodes.some(
            (node) =>
                candidate.x < node.x + NODE_WIDTH + 24 &&
                candidate.x + NODE_WIDTH + 24 > node.x &&
                candidate.y < node.y + nodeHeight(node, sizes) + 24 &&
                candidate.y + NODE_HEIGHT + 24 > node.y,
        );
    let candidate = point;
    for (let step = 0; step < 24 && overlaps(candidate); step++) {
        candidate = { x: candidate.x + NODE_WIDTH + 40, y: candidate.y };
    }

    return candidate;
}

export type SnapBox = { x: number; y: number; width: number; height: number };

function alignAxis(
    moving: SnapBox,
    others: SnapBox[],
    tolerance: number,
    axis: 'x' | 'y',
): number {
    const middle =
        axis === 'x'
            ? moving.x + moving.width / 2
            : moving.y + moving.height / 2;
    let best = 0;
    let closest = tolerance;
    for (const other of others) {
        const otherMiddle =
            axis === 'x'
                ? other.x + other.width / 2
                : other.y + other.height / 2;
        const delta = otherMiddle - middle;
        if (Math.abs(delta) > closest) continue;
        closest = Math.abs(delta);
        best = delta;
    }

    return best;
}

export function snapToNodes(
    moving: SnapBox,
    others: SnapBox[],
    tolerance: number,
): Point {
    return {
        x: moving.x + alignAxis(moving, others, tolerance, 'x'),
        y: moving.y + alignAxis(moving, others, tolerance, 'y'),
    };
}

export function toWorld(
    clientX: number,
    clientY: number,
    rect: DOMRect,
    view: View,
): Point {
    return {
        x: (clientX - rect.left - rect.width / 2 - view.x) / view.zoom,
        y: (clientY - rect.top - view.y) / view.zoom,
    };
}

export function clampZoom(zoom: number): number {
    return Math.min(MAX_ZOOM, Math.max(MIN_ZOOM, zoom));
}

export function zoomAround(
    view: View,
    zoom: number,
    localX: number,
    localY: number,
    width: number,
): View {
    const next = clampZoom(zoom);
    const worldX = (localX - width / 2 - view.x) / view.zoom;
    const worldY = (localY - view.y) / view.zoom;

    return {
        zoom: next,
        x: localX - width / 2 - worldX * next,
        y: localY - worldY * next,
    };
}

export function portFromPointer(
    source: WorkflowNode,
    pointerX: number,
): PortId {
    const ports = portsOf(source);
    if (ports.length === 1) return ports[0];
    const middle = source.x + NODE_WIDTH / 2;

    if (source.kind === 'branch') return pointerX < middle ? 'true' : 'false';

    return pointerX > middle + NODE_WIDTH / 4 ? 'failed' : 'out';
}

const PORT_ORDER: Record<PortId, number> = {
    true: 0,
    out: 1,
    failed: 2,
    false: 3,
};

type Extent = {
    left: number;
    right: number;
    place: (x: number, y: number) => void;
};

export function tidy(
    nodes: WorkflowNode[],
    edges: WorkflowEdge[],
): Record<string, Point> {
    const positions: Record<string, Point> = {};
    const byId = new Map(nodes.map((n) => [n.id, n]));
    const seen = new Set<string>();
    const children = new Map<string, string[]>();
    const root = nodes.find((n) => n.kind === 'trigger') ?? nodes[0];
    if (!root) return positions;

    const queue = [root.id];
    seen.add(root.id);
    while (queue.length) {
        const id = queue.shift()!;
        const kids = edges
            .filter((e) => e.source === id && byId.has(e.target))
            .sort((a, b) => PORT_ORDER[a.port] - PORT_ORDER[b.port])
            .map((e) => e.target)
            .filter((target) => {
                if (seen.has(target)) return false;
                seen.add(target);

                return true;
            });
        children.set(id, kids);
        queue.push(...kids);
    }

    const build = (id: string, row: number): Extent => {
        const kids = children.get(id) ?? [];
        const node = byId.get(id)!;
        if (kids.length === 1 && row < 3 && node.kind !== 'branch') {
            const next = build(kids[0], row + 1);
            const offset = NODE_WIDTH + ROW_GAP;

            return {
                left: Math.min(0, next.left + offset),
                right: Math.max(NODE_WIDTH, next.right + offset),
                place: (x, y) => {
                    positions[id] = { x, y };
                    next.place(x + offset, y);
                },
            };
        }
        const boxes = kids.map((kid) => build(kid, 1));
        const widths = boxes.map((box) => box.right - box.left);
        const total =
            widths.reduce((sum, w) => sum + w, 0) +
            Math.max(0, boxes.length - 1) * SIBLING_GAP;
        const start = NODE_WIDTH / 2 - total / 2;
        const gap = kids.length > 1 ? BRANCH_GAP : STEP_GAP;
        let cursor = start;
        const offsets = boxes.map((box, i) => {
            const at = cursor - box.left;
            cursor += widths[i] + SIBLING_GAP;

            return at;
        });

        return {
            left: Math.min(0, start),
            right: Math.max(NODE_WIDTH, start + total),
            place: (x, y) => {
                positions[id] = { x, y };
                boxes.forEach((box, i) =>
                    box.place(x + offsets[i], y + NODE_HEIGHT + gap),
                );
            },
        };
    };

    build(root.id, 1).place(root.x, root.y);

    const bottom = Math.max(
        ...Object.values(positions).map((p) => p.y + NODE_HEIGHT),
    );
    let x = root.x;
    for (const node of nodes) {
        if (positions[node.id]) continue;
        positions[node.id] = { x, y: bottom + STEP_GAP };
        x += NODE_WIDTH + ROW_GAP;
    }

    return positions;
}
