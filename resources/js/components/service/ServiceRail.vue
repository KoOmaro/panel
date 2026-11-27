<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import BrandLogo from '@/components/dash/BrandLogo.vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import type { AppLimits } from '@/components/machines/AppLimitsDialog.vue';
import type { Service, ServiceStat } from '@/components/machines/serviceFormat';
import type {
    LinkedDatabase,
    ServiceStack,
} from '@/components/service/serviceTypes';
import {
    cpus,
    dotClass,
    duration,
    megabytes,
    restartPolicy,
    size,
    statusOf,
} from '@/components/service/serviceTypes';

const props = defineProps<{
    service: Service & { managed: boolean };
    stats: ServiceStat | null;
    stack: ServiceStack;
    linked: LinkedDatabase;
    limits: AppLimits | null;
    memLimit: number | null;
    machineName: string;
    links: string[];
    canManage: boolean;
}>();

defineEmits<{ limits: [] }>();

const now = ref(Date.now());
let clock: ReturnType<typeof setInterval> | undefined;
onMounted(() => (clock = setInterval(() => (now.value = Date.now()), 30_000)));
onBeforeUnmount(() => clearInterval(clock));

const up = computed(() =>
    props.stats?.startedAt
        ? Math.max(0, now.value / 1000 - props.stats.startedAt)
        : null,
);

const state = computed(() => {
    const label = statusOf(props.service.status).label;
    if (props.service.status === 'running' && up.value !== null) {
        return trans('Running for :time', { time: duration(up.value) });
    }

    return label;
});

const restarts = computed(() => {
    const count = props.stats?.restarts;
    if (count === null || count === undefined) return '–';
    if (count === 0) {
        return up.value !== null
            ? trans('None in :time', { time: duration(up.value) })
            : trans('None');
    }

    return count === 1
        ? trans('1 restart')
        : trans(':count restarts', { count: String(count) });
});

const managedLabel = computed(() =>
    props.service.managed
        ? props.service.origin === 'created'
            ? trans('By ServerOS')
            : trans('Adopted')
        : trans('Not yet'),
);

const details = computed(() =>
    [
        {
            key: 'image',
            label: trans('Image'),
            value: props.service.details?.image,
            mono: true,
        },
        {
            key: 'command',
            label: trans('Command'),
            value: props.service.exec,
            mono: true,
        },
        {
            key: 'directory',
            label: trans('Directory'),
            value: props.service.working_dir,
            mono: true,
        },
        {
            key: 'user',
            label: trans('Runs as'),
            value: props.service.user,
            mono: true,
        },
        {
            key: 'managed',
            label: trans('Managed'),
            value: managedLabel.value,
            mono: false,
        },
    ].map((row) => ({ ...row, value: row.value || null })),
);

const limitRows = computed(() => {
    if (!props.limits) {
        return [
            { key: 'cpu', label: trans('CPU'), value: '–' },
            {
                key: 'memory',
                label: trans('Memory'),
                value: props.memLimit ? size(props.memLimit) : '–',
            },
        ];
    }
    const setting = props.limits.setting ?? null;

    return [
        {
            key: 'cpu',
            label: trans('CPU'),
            value: setting?.cpus ? cpus(setting.cpus) : trans('No limit set'),
        },
        {
            key: 'memory',
            label: trans('Memory'),
            value: setting?.memoryMb
                ? megabytes(setting.memoryMb)
                : trans('No limit set'),
        },
    ];
});

const limitsPending = computed(() => {
    const setting = props.limits?.setting;
    if (!setting) return false;
    const applied = props.limits?.applied ?? null;

    return (
        setting.cpus !== (applied?.cpus ?? null) ||
        setting.memoryMb !== (applied?.memoryMb ?? null)
    );
});

const stackTitle = computed(() =>
    props.stack?.kind === 'compose'
        ? trans('In the :name stack', { name: props.stack.name })
        : trans('In the :name project', { name: props.stack?.name ?? '' }),
);

const row = 'flex min-w-0 items-center gap-2 text-[13px]/5 tracking-[-0.005em]';
const empty = 'text-[13px]/5 tracking-[-0.005em] text-fg-muted';
const label = 'w-[118px] shrink-0 text-fg-muted';
const item =
    'group flex items-center gap-2.5 rounded-[8px] py-1.5 pr-1.5 pl-2 hover:bg-surface-hover focus-visible:outline-2 focus-visible:outline-(--ds-ring)';
</script>

<template>
    <aside
        class="flex w-full shrink-0 flex-col gap-[22px] border-edge-tabs p-5 max-xl:border-t-[0.5px] xl:w-[316px] xl:border-l-[0.5px]"
        :aria-label="$t('About this service')"
    >
        <section class="flex flex-col gap-2">
            <h2
                class="text-[13px]/5 font-medium tracking-[-0.005em] text-fg-primary"
            >
                {{ $t('Status') }}
            </h2>
            <div :class="row">
                <span :class="label">{{ $t('State') }}</span>
                <span
                    class="size-1.5 shrink-0 rounded-full"
                    :class="dotClass(service.status)"
                    aria-hidden="true"
                />
                <span class="min-w-0 truncate text-fg-secondary">{{
                    state
                }}</span>
            </div>
            <div :class="row">
                <span :class="label">{{ $t('Restarts') }}</span>
                <span
                    class="min-w-0 truncate"
                    :class="
                        (stats?.restarts ?? 0) > 0
                            ? 'text-status-pending'
                            : 'text-fg-secondary'
                    "
                    >{{ restarts }}</span
                >
            </div>
            <div :class="row">
                <span :class="label">{{ $t('Restart policy') }}</span>
                <span
                    v-if="service.details?.restart_policy"
                    class="min-w-0 truncate text-fg-secondary"
                    >{{ restartPolicy(service.details.restart_policy) }}</span
                >
                <span v-else class="text-fg-faint">–</span>
            </div>
        </section>

        <section v-if="stack" class="flex flex-col gap-2">
            <h2
                class="flex min-w-0 gap-1.5 text-[13px]/5 tracking-[-0.005em] whitespace-nowrap"
            >
                <component
                    :is="stack.url ? Link : 'span'"
                    :href="stack.url ?? undefined"
                    class="truncate font-medium text-fg-primary"
                    :class="
                        stack.url && 'hover:underline hover:underline-offset-4'
                    "
                    >{{ stackTitle }}</component
                >
                <span class="shrink-0 text-fg-muted">{{
                    $t('on :machine', { machine: machineName })
                }}</span>
            </h2>
            <ul v-if="stack.members.length" class="flex flex-col gap-2">
                <li v-for="member in stack.members" :key="member.url">
                    <Link :href="member.url" :class="item">
                        <BrandLogo
                            :name="member.logo ?? member.name"
                            :size="18"
                        />
                        <span class="flex min-w-0 flex-1 flex-col">
                            <span
                                class="truncate text-[13px]/5 tracking-[-0.005em] text-fg-primary"
                                >{{ member.name }}</span
                            >
                            <span class="flex items-center gap-1.5">
                                <span
                                    class="size-1.5 shrink-0 rounded-full"
                                    :class="dotClass(member.status)"
                                    aria-hidden="true"
                                />
                                <span
                                    class="truncate text-[12px]/4 tracking-[-0.005em] text-fg-muted"
                                    >{{ statusOf(member.status).label }}</span
                                >
                            </span>
                        </span>
                        <FilledIcon
                            name="chevron-right"
                            :size="14"
                            class="text-fg-muted group-hover:text-fg-secondary"
                        />
                    </Link>
                </li>
            </ul>
            <p v-else :class="empty">
                {{ $t('Nothing else runs in it.') }}
            </p>
        </section>
        <section v-else class="flex flex-col gap-2">
            <h2
                class="text-[13px]/5 font-medium tracking-[-0.005em] text-fg-primary"
            >
                {{ $t('Stack') }}
            </h2>
            <p :class="empty">
                {{ $t('Not part of a Compose stack or a project.') }}
            </p>
        </section>

        <section class="flex flex-col gap-2">
            <h2
                class="text-[13px]/5 font-medium tracking-[-0.005em] text-fg-primary"
            >
                {{ $t('Its database') }}
            </h2>
            <p v-if="!linked" :class="empty">
                {{ $t('No database is linked to it.') }}
            </p>
            <component
                v-else
                :is="linked.url ? Link : 'div'"
                :href="linked.url ?? undefined"
                :class="item"
            >
                <BrandLogo :name="linked.logo ?? linked.name" :size="18" />
                <span class="flex min-w-0 flex-1 flex-col">
                    <span
                        class="truncate text-[13px]/5 tracking-[-0.005em] text-fg-primary"
                        >{{ linked.name }}</span
                    >
                    <span class="flex items-center gap-1.5">
                        <span
                            class="size-1.5 shrink-0 rounded-full"
                            :class="dotClass(linked.status)"
                            aria-hidden="true"
                        />
                        <span
                            class="truncate text-[12px]/4 tracking-[-0.005em] text-fg-muted"
                            >{{
                                linked.sameMachine
                                    ? statusOf(linked.status).label
                                    : $t(':state on :machine', {
                                          state: statusOf(linked.status).label,
                                          machine: linked.machine,
                                      })
                            }}</span
                        >
                    </span>
                </span>
                <FilledIcon
                    v-if="linked.url"
                    name="chevron-right"
                    :size="14"
                    class="text-fg-muted group-hover:text-fg-secondary"
                />
            </component>
        </section>

        <section class="flex flex-col gap-2">
            <h2
                class="text-[13px]/5 font-medium tracking-[-0.005em] text-fg-primary"
            >
                {{ $t('Details') }}
            </h2>
            <div v-for="detail in details" :key="detail.key" :class="row">
                <span :class="label">{{ detail.label }}</span>
                <span
                    v-if="detail.value"
                    class="min-w-0 flex-1 truncate text-fg-secondary"
                    :class="detail.mono && 'font-ui-mono text-[12px]/5'"
                    :title="detail.value"
                    >{{ detail.value }}</span
                >
                <span v-else class="text-fg-faint">–</span>
            </div>
            <div v-for="link in links" :key="link" :class="row">
                <span :class="label">{{ $t('On the machine') }}</span>
                <a
                    :href="link"
                    target="_blank"
                    rel="noopener"
                    class="min-w-0 truncate font-ui-mono text-[12px]/5 text-fg-secondary hover:text-fg-primary hover:underline"
                    >{{ link.replace('http://', '') }}</a
                >
            </div>
        </section>

        <section class="flex flex-col gap-2">
            <div class="flex items-center gap-2">
                <h2
                    class="flex-1 text-[13px]/5 font-medium tracking-[-0.005em] text-fg-primary"
                >
                    {{ $t('Limits') }}
                </h2>
                <button
                    v-if="canManage && limits && limits.unavailable === null"
                    type="button"
                    class="cursor-pointer text-[12px]/4 font-medium text-fg-muted hover:text-fg-secondary focus-visible:outline-2 focus-visible:outline-(--ds-ring)"
                    @click="$emit('limits')"
                >
                    {{ $t('Edit') }}
                </button>
            </div>
            <div v-for="limit in limitRows" :key="limit.key" :class="row">
                <span :class="label">{{ limit.label }}</span>
                <span
                    class="min-w-0 truncate"
                    :class="
                        limit.value === '–'
                            ? 'text-fg-faint'
                            : 'text-fg-secondary'
                    "
                    >{{ limit.value }}</span
                >
            </div>
            <p
                v-if="limits && (limits.unavailable || limitsPending)"
                class="text-[12px]/4 tracking-[-0.005em] text-fg-muted"
            >
                {{
                    limits?.unavailable ??
                    $t(
                        'Not applied yet: it runs without them until it deploys again.',
                    )
                }}
            </p>
        </section>
    </aside>
</template>
