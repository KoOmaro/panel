<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Copy } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';
import BrandLogo from '@/components/dash/BrandLogo.vue';
import DashButton from '@/components/dash/DashButton.vue';
import DashHeader from '@/components/dash/DashHeader.vue';
import EmptyArt from '@/components/dash/EmptyArt.vue';
import CodeLines from '@/components/code/CodeLines.vue';
import { copyText } from '@/components/code/yaml';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import { onWorkspace } from '@/lib/workspaceRoute';
import codeRoutes from '@/routes/workspace/code';
import projectsRoutes from '@/routes/workspace/projects';
import type { Workspace } from '@/types';

type Stack = 'next' | 'laravel' | 'django' | 'node' | 'bun' | 'astro';

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
    mcpUrl: string;
    sample: { repo: string; stack: Stack; branch: string } | null;
}>();

const stacks: { key: Stack; label: string }[] = [
    { key: 'next', label: 'Next.js' },
    { key: 'laravel', label: 'Laravel' },
    { key: 'django', label: 'Django' },
    { key: 'node', label: 'Node.js' },
    { key: 'bun', label: 'Bun' },
    { key: 'astro', label: 'Astro' },
];

const modes = computed(() => [
    { key: 'agent' as const, label: trans('Agent setup') },
    { key: 'manual' as const, label: trans('Manual setup') },
]);

const stack = ref<Stack>(props.sample?.stack ?? 'next');
const mode = ref<'agent' | 'manual'>('agent');
const expanded = ref(false);

const stackLabel = computed(
    () => stacks.find((entry) => entry.key === stack.value)?.label ?? '',
);
const detected = computed(
    () =>
        stacks.find((entry) => entry.key === props.sample?.stack)?.label ?? '',
);

const guide = computed<string[]>(() => {
    if (!props.project || !props.sample) return [];

    const name = props.project.name;
    const repo = props.sample.repo;

    return [
        trans('# Set up ServerOS for :repo', { repo }),
        '',
        trans(
            'Write serveros.yml at the root of the repo. Describe what :project runs today, not what it should run one day.',
            { project: name },
        ),
        '',
        trans(
            'Read the live setup with the ServerOS MCP server first. It can see machines and services, but it can’t change them and never returns secret values.',
        ),
        '',
        trans('Write secrets as :ref, never the values.', {
            ref: '${{ secrets.NAME }}',
        }),
        '',
        trans('## Before you start'),
        '',
        trans('- Add the ServerOS MCP server to your agent: :url', {
            url: props.mcpUrl,
        }),
        trans('- :project is a :stack app. Leave its build as it is.', {
            project: name,
            stack: stackLabel.value,
        }),
        trans('- Work on a new branch: git checkout -b :branch', {
            branch: props.sample.branch,
        }),
        '',
        trans('## What to write'),
        '',
        trans(
            '- serveros.yml, with one entry per service :project runs: its machine, domain, port and environment.',
            { project: name },
        ),
        trans(
            '- .github/workflows/serveros.yml, a GitHub Action that posts the ServerOS plan on every pull request.',
        ),
        '',
        trans('## Start from this'),
        '',
    ];
});

const prompt = computed(() => [
    ...guide.value.map((text) => ({ text, heading: text.startsWith('#') })),
    ...(props.project?.file ?? []).map((text) => ({ text, heading: false })),
]);

function copyPrompt(): void {
    void copyText(
        prompt.value.map((line) => line.text).join('\n') + '\n',
        trans('Copied the prompt. Paste it into your agent.'),
    );
}

function copyFile(): void {
    if (!props.project) return;

    void copyText(
        props.project.file.join('\n') + '\n',
        trans('Copied serveros.yml'),
    );
}
</script>

<template>
    <Head :title="$t('Set up code')" />

    <DashHeader
        :crumbs="
            project
                ? [
                      {
                          label: $t('Code'),
                          href: onWorkspace(codeRoutes.index(workspace.slug))
                              .url,
                      },
                      { label: project.name, logo: stack },
                  ]
                : [
                      {
                          label: $t('Code'),
                          href: onWorkspace(codeRoutes.index(workspace.slug))
                              .url,
                      },
                      { label: $t('Set up') },
                  ]
        "
    />

    <div
        v-if="!project || !sample"
        class="flex min-h-full flex-col items-center justify-center gap-5 px-6 py-16 text-center"
    >
        <EmptyArt kind="project-graph" />
        <div class="flex max-w-sm flex-col gap-1">
            <h1
                class="text-[15px]/6 font-medium tracking-[-0.01em] text-fg-primary"
            >
                {{ $t('Add a project first') }}
            </h1>
            <p class="text-[13px]/5 text-fg-tertiary">
                {{
                    $t(
                        'Code is set up one project at a time. Make a project, then come back to manage it with code.',
                    )
                }}
            </p>
        </div>
        <DashButton
            variant="primary"
            :href="onWorkspace(projectsRoutes.index(workspace.slug)).url"
            :label="$t('Go to projects')"
        />
    </div>

    <div v-else class="flex justify-center px-5 pt-[52px] pb-14 sm:px-8">
        <div class="flex w-full max-w-[824px] flex-col">
            <div class="flex">
                <div class="hidden w-16 shrink-0 sm:block" />
                <div class="flex min-w-0 flex-1 flex-col gap-2">
                    <div class="flex items-center gap-2 pb-1">
                        <span
                            class="size-3.5 shrink-0 animate-spin rounded-full border-[1.7px] border-[#87a3ff]/20 border-t-[#87a3ff] border-r-[#87a3ff] [animation-duration:1.4s] motion-reduce:animate-none"
                            aria-hidden="true"
                        />
                        <p
                            class="text-[13px]/5 font-medium text-[#3d5ee0] dark:text-[#87a3ff]"
                        >
                            {{
                                $t('Watching :repo for a pull request', {
                                    repo: sample.repo,
                                })
                            }}
                        </p>
                    </div>
                    <h1
                        class="text-[28px]/9 font-medium tracking-[-0.4px] text-fg-primary"
                    >
                        {{
                            $t('Manage :project with code', {
                                project: project.name,
                            })
                        }}
                    </h1>
                    <p class="text-[14px]/[22px] text-fg-tertiary">
                        {{
                            $t(
                                'Your agent writes serveros.yml from what :project runs today. You see the plan before anything changes.',
                                { project: project.name },
                            )
                        }}
                    </p>
                </div>
            </div>

            <section class="flex">
                <div
                    class="hidden w-16 shrink-0 flex-col items-center pt-11 sm:flex"
                >
                    <span
                        class="flex size-6 items-center justify-center rounded-full bg-[rgb(62_196_109/0.14)] text-status-live"
                    >
                        <FilledIcon name="check" :size="14" />
                    </span>
                    <span class="mt-1.5 w-px grow bg-edge-control" />
                </div>
                <div class="flex min-w-0 flex-1 flex-col gap-1.5 pt-11">
                    <h2 class="text-[15px]/6 font-medium text-fg-primary">
                        {{ $t('Pick your stack') }}
                    </h2>
                    <p class="text-[14px]/[22px] text-fg-tertiary">
                        {{
                            $t('We found :stack in :repo.', {
                                stack: detected,
                                repo: sample.repo,
                            })
                        }}
                    </p>
                    <div
                        class="mt-2 grid grid-cols-3 gap-2 md:grid-cols-6"
                        role="radiogroup"
                        :aria-label="$t('Stack')"
                    >
                        <button
                            v-for="entry in stacks"
                            :key="entry.key"
                            type="button"
                            role="radio"
                            :aria-checked="stack === entry.key"
                            class="flex h-[88px] flex-col items-center justify-center gap-2 rounded-[10px] border-[0.5px] transition-colors duration-150"
                            :class="
                                stack === entry.key
                                    ? 'border-[#383838] bg-surface-badge shadow-card'
                                    : 'border-edge-tabs hover:bg-surface-hover'
                            "
                            @click="stack = entry.key"
                        >
                            <BrandLogo :name="entry.key" :size="28" />
                            <span
                                class="text-[13px]/5 font-medium"
                                :class="
                                    stack === entry.key
                                        ? 'text-fg-primary'
                                        : 'text-fg-muted'
                                "
                                >{{ entry.label }}</span
                            >
                        </button>
                    </div>
                </div>
            </section>

            <section class="flex">
                <div class="hidden w-16 shrink-0 flex-col items-center sm:flex">
                    <span class="h-10 w-px bg-edge-control" />
                    <span
                        class="flex size-6 items-center justify-center rounded-full bg-fg-primary text-[12px]/4 font-medium text-surface-main"
                        >2</span
                    >
                    <span class="w-px grow bg-edge-control" />
                </div>
                <div class="flex min-w-0 flex-1 flex-col gap-1.5 pt-10">
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="text-[15px]/6 font-medium text-fg-primary">
                            {{ $t('Prompt your agent') }}
                        </h2>
                        <span class="grow" />
                        <div
                            class="flex items-center gap-0.5 rounded-lg border-[0.5px] border-edge-row bg-surface-field p-0.5 shadow-[inset_0_1px_2px_rgb(0_0_0/0.4)]"
                            role="tablist"
                        >
                            <button
                                v-for="option in modes"
                                :key="option.key"
                                type="button"
                                role="tab"
                                :aria-selected="mode === option.key"
                                class="flex h-[26px] items-center rounded-md px-3 text-[12px]/4 font-medium transition-colors duration-150"
                                :class="
                                    mode === option.key
                                        ? 'border-[0.5px] border-edge-tile bg-white text-fg-primary shadow-card dark:border-[#404040] dark:bg-[#2b2b2b]'
                                        : 'text-fg-muted hover:text-fg-secondary'
                                "
                                @click="mode = option.key"
                            >
                                {{ option.label }}
                            </button>
                        </div>
                    </div>
                    <p class="text-[14px]/[22px] text-fg-tertiary">
                        {{
                            mode === 'agent'
                                ? $t(
                                      'Paste it into any AI coding agent. It writes serveros.yml and a GitHub Action for :project.',
                                      { project: project.name },
                                  )
                                : $t(
                                      'Add this as serveros.yml at the root of :repo. We wrote it from what :project runs today.',
                                      {
                                          repo: sample.repo,
                                          project: project.name,
                                      },
                                  )
                        }}
                    </p>

                    <div
                        class="mt-2 flex flex-col rounded-xl border-[0.5px] border-edge-card bg-surface-card px-[5px] pb-[5px] shadow-card"
                    >
                        <div
                            class="flex items-center gap-2 py-[7px] pr-[3px] pl-[11px]"
                        >
                            <BrandLogo
                                v-if="mode === 'agent'"
                                :name="stack"
                                :size="14"
                            />
                            <FilledIcon
                                v-else
                                name="file-fill"
                                :size="14"
                                class="text-fg-muted"
                            />
                            <span
                                class="font-ui-mono text-[12px]/4 text-fg-secondary"
                                >{{
                                    mode === 'agent'
                                        ? $t(':stack guide', {
                                              stack: stackLabel,
                                          })
                                        : 'serveros.yml'
                                }}</span
                            >
                            <span class="grow" />
                            <DashButton
                                v-if="mode === 'agent'"
                                variant="primary"
                                :icon="Copy"
                                :label="$t('Copy prompt')"
                                @click="copyPrompt"
                            />
                            <DashButton
                                v-else
                                variant="primary"
                                :icon="Copy"
                                :label="$t('Copy file')"
                                @click="copyFile"
                            />
                        </div>

                        <div
                            v-if="mode === 'agent'"
                            class="relative overflow-hidden rounded-lg border-[0.5px] border-edge-row bg-surface-field shadow-[inset_0_1px_2px_rgb(0_0_0/0.4)]"
                            :class="expanded ? '' : 'h-[204px]'"
                        >
                            <div
                                class="flex flex-col px-[17.5px] pt-[15.5px] font-ui-mono text-[12px]/5"
                                :class="
                                    expanded
                                        ? 'pb-14 break-words whitespace-pre-wrap'
                                        : 'whitespace-pre'
                                "
                            >
                                <p
                                    v-for="(line, index) in prompt"
                                    :key="index"
                                    class="min-h-5"
                                    :class="
                                        line.heading
                                            ? 'text-fg-primary'
                                            : 'text-fg-tertiary'
                                    "
                                    v-text="line.text"
                                />
                            </div>
                            <template v-if="!expanded">
                                <div
                                    class="pointer-events-none absolute inset-y-0 right-0 w-20 bg-gradient-to-r from-transparent to-(--dash-bg-field)"
                                />
                                <div
                                    class="pointer-events-none absolute inset-x-0 bottom-0 h-[84px] bg-gradient-to-b from-transparent to-(--dash-bg-field) to-70%"
                                />
                            </template>
                            <button
                                type="button"
                                class="absolute bottom-3.5 left-1/2 flex h-7 -translate-x-1/2 items-center gap-1.5 rounded-full border-[0.5px] border-edge-strong bg-surface-badge pr-3 pl-2.5 text-[12px]/4 font-medium text-fg-primary shadow-card transition-colors duration-150 hover:bg-surface-tile"
                                :aria-expanded="expanded"
                                @click="expanded = !expanded"
                            >
                                <FilledIcon
                                    name="open-fill"
                                    :size="12"
                                    class="text-fg-muted"
                                />
                                {{
                                    expanded
                                        ? $t('Show less')
                                        : $t('Expand prompt')
                                }}
                            </button>
                        </div>

                        <div
                            v-else
                            class="max-h-[420px] overflow-auto rounded-lg border-[0.5px] border-edge-row bg-surface-field shadow-[inset_0_1px_2px_rgb(0_0_0/0.4)]"
                        >
                            <CodeLines :lines="project.file" />
                        </div>
                    </div>
                </div>
            </section>

            <section class="flex">
                <div class="hidden w-16 shrink-0 flex-col items-center sm:flex">
                    <span class="mb-1.5 h-[34px] w-px bg-edge-control" />
                    <span
                        class="flex size-6 items-center justify-center rounded-full border border-edge-tile bg-surface-main text-[12px]/4 font-medium text-fg-muted"
                        >3</span
                    >
                </div>
                <div class="flex min-w-0 flex-1 flex-col gap-1.5 pt-10">
                    <h2 class="text-[15px]/6 font-medium text-fg-primary">
                        {{ $t('Push it and open a pull request') }}
                    </h2>
                    <div
                        class="flex flex-wrap items-center gap-x-1.5 gap-y-1 text-[14px]/[22px] text-fg-tertiary"
                    >
                        <span>{{ $t('When your agent is done, run') }}</span>
                        <code
                            class="rounded-md border-[0.5px] border-edge-control bg-surface-badge px-[7px] py-px font-ui-mono text-[12px]/5 text-fg-primary"
                            >git push -u origin {{ sample.branch }}</code
                        >
                        <span>{{ $t('and open a pull request.') }}</span>
                    </div>
                    <p class="text-[14px]/[22px] text-fg-tertiary">
                        {{
                            $t(
                                'The plan shows up here and on the pull request. :project is in :environment, so it needs a review before it runs.',
                                {
                                    project: project.name,
                                    environment: project.environment,
                                },
                            )
                        }}
                    </p>
                </div>
            </section>
        </div>
    </div>
</template>
