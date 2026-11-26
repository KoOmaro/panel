<script setup lang="ts">
import { computed } from 'vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import {
    inAnchor,
    nodeHeight,
    openPorts,
    outAnchor,
    portPoint,
    wire,
} from './geometry';
import type { Point } from './geometry';
import { PORT_LABELS } from './kinds';
import type { PortId, Run, WorkflowEdge, WorkflowNode } from './kinds';
import type { Selection } from './useWorkflowEditor';

export type EdgePreview = { from: Point; to: Point };

const props = defineProps<{
    nodes: WorkflowNode[];
    edges: WorkflowEdge[];
    sizes: Record<string, number>;
    selection: Selection;
    snapKey: string | null;
    targetKey: string | null;
    preview: EdgePreview | null;
    run: Run | null;
    readOnly: boolean;
}>();

const emit = defineEmits<{
    edgePointerdown: [id: string, event: PointerEvent];
    endpointPointerdown: [source: string, port: PortId, event: PointerEvent];
    deleteEdge: [];
}>();

const byId = computed(() => new Map(props.nodes.map((n) => [n.id, n])));

const traversed = computed(
    () =>
        new Set(
            Object.values(props.run?.steps ?? {}).flatMap((s) => s.edges ?? []),
        ),
);

function sourceRan(id: string): boolean {
    const state = props.run?.steps[id]?.state;

    return state === 'succeeded' || state === 'failed';
}

function label(edge: WorkflowEdge, ran: boolean): string | null {
    if (PORT_LABELS[edge.port]) return PORT_LABELS[edge.port] ?? null;
    if (!ran) return null;
    const message = props.run?.steps[edge.source]?.message?.trim();

    return message && message.length <= 24 ? message : null;
}

const wires = computed(() =>
    props.edges.flatMap((edge) => {
        const source = byId.value.get(edge.source);
        const target = byId.value.get(edge.target);
        if (!source || !target) return [];
        const from = outAnchor(
            source,
            nodeHeight(source, props.sizes),
            edge.port,
            target,
        );
        const to = inAnchor(
            target,
            nodeHeight(target, props.sizes),
            source,
            edge.port,
        );
        const ran = traversed.value.has(edge.id);
        const state: 'ran' | 'skipped' | 'idle' = ran
            ? 'ran'
            : props.run && edge.port !== 'out' && sourceRan(edge.source)
              ? 'skipped'
              : 'idle';

        return [
            {
                edge,
                from,
                to,
                state,
                label: label(edge, ran),
                ...wire(from, to),
            },
        ];
    }),
);

const open = computed(() =>
    openPorts(props.nodes, props.edges, props.sizes).filter(
        (port) =>
            port.port !== 'failed' ||
            (props.selection?.type === 'node' &&
                props.selection.id === port.source.id),
    ),
);

const target = computed(() =>
    props.readOnly || !props.targetKey
        ? null
        : (open.value.find(
              (p) => `${p.source.id}:${p.port}` === props.targetKey,
          ) ?? null),
);

const selectedWire = computed(() =>
    props.selection?.type === 'edge'
        ? wires.value.find((w) => w.edge.id === props.selection!.id)
        : undefined,
);

const ports = computed(() => {
    const out: { key: string; point: Point; ran: boolean }[] = [];
    for (const w of wires.value) {
        const ran = w.state === 'ran';
        out.push(
            { key: `${w.edge.id}-from`, point: w.from.point, ran },
            { key: `${w.edge.id}-to`, point: w.to.point, ran },
        );
    }

    return out;
});

function at(point: Point) {
    return { left: `${point.x}px`, top: `${point.y}px` };
}

function openPortPoint(source: WorkflowNode, port: PortId): Point {
    return portPoint(source, nodeHeight(source, props.sizes), port);
}
</script>

<template>
    <svg
        aria-hidden="true"
        width="1"
        height="1"
        class="pointer-events-none absolute top-0 left-0 overflow-visible"
    >
        <defs>
            <filter
                id="wf-glow"
                x="-50%"
                y="-50%"
                width="200%"
                height="200%"
                filterUnits="objectBoundingBox"
            >
                <feGaussianBlur stdDeviation="3" />
            </filter>
        </defs>
        <g v-for="w in wires" :key="w.edge.id">
            <path
                v-if="w.state === 'ran'"
                :d="w.path"
                fill="none"
                stroke="#3EC46D"
                stroke-width="5"
                stroke-linecap="round"
                opacity="0.35"
                filter="url(#wf-glow)"
            />
            <path
                :d="w.path"
                fill="none"
                stroke-linecap="round"
                class="transition-[stroke] duration-150"
                :stroke="
                    selection?.type === 'edge' && selection.id === w.edge.id
                        ? 'var(--dash-blue)'
                        : w.state === 'ran'
                          ? '#4FD47F'
                          : 'var(--wf-wire)'
                "
                :stroke-width="
                    selection?.type === 'edge' && selection.id === w.edge.id
                        ? 1.8
                        : 1.4
                "
                :stroke-dasharray="w.state === 'skipped' ? '2 4' : undefined"
            />
            <path
                v-if="run?.state === 'running' && w.state === 'ran'"
                :d="w.path"
                fill="none"
                stroke="#ffffff"
                stroke-opacity="0.6"
                stroke-width="1.4"
                stroke-linecap="round"
                stroke-dasharray="4 8"
                class="wf-dash motion-reduce:hidden"
            />
            <path
                :d="w.path"
                fill="none"
                stroke="transparent"
                stroke-width="14"
                class="[pointer-events:stroke] cursor-pointer"
                @pointerdown="emit('edgePointerdown', w.edge.id, $event)"
            />
        </g>
        <path
            v-if="target"
            :d="`M${openPortPoint(target.source, target.port).x} ${openPortPoint(target.source, target.port).y + 4}v30`"
            stroke="var(--wf-wire)"
            stroke-width="1.2"
            stroke-dasharray="3 3"
        />
        <path
            v-if="preview"
            :d="
                wire(
                    { point: preview.from, side: 'bottom' },
                    { point: preview.to, side: 'top' },
                ).path
            "
            fill="none"
            stroke="var(--wf-wire-hover)"
            stroke-width="1.4"
            stroke-dasharray="4 4"
        />
    </svg>

    <span
        v-for="port in ports"
        :key="port.key"
        aria-hidden="true"
        class="pointer-events-none absolute size-3 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-(--wf-port-ring)"
        :class="
            port.ran
                ? 'bg-[#5FE08F] shadow-[0_0_8px_rgb(62_196_109/0.9)]'
                : 'bg-(--wf-port)'
        "
        :style="at(port.point)"
    />

    <template v-for="port in open" :key="`${port.source.id}:${port.port}`">
        <span
            role="button"
            :aria-label="
                $t('Add a step after :title', { title: port.source.title })
            "
            :title="$t('Click to add a step here, or drag to connect')"
            class="absolute size-3 -translate-x-1/2 -translate-y-1/2 cursor-crosshair rounded-full border-2 border-(--wf-port-ring) bg-(--wf-port) transition-transform before:absolute before:-inset-2 before:rounded-full hover:scale-125"
            :class="{
                'scale-125 bg-dash-blue!':
                    snapKey === `${port.source.id}:${port.port}`,
                'pointer-events-none': readOnly,
            }"
            :style="at(port.point)"
            @pointerdown="
                emit('endpointPointerdown', port.source.id, port.port, $event)
            "
        />
        <span
            v-if="PORT_LABELS[port.port]"
            class="pointer-events-none absolute -translate-x-1/2 translate-y-3 rounded-full border-[0.5px] border-edge-row bg-surface-card-footer px-2 py-0.5 text-[11.5px]/4 tracking-[-0.005em] whitespace-nowrap text-fg-secondary"
            :style="at(port.point)"
            >{{ PORT_LABELS[port.port] }}</span
        >
    </template>

    <span
        v-for="w in wires.filter((w) => w.label)"
        :key="`label-${w.edge.id}`"
        class="absolute -translate-x-1/2 -translate-y-1/2 rounded-full border-[0.5px] bg-surface-card-footer px-2 py-0.5 text-[11.5px]/4 tracking-[-0.005em] whitespace-nowrap text-fg-secondary"
        :class="
            w.state === 'ran'
                ? 'border-[#c6e8d1] dark:border-[#2c3a30]'
                : 'border-edge-row'
        "
        :style="at(w.mid)"
        @pointerdown="emit('edgePointerdown', w.edge.id, $event)"
        >{{ w.label }}</span
    >

    <button
        v-if="target"
        type="button"
        class="absolute flex size-[22px] -translate-x-1/2 cursor-pointer items-center justify-center rounded-full border border-[#5fe08f] bg-(--wf-handle) text-fg-primary shadow-[0_0_8px_rgb(62_196_109/0.45)] hover:scale-110"
        :style="{
            left: `${openPortPoint(target.source, target.port).x}px`,
            top: `${openPortPoint(target.source, target.port).y + 34}px`,
        }"
        :aria-label="
            $t('Add a step after :title', { title: target.source.title })
        "
        @pointerdown.stop="
            emit('endpointPointerdown', target.source.id, target.port, $event)
        "
    >
        <FilledIcon name="plus-fill" :size="12" />
    </button>

    <div
        v-if="selectedWire && !readOnly"
        class="absolute -translate-x-1/2 -translate-y-[calc(100%+14px)]"
        :style="at(selectedWire.mid)"
        @pointerdown.stop
    >
        <button
            type="button"
            class="dash-button dash-button--secondary dash-button--sm"
            @click="emit('deleteEdge')"
        >
            {{ $t('Delete') }}
        </button>
    </div>
</template>
