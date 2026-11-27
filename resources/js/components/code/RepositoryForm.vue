<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, onMounted, ref } from 'vue';
import BrandLogo from '@/components/dash/BrandLogo.vue';
import DashButton from '@/components/dash/DashButton.vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import { SelectMenu } from '@/components/ui/select';
import { onWorkspace } from '@/lib/workspaceRoute';
import codeRoutes from '@/routes/workspace/code';
import githubRoutes from '@/routes/workspace/github';

export type CodeRepository = {
    repo: string;
    branch: string;
    folder: string;
    private: boolean;
    url: string;
    pullRequest: { number: number; url: string } | null;
};

type Listed = { name: string; private: boolean; branch: string };

const props = defineProps<{
    slug: string;
    repository: CodeRepository | null;
    defaults: { branch: string; folder: string };
    canManage: boolean;
    connected: boolean;
}>();

const field =
    'flex h-[34px] items-center gap-2 rounded-[7px] border-[0.5px] border-edge-row bg-surface-field px-2.5 shadow-[inset_0_1px_2px_rgb(0_0_0/0.4)]';
const label = 'text-[12px]/4 font-medium text-fg-tertiary';

const listed = ref<Listed[] | null>(null);
const form = useForm({
    repo: props.repository?.repo ?? '',
    branch: props.repository?.branch ?? props.defaults.branch,
    folder: props.repository ? props.repository.folder : props.defaults.folder,
});

const editable = computed(() => props.connected && props.canManage);
const options = computed(() => {
    const names = (listed.value ?? []).map((repo) => ({
        value: repo.name,
        label: repo.name,
        hint: repo.private ? trans('Private') : undefined,
    }));

    if (
        props.repository &&
        !names.some((option) => option.value === props.repository?.repo)
    ) {
        names.unshift({
            value: props.repository.repo,
            label: props.repository.repo,
            hint: undefined,
        });
    }

    return names;
});
const placeholder = computed(() => {
    if (listed.value === null) return trans('Loading repositories…');

    return options.value.length
        ? trans('Pick a repository')
        : trans('No repositories shared with ServerOS');
});
const dirty = computed(
    () =>
        form.repo !== (props.repository?.repo ?? '') ||
        form.branch !== (props.repository?.branch ?? props.defaults.branch) ||
        form.folder !==
            (props.repository
                ? props.repository.folder
                : props.defaults.folder),
);
const folderLabel = computed(() =>
    props.repository?.folder
        ? `${props.repository.folder}/`
        : trans('The repository root'),
);

async function load(): Promise<void> {
    if (!editable.value) return;

    try {
        const response = await fetch(
            onWorkspace(githubRoutes.repositories(props.slug)).url,
            {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            },
        );
        const body = response.ok
            ? ((await response.json()) as { repositories?: Listed[] })
            : null;
        listed.value = body?.repositories ?? [];
    } catch {
        listed.value = [];
    }
}

onMounted(load);

function save(): void {
    form.put(onWorkspace(codeRoutes.repository(props.slug)).url, {
        preserveScroll: true,
        onSuccess: () => form.defaults(),
    });
}
</script>

<template>
    <template v-if="editable">
        <div class="flex flex-col gap-1.5">
            <label for="code-repo" :class="label">{{ $t('Repository') }}</label>
            <div class="relative">
                <BrandLogo
                    name="github"
                    :size="14"
                    class="pointer-events-none absolute top-[10px] left-2.5 z-10"
                />
                <SelectMenu
                    id="code-repo"
                    v-model="form.repo"
                    :options="options"
                    :placeholder="placeholder"
                    :disabled="listed === null"
                    class="h-[34px] w-full rounded-[7px] border-[0.5px] border-edge-row bg-surface-field pr-2.5 pl-8 text-[13px]/5 text-fg-primary shadow-[inset_0_1px_2px_rgb(0_0_0/0.4)] transition-colors hover:border-edge-strong"
                />
            </div>
            <p v-if="form.errors.repo" class="text-[12px]/4 text-status-down">
                {{ form.errors.repo }}
            </p>
        </div>

        <div class="flex flex-col gap-1.5">
            <label for="code-branch" :class="label">{{ $t('Branch') }}</label>
            <div :class="field">
                <FilledIcon
                    name="branch-fill"
                    :size="14"
                    class="text-fg-muted"
                />
                <input
                    id="code-branch"
                    v-model="form.branch"
                    type="text"
                    spellcheck="false"
                    autocomplete="off"
                    :placeholder="defaults.branch"
                    class="min-w-0 flex-1 bg-transparent font-ui-mono text-[12px]/4 text-fg-primary outline-none placeholder:text-fg-faint"
                />
            </div>
            <p v-if="form.errors.branch" class="text-[12px]/4 text-status-down">
                {{ form.errors.branch }}
            </p>
        </div>

        <div class="flex flex-col gap-1.5">
            <label for="code-folder" :class="label">{{ $t('Folder') }}</label>
            <div :class="field">
                <FilledIcon
                    name="folder-fill"
                    :size="14"
                    class="text-fg-muted"
                />
                <input
                    id="code-folder"
                    v-model="form.folder"
                    type="text"
                    spellcheck="false"
                    autocomplete="off"
                    :placeholder="$t('The repository root')"
                    class="min-w-0 flex-1 bg-transparent font-ui-mono text-[12px]/4 text-fg-primary outline-none placeholder:text-fg-faint"
                />
            </div>
            <p v-if="form.errors.folder" class="text-[12px]/4 text-status-down">
                {{ form.errors.folder }}
            </p>
        </div>

        <div v-if="dirty" class="flex justify-end">
            <DashButton
                size="sm"
                :label="$t('Save')"
                :disabled="!form.repo"
                :loading="form.processing"
                @click="save"
            />
        </div>
    </template>

    <template v-else-if="repository">
        <div class="flex flex-col gap-1.5">
            <span :class="label">{{ $t('Repository') }}</span>
            <div :class="field">
                <BrandLogo name="github" :size="14" />
                <span class="truncate text-[13px]/5 text-fg-primary">{{
                    repository.repo
                }}</span>
                <FilledIcon
                    v-if="repository.private"
                    name="lock-fill"
                    :size="12"
                    class="text-fg-muted"
                />
            </div>
        </div>
        <div class="flex flex-col gap-1.5">
            <span :class="label">{{ $t('Branch') }}</span>
            <div :class="field">
                <FilledIcon
                    name="branch-fill"
                    :size="14"
                    class="text-fg-muted"
                />
                <span
                    class="truncate font-ui-mono text-[12px]/4 text-fg-primary"
                    >{{ repository.branch }}</span
                >
            </div>
        </div>
        <div class="flex flex-col gap-1.5">
            <span :class="label">{{ $t('Folder') }}</span>
            <div :class="field">
                <FilledIcon
                    name="folder-fill"
                    :size="14"
                    class="text-fg-muted"
                />
                <span
                    class="truncate font-ui-mono text-[12px]/4 text-fg-primary"
                    >{{ folderLabel }}</span
                >
            </div>
        </div>
    </template>

    <p v-else class="text-[12px]/[18px] text-fg-tertiary">
        {{
            connected
                ? $t(
                      'No repository yet. Someone who can manage machines picks where these files go.',
                  )
                : canManage
                  ? $t(
                        'Connect GitHub, then pick the repository these files go to.',
                    )
                  : $t(
                        'GitHub isn’t connected yet. Someone who can manage machines can connect it.',
                    )
        }}
    </p>

    <p v-if="repository" class="text-[12px]/[18px] text-fg-tertiary">
        {{
            $t(
                'Open pull request sends these files to :branch on :repo. Pull requests that change them show up in',
                { branch: repository.branch, repo: repository.repo },
            ) + ' '
        }}<Link
            :href="onWorkspace(codeRoutes.changes(slug)).url"
            class="underline decoration-edge-strong underline-offset-2 transition-colors duration-150 hover:text-fg-secondary"
            >{{ $t('Review changes') }}</Link
        >.
    </p>

    <a
        v-if="repository?.pullRequest"
        :href="repository.pullRequest.url"
        target="_blank"
        rel="noopener"
        class="flex h-[34px] items-center gap-2 rounded-lg px-2.5 text-[13px]/5 transition-colors duration-150 hover:bg-edge"
    >
        <BrandLogo name="github" :size="14" />
        <span class="font-medium text-fg-primary">{{
            $t('Pull request #:number', {
                number: String(repository.pullRequest.number),
            })
        }}</span>
        <span class="grow" />
        <span class="text-[12px]/4 text-fg-tertiary">{{
            $t('View on GitHub')
        }}</span>
        <FilledIcon name="open-fill" :size="12" class="text-fg-muted" />
    </a>
</template>
