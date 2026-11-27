<script setup lang="ts">
import { computed } from 'vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';

type Folder = { name: string; files: string[]; flagged: boolean };

const {
    paths,
    selected,
    flagged = [],
} = defineProps<{
    paths: string[];
    selected: string | null;
    flagged?: string[];
}>();

const open = defineModel<string[]>('open', { required: true });

defineEmits<{ select: [path: string] }>();

const folders = computed<Folder[]>(() => {
    const byName = new Map<string, Folder>();

    for (const path of paths) {
        const slash = path.indexOf('/');

        if (slash === -1) continue;

        const name = path.slice(0, slash);
        const folder = byName.get(name) ?? { name, files: [], flagged: false };

        folder.files.push(path);
        folder.flagged ||= flagged.includes(path);
        byName.set(name, folder);
    }

    return [...byName.values()];
});

const rootFiles = computed(() => paths.filter((path) => !path.includes('/')));

function toggle(name: string): void {
    open.value = open.value.includes(name)
        ? open.value.filter((entry) => entry !== name)
        : [...open.value, name];
}

function fileName(path: string): string {
    return path.slice(path.lastIndexOf('/') + 1);
}
</script>

<template>
    <div class="flex flex-col gap-0.5 p-2" role="tree">
        <template v-for="folder in folders" :key="folder.name">
            <button
                type="button"
                role="treeitem"
                :aria-expanded="open.includes(folder.name)"
                class="flex h-7.5 w-full shrink-0 items-center gap-1.5 rounded-md pr-2.5 pl-2 text-left transition-colors duration-150 hover:bg-surface-hover"
                @click="toggle(folder.name)"
            >
                <span
                    class="inline-flex text-fg-secondary transition-transform duration-150"
                    :class="{ 'rotate-90': open.includes(folder.name) }"
                >
                    <FilledIcon name="chevron-right" />
                </span>
                <FilledIcon name="folder-fill" class="text-fg-muted" />
                <span class="truncate text-[13px]/5 text-fg-secondary">{{
                    folder.name
                }}</span>
                <span class="grow" />
                <span
                    v-if="folder.flagged"
                    class="size-1.5 shrink-0 rounded-full bg-[#f5a833]"
                    :title="$t('Needs a look')"
                />
                <span class="text-[12px]/4 text-fg-faint">{{
                    folder.files.length
                }}</span>
            </button>
            <template v-if="open.includes(folder.name)">
                <button
                    v-for="path in folder.files"
                    :key="path"
                    type="button"
                    role="treeitem"
                    :aria-selected="selected === path"
                    class="flex h-7.5 w-full shrink-0 items-center gap-1.5 rounded-md pr-2.5 pl-[52px] text-left transition-colors duration-150"
                    :class="
                        selected === path
                            ? 'bg-edge-row'
                            : 'hover:bg-surface-hover'
                    "
                    @click="$emit('select', path)"
                >
                    <FilledIcon
                        name="file-fill"
                        :class="
                            selected === path
                                ? 'text-fg-primary'
                                : 'text-fg-muted'
                        "
                    />
                    <span
                        class="truncate text-[13px]/5"
                        :class="
                            selected === path
                                ? 'text-fg-primary'
                                : 'text-fg-secondary'
                        "
                        >{{ fileName(path) }}</span
                    >
                </button>
            </template>
        </template>
        <button
            v-for="path in rootFiles"
            :key="path"
            type="button"
            role="treeitem"
            :aria-selected="selected === path"
            class="flex h-7.5 w-full shrink-0 items-center gap-1.5 rounded-md pr-2.5 pl-[30px] text-left transition-colors duration-150"
            :class="
                selected === path ? 'bg-edge-row' : 'hover:bg-surface-hover'
            "
            @click="$emit('select', path)"
        >
            <FilledIcon
                name="file-fill"
                :class="selected === path ? 'text-fg-primary' : 'text-fg-muted'"
            />
            <span
                class="truncate text-[13px]/5"
                :class="
                    selected === path ? 'text-fg-primary' : 'text-fg-secondary'
                "
                >{{ path }}</span
            >
        </button>
    </div>
</template>
