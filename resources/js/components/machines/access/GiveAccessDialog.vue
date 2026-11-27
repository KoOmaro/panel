<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { useForm } from '@inertiajs/vue3';
import { X } from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import DashAvatar from '@/components/dash/DashAvatar.vue';
import DashButton from '@/components/dash/DashButton.vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { SelectMenu } from '@/components/ui/select';
import { onWorkspace } from '@/lib/workspaceRoute';
import accessRoutes from '@/routes/workspace/machines/access';
import { initials } from './access';
import type { AccessMember } from './access';

const open = defineModel<boolean>('open', { default: false });

const props = defineProps<{
    workspaceSlug: string;
    workspaceName: string;
    machineId: number;
    machineName: string;
    login: string;
    members: AccessMember[];
    expiryDays: number[];
}>();

const emit = defineEmits<{ done: [] }>();

type Chosen =
    | { kind: 'member'; member: AccessMember; key: string }
    | { kind: 'external'; email: string; key: string };

const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

const query = ref('');
const chosen = ref<Chosen[]>([]);
const days = ref<number>(30);
const focused = ref(false);
const highlighted = ref(0);
const input = ref<HTMLInputElement | null>(null);

const form = useForm<{
    people: { userId?: number; email?: string; key: string }[];
    login: string;
    expiresInDays: number | null;
}>({ people: [], login: props.login, expiresInDays: null });

watch(open, (value) => {
    if (!value) return;
    query.value = '';
    chosen.value = [];
    days.value = props.expiryDays.includes(30)
        ? 30
        : (props.expiryDays[0] ?? 30);
    form.clearErrors();
    nextTick(() => input.value?.focus());
});

const pickedIds = computed(
    () =>
        new Set(
            chosen.value.flatMap((item) =>
                item.kind === 'member' ? [item.member.id] : [],
            ),
        ),
);

const suggestions = computed(() => {
    const needle = query.value.trim().toLowerCase();

    return props.members
        .filter((member) => !pickedIds.value.has(member.id))
        .filter(
            (member) =>
                !needle ||
                member.name.toLowerCase().includes(needle) ||
                member.email.toLowerCase().includes(needle),
        )
        .slice(0, 5);
});

const typedEmail = computed(() => {
    const value = query.value.trim().toLowerCase();
    if (!EMAIL.test(value)) return null;
    if (props.members.some((member) => member.email.toLowerCase() === value))
        return null;
    if (
        chosen.value.some(
            (item) => item.kind === 'external' && item.email === value,
        )
    )
        return null;

    return value;
});

const externals = computed(() =>
    chosen.value.filter(
        (item): item is Extract<Chosen, { kind: 'external' }> =>
            item.kind === 'external',
    ),
);

const showSuggestions = computed(
    () => focused.value || query.value.trim() !== '',
);

watch(suggestions, () => (highlighted.value = 0));

function pick(member: AccessMember): void {
    chosen.value.push({ kind: 'member', member, key: '' });
    query.value = '';
    input.value?.focus();
}

function addExternal(email: string): void {
    chosen.value.push({ kind: 'external', email, key: '' });
    query.value = '';
    input.value?.focus();
}

function drop(index: number): void {
    chosen.value.splice(index, 1);
    form.clearErrors();
}

function onEnter(): void {
    const member = suggestions.value[highlighted.value];
    if (query.value.trim() !== '' && member) {
        pick(member);
    } else if (typedEmail.value) {
        addExternal(typedEmail.value);
    }
}

function onBackspace(): void {
    if (query.value === '' && chosen.value.length) chosen.value.pop();
}

function move(step: number): void {
    const count = suggestions.value.length;
    if (count) highlighted.value = (highlighted.value + step + count) % count;
}

const label = (item: Chosen): string =>
    item.kind === 'member' ? item.member.name : item.email;

const dayOptions = computed(() =>
    props.expiryDays.map((value) => ({
        value,
        label:
            value === 1
                ? trans('In 1 day')
                : trans('In :count days', { count: String(value) }),
    })),
);

const generalError = computed(() => {
    const errors = form.errors as Record<string, string | undefined>;

    return (
        errors.access ??
        errors.people ??
        errors.expiresInDays ??
        errors.login ??
        null
    );
});

function keyError(index: number): string | undefined {
    const errors = form.errors as Record<string, string | undefined>;

    return (
        errors[`people.${index}.key`] ??
        errors[`people.${index}.email`] ??
        errors[`people.${index}.userId`]
    );
}

function submit(): void {
    if (!chosen.value.length) return;
    form.transform(() => ({
        people: chosen.value.map((item) =>
            item.kind === 'member'
                ? { userId: item.member.id, key: item.key.trim() }
                : { email: item.email, key: item.key.trim() },
        ),
        login: props.login,
        expiresInDays: externals.value.length ? days.value : null,
    })).post(
        onWorkspace(
            accessRoutes.store({
                workspace: props.workspaceSlug,
                machine: props.machineId,
            }),
        ).url,
        {
            preserveScroll: true,
            only: ['access'],
            onSuccess: () => {
                open.value = false;
                emit('done');
            },
        },
    );
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="workspace-panel ds-modal gap-0 !rounded-[12px] p-0 font-ui text-fg-primary sm:max-w-[540px]"
            :show-close-button="false"
        >
            <form class="flex flex-col" @submit.prevent="submit">
                <div class="flex items-start gap-3 pt-[18px] pr-4 pb-4 pl-5">
                    <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                        <DialogTitle
                            class="text-[16px]/6 font-medium tracking-[-0.01em] text-fg-primary"
                        >
                            {{
                                $t('Give access to :name', {
                                    name: machineName,
                                })
                            }}
                        </DialogTitle>
                        <DialogDescription
                            class="text-[13px]/5 text-fg-tertiary"
                        >
                            {{
                                $t(
                                    'Pick people from :workspace, or add anyone by email.',
                                    {
                                        workspace: workspaceName,
                                    },
                                )
                            }}
                        </DialogDescription>
                    </div>
                    <kbd
                        class="flex h-5 items-center rounded-[5px] border-[0.5px] border-edge-strong px-1.5 font-ui text-[11px]/[14px] text-fg-muted"
                        >Esc</kbd
                    >
                </div>

                <div class="flex flex-col gap-3.5 px-5 pb-[18px]">
                    <div
                        class="flex flex-wrap items-center gap-1.5 rounded-[8px] border bg-surface-field py-1.5 pr-2.5 pl-1.5"
                        :class="
                            focused
                                ? 'border-[rgba(51,97,255,0.7)] shadow-[0_0_0_3px_rgba(51,97,255,0.25),inset_0_1px_2px_rgb(0_0_0/0.4)]'
                                : 'border-edge-field shadow-field'
                        "
                        @click="input?.focus()"
                    >
                        <span
                            v-for="(item, index) in chosen"
                            :key="
                                item.kind === 'member'
                                    ? `m${item.member.id}`
                                    : `e${item.email}`
                            "
                            class="flex h-[26px] items-center gap-1.5 rounded-[6px] border-[0.5px] border-edge-strong bg-surface-badge pr-[7px] pl-1"
                        >
                            <DashAvatar
                                v-if="item.kind === 'member'"
                                :src="item.member.avatar"
                                :name="item.member.name"
                                :size="18"
                            />
                            <span
                                v-else
                                class="flex size-[18px] items-center justify-center rounded-full bg-[rgba(245,168,51,0.18)] text-[10px]/3 font-medium text-[#f5a833]"
                                >{{ initials(item.email).slice(0, 1) }}</span
                            >
                            <span
                                class="text-[13px]/5 tracking-[-0.005em] whitespace-nowrap text-fg-primary"
                                >{{ label(item) }}</span
                            >
                            <span
                                v-if="item.kind === 'external'"
                                class="text-[11px]/[14px] text-[#f5a833]"
                                >{{ $t('External') }}</span
                            >
                            <button
                                type="button"
                                class="flex size-3.5 cursor-pointer items-center justify-center rounded-sm text-fg-muted hover:text-fg-primary focus-visible:outline-2 focus-visible:outline-(--ds-ring)"
                                :aria-label="
                                    $t('Remove :name', { name: label(item) })
                                "
                                @click.stop="drop(index)"
                            >
                                <X :size="10" :stroke-width="1.6" />
                            </button>
                        </span>
                        <input
                            ref="input"
                            v-model="query"
                            type="text"
                            autocomplete="off"
                            spellcheck="false"
                            :aria-label="$t('Name or email')"
                            :placeholder="
                                chosen.length ? '' : $t('Name or email')
                            "
                            class="h-[26px] min-w-[120px] flex-1 bg-transparent text-[13px]/5 tracking-[-0.005em] text-fg-primary outline-none placeholder:text-fg-faint"
                            @focus="focused = true"
                            @blur="focused = false"
                            @keydown.enter.prevent="onEnter"
                            @keydown.backspace="onBackspace"
                            @keydown.down.prevent="move(1)"
                            @keydown.up.prevent="move(-1)"
                        />
                    </div>

                    <div
                        v-if="showSuggestions"
                        class="flex flex-col gap-0.5 rounded-[9px] border-[0.5px] border-edge-control bg-surface-canvas p-1 shadow-card"
                    >
                        <template v-if="suggestions.length">
                            <span
                                class="pt-1 pb-0.5 pl-2 text-[12px]/4 tracking-[-0.005em] text-fg-muted"
                                >{{
                                    $t('In :workspace', {
                                        workspace: workspaceName,
                                    })
                                }}</span
                            >
                            <button
                                v-for="(member, index) in suggestions"
                                :key="member.id"
                                type="button"
                                class="flex w-full cursor-pointer items-center gap-2.5 rounded-[6px] py-1.5 pr-2.5 pl-2 text-left"
                                :class="
                                    index === highlighted
                                        ? 'bg-[#ececec] dark:bg-[#292929]'
                                        : 'hover:bg-surface-hover'
                                "
                                @mousedown.prevent
                                @mouseenter="highlighted = index"
                                @click="pick(member)"
                            >
                                <DashAvatar
                                    :src="member.avatar"
                                    :name="member.name"
                                    :size="22"
                                />
                                <span class="flex min-w-0 flex-1 flex-col">
                                    <span
                                        class="truncate text-[13px]/5 tracking-[-0.005em] text-fg-primary"
                                        >{{ member.name }}</span
                                    >
                                    <span
                                        class="truncate text-[12px]/4 tracking-[-0.005em] text-fg-muted"
                                        >{{ member.email }}</span
                                    >
                                </span>
                                <span
                                    class="text-[12px]/4 tracking-[-0.005em] text-fg-muted"
                                    >{{ member.role }}</span
                                >
                            </button>
                        </template>
                        <button
                            type="button"
                            class="flex w-full items-center gap-2.5 py-[7px] pr-2.5 pl-2 text-left"
                            :class="[
                                suggestions.length
                                    ? 'border-t-[0.5px] border-[#e2e2e2] dark:border-[#292929]'
                                    : '',
                                typedEmail
                                    ? 'cursor-pointer rounded-[6px] hover:bg-surface-hover'
                                    : 'cursor-default',
                            ]"
                            :disabled="!typedEmail"
                            @mousedown.prevent
                            @click="typedEmail && addExternal(typedEmail)"
                        >
                            <span
                                class="flex size-[22px] items-center justify-center rounded-full border-[0.5px] border-dashed border-[#c4c4c4] dark:border-[#4d4d4d]"
                            >
                                <FilledIcon
                                    name="plus-fill"
                                    :size="11"
                                    class="text-fg-muted"
                                />
                            </span>
                            <span
                                class="flex-1 text-[12px]/4 tracking-[-0.005em]"
                                :class="
                                    typedEmail
                                        ? 'text-fg-primary'
                                        : 'text-fg-muted'
                                "
                            >
                                {{
                                    typedEmail
                                        ? $t('Add :email as external', {
                                              email: typedEmail,
                                          })
                                        : $t(
                                              'Not in :workspace? Type their email to add them as external.',
                                              { workspace: workspaceName },
                                          )
                                }}
                            </span>
                        </button>
                    </div>

                    <div
                        v-if="chosen.length"
                        class="flex flex-col rounded-[10px] border-[0.5px] border-edge-card bg-surface-card"
                    >
                        <label
                            v-for="(item, index) in chosen"
                            :key="
                                item.kind === 'member'
                                    ? `k${item.member.id}`
                                    : `k${item.email}`
                            "
                            class="flex flex-col gap-1 border-edge py-[9px] pr-2.5 pl-3.5"
                            :class="index > 0 ? 'border-t-[0.5px]' : ''"
                        >
                            <span class="flex items-center gap-3">
                                <span class="flex min-w-0 flex-1 flex-col">
                                    <span
                                        class="truncate text-[13px]/5 text-fg-primary"
                                        >{{
                                            $t(':name’s key', {
                                                name: label(item),
                                            })
                                        }}</span
                                    >
                                    <span class="text-[12px]/4 text-fg-muted">{{
                                        item.kind === 'member'
                                            ? $t(
                                                  'Optional. Leave it empty and they add their own.',
                                              )
                                            : $t(
                                                  'Needed. They can’t add their own.',
                                              )
                                    }}</span>
                                </span>
                                <input
                                    v-model="item.key"
                                    type="text"
                                    spellcheck="false"
                                    autocomplete="off"
                                    :placeholder="$t('ssh-ed25519 AAAA…')"
                                    class="h-[30px] w-[190px] rounded-[7px] border-[0.5px] border-edge-card bg-surface-field px-2.5 font-ui-mono text-[12px]/4 text-fg-primary shadow-field outline-none placeholder:text-fg-faint focus:border-[rgba(51,97,255,0.7)]"
                                />
                            </span>
                            <span
                                v-if="keyError(index)"
                                class="text-[12px]/4 text-status-down"
                                >{{ keyError(index) }}</span
                            >
                        </label>
                    </div>

                    <div
                        class="flex flex-col rounded-[10px] border-[0.5px] border-edge-card bg-surface-card"
                    >
                        <div
                            class="flex items-center gap-3 py-[9px] pr-2.5 pl-3.5"
                        >
                            <div class="flex min-w-0 flex-1 flex-col">
                                <span class="text-[13px]/5 text-fg-primary">{{
                                    $t('Logs in as')
                                }}</span>
                                <span class="text-[12px]/4 text-fg-muted">{{
                                    $t(
                                        'The login ServerOS looks after on :name',
                                        {
                                            name: machineName,
                                        },
                                    )
                                }}</span>
                            </div>
                            <span
                                class="flex h-[30px] w-[190px] items-center rounded-[7px] border-[0.5px] border-edge-card bg-surface-field pr-[9px] pl-2.5 font-ui-mono text-[12px]/4 text-fg-primary shadow-field"
                                >{{ login }}</span
                            >
                        </div>
                        <div
                            class="flex items-center gap-3 border-t-[0.5px] border-edge py-[9px] pr-2.5 pl-3.5"
                        >
                            <div class="flex min-w-0 flex-1 flex-col">
                                <span class="text-[13px]/5 text-fg-primary">{{
                                    $t('Access ends')
                                }}</span>
                                <span class="text-[12px]/4 text-fg-muted">{{
                                    $t('For people outside :workspace', {
                                        workspace: workspaceName,
                                    })
                                }}</span>
                            </div>
                            <SelectMenu
                                v-model="days"
                                :options="dayOptions"
                                :disabled="!externals.length"
                                :aria-label="$t('Access ends')"
                                class="h-[30px] w-[190px] rounded-[7px] border-[0.5px] border-edge-card bg-surface-field pr-[9px] pl-2.5 text-[13px]/5 text-fg-primary shadow-field"
                            />
                        </div>
                    </div>

                    <p
                        v-if="externals.length"
                        class="flex items-start gap-2.5 rounded-[8px] border-[0.5px] border-[rgba(245,168,51,0.22)] bg-[rgba(245,168,51,0.07)] px-3 py-2.5 text-[12px]/[18px] tracking-[-0.005em] text-fg-secondary"
                    >
                        <FilledIcon
                            name="info-fill"
                            :size="14"
                            class="mt-0.5 text-[#f5a833]"
                        />
                        <span>{{
                            externals.length === 1
                                ? $t(
                                      ':email isn’t in :workspace. ServerOS adds the key you paste for them and takes it off when their access ends. They don’t get a ServerOS account.',
                                      {
                                          email: externals[0]!.email,
                                          workspace: workspaceName,
                                      },
                                  )
                                : $t(
                                      ':count people aren’t in :workspace. ServerOS adds the keys you paste for them and takes them off when their access ends. They don’t get a ServerOS account.',
                                      {
                                          count: String(externals.length),
                                          workspace: workspaceName,
                                      },
                                  )
                        }}</span>
                    </p>

                    <p
                        v-if="generalError"
                        class="text-[12px]/4 text-status-down"
                    >
                        {{ generalError }}
                    </p>
                </div>

                <div
                    class="flex items-center gap-2 border-t-[0.5px] border-edge bg-surface-card-footer py-3 pr-4 pl-5"
                >
                    <span class="flex-1 text-[13px]/5 text-fg-muted">{{
                        chosen.length === 1
                            ? $t('1 person')
                            : $t(':count people', {
                                  count: String(chosen.length),
                              })
                    }}</span>
                    <DashButton :label="$t('Cancel')" @click="open = false" />
                    <DashButton
                        type="submit"
                        variant="primary"
                        :label="$t('Give access')"
                        :loading="form.processing"
                        :disabled="!chosen.length"
                    />
                </div>
            </form>
        </DialogContent>
    </Dialog>
</template>
