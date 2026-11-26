<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';
import BrandLogo from '@/components/dash/BrandLogo.vue';
import DashButton from '@/components/dash/DashButton.vue';
import DashHeader from '@/components/dash/DashHeader.vue';
import DashTab from '@/components/dash/DashTab.vue';
import DashTabs from '@/components/dash/DashTabs.vue';
import EmptyArt from '@/components/dash/EmptyArt.vue';
import StatusBadge from '@/components/dash/StatusBadge.vue';
import ChangeRow from '@/components/code/ChangeRow.vue';
import type { PlanChange } from '@/components/code/ChangeRow.vue';
import CodeLines from '@/components/code/CodeLines.vue';
import RailSection from '@/components/code/RailSection.vue';
import { comingSoon } from '@/components/code/yaml';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import { onWorkspace } from '@/lib/workspaceRoute';
import codeRoutes from '@/routes/workspace/code';
import machinesRoutes from '@/routes/workspace/machines';
import projectsRoutes from '@/routes/workspace/projects';
import type { Workspace } from '@/types';

type Tab = 'changes' | 'file' | 'checks' | 'runs';

const props = defineProps<{
    workspace: Workspace;
    project: {
        uid: string;
        name: string;
        environment: string;
        slug: string;
        file: string[];
    } | null;
    projects: { uid: string; name: string }[];
    sample: {
        title: string;
        pullRequest: number;
        author: string;
        from: string;
        to: string;
        commit: string;
        planned: string;
        stack: string;
        changes: PlanChange[];
        checks: { title: string; detail: string }[];
        machines: {
            id: number;
            name: string;
            provider: string | null;
            changes: number;
        }[];
    } | null;
}>();

const tab = ref<Tab>('changes');
const opened = ref<string[]>(
    props.sample?.changes
        .filter((change) => change.op === 'change')
        .slice(0, 1)
        .map((change) => change.id) ?? [],
);

const changes = computed(() => props.sample?.changes ?? []);
const figures = computed(() => [
    {
        label: trans('To add'),
        value: changes.value.filter((change) => change.op === 'add').length,
        tone: 'text-status-live',
    },
    {
        label: trans('To change'),
        value: changes.value.filter((change) => change.op === 'change').length,
        tone: 'text-[#c27c12] dark:text-[#f5a833]',
    },
    {
        label: trans('To remove'),
        value: changes.value.filter((change) => change.op === 'remove').length,
        tone: 'text-status-down',
    },
    {
        label: trans('Machines'),
        value: props.sample?.machines.length ?? 0,
        tone: 'text-fg-primary',
    },
]);
const allOpen = computed(
    () =>
        changes.value.length > 0 &&
        changes.value.every((change) => opened.value.includes(change.id)),
);
const highlighted = computed(
    () =>
        new Set(
            tab.value === 'changes'
                ? changes.value
                      .filter((change) => opened.value.includes(change.id))
                      .map((change) => change.machine?.id)
                : [],
        ),
);
const crumbs = computed(() => [
    {
        label: trans('Projects'),
        href: onWorkspace(projectsRoutes.index(props.workspace.slug)).url,
    },
    ...(props.project
        ? [
              {
                  label: props.project.name,
                  logo: props.sample?.stack,
                  href: onWorkspace(
                      projectsRoutes.show({
                          workspace: props.workspace.slug,
                          project: props.project.uid,
                      }),
                  ).url,
              },
          ]
        : []),
    { label: trans('Changes') },
]);

function toggle(id: string): void {
    opened.value = opened.value.includes(id)
        ? opened.value.filter((entry) => entry !== id)
        : [...opened.value, id];
}

function toggleAll(): void {
    opened.value = allOpen.value
        ? []
        : changes.value.map((change) => change.id);
}

function machineHref(id: number): string {
    return onWorkspace(
        machinesRoutes.show({ workspace: props.workspace.slug, machine: id }),
    ).url;
}

function apply(): void {
    comingSoon(
        trans(
            'Applying a pull request’s plan from ServerOS is on the way. Nothing was changed.',
        ),
    );
}

function viewOnGitHub(): void {
    comingSoon(
        trans('Pull requests from code show up here once code is live.'),
    );
}
</script>

<template>
    <Head :title="$t('Review changes')" />

    <DashHeader :crumbs="crumbs">
        <template v-if="sample" #actions>
            <DashButton @click="viewOnGitHub">
                <BrandLogo name="github" :size="16" />
                {{ $t('View on GitHub') }}
            </DashButton>
            <DashButton
                variant="primary"
                :label="
                    $t('Apply :count changes', {
                        count: String(changes.length),
                    })
                "
                @click="apply"
            />
        </template>
    </DashHeader>

    <div
        v-if="!project || !sample"
        class="flex min-h-full flex-col items-center justify-center gap-5 px-6 py-16 text-center"
    >
        <EmptyArt kind="project-graph" />
        <div class="flex max-w-sm flex-col gap-1">
            <h1
                class="text-[15px]/6 font-medium tracking-[-0.01em] text-fg-primary"
            >
                {{ $t('No changes to review') }}
            </h1>
            <p class="text-[13px]/5 text-fg-tertiary">
                {{
                    $t(
                        'Once a project is managed with code, every pull request that changes it shows up here as a plan.',
                    )
                }}
            </p>
        </div>
        <DashButton
            variant="primary"
            :href="onWorkspace(codeRoutes.setup(workspace.slug)).url"
            :label="$t('Set up code')"
        />
    </div>

    <div v-else class="flex min-h-full flex-col xl:h-full">
        <div class="flex shrink-0 flex-wrap items-center gap-3 px-6 pt-5 pb-4">
            <h1
                class="text-[20px]/7 font-medium tracking-[-0.4px] text-fg-primary"
            >
                {{ sample.title }}
            </h1>
            <StatusBadge status="live" :label="$t('Ready to apply')" />
            <span class="grow" />
            <span
                class="flex h-[26px] items-center rounded-full border-[0.5px] border-edge-strong bg-surface-raised px-2.5 text-[12px]/4 font-medium tracking-[-0.005em] text-fg-secondary dark:border-[#303030]"
                >{{ project.environment }}</span
            >
            <span
                class="flex h-[26px] items-center gap-1.5 rounded-full border-[0.5px] border-edge-strong bg-surface-raised px-2.5 text-[12px]/4 font-medium tracking-[-0.005em] text-fg-secondary dark:border-[#303030]"
            >
                <BrandLogo name="github" :size="12" />
                {{
                    $t('#:number by :author', {
                        number: String(sample.pullRequest),
                        author: sample.author,
                    })
                }}
            </span>
            <span
                class="flex h-[26px] items-center rounded-full border-[0.5px] border-edge-strong bg-surface-raised px-2.5 font-ui-mono text-[12px]/4 tracking-[-0.005em] text-fg-secondary dark:border-[#303030]"
                >{{ sample.from }} → {{ sample.to }}</span
            >
        </div>

        <DashTabs>
            <DashTab
                :label="$t('Changes')"
                icon="layers-fill"
                :active="tab === 'changes'"
                @click="tab = 'changes'"
            />
            <DashTab
                label="serveros.yml"
                icon="code-fill"
                :active="tab === 'file'"
                @click="tab = 'file'"
            />
            <DashTab
                :label="$t('Checks')"
                icon="setup-fill"
                :active="tab === 'checks'"
                @click="tab = 'checks'"
            />
            <DashTab
                :label="$t('Runs')"
                icon="activity-fill"
                :active="tab === 'runs'"
                @click="tab = 'runs'"
            />
        </DashTabs>

        <div class="flex flex-col xl:min-h-0 xl:flex-1 xl:flex-row">
            <div
                class="flex flex-col gap-3 px-6 py-4 xl:min-w-0 xl:flex-1 xl:overflow-y-auto"
            >
                <template v-if="tab === 'changes'">
                    <section
                        class="dash-card flex flex-col gap-3 px-[18px] pt-3 pb-3.5"
                    >
                        <div class="flex flex-wrap items-center gap-2">
                            <h2
                                class="text-[14px]/5 font-medium text-fg-primary"
                            >
                                {{ $t('Plan') }}
                            </h2>
                            <span class="text-[13px]/5 text-fg-tertiary">{{
                                $t('from')
                            }}</span>
                            <span
                                class="font-ui-mono text-[12px]/5 text-fg-secondary"
                                >{{ sample.commit }}</span
                            >
                            <span class="text-[13px]/5 text-fg-tertiary">{{
                                sample.planned
                            }}</span>
                        </div>
                        <div class="grid grid-cols-2 gap-y-3 sm:flex">
                            <div
                                v-for="(figure, index) in figures"
                                :key="figure.label"
                                class="flex flex-1 flex-col gap-0.5"
                                :class="
                                    index === 0
                                        ? 'pr-4'
                                        : 'px-4 sm:border-l-[0.5px] sm:border-edge-tabs'
                                "
                            >
                                <span class="text-[12px]/4 text-fg-tertiary">{{
                                    figure.label
                                }}</span>
                                <span
                                    class="text-[20px]/7 font-medium"
                                    :class="figure.tone"
                                    >{{ figure.value }}</span
                                >
                            </div>
                        </div>
                    </section>

                    <section class="dash-card flex flex-col">
                        <div class="flex items-center gap-2 px-[18px] py-3">
                            <h2
                                class="text-[14px]/5 font-medium text-fg-primary"
                            >
                                {{ $t('Changes') }}
                            </h2>
                            <span class="text-[13px]/5 text-fg-tertiary">{{
                                changes.length
                            }}</span>
                            <span class="grow" />
                            <button
                                type="button"
                                class="text-[12px]/4 font-medium text-fg-secondary transition-colors duration-150 hover:text-fg-primary"
                                @click="toggleAll"
                            >
                                {{
                                    allOpen
                                        ? $t('Collapse all')
                                        : $t('Expand all')
                                }}
                            </button>
                        </div>
                        <ChangeRow
                            v-for="change in changes"
                            :key="change.id"
                            :change="change"
                            :open="opened.includes(change.id)"
                            @toggle="toggle(change.id)"
                        />
                    </section>
                </template>

                <section
                    v-else-if="tab === 'file'"
                    class="dash-card flex flex-col"
                >
                    <div
                        class="flex h-11 shrink-0 items-center gap-2 border-b-[0.5px] border-edge-tabs pr-2.5 pl-4"
                    >
                        <FilledIcon name="file-fill" class="text-fg-muted" />
                        <span class="font-ui-mono text-[12px]/4 text-fg-primary"
                            >serveros.yml</span
                        >
                        <span
                            class="font-ui-mono text-[12px]/4 text-fg-muted"
                            >{{ sample.to }}</span
                        >
                        <span class="grow" />
                        <span class="text-[12px]/4 text-fg-faint">{{
                            $t(':count lines', {
                                count: String(project.file.length),
                            })
                        }}</span>
                    </div>
                    <div class="overflow-x-auto bg-surface-field">
                        <CodeLines :lines="project.file" />
                    </div>
                </section>

                <section
                    v-else-if="tab === 'checks'"
                    class="dash-card flex flex-col"
                >
                    <div
                        v-for="(check, index) in sample.checks"
                        :key="check.title"
                        class="flex items-start gap-3 px-[18px] py-3"
                        :class="{
                            'border-t-[0.5px] border-edge-tabs': index > 0,
                        }"
                    >
                        <svg
                            viewBox="0 0 18 18"
                            class="mt-px size-[18px] shrink-0 text-status-live"
                            aria-hidden="true"
                        >
                            <circle
                                cx="9"
                                cy="9"
                                r="8.75"
                                fill="currentColor"
                                fill-opacity="0.14"
                                stroke="currentColor"
                                stroke-opacity="0.35"
                                stroke-width="0.5"
                            />
                            <path
                                d="M5.8 9.2L7.9 11.2L12.2 6.8"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="1.5"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                            />
                        </svg>
                        <div class="flex flex-col">
                            <span
                                class="text-[13px]/5 font-medium text-fg-primary"
                                >{{ check.title }}</span
                            >
                            <span class="text-[12px]/4 text-fg-tertiary">{{
                                check.detail
                            }}</span>
                        </div>
                    </div>
                </section>

                <section
                    v-else
                    class="dash-card flex flex-col items-center gap-1 px-6 py-12 text-center"
                >
                    <h2 class="text-[14px]/5 font-medium text-fg-primary">
                        {{ $t('Nothing has run yet') }}
                    </h2>
                    <p class="max-w-sm text-[13px]/5 text-fg-tertiary">
                        {{
                            $t(
                                'Apply the changes and each step shows up here as it runs, with its output.',
                            )
                        }}
                    </p>
                </section>
            </div>

            <aside
                class="flex shrink-0 flex-col border-t-[0.5px] border-edge-tabs p-5 xl:w-[316px] xl:overflow-y-auto xl:border-t-0 xl:border-l-[0.5px]"
            >
                <RailSection :title="$t('Checks')" first quiet>
                    <template #aside>
                        <span
                            class="text-[12px]/4 font-medium text-status-live"
                            >{{
                                $t('All :count passed', {
                                    count: String(sample.checks.length),
                                })
                            }}</span
                        >
                    </template>
                    <div
                        v-for="check in sample.checks"
                        :key="check.title"
                        class="flex items-start gap-2.5"
                    >
                        <svg
                            viewBox="0 0 18 18"
                            class="mt-px size-[18px] shrink-0 text-status-live"
                            aria-hidden="true"
                        >
                            <circle
                                cx="9"
                                cy="9"
                                r="8.75"
                                fill="currentColor"
                                fill-opacity="0.14"
                                stroke="currentColor"
                                stroke-opacity="0.35"
                                stroke-width="0.5"
                            />
                            <path
                                d="M5.8 9.2L7.9 11.2L12.2 6.8"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="1.5"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                            />
                        </svg>
                        <div class="flex min-w-0 flex-1 flex-col">
                            <span
                                class="text-[13px]/5 font-medium text-fg-primary"
                                >{{ check.title }}</span
                            >
                            <span class="text-[12px]/4 text-fg-tertiary">{{
                                check.detail
                            }}</span>
                        </div>
                    </div>
                </RailSection>

                <RailSection :title="$t('Runs in this order')" quiet>
                    <div
                        v-for="(change, index) in changes"
                        :key="change.id"
                        class="flex items-center gap-2.5"
                    >
                        <span
                            class="flex size-[18px] shrink-0 items-center justify-center rounded-full border border-edge-tile text-[10px]/3 font-medium text-fg-muted"
                            >{{ index + 1 }}</span
                        >
                        <span class="text-[13px]/5 text-fg-secondary">{{
                            change.short
                        }}</span>
                    </div>
                </RailSection>

                <RailSection
                    v-if="sample.machines.length"
                    :title="$t('Machines')"
                    quiet
                >
                    <div class="flex flex-col gap-0.5">
                        <Link
                            v-for="machine in sample.machines"
                            :key="machine.id"
                            :href="machineHref(machine.id)"
                            class="flex h-[34px] items-center gap-2.5 rounded-lg px-2.5 transition-colors duration-150 hover:bg-edge"
                            :class="{ 'bg-edge': highlighted.has(machine.id) }"
                        >
                            <BrandLogo :name="machine.provider" :size="14" />
                            <span
                                class="truncate text-[13px]/5 font-medium text-fg-primary"
                                >{{ machine.name }}</span
                            >
                            <span class="grow" />
                            <span class="text-[12px]/4 text-fg-tertiary">{{
                                machine.changes === 1
                                    ? $t('1 change')
                                    : $t(':count changes', {
                                          count: String(machine.changes),
                                      })
                            }}</span>
                            <FilledIcon
                                name="chevron-right"
                                class="text-fg-secondary"
                            />
                        </Link>
                    </div>
                </RailSection>
            </aside>
        </div>
    </div>
</template>
