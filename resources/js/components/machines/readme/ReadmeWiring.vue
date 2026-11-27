<script setup lang="ts">
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';
import BrandLogo from '@/components/dash/BrandLogo.vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import type {
    ReadmeChip,
    ReadmeFacts,
} from '@/components/machines/readme/readme';

const { facts, label } = defineProps<{
    facts: ReadmeFacts;
    label: string;
}>();

const root = ref<HTMLElement | null>(null);
const paths = ref<string[]>([]);
const tags = ref<{ key: string; right: number; top: number }[]>([]);
const size = ref({ width: 0, height: 0 });

const entry = computed(() => facts.wiring.entry);
const apps = computed(() => facts.wiring.apps);
const local = computed(() =>
    facts.wiring.deps.filter((dep) => dep.machine === null),
);
const remote = computed(() =>
    facts.wiring.deps.filter((dep) => dep.machine !== null),
);
const empty = computed(() => !entry.value && apps.value.length === 0);

const chipClass =
    'relative z-10 flex w-max items-center gap-[7px] rounded-[8px] border-[0.5px] border-[#d6d6d6] bg-linear-to-b from-[#fbfbfb] to-[#f1f1f1] py-1.5 pr-[11px] pl-[9px] whitespace-nowrap shadow-[0_1px_2px_rgba(0,0,0,0.12)] dark:border-[#424242] dark:from-[#292929] dark:to-[#202020] dark:shadow-[0_1px_2px_rgba(0,0,0,0.45)]';

function meta(
    chip: ReadmeChip & { machine?: { name: string } | null },
): string | null {
    if (chip.machine) return chip.machine.name;
    if (chip.note === 'static') return 'static';
    return chip.port === null ? null : `:${chip.port}`;
}

type Box = { left: number; right: number; y: number };

function box(id: string, base: DOMRect): Box | null {
    const el = root.value?.querySelector<HTMLElement>(
        `[data-chip="${CSS.escape(id)}"]`,
    );
    if (!el) return null;
    const rect = el.getBoundingClientRect();

    return {
        left: rect.left - base.left,
        right: rect.right - base.left,
        y: Math.round(rect.top - base.top + rect.height / 2) + 0.5,
    };
}

function elbow(from: Box, to: Box, mid: number): string {
    if (Math.abs(from.y - to.y) < 1)
        return `M${from.right} ${from.y}H${to.left}`;

    return `M${from.right} ${from.y}H${mid}V${to.y}H${to.left}`;
}

function draw(): void {
    const el = root.value;
    if (!el) return;
    const base = el.getBoundingClientRect();
    size.value = { width: el.scrollWidth, height: el.scrollHeight };
    const next: string[] = [];
    const nextTags: typeof tags.value = [];

    const internet = box('internet', base);
    const front = entry.value ? box(entry.value.id, base) : null;
    if (internet && front) next.push(elbow(internet, front, front.left));

    const appBoxes = apps.value
        .map((app) => ({ app, at: box(app.id, base) }))
        .filter(
            (item): item is { app: (typeof apps.value)[number]; at: Box } =>
                item.at !== null,
        );
    if (front) {
        const mid = front.right + 17;
        for (const { app, at } of appBoxes) {
            if (app.routed) next.push(elbow(front, at, mid));
        }
    }

    const appsRight = Math.max(0, ...appBoxes.map((item) => item.at.right));
    const mid = appsRight + 22;
    for (const dep of facts.wiring.deps) {
        const to = box(dep.id, base);
        if (!to) continue;
        for (const from of appBoxes.filter((item) =>
            dep.from.includes(item.app.id),
        )) {
            next.push(elbow(from.at, to, mid));
        }
        if (dep.machine && dep.private && nextTags.length === 0) {
            nextTags.push({
                key: dep.id,
                right: size.value.width - (to.left - 12),
                top: to.y - 16.5,
            });
        }
    }

    paths.value = next;
    tags.value = nextTags;
}

let observer: ResizeObserver | null = null;

onMounted(() => {
    void nextTick(draw);
    if (typeof ResizeObserver !== 'undefined' && root.value) {
        observer = new ResizeObserver(() => draw());
        observer.observe(root.value);
    }
    void document.fonts?.ready.then(() => draw());
});

onBeforeUnmount(() => observer?.disconnect());

watch(
    () => facts.wiring,
    () => void nextTick(draw),
    { deep: true },
);
</script>

<template>
    <div class="overflow-x-auto">
        <div
            ref="root"
            class="relative mx-auto flex w-max min-w-full items-stretch justify-center px-4 py-[18px]"
        >
            <svg
                class="pointer-events-none absolute inset-0 text-[#c4c4c4] dark:text-[#4a4a4a]"
                :width="size.width"
                :height="size.height"
                aria-hidden="true"
            >
                <path
                    v-for="(d, index) in paths"
                    :key="index"
                    :d="d"
                    fill="none"
                    stroke="currentColor"
                    stroke-linejoin="round"
                />
            </svg>
            <span
                v-for="tag in tags"
                :key="tag.key"
                class="pointer-events-none absolute z-10 text-[10px]/3 whitespace-nowrap text-fg-faint"
                :style="{ right: `${tag.right}px`, top: `${tag.top}px` }"
                >{{ $t('private network') }}</span
            >

            <div
                v-if="!empty"
                class="mr-[17px] flex flex-col justify-center pt-[42px] pb-[21px]"
            >
                <span data-chip="internet" :class="chipClass">
                    <FilledIcon
                        name="globe-fill"
                        :size="14"
                        class="text-fg-muted"
                    />
                    <span class="text-[12px]/4 font-medium text-fg-primary">{{
                        $t('Internet')
                    }}</span>
                </span>
            </div>

            <div
                class="relative flex min-h-[200px] items-center rounded-[12px] border border-dashed border-[#cfcfcf] bg-white/[0.01] pt-[42px] pr-[18px] pb-[21px] pl-[26px] dark:border-[#424242]"
                :class="empty ? 'grow justify-center' : ''"
            >
                <span
                    class="absolute top-2.5 left-3.5 text-[11px]/[14px] whitespace-nowrap text-fg-faint"
                    >{{ label }}</span
                >
                <p v-if="empty" class="text-[12px]/[18px] text-fg-muted">
                    {{
                        $t(
                            'Nothing here takes web traffic that ServerOS can see.',
                        )
                    }}
                </p>
                <div
                    v-if="entry"
                    class="mr-[33px] flex flex-col justify-center"
                >
                    <span
                        :data-chip="entry.id"
                        :class="[
                            chipClass,
                            entry.state === 'down'
                                ? 'border-status-down/70! dark:border-status-down/70!'
                                : '',
                        ]"
                    >
                        <BrandLogo :name="entry.logo" :size="14" />
                        <span
                            class="text-[12px]/4 font-medium text-fg-primary"
                            >{{ entry.name }}</span
                        >
                        <span
                            v-if="entry.port"
                            class="font-ui-mono text-[11px]/[14px] text-fg-muted"
                            >:{{ entry.port }}</span
                        >
                        <span
                            class="size-1.5 rounded-full"
                            :class="
                                entry.state === 'down'
                                    ? 'bg-status-down'
                                    : 'bg-status-live'
                            "
                            :aria-label="
                                entry.state === 'down'
                                    ? $t('Stopped')
                                    : $t('Running')
                            "
                        />
                    </span>
                </div>
                <div
                    v-if="apps.length"
                    class="flex flex-col justify-center gap-[25px]"
                    :class="local.length ? 'mr-[42px]' : ''"
                >
                    <span
                        v-for="app in apps"
                        :key="app.id"
                        :data-chip="app.id"
                        :class="chipClass"
                    >
                        <FilledIcon
                            v-if="app.note === 'static'"
                            name="docs-fill"
                            :size="14"
                            class="text-fg-muted"
                        />
                        <BrandLogo v-else :name="app.logo" :size="14" />
                        <span
                            class="text-[12px]/4 font-medium text-fg-primary"
                            >{{ app.name }}</span
                        >
                        <span
                            v-if="meta(app)"
                            class="font-ui-mono text-[11px]/[14px] text-fg-muted"
                            >{{
                                app.note === 'static' ? $t('static') : meta(app)
                            }}</span
                        >
                    </span>
                </div>
                <div
                    v-if="local.length"
                    class="flex flex-col justify-center gap-[25px]"
                >
                    <span
                        v-for="dep in local"
                        :key="dep.id"
                        :data-chip="dep.id"
                        :class="chipClass"
                    >
                        <BrandLogo :name="dep.logo" :size="14" />
                        <span
                            class="text-[12px]/4 font-medium text-fg-primary"
                            >{{ dep.name }}</span
                        >
                        <span
                            v-if="meta(dep)"
                            class="font-ui-mono text-[11px]/[14px] text-fg-muted"
                            >{{ meta(dep) }}</span
                        >
                    </span>
                </div>
            </div>

            <div
                v-if="remote.length"
                class="ml-[28px] flex flex-col justify-center gap-[25px] pt-[42px] pb-[21px]"
            >
                <span
                    v-for="dep in remote"
                    :key="dep.id"
                    :data-chip="dep.id"
                    :class="chipClass"
                >
                    <BrandLogo :name="dep.logo" :size="14" />
                    <span class="text-[12px]/4 font-medium text-fg-primary">{{
                        dep.name
                    }}</span>
                    <span
                        class="font-ui-mono text-[11px]/[14px] text-fg-muted"
                        >{{ dep.machine?.name }}</span
                    >
                </span>
            </div>
        </div>
    </div>
</template>
