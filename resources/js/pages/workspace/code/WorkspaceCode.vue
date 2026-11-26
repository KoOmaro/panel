<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Copy } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';
import BrandLogo from '@/components/dash/BrandLogo.vue';
import DashButton from '@/components/dash/DashButton.vue';
import DashHeader from '@/components/dash/DashHeader.vue';
import EmptyArt from '@/components/dash/EmptyArt.vue';
import CodeLines from '@/components/code/CodeLines.vue';
import FileTree from '@/components/code/FileTree.vue';
import RailNote from '@/components/code/RailNote.vue';
import RailSection from '@/components/code/RailSection.vue';
import RepositoryForm from '@/components/code/RepositoryForm.vue';
import type { CodeRepository } from '@/components/code/RepositoryForm.vue';
import { copyText } from '@/components/code/yaml';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import type { FilledIconName } from '@/components/icons/filledIcons';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { onWorkspace } from '@/lib/workspaceRoute';
import codeRoutes from '@/routes/workspace/code';
import machinesRoutes from '@/routes/workspace/machines';
import type { Workspace } from '@/types';

type CodeFile = { path: string; lines: string[] };

const props = defineProps<{
    workspace: Workspace;
    code: {
        files: CodeFile[];
        machines: number;
        services: number;
        monitors: number;
        projects: number;
        secrets: number;
        open: string | null;
    };
    github: {
        enabled: boolean;
        connected: boolean;
        account: string | null;
        connectUrl: string | null;
    };
    repository: CodeRepository | null;
    defaults: { branch: string; folder: string };
    canManage: boolean;
    needsLook: {
        title: string;
        body: string;
        icon: FilledIconName;
        path: string;
    }[];
}>();

const NEEDS_LOOK_SHOWN = 4;

const paths = computed(() => props.code.files.map((file) => file.path));
const folderNames = computed(() => [
    ...new Set(
        paths.value
            .filter((path) => path.includes('/'))
            .map((path) => path.slice(0, path.indexOf('/'))),
    ),
]);
const flagged = computed(() => props.needsLook.map((item) => item.path));

const selected = ref<string | null>(props.code.open);
const openFolders = ref<string[]>(
    props.code.open?.includes('/')
        ? [props.code.open.slice(0, props.code.open.indexOf('/'))]
        : [],
);

const allOpen = computed(() =>
    folderNames.value.every((name) => openFolders.value.includes(name)),
);
const current = computed(
    () => props.code.files.find((file) => file.path === selected.value) ?? null,
);
const currentFolder = computed(() => {
    const path = current.value?.path ?? '';

    return path.includes('/') ? path.slice(0, path.lastIndexOf('/') + 1) : '';
});
const currentName = computed(() =>
    (current.value?.path ?? '').slice(currentFolder.value.length),
);
const empty = computed(() => props.code.machines === 0);

function toggleAll(): void {
    openFolders.value = allOpen.value ? [] : [...folderNames.value];
}

function copyFile(): void {
    if (!current.value) return;

    void copyText(
        current.value.lines.join('\n') + '\n',
        trans('Copied :file', { file: current.value.path }),
    );
}

const sending = ref(false);
const allNeedsLook = ref(false);
const shownNeedsLook = computed(() =>
    allNeedsLook.value
        ? props.needsLook
        : props.needsLook.slice(0, NEEDS_LOOK_SHOWN),
);
const blocked = computed(() => {
    if (!props.canManage)
        return trans(
            'Only people who can manage machines can send these files to GitHub.',
        );
    if (!props.github.connected) return trans('Connect GitHub first.');
    if (!props.repository)
        return trans('Pick a repository under Send to GitHub first.');

    return null;
});

function openPullRequest(): void {
    router.post(
        onWorkspace(codeRoutes.pullRequest(props.workspace.slug)).url,
        {},
        {
            preserveScroll: true,
            onStart: () => (sending.value = true),
            onFinish: () => (sending.value = false),
        },
    );
}

function downloadZip(): void {
    window.location.assign(
        onWorkspace(codeRoutes.download(props.workspace.slug)).url,
    );
}

function lookAt(path: string): void {
    selected.value = path;
    openFolders.value = [
        ...new Set([...openFolders.value, path.split('/')[0]]),
    ];
}
</script>

<template>
    <Head :title="$t('Workspace as code')" />

    <DashHeader :crumbs="[{ label: $t('Code') }]">
        <template v-if="!empty" #actions>
            <DashButton
                icon="zip-fill"
                :label="$t('Download zip')"
                @click="downloadZip"
            />
            <DashButton
                v-if="repository?.pullRequest"
                :href="repository.pullRequest.url"
                external
            >
                <BrandLogo name="github" :size="16" />
                {{ $t('View on GitHub') }}
            </DashButton>
            <TooltipProvider :delay-duration="100">
                <Tooltip :disabled="blocked === null">
                    <TooltipTrigger as-child>
                        <span
                            class="inline-flex"
                            :tabindex="blocked === null ? undefined : 0"
                        >
                            <DashButton
                                variant="primary"
                                :label="$t('Open pull request')"
                                :disabled="blocked !== null"
                                :loading="sending"
                                @click="openPullRequest"
                            />
                        </span>
                    </TooltipTrigger>
                    <TooltipContent
                        side="bottom"
                        align="end"
                        class="max-w-64 px-3 py-2 text-left text-[13px]/5"
                    >
                        {{ blocked }}
                    </TooltipContent>
                </Tooltip>
            </TooltipProvider>
        </template>
    </DashHeader>

    <div
        v-if="empty"
        class="flex min-h-full flex-col items-center justify-center gap-5 px-6 py-16 text-center"
    >
        <EmptyArt kind="project-graph" />
        <div class="flex max-w-sm flex-col gap-1">
            <h1
                class="text-[15px]/6 font-medium tracking-[-0.01em] text-fg-primary"
            >
                {{ $t('Nothing to write yet') }}
            </h1>
            <p class="text-[13px]/5 text-fg-tertiary">
                {{
                    $t(
                        'Connect a machine and we write what runs on it as files you can keep in GitHub.',
                    )
                }}
            </p>
        </div>
        <DashButton
            variant="primary"
            :href="onWorkspace(machinesRoutes.index(workspace.slug)).url"
            :label="$t('Connect a machine')"
        />
    </div>

    <div v-else class="flex min-h-full flex-col xl:h-full">
        <div
            class="flex shrink-0 flex-col gap-1 border-b-[0.5px] border-edge-tabs px-6 pt-5 pb-[18px]"
        >
            <h1 class="text-[20px]/7 font-medium text-fg-primary">
                {{ $t('Your workspace as code') }}
            </h1>
            <p class="text-[13px]/5 text-fg-tertiary">
                {{
                    $t(
                        'We wrote your :machines machines, :services services and :monitors monitors as :files files. Look through them, then send them to GitHub. Nothing changes on your servers.',
                        {
                            machines: String(code.machines),
                            services: String(code.services),
                            monitors: String(code.monitors),
                            files: String(code.files.length),
                        },
                    )
                }}
            </p>
        </div>

        <div class="flex flex-col xl:min-h-0 xl:flex-1 xl:flex-row">
            <div class="flex flex-col px-6 pt-4 pb-6 xl:min-w-0 xl:flex-1">
                <section
                    class="dash-card flex min-h-[480px] flex-col xl:min-h-0 xl:flex-1"
                >
                    <div
                        class="flex shrink-0 items-center gap-2 border-b-[0.5px] border-edge-tabs px-[18px] py-3"
                    >
                        <h2 class="text-[14px]/5 font-medium text-fg-primary">
                            {{ $t('Files') }}
                        </h2>
                        <span class="text-[13px]/5 text-fg-muted">{{
                            code.files.length
                        }}</span>
                        <span class="grow" />
                        <button
                            v-if="folderNames.length"
                            type="button"
                            class="text-[12px]/4 font-medium text-fg-secondary transition-colors duration-150 hover:text-fg-primary"
                            @click="toggleAll"
                        >
                            {{
                                allOpen ? $t('Collapse all') : $t('Expand all')
                            }}
                        </button>
                    </div>

                    <div class="flex min-h-0 flex-1 flex-col md:flex-row">
                        <FileTree
                            v-model:open="openFolders"
                            class="shrink-0 overflow-y-auto md:w-[232px]"
                            :paths="paths"
                            :selected="selected"
                            :flagged="flagged"
                            @select="selected = $event"
                        />

                        <div
                            class="flex min-h-[320px] min-w-0 flex-1 flex-col border-t-[0.5px] border-edge-tabs bg-surface-field md:border-t-0 md:border-l-[0.5px]"
                        >
                            <template v-if="current">
                                <div
                                    class="flex h-11 shrink-0 items-center gap-2 border-b-[0.5px] border-edge-tabs pr-2.5 pl-4"
                                >
                                    <FilledIcon
                                        name="file-fill"
                                        class="text-fg-muted"
                                    />
                                    <span
                                        class="min-w-0 truncate font-ui-mono text-[12px]/4 text-fg-primary"
                                        ><span class="text-fg-muted">{{
                                            currentFolder
                                        }}</span
                                        >{{ currentName }}</span
                                    >
                                    <span class="grow" />
                                    <span
                                        class="shrink-0 text-[12px]/4 text-fg-faint"
                                        >{{
                                            $t(':count lines', {
                                                count: String(
                                                    current.lines.length,
                                                ),
                                            })
                                        }}</span
                                    >
                                    <DashButton
                                        :icon="Copy"
                                        :label="$t('Copy')"
                                        @click="copyFile"
                                    />
                                </div>
                                <div class="min-h-0 flex-1 overflow-auto">
                                    <CodeLines :lines="current.lines" />
                                </div>
                            </template>
                            <p
                                v-else
                                class="m-auto text-[13px]/5 text-fg-muted"
                            >
                                {{ $t('Pick a file to see it here.') }}
                            </p>
                        </div>
                    </div>
                </section>
            </div>

            <aside
                class="flex shrink-0 flex-col border-t-[0.5px] border-edge-tabs p-5 xl:w-[316px] xl:overflow-y-auto xl:border-t-0 xl:border-l-[0.5px]"
            >
                <RailSection :title="$t('Send to GitHub')" first>
                    <template v-if="github.connectUrl" #aside>
                        <a
                            :href="github.connectUrl"
                            class="text-[12px]/4 font-medium text-fg-secondary transition-colors duration-150 hover:text-fg-primary"
                            >{{ $t('Connect GitHub') }}</a
                        >
                    </template>

                    <RepositoryForm
                        :slug="workspace.slug"
                        :repository="repository"
                        :defaults="defaults"
                        :can-manage="canManage"
                        :connected="github.connected"
                    />
                </RailSection>

                <RailSection :title="$t('Stays in ServerOS')">
                    <RailNote
                        icon="key-fill"
                        :title="
                            code.secrets
                                ? $t(':count secrets', {
                                      count: String(code.secrets),
                                  })
                                : $t('Secrets')
                        "
                    >
                        {{ $t('The files say') + ' ' }}
                        <span
                            class="font-ui-mono text-[#3d5ee0] dark:text-[#87a3ff]"
                            v-text="'${{ secrets.NAME }}'"
                        />{{ $t(', never the value.') }}
                    </RailNote>
                    <RailNote
                        icon="activity-fill"
                        :title="$t('Logs, activity and backups')"
                    >
                        {{ $t('History stays in the panel, not in git.') }}
                    </RailNote>
                </RailSection>

                <RailSection
                    v-if="needsLook.length"
                    :title="$t('Needs a look')"
                >
                    <template #aside>
                        <span
                            class="text-[12px]/4 font-medium text-[#c27c12] dark:text-[#f5a833]"
                            >{{ needsLook.length }}</span
                        >
                    </template>
                    <button
                        v-for="item in shownNeedsLook"
                        :key="item.title"
                        type="button"
                        class="text-left"
                        @click="lookAt(item.path)"
                    >
                        <RailNote :icon="item.icon" :title="item.title" amber>
                            {{ item.body }}
                        </RailNote>
                    </button>
                    <button
                        v-if="needsLook.length > NEEDS_LOOK_SHOWN"
                        type="button"
                        class="self-start pl-[30px] text-[12px]/4 font-medium text-fg-secondary transition-colors duration-150 hover:text-fg-primary"
                        @click="allNeedsLook = !allNeedsLook"
                    >
                        {{
                            allNeedsLook
                                ? $t('Show fewer')
                                : $t('Show :count more', {
                                      count: String(
                                          needsLook.length - NEEDS_LOOK_SHOWN,
                                      ),
                                  })
                        }}
                    </button>
                </RailSection>
            </aside>
        </div>
    </div>
</template>
