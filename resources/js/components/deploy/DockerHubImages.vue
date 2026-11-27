<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';
import BrandLogo from '@/components/dash/BrandLogo.vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import type {
    DockerHubList,
    DockerHubPick,
    DockerHubTag,
} from '@/components/integrations/dockerHub';
import { bytes } from '@/components/machines/serviceFormat';
import { onWorkspace } from '@/lib/workspaceRoute';
import dockerHubRoutes from '@/routes/workspace/docker-hub';
import { shortAgo } from './catalog';

const {
    list,
    connectUrl,
    disabled = false,
} = defineProps<{
    list: DockerHubList | null;
    connectUrl: string;
    disabled?: boolean;
}>();

const emit = defineEmits<{ choose: [pick: DockerHubPick] }>();

const page = usePage<{ workspace: { slug: string } }>();

const query = ref('');
const open = ref<string | null>(null);
const tags = ref<Record<string, DockerHubTag[] | string>>({});

const matches = computed(() => {
    const term = query.value.trim().toLowerCase();

    return (list?.repositories ?? [])
        .filter((row) => row.repository.includes(term))
        .slice(0, 8);
});

async function toggle(repository: string): Promise<void> {
    open.value = open.value === repository ? null : repository;
    if (open.value === null || Array.isArray(tags.value[repository])) return;

    try {
        const response = await fetch(
            onWorkspace(
                dockerHubRoutes.tags(page.props.workspace.slug, {
                    query: { repository },
                }),
            ).url,
            {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            },
        );
        const body = (await response.json().catch(() => ({}))) as {
            tags?: DockerHubTag[];
            error?: string;
        };
        tags.value[repository] = response.ok
            ? body.error && !body.tags?.length
                ? body.error
                : (body.tags ?? [])
            : trans('Couldn’t load its tags. Try again.');
    } catch {
        tags.value[repository] = trans('Couldn’t load its tags. Try again.');
    }
}

function tagDetails(tag: DockerHubTag): string {
    return [
        shortAgo(tag.pushedAt)
            ? trans('pushed :when', { when: shortAgo(tag.pushedAt) ?? '' })
            : null,
        tag.size ? bytes(tag.size) : null,
        tag.platforms.length ? tag.platforms.join(', ') : null,
    ]
        .filter(Boolean)
        .join(' · ');
}
</script>

<template>
    <div class="flex flex-col gap-2">
        <div class="flex flex-wrap items-center gap-2">
            <template v-if="list?.connected">
                <span
                    class="flex h-7 items-center gap-1.5 rounded-[6px] px-1 text-[13px]/5 font-medium tracking-[-0.01em] text-fg-primary"
                >
                    <BrandLogo name="docker" :size="14" />
                    {{ list.account?.username }}
                </span>
                <span class="min-w-2 flex-1" />
                <label
                    class="flex h-7 w-[236px] max-w-full items-center gap-[7px] rounded-[6px] border-[0.5px] border-[#dcdcdc] bg-[#f5f5f5] px-[9px] shadow-[inset_0_1px_2px_rgb(0_0_0/0.06)] focus-within:border-[#4a4a4a] dark:border-[#2a2a2a] dark:bg-[#101010] dark:shadow-[inset_0_1px_2px_rgb(0_0_0/0.5)]"
                >
                    <FilledIcon
                        name="search-fill"
                        :size="13"
                        class="shrink-0 text-fg-muted"
                    />
                    <span class="sr-only">{{ $t('Search images') }}</span>
                    <input
                        v-model="query"
                        type="text"
                        autocomplete="off"
                        spellcheck="false"
                        :placeholder="$t('Search images')"
                        class="h-full min-w-0 flex-1 bg-transparent text-[12.5px]/4 tracking-[-0.005em] text-fg-primary outline-none placeholder:text-fg-faint"
                    />
                </label>
            </template>
            <template v-else-if="list">
                <a
                    :href="connectUrl"
                    class="dash-button dash-button--secondary dash-button--sm"
                >
                    <BrandLogo name="docker" :size="14" />
                    {{ $t('Connect Docker Hub') }}
                </a>
                <span
                    class="text-[12px]/4 tracking-[-0.005em] text-fg-tertiary"
                    >{{ $t('to deploy your images, private ones too') }}</span
                >
            </template>
        </div>

        <div
            v-if="list === null"
            class="h-[81px] animate-pulse rounded-[8px] bg-[#f2f2f2] dark:bg-[#1c1c1c]"
            :aria-label="$t('Loading your images')"
        />

        <ul
            v-else-if="list.connected"
            class="overflow-clip rounded-[8px] border-[0.5px] border-[#e2e2e2] bg-white shadow-[0_1px_2px_rgb(0_0_0/0.06)] dark:border-[#2a2a2a] dark:bg-[#1c1c1c] dark:shadow-[inset_0_1px_0_rgb(255_255_255/0.04),0_3px_6px_-2px_rgb(0_0_0/0.3),0_1px_1.5px_rgb(0_0_0/0.5)]"
        >
            <li
                v-for="row in matches"
                :key="row.repository"
                class="border-b-[0.5px] border-[#ececec] last:border-b-0 dark:border-[#262626]"
            >
                <button
                    type="button"
                    class="flex h-10 w-full cursor-pointer items-center gap-2.5 pr-3 pl-2 text-left hover:bg-black/[0.02] focus-visible:outline-2 focus-visible:outline-(--ds-ring) dark:hover:bg-white/[0.02]"
                    :aria-expanded="open === row.repository"
                    @click="toggle(row.repository)"
                >
                    <span
                        class="flex size-[26px] shrink-0 items-center justify-center rounded-[6px] bg-[#f2f2f2] shadow-[0_1px_1.5px_rgb(0_0_0/0.08)] dark:bg-[#262626] dark:shadow-[inset_0_1px_0_rgb(255_255_255/0.05),0_1px_1.5px_rgb(0_0_0/0.5)]"
                    >
                        <BrandLogo name="docker" :size="15" />
                    </span>
                    <span class="flex min-w-0 items-center text-[13px]/5">
                        <span class="truncate tracking-[-0.005em] text-fg-muted"
                            >{{ row.namespace }}/</span
                        >
                        <span
                            class="truncate font-medium tracking-[-0.01em] text-fg-primary"
                            >{{ row.name }}</span
                        >
                        <FilledIcon
                            v-if="row.private"
                            name="lock-fill"
                            :size="12"
                            class="ml-1.5 shrink-0 text-fg-muted"
                            :aria-label="$t('Private')"
                        />
                    </span>
                    <span class="flex-1" />
                    <span
                        class="hidden shrink-0 text-[12px]/4 tracking-[-0.005em] text-fg-tertiary min-[560px]:block"
                        >{{ shortAgo(row.pushedAt) }}</span
                    >
                    <FilledIcon
                        :name="
                            open === row.repository
                                ? 'chevron-up'
                                : 'chevron-down'
                        "
                        :size="13"
                        class="shrink-0 text-fg-muted"
                    />
                </button>
                <div
                    v-if="open === row.repository"
                    class="border-t-[0.5px] border-[#ececec] bg-[#fafafa] dark:border-[#262626] dark:bg-[#181818]"
                >
                    <p
                        v-if="tags[row.repository] === undefined"
                        class="px-3 py-2.5 text-[12.5px]/4 text-fg-muted"
                        role="status"
                    >
                        {{ $t('Loading tags…') }}
                    </p>
                    <p
                        v-else-if="typeof tags[row.repository] === 'string'"
                        class="px-3 py-2.5 text-[12.5px]/4 text-status-down"
                        role="alert"
                    >
                        {{ tags[row.repository] }}
                    </p>
                    <p
                        v-else-if="!tags[row.repository]?.length"
                        class="px-3 py-2.5 text-[12.5px]/4 text-fg-muted"
                    >
                        {{ $t('Nothing has been pushed here yet.') }}
                    </p>
                    <ul v-else class="flex flex-col">
                        <li
                            v-for="tag in tags[
                                row.repository
                            ] as DockerHubTag[]"
                            :key="tag.name"
                            class="flex h-10 items-center gap-2.5 border-t-[0.5px] border-[#ececec] pr-1.5 pl-[46px] first:border-t-0 dark:border-[#232323]"
                        >
                            <span
                                class="min-w-0 truncate font-ui-mono text-[12.5px]/5 text-fg-primary"
                                >{{ tag.name }}</span
                            >
                            <span
                                class="hidden min-w-0 truncate text-[12px]/4 tracking-[-0.005em] text-fg-tertiary min-[560px]:block"
                                >{{ tagDetails(tag) }}</span
                            >
                            <span class="flex-1" />
                            <button
                                type="button"
                                class="dash-button dash-button--secondary dash-button--sm"
                                :disabled="disabled"
                                @click="
                                    emit('choose', {
                                        repository: row.repository,
                                        tag,
                                    })
                                "
                            >
                                {{ $t('Deploy') }}
                            </button>
                        </li>
                    </ul>
                </div>
            </li>
            <li
                v-if="matches.length === 0"
                class="flex h-10 items-center px-3 text-[12.5px]/4 text-fg-muted"
            >
                {{
                    query.trim()
                        ? $t('No image matches “:query”.', {
                              query: query.trim(),
                          })
                        : $t('No repositories on this Docker Hub account yet.')
                }}
            </li>
            <li
                v-if="list.error"
                class="flex min-h-10 items-center border-t-[0.5px] border-[#ececec] px-3 py-2 text-[12.5px]/4 text-status-down dark:border-[#262626]"
                role="alert"
            >
                {{
                    $t('Couldn’t load every repository: :error', {
                        error: list.error,
                    })
                }}
            </li>
        </ul>
    </div>
</template>
