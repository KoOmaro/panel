<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';
import BrandLogo from '@/components/dash/BrandLogo.vue';
import DashButton from '@/components/dash/DashButton.vue';
import DashHeader from '@/components/dash/DashHeader.vue';
import DashTab from '@/components/dash/DashTab.vue';
import DashTabs from '@/components/dash/DashTabs.vue';
import DashTray from '@/components/dash/DashTray.vue';
import EmptyArt from '@/components/dash/EmptyArt.vue';
import StatusBadge from '@/components/dash/StatusBadge.vue';
import type { DashStatus } from '@/components/dash/StatusBadge.vue';
import ChangeRow from '@/components/code/ChangeRow.vue';
import type { PlanChange } from '@/components/code/ChangeRow.vue';
import CheckMark from '@/components/code/CheckMark.vue';
import CodeDiff from '@/components/code/CodeDiff.vue';
import RailSection from '@/components/code/RailSection.vue';
import type { CodeRepository } from '@/components/code/RepositoryForm.vue';
import type { DiffLine } from '@/components/code/yaml';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { timeAgo } from '@/lib/timeAgo';
import { onWorkspace } from '@/lib/workspaceRoute';
import codeRoutes from '@/routes/workspace/code';
import machinesRoutes from '@/routes/workspace/machines';
import type { Workspace } from '@/types';

type Tab = 'changes' | 'files' | 'checks';

type Review = {
    author: string;
    state: 'approved' | 'changes_requested' | 'commented';
    at: string | null;
};

type Check = {
    name: string;
    state: 'success' | 'failure' | 'pending' | 'neutral';
    url: string | null;
};

const props = defineProps<{
    workspace: Workspace;
    repository: CodeRepository | null;
    github: {
        enabled: boolean;
        connected: boolean;
        account: string | null;
        connectUrl: string | null;
    };
    pullRequests: {
        number: number;
        title: string;
        author: string | null;
        draft: boolean;
        updated: string | null;
    }[];
    pullRequest: {
        number: number;
        title: string;
        url: string;
        author: string | null;
        draft: boolean;
        from: string;
        to: string;
        commit: string;
        commitUrl: string;
        updated: string | null;
        files: {
            path: string;
            status: string;
            additions: number;
            deletions: number;
            lines: DiffLine[];
        }[];
        reviews: Review[] | null;
        checks: Check[] | null;
        changes: PlanChange[];
        unreadable: string[];
        machines: {
            id: number;
            name: string;
            provider: string | null;
            changes: number;
        }[];
    } | null;
    error: string | null;
}>();

const tab = ref<Tab>('changes');
const opened = ref<string[]>(
    props.pullRequest?.changes
        .filter((change) => change.op === 'change')
        .slice(0, 1)
        .map((change) => change.id) ?? [],
);

const codeHref = computed(
    () => onWorkspace(codeRoutes.index(props.workspace.slug)).url,
);
const changes = computed(() => props.pullRequest?.changes ?? []);
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
        value: props.pullRequest?.machines.length ?? 0,
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
const status = computed<{ status: DashStatus; label: string } | null>(() => {
    const pull = props.pullRequest;

    if (!pull) return null;
    if (pull.draft) return { status: 'stopped', label: trans('Draft') };

    const reviews = pull.reviews ?? [];

    if (reviews.some((review) => review.state === 'changes_requested'))
        return { status: 'down', label: trans('Changes requested') };
    if (reviews.some((review) => review.state === 'approved'))
        return { status: 'live', label: trans('Approved') };

    return { status: 'pending', label: trans('Waiting for review') };
});
const passed = computed(
    () =>
        props.pullRequest?.checks?.filter((check) => check.state === 'success')
            .length ?? 0,
);
const crumbs = computed(() => [
    { label: trans('Code'), href: codeHref.value },
    { label: trans('Changes') },
]);
const where = computed(() =>
    props.repository?.folder
        ? `${props.repository.folder}/`
        : trans('the repository root'),
);

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

function pullHref(number: number): string {
    return onWorkspace(
        codeRoutes.changes(props.workspace.slug, { query: { pull: number } }),
    ).url;
}

function reviewLabel(review: Review): string {
    return review.state === 'approved'
        ? trans('Approved by :name', { name: review.author })
        : review.state === 'changes_requested'
          ? trans('Changes requested by :name', { name: review.author })
          : trans('Commented by :name', { name: review.author });
}

function retry(): void {
    router.reload();
}
</script>

<template>
    <Head :title="$t('Review changes')" />

    <DashHeader :crumbs="crumbs">
        <template v-if="pullRequest" #actions>
            <DashButton :href="pullRequest.url" external>
                <BrandLogo name="github" :size="16" />
                {{ $t('View on GitHub') }}
            </DashButton>
            <TooltipProvider :delay-duration="100">
                <Tooltip>
                    <TooltipTrigger as-child>
                        <span class="inline-flex" tabindex="0">
                            <DashButton
                                variant="primary"
                                :label="
                                    changes.length === 1
                                        ? $t('Apply 1 change')
                                        : $t('Apply :count changes', {
                                              count: String(changes.length),
                                          })
                                "
                                disabled
                            />
                        </span>
                    </TooltipTrigger>
                    <TooltipContent
                        side="bottom"
                        align="end"
                        class="max-w-64 px-3 py-2 text-left text-[13px]/5"
                    >
                        {{
                            $t(
                                'ServerOS can’t apply changes from code yet. Make them in the panel for now.',
                            )
                        }}
                    </TooltipContent>
                </Tooltip>
            </TooltipProvider>
        </template>
    </DashHeader>

    <div
        v-if="!pullRequest"
        class="flex min-h-full flex-col items-center justify-center gap-5 px-6 py-16 text-center"
    >
        <EmptyArt kind="project-graph" />
        <div class="flex max-w-sm flex-col gap-1">
            <template v-if="!github.connected">
                <h1
                    class="text-[15px]/6 font-medium tracking-[-0.01em] text-fg-primary"
                >
                    {{ $t('Connect GitHub first') }}
                </h1>
                <p class="text-[13px]/5 text-fg-tertiary">
                    {{
                        $t(
                            'Review changes reads the open pull requests on the repository your workspace’s code goes to.',
                        )
                    }}
                </p>
            </template>
            <template v-else-if="!repository">
                <h1
                    class="text-[15px]/6 font-medium tracking-[-0.01em] text-fg-primary"
                >
                    {{ $t('Pick a repository first') }}
                </h1>
                <p class="text-[13px]/5 text-fg-tertiary">
                    {{
                        $t(
                            'Choose where your workspace’s code goes on the Code page. Open pull requests that change those files show up here.',
                        )
                    }}
                </p>
            </template>
            <template v-else-if="error">
                <h1
                    class="text-[15px]/6 font-medium tracking-[-0.01em] text-fg-primary"
                >
                    {{ $t('Couldn’t read the pull requests') }}
                </h1>
                <p class="text-[13px]/5 text-fg-tertiary">{{ error }}</p>
            </template>
            <template v-else>
                <h1
                    class="text-[15px]/6 font-medium tracking-[-0.01em] text-fg-primary"
                >
                    {{ $t('No pull requests to review') }}
                </h1>
                <p class="text-[13px]/5 text-fg-tertiary">
                    {{
                        $t(
                            'Open pull requests on :repo that change the files in :folder show up here.',
                            { repo: repository.repo, folder: where },
                        )
                    }}
                </p>
            </template>
        </div>
        <div class="flex items-center gap-2">
            <template v-if="!github.connected && github.connectUrl">
                <DashButton :href="codeHref" :label="$t('Go to Code')" />
                <a
                    :href="github.connectUrl"
                    class="dash-button dash-button--primary"
                    >{{ $t('Connect GitHub') }}</a
                >
            </template>
            <template v-else-if="repository && error">
                <DashButton :href="repository.url" external>
                    <BrandLogo name="github" :size="16" />
                    {{ $t('View on GitHub') }}
                </DashButton>
                <DashButton
                    variant="primary"
                    :label="$t('Try again')"
                    @click="retry"
                />
            </template>
            <DashButton
                v-else
                variant="primary"
                :href="codeHref"
                :label="$t('Go to Code')"
            />
        </div>
    </div>

    <div v-else class="flex min-h-full flex-col xl:h-full">
        <div class="flex shrink-0 flex-wrap items-center gap-3 px-6 pt-5 pb-4">
            <h1
                class="min-w-0 text-[20px]/7 font-medium tracking-[-0.4px] text-fg-primary"
            >
                {{ pullRequest.title }}
            </h1>
            <StatusBadge
                v-if="status"
                :status="status.status"
                :label="status.label"
            />
            <span class="grow" />
            <span
                v-if="repository"
                class="flex h-[26px] items-center rounded-full border-[0.5px] border-edge-strong bg-surface-raised px-2.5 text-[12px]/4 font-medium tracking-[-0.005em] text-fg-secondary dark:border-[#303030]"
                >{{ repository.repo }}</span
            >
            <span
                class="flex h-[26px] items-center gap-1.5 rounded-full border-[0.5px] border-edge-strong bg-surface-raised px-2.5 text-[12px]/4 font-medium tracking-[-0.005em] text-fg-secondary dark:border-[#303030]"
            >
                <BrandLogo name="github" :size="12" />
                {{
                    pullRequest.author
                        ? $t('#:number by :author', {
                              number: String(pullRequest.number),
                              author: pullRequest.author,
                          })
                        : '#' + pullRequest.number
                }}
            </span>
            <span
                class="flex h-[26px] items-center rounded-full border-[0.5px] border-edge-strong bg-surface-raised px-2.5 font-ui-mono text-[12px]/4 tracking-[-0.005em] text-fg-secondary dark:border-[#303030]"
                >{{ pullRequest.from }} → {{ pullRequest.to }}</span
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
                :label="$t('Files')"
                icon="code-fill"
                :active="tab === 'files'"
                @click="tab = 'files'"
            />
            <DashTab
                v-if="pullRequest.checks !== null"
                :label="$t('Checks')"
                icon="setup-fill"
                :active="tab === 'checks'"
                @click="tab = 'checks'"
            />
        </DashTabs>

        <div class="flex flex-col xl:min-h-0 xl:flex-1 xl:flex-row">
            <div
                class="flex flex-col gap-3 px-6 py-4 xl:min-w-0 xl:flex-1 xl:overflow-y-auto"
            >
                <template v-if="tab === 'changes'">
                    <DashTray
                        icon="file-fill"
                        :title="$t('Plan')"
                        panel-class="gap-3 px-[18px] pt-3 pb-3.5"
                    >
                        <template #meta>
                            <span
                                class="flex items-center gap-[9px] tracking-normal text-fg-tertiary"
                            >
                                {{ $t('from') }}
                                <a
                                    :href="pullRequest.commitUrl"
                                    target="_blank"
                                    rel="noopener"
                                    class="font-ui-mono text-[12px]/5 text-fg-secondary transition-colors duration-150 hover:text-fg-primary"
                                    >{{ pullRequest.commit }}</a
                                >
                                <span v-if="pullRequest.updated">{{
                                    timeAgo(pullRequest.updated)
                                }}</span>
                            </span>
                        </template>

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
                        <p
                            v-for="file in pullRequest.unreadable"
                            :key="file"
                            class="flex items-center gap-2 text-[12px]/4 text-[#c27c12] dark:text-[#f5a833]"
                        >
                            <FilledIcon name="info-fill" class="shrink-0" />
                            {{
                                $t(
                                    'Couldn’t read :file as YAML, so it’s not in the plan.',
                                    { file },
                                )
                            }}
                        </p>
                    </DashTray>

                    <DashTray
                        icon="branch-fill"
                        :title="$t('Changes')"
                        panel-class="pt-3"
                    >
                        <template #meta>
                            <span class="tracking-normal text-fg-tertiary">{{
                                changes.length
                            }}</span>
                        </template>
                        <template v-if="changes.length" #actions>
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
                        </template>
                        <ChangeRow
                            v-for="change in changes"
                            :key="change.id"
                            :change="change"
                            :open="opened.includes(change.id)"
                            @toggle="toggle(change.id)"
                        />
                        <p
                            v-if="!changes.length"
                            class="border-t-[0.5px] border-edge-tabs px-[18px] py-4 text-[13px]/5 text-fg-tertiary"
                        >
                            {{
                                $t(
                                    'These files match what runs now, so nothing would change.',
                                )
                            }}
                        </p>
                    </DashTray>
                </template>

                <template v-else-if="tab === 'files'">
                    <CodeDiff
                        v-for="file in pullRequest.files"
                        :key="file.path"
                        :file="file.path"
                        :lines="file.lines"
                        :additions="file.additions"
                        :deletions="file.deletions"
                    >
                        <p
                            v-if="!file.lines.length"
                            class="px-3 py-2.5 font-ui text-[12px]/4 text-fg-tertiary"
                        >
                            {{
                                file.status === 'removed'
                                    ? $t('This pull request deletes the file.')
                                    : $t(
                                          'GitHub didn’t send a diff for this file. See it on GitHub.',
                                      )
                            }}
                        </p>
                    </CodeDiff>
                </template>

                <DashTray
                    v-else-if="pullRequest.checks"
                    icon="setup-fill"
                    :title="$t('Checks')"
                    panel-class="pt-3"
                >
                    <template v-if="pullRequest.checks.length" #meta>
                        <span class="tracking-normal text-fg-tertiary">{{
                            pullRequest.checks.length
                        }}</span>
                    </template>
                    <component
                        :is="check.url ? 'a' : 'div'"
                        v-for="(check, index) in pullRequest.checks"
                        :key="check.name + index"
                        :href="check.url ?? undefined"
                        :target="check.url ? '_blank' : undefined"
                        :rel="check.url ? 'noopener' : undefined"
                        class="flex items-start gap-3 border-t-[0.5px] border-edge-tabs px-[18px] py-3"
                    >
                        <CheckMark :state="check.state" />
                        <span
                            class="text-[13px]/5 font-medium text-fg-primary"
                            >{{ check.name }}</span
                        >
                    </component>
                    <p
                        v-if="!pullRequest.checks.length"
                        class="border-t-[0.5px] border-edge-tabs px-[18px] py-4 text-[13px]/5 text-fg-tertiary"
                    >
                        {{ $t('No checks ran on this commit.') }}
                    </p>
                </DashTray>
            </div>

            <aside
                class="flex shrink-0 flex-col border-t-[0.5px] border-edge-tabs p-5 xl:w-[316px] xl:overflow-y-auto xl:border-t-0 xl:border-l-[0.5px]"
            >
                <RailSection
                    v-if="pullRequests.length > 1"
                    :title="$t('Open pull requests')"
                    first
                    quiet
                >
                    <template #aside>
                        <span class="text-[12px]/4 text-fg-tertiary">{{
                            pullRequests.length
                        }}</span>
                    </template>
                    <div class="flex flex-col gap-0.5">
                        <Link
                            v-for="pull in pullRequests"
                            :key="pull.number"
                            :href="pullHref(pull.number)"
                            class="flex h-[34px] items-center gap-2.5 rounded-lg px-2.5 transition-colors duration-150 hover:bg-edge"
                            :class="{
                                'bg-edge': pull.number === pullRequest.number,
                            }"
                        >
                            <span
                                class="shrink-0 font-ui-mono text-[12px]/4 text-fg-muted"
                                >#{{ pull.number }}</span
                            >
                            <span
                                class="truncate text-[13px]/5 font-medium text-fg-primary"
                                >{{ pull.title }}</span
                            >
                        </Link>
                    </div>
                </RailSection>

                <RailSection
                    :title="$t('Checks')"
                    :first="pullRequests.length <= 1"
                    quiet
                >
                    <template
                        v-if="pullRequest.checks && pullRequest.checks.length"
                        #aside
                    >
                        <span
                            class="text-[12px]/4 font-medium"
                            :class="
                                passed === pullRequest.checks.length
                                    ? 'text-status-live'
                                    : 'text-fg-tertiary'
                            "
                            >{{
                                passed === pullRequest.checks.length
                                    ? $t('All :count passed', {
                                          count: String(passed),
                                      })
                                    : $t(':passed of :count passed', {
                                          passed: String(passed),
                                          count: String(
                                              pullRequest.checks.length,
                                          ),
                                      })
                            }}</span
                        >
                    </template>
                    <p
                        v-if="pullRequest.checks === null"
                        class="text-[12px]/[18px] text-fg-tertiary"
                    >
                        {{
                            $t(
                                'ServerOS can’t read checks on this repository.',
                            ) + ' '
                        }}<a
                            :href="pullRequest.url + '/checks'"
                            target="_blank"
                            rel="noopener"
                            class="underline decoration-edge-strong underline-offset-2 transition-colors duration-150 hover:text-fg-secondary"
                            >{{ $t('See them on GitHub') }}</a
                        >.
                    </p>
                    <p
                        v-else-if="!pullRequest.checks.length"
                        class="text-[12px]/[18px] text-fg-tertiary"
                    >
                        {{ $t('No checks ran on this commit.') }}
                    </p>
                    <div
                        v-for="(check, index) in pullRequest.checks ?? []"
                        :key="check.name + index"
                        class="flex items-start gap-2.5"
                    >
                        <CheckMark :state="check.state" />
                        <span
                            class="min-w-0 flex-1 truncate text-[13px]/5 font-medium text-fg-primary"
                            >{{ check.name }}</span
                        >
                    </div>
                </RailSection>

                <RailSection :title="$t('Reviews')" quiet>
                    <p
                        v-if="pullRequest.reviews === null"
                        class="text-[12px]/[18px] text-fg-tertiary"
                    >
                        {{ $t('ServerOS couldn’t read the reviews.') }}
                    </p>
                    <p
                        v-else-if="!pullRequest.reviews.length"
                        class="text-[12px]/[18px] text-fg-tertiary"
                    >
                        {{ $t('No reviews yet.') }}
                    </p>
                    <div
                        v-for="review in pullRequest.reviews ?? []"
                        :key="review.author"
                        class="flex items-start gap-2.5"
                    >
                        <CheckMark
                            :state="
                                review.state === 'approved'
                                    ? 'success'
                                    : review.state === 'changes_requested'
                                      ? 'failure'
                                      : 'neutral'
                            "
                        />
                        <div class="flex min-w-0 flex-1 flex-col">
                            <span
                                class="truncate text-[13px]/5 font-medium text-fg-primary"
                                >{{ reviewLabel(review) }}</span
                            >
                            <span
                                v-if="review.at"
                                class="text-[12px]/4 text-fg-tertiary"
                                >{{ timeAgo(review.at) }}</span
                            >
                        </div>
                    </div>
                </RailSection>

                <RailSection
                    v-if="pullRequest.machines.length"
                    :title="$t('Machines')"
                    quiet
                >
                    <div class="flex flex-col gap-0.5">
                        <Link
                            v-for="machine in pullRequest.machines"
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
