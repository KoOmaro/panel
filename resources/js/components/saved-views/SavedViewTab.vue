<script setup lang="ts" generic="F extends SavedViewFilters">
import { trans } from 'laravel-vue-i18n';
import {
    PopoverAnchor,
    PopoverContent,
    PopoverPortal,
    PopoverRoot,
} from 'reka-ui';
import { computed, ref } from 'vue';
import DashButton from '@/components/dash/DashButton.vue';
import DashTab from '@/components/dash/DashTab.vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import type { FilledIconName } from '@/components/icons/filledIcons';
import SavedViewForm from '@/components/saved-views/SavedViewForm.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type {
    SavedView,
    SavedViewFilters,
    SavedViews,
} from '@/composables/useSavedViews';
import { toast } from '@/lib/toast';

const props = withDefaults(
    defineProps<{
        view: SavedView<F>;
        views: SavedViews<F>;
        icon?: FilledIconName;
    }>(),
    { icon: 'filter-fill' },
);

const active = computed(() => props.views.active?.id === props.view.id);
const changed = computed(() => active.value && props.views.changed);
const matches = computed(() => props.views.matches(props.view));

const menuOpen = ref(false);
const fromChevron = ref(false);
const next = ref<'rename' | 'delete' | null>(null);
const renaming = ref(false);
const renameError = ref<string | null>(null);
const deleting = ref(false);

function onPointerDown(event: PointerEvent): void {
    fromChevron.value =
        event.target instanceof Element &&
        event.target.closest('[data-view-menu]') !== null;
}

function onMenu(open: boolean): void {
    const chevron = fromChevron.value;
    fromChevron.value = false;
    if (open && !active.value && !chevron) {
        props.views.select(props.view);

        return;
    }
    menuOpen.value = open;
}

function after(action: 'rename' | 'delete'): void {
    next.value = action;
}

function onMenuClosed(event: Event): void {
    if (next.value === null) return;
    event.preventDefault();
    if (next.value === 'rename') {
        renameError.value = null;
        renaming.value = true;
    } else {
        deleting.value = true;
    }
    next.value = null;
}

async function run(
    work: Promise<Record<string, string> | null>,
): Promise<boolean> {
    const errors = await work;
    if (errors) {
        toast.error(Object.values(errors)[0] ?? trans('That didn’t work.'));
    }

    return !errors;
}

async function rename(name: string): Promise<void> {
    const errors = await props.views.rename(props.view, name);
    if (errors) {
        renameError.value = Object.values(errors)[0] ?? null;

        return;
    }
    renaming.value = false;
}

async function remove(): Promise<void> {
    if (await run(props.views.remove(props.view))) deleting.value = false;
}
</script>

<template>
    <PopoverRoot v-model:open="renaming">
        <PopoverAnchor class="flex shrink-0">
            <DropdownMenu :open="menuOpen" @update:open="onMenu">
                <DropdownMenuTrigger as-child>
                    <DashTab
                        :label="view.name"
                        :icon="icon"
                        :active="active"
                        @pointerdown="onPointerDown"
                        @contextmenu.prevent="menuOpen = true"
                    >
                        <template #suffix>
                            <span
                                v-if="changed"
                                class="size-1.5 shrink-0 rounded-full bg-status-pending"
                                :title="$t('Changed')"
                            />
                            <span v-if="changed" class="sr-only">{{
                                $t('Changed')
                            }}</span>
                            <span
                                data-view-menu
                                class="-mr-1 flex size-4 shrink-0 items-center justify-center rounded-[4px] transition-colors hover:bg-surface-hover hover:text-fg-secondary"
                                :class="
                                    active ? 'text-fg-muted' : 'text-fg-faint'
                                "
                            >
                                <FilledIcon name="chevron-down" :size="12" />
                            </span>
                        </template>
                    </DashTab>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    class="ds-modal min-w-52 font-ui"
                    align="start"
                    @close-auto-focus="onMenuClosed"
                >
                    <template v-if="changed">
                        <DropdownMenuItem @select="run(views.save(view))">
                            {{ $t('Save changes') }}
                        </DropdownMenuItem>
                        <DropdownMenuItem @select="views.revert()">
                            {{ $t('Revert') }}
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                    </template>
                    <DropdownMenuItem
                        v-else-if="!active"
                        :disabled="matches"
                        @select="run(views.save(view))"
                    >
                        {{ $t('Update with current filters') }}
                    </DropdownMenuItem>
                    <DropdownMenuItem @select="after('rename')">
                        {{ $t('Rename') }}
                    </DropdownMenuItem>
                    <DropdownMenuItem @select="run(views.duplicate(view))">
                        {{ $t('Duplicate') }}
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                        variant="destructive"
                        @select="after('delete')"
                    >
                        {{ $t('Delete') }}
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </PopoverAnchor>
        <PopoverPortal>
            <PopoverContent
                align="start"
                :side-offset="2"
                :collision-padding="12"
                class="workspace-panel ds-modal z-50 overflow-hidden text-fg-primary data-[state=open]:animate-in data-[state=open]:fade-in-0 data-[state=open]:zoom-in-95"
            >
                <SavedViewForm
                    :title="$t('Rename view')"
                    :name="view.name"
                    :submit-label="$t('Save')"
                    :busy="views.busy"
                    :error="renameError"
                    @submit="rename"
                    @cancel="renaming = false"
                />
            </PopoverContent>
        </PopoverPortal>
    </PopoverRoot>

    <Dialog v-model:open="deleting">
        <DialogContent
            class="workspace-panel ds-modal gap-0 p-0 font-ui text-fg-primary sm:max-w-md"
        >
            <div class="flex flex-col gap-4 p-5">
                <div class="flex flex-col gap-1">
                    <DialogTitle
                        class="text-[15px]/5 font-medium tracking-[-0.01em] text-fg-primary"
                        >{{
                            $t('Delete “:name”?', { name: view.name })
                        }}</DialogTitle
                    >
                    <DialogDescription
                        class="text-[13px]/5 text-pretty text-fg-tertiary"
                    >
                        {{
                            $t(
                                'This only removes the saved view. Nothing in the list changes.',
                            )
                        }}
                    </DialogDescription>
                </div>
                <div class="flex justify-end gap-2">
                    <DashButton
                        :label="$t('Cancel')"
                        @click="deleting = false"
                    />
                    <DashButton
                        variant="danger"
                        :label="$t('Delete view')"
                        :loading="views.busy"
                        @click="remove"
                    />
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
