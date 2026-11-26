<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import {
    computed,
    onBeforeUnmount,
    onMounted,
    ref,
    useTemplateRef,
    watch,
} from 'vue';
import SectionLabel from '@/components/dash/SectionLabel.vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import SettingsNavIcon from '@/components/workspace/settings/SettingsNavIcon.vue';
import type { Group, Result } from '@/components/workspace/settingsNav';

const { groups, backHref, backLabel } = defineProps<{
    groups: Group[];
    backHref: string;
    backLabel: string;
}>();

const query = ref('');
const searchInput = useTemplateRef<HTMLInputElement>('searchInput');
const searching = computed(() => query.value.trim() !== '');
const sidebarGroups = computed(() =>
    groups.filter((group) => group.inSidebar && group.items.length > 0),
);

const results = computed<Result[]>(() => {
    const words = query.value.trim().toLowerCase().split(/\s+/);
    const matches = (...text: (string | null | undefined)[]): boolean => {
        const haystack = text.join(' ').toLowerCase();

        return words.every((word) => haystack.includes(word));
    };

    const pages: Result[] = [];
    const sections: Result[] = [];

    for (const group of groups) {
        for (const item of group.items) {
            if (matches(item.label, group.label, item.keywords)) {
                pages.push({
                    key: item.href,
                    label: item.label,
                    page: group.inSidebar ? null : group.label,
                    icon: item.icon,
                    href: item.href,
                });
            }

            for (const section of item.sections) {
                if (matches(section.label, item.label, section.keywords)) {
                    sections.push({
                        key: `${item.href}#${section.anchor}`,
                        label: section.label,
                        page: item.label,
                        icon: item.icon,
                        href: `${item.href}#${section.anchor}`,
                    });
                }
            }
        }
    }

    return [...pages, ...sections];
});

const selected = ref(0);

watch(query, () => (selected.value = 0));

function onSearchKeydown(event: KeyboardEvent): void {
    const count = results.value.length;

    if (event.key === 'Escape') {
        query.value = '';
    } else if (event.key === 'ArrowDown' && count) {
        event.preventDefault();
        selected.value = (selected.value + 1) % count;
    } else if (event.key === 'ArrowUp' && count) {
        event.preventDefault();
        selected.value = (selected.value - 1 + count) % count;
    } else if (event.key === 'Enter' && count) {
        event.preventDefault();
        router.visit(results.value[selected.value].href);
    }
}

function onKeydown(event: KeyboardEvent): void {
    const target = event.target as HTMLElement | null;
    const typing =
        target?.isContentEditable ||
        ['INPUT', 'TEXTAREA', 'SELECT'].includes(target?.tagName ?? '');

    if (event.key === '/' && !typing && !event.metaKey && !event.ctrlKey) {
        event.preventDefault();
        searchInput.value?.focus();
    }
}

let stopNavigate: (() => void) | undefined;

onMounted(() => {
    window.addEventListener('keydown', onKeydown);
    stopNavigate = router.on('navigate', () => (query.value = ''));
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeydown);
    stopNavigate?.();
});

const row =
    'flex h-[30px] w-full shrink-0 items-center gap-[11px] rounded-[8px] px-2.5 text-[13px]/5 tracking-[-0.01em] transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-(--ds-ring)';
</script>

<template>
    <div
        class="flex h-full min-h-0 flex-col gap-0.5 overflow-y-auto px-3 pb-4 font-ui"
    >
        <Link
            :href="backHref"
            class="group/back flex h-14 shrink-0 items-center gap-2 rounded-[8px] pl-2 focus-visible:outline-2 focus-visible:-outline-offset-4 focus-visible:outline-(--ds-ring)"
        >
            <FilledIcon
                name="chevron-right"
                class="rotate-180 text-fg-tertiary transition-colors group-hover/back:text-fg-secondary"
            />
            <span
                class="truncate text-[13px]/5 font-medium tracking-[-0.01em] text-fg-secondary transition-colors group-hover/back:text-fg-primary"
                >{{ backLabel }}</span
            >
        </Link>

        <label
            class="flex h-[30px] shrink-0 cursor-text items-center gap-2 rounded-[7px] border-[0.5px] border-edge-row bg-surface-well px-[9px] shadow-[inset_0_1px_2px_rgb(0_0_0/0.4)] focus-within:border-edge-checkbox-hover"
        >
            <FilledIcon name="search-fill" :size="14" class="text-fg-muted" />
            <span class="sr-only">{{ $t('Search settings') }}</span>
            <input
                ref="searchInput"
                v-model="query"
                type="search"
                :placeholder="$t('Search settings')"
                role="combobox"
                aria-controls="settings-nav"
                :aria-expanded="searching"
                :aria-activedescendant="
                    searching && results.length
                        ? `settings-result-${selected}`
                        : undefined
                "
                class="h-full min-w-0 grow bg-transparent text-[12.5px]/4 tracking-[-0.005em] text-fg-primary outline-none placeholder:text-fg-faint [&::-webkit-search-cancel-button]:hidden"
                @keydown="onSearchKeydown"
            />
            <kbd
                class="flex h-[18px] shrink-0 items-center rounded-[4px] border-[0.5px] border-edge-control bg-surface-raised px-[5px] font-ui-mono text-[11px]/3.5 text-fg-tertiary dark:bg-[#1f1f1f]"
                aria-hidden="true"
                >/</kbd
            >
        </label>

        <nav
            id="settings-nav"
            :aria-label="$t('Settings')"
            class="flex flex-col gap-0.5"
        >
            <template v-if="searching">
                <ul
                    v-if="results.length"
                    role="listbox"
                    :aria-label="$t('Search settings')"
                    class="mt-2.5 flex flex-col gap-0.5"
                >
                    <li
                        v-for="(result, index) in results"
                        :id="`settings-result-${index}`"
                        :key="result.key"
                        role="option"
                        :aria-selected="index === selected"
                    >
                        <Link
                            :href="result.href"
                            :class="[
                                row,
                                index === selected
                                    ? 'bg-surface-raised text-fg-primary'
                                    : 'text-fg-tertiary hover:bg-surface-hover hover:text-fg-secondary',
                            ]"
                            tabindex="-1"
                            @mouseenter="selected = index"
                        >
                            <SettingsNavIcon
                                :icon="result.icon"
                                class="text-fg-tertiary"
                            />
                            <span class="min-w-0 flex-1 truncate">{{
                                result.label
                            }}</span>
                            <span
                                v-if="result.page"
                                class="max-w-20 shrink-0 truncate text-[12px]/4 text-fg-faint"
                                >{{ result.page }}</span
                            >
                        </Link>
                    </li>
                </ul>
                <p v-else class="mt-2.5 px-2.5 text-[13px]/5 text-fg-muted">
                    {{ $t('Nothing in settings matches that.') }}
                </p>
            </template>

            <template v-else>
                <div
                    v-for="group in sidebarGroups"
                    :key="group.key"
                    class="mt-2.5 flex flex-col gap-0.5"
                >
                    <SectionLabel>{{ group.label }}</SectionLabel>
                    <Link
                        v-for="link in group.items"
                        :key="link.href"
                        :href="link.href"
                        :class="[
                            row,
                            link.current
                                ? 'bg-surface-raised font-medium text-fg-primary'
                                : 'group/link text-fg-tertiary hover:bg-surface-hover hover:text-fg-secondary',
                        ]"
                        :aria-current="link.current ? 'page' : undefined"
                    >
                        <SettingsNavIcon
                            :icon="link.icon"
                            class="text-fg-tertiary"
                        />
                        <span class="min-w-0 flex-1 truncate">{{
                            link.label
                        }}</span>
                    </Link>
                </div>
            </template>
        </nav>
    </div>
</template>
