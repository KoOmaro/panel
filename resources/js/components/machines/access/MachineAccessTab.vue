<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { Link, router } from '@inertiajs/vue3';
import { Ellipsis } from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import DashAvatar from '@/components/dash/DashAvatar.vue';
import DashButton from '@/components/dash/DashButton.vue';
import DashTray from '@/components/dash/DashTray.vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
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
import { timeAgo } from '@/lib/timeAgo';
import { toast } from '@/lib/toast';
import { onWorkspace } from '@/lib/workspaceRoute';
import activityRoutes from '@/routes/workspace/activity';
import accessRoutes from '@/routes/workspace/machines/access';
import {
    dayMonth,
    initials,
    rootLabel,
    shortFingerprint,
    sshCommand,
} from './access';
import type { AccessEvent, AccessPerson, MachineAccess } from './access';
import AddKeyDialog from './AddKeyDialog.vue';
import GiveAccessDialog from './GiveAccessDialog.vue';

const props = defineProps<{
    access: MachineAccess;
    workspaceSlug: string;
    workspaceName: string;
    machineId: number;
    machineName: string;
}>();

const emit = defineEmits<{ tab: [key: 'terminal'] }>();

const giving = ref(false);
const keyMode = ref<'ask' | 'key' | 'own'>('ask');
const addingKey = ref(false);
const confirmAll = ref(false);
const busy = ref(false);

const people = computed(() => props.access.people);
const withAccess = computed(
    () => people.value.filter((person) => person.state === 'active').length,
);
const waitingForMe = computed(() =>
    people.value.find(
        (person) => person.mine && person.state === 'waiting_key',
    ),
);
const askedByMe = computed(() =>
    people.value.some((person) => person.mine && person.state === 'requested'),
);

const command = computed(() => sshCommand(props.access, props.access.login));
const prompt = computed(() => `${props.access.login}@${props.access.hostname}`);

const keysOnly = computed(() => {
    const ssh = props.access.ssh;
    if (!ssh || ssh.passwords === null) return null;

    return !ssh.passwords && !ssh.keyboardInteractive;
});

const lastChange = computed(() => {
    const change = props.access.lastChange;
    if (!change) return null;

    return change.by
        ? trans('Last changed :date by :name', {
              date: dayMonth(change.at),
              name: change.by,
          })
        : trans('Last changed :date', { date: dayMonth(change.at) });
});

const activityUrl = computed(
    () =>
        onWorkspace(
            activityRoutes.index(props.workspaceSlug, {
                query: { machine: props.machineId },
            }),
        ).url,
);

function route(
    name: 'approve' | 'decline' | 'retry' | 'destroy' | 'show',
    person: AccessPerson,
) {
    return onWorkspace(
        accessRoutes[name]({
            workspace: props.workspaceSlug,
            machine: props.machineId,
            access: person.id,
        }),
    );
}

function reload(): void {
    router.reload({ only: ['access'] });
}

function send(method: 'post' | 'delete', url: string, done?: () => void): void {
    if (busy.value) return;
    busy.value = true;
    router.visit(url, {
        method,
        preserveScroll: true,
        only: ['access'],
        onError: (errors) =>
            toast.error(
                Object.values(errors)[0] ??
                    trans('That didn’t work. Try again.'),
            ),
        onSuccess: () => done?.(),
        onFinish: () => (busy.value = false),
    });
}

function openKey(mode: 'ask' | 'key' | 'own'): void {
    keyMode.value = mode;
    addingKey.value = true;
}

function removeEveryone(): void {
    send(
        'delete',
        onWorkspace(
            accessRoutes.destroyAll({
                workspace: props.workspaceSlug,
                machine: props.machineId,
            }),
        ).url,
        () => (confirmAll.value = false),
    );
}

async function copy(text: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(text);
        toast.success(trans('Copied.'));
    } catch {
        toast.error(trans('Couldn’t copy. Select it and copy it yourself.'));
    }
}

function canChange(person: AccessPerson): boolean {
    return props.access.canApprove || person.mine;
}

function hasMenu(person: AccessPerson): boolean {
    return (
        !!person.fingerprint ||
        (canChange(person) && !['adding', 'removing'].includes(person.state))
    );
}

type Status = { text: string; tone: string; title?: string };

function status(person: AccessPerson): Status {
    if (person.error && person.state === 'active') {
        return {
            text: trans('Couldn’t remove'),
            tone: 'text-status-down',
            title: person.error,
        };
    }
    switch (person.state) {
        case 'requested':
            return {
                text: trans('Needs approval'),
                tone: 'text-status-pending',
                title: trans('Asked :time', {
                    time: timeAgo(person.requestedAt),
                }),
            };
        case 'waiting_key':
            return {
                text: trans('Waiting for key'),
                tone: 'text-status-pending',
            };
        case 'adding':
            return { text: trans('Adding…'), tone: 'text-status-deploying' };
        case 'removing':
            return { text: trans('Removing…'), tone: 'text-fg-muted' };
        case 'failed':
            return {
                text: trans('Couldn’t add'),
                tone: 'text-status-down',
                title: person.error ?? undefined,
            };
        default:
            return {
                text: capitalise(timeAgo(person.addedAt)),
                tone: 'text-fg-secondary',
            };
    }
}

function capitalise(text: string): string {
    return text.charAt(0).toUpperCase() + text.slice(1);
}

function when(iso: string): string {
    const date = new Date(iso);
    const today = new Date();
    if (date.toDateString() === today.toDateString()) {
        return date.toLocaleTimeString(undefined, {
            hour: '2-digit',
            minute: '2-digit',
        });
    }

    return capitalise(timeAgo(iso));
}

function eventTitle(event: AccessEvent): string {
    if (event.kind === 'revoke_all') return trans('Everyone');
    const name = event.person.name ?? trans('Someone');

    return event.login
        ? trans(':name as :login', { name, login: event.login })
        : name;
}

function eventDetail(event: AccessEvent): string {
    const by = event.by ?? 'ServerOS';
    const key = event.fingerprint
        ? ` · ${shortFingerprint(event.fingerprint)}`
        : '';
    switch (event.kind) {
        case 'give':
            return trans('Given by :name', { name: by }) + key;
        case 'request':
            return trans('Asked for access') + key;
        case 'key':
            return trans('Added their key') + key;
        case 'withdraw':
            return trans('Withdrew their request');
        case 'approve':
            return trans('Approved by :name', { name: by }) + key;
        case 'decline':
            return trans('Turned down by :name', { name: by });
        case 'retry':
            return trans('Tried again by :name', { name: by });
        case 'revoke':
            return trans('Removed by :name', { name: by });
        case 'revoke_all':
            return (
                trans('Removed by :name', { name: by }) +
                (event.count
                    ? ` · ${event.count === 1 ? trans('1 person') : trans(':count people', { count: String(event.count) })}`
                    : '')
            );
        case 'failed':
            return event.error ?? trans('The machine couldn’t add the key');
        case 'end':
            return event.reason === 'left'
                ? trans('Left the workspace')
                : trans('Their access expired');
        default:
            return '';
    }
}

function eventStatus(event: AccessEvent): { text: string; tone: string } {
    switch (event.kind) {
        case 'give':
            return { text: trans('Given'), tone: 'text-status-live' };
        case 'approve':
            return { text: trans('Approved'), tone: 'text-status-live' };
        case 'key':
            return { text: trans('Key added'), tone: 'text-status-live' };
        case 'request':
            return { text: trans('Asked'), tone: 'text-status-pending' };
        case 'failed':
            return { text: trans('Failed'), tone: 'text-status-down' };
        case 'decline':
            return { text: trans('Turned down'), tone: 'text-fg-muted' };
        case 'withdraw':
            return { text: trans('Withdrawn'), tone: 'text-fg-muted' };
        case 'retry':
            return { text: trans('Tried again'), tone: 'text-fg-muted' };
        case 'end':
            return { text: trans('Ended'), tone: 'text-fg-muted' };
        default:
            return { text: trans('Removed'), tone: 'text-fg-muted' };
    }
}

const moving = computed(() =>
    people.value.some((person) =>
        ['adding', 'removing'].includes(person.state),
    ),
);
let poll: ReturnType<typeof setInterval> | undefined;
watch(
    moving,
    (active) => {
        clearInterval(poll);
        poll = active ? setInterval(reload, 3000) : undefined;
    },
    { immediate: true },
);
onBeforeUnmount(() => clearInterval(poll));
</script>

<template>
    <div class="flex min-h-full flex-col xl:flex-row">
        <div class="flex min-w-0 flex-1 flex-col gap-3 px-6 py-4">
            <DashTray
                icon="lock-fill"
                :title="$t('SSH Access')"
                panel-class="px-[18px] pt-3 pb-3.5"
            >
                <template #meta>
                    <span
                        v-if="keysOnly !== null"
                        class="inline-flex h-5 items-center gap-[5px] rounded-[10px] pr-2 pl-[7px] align-middle text-[12px]/4 font-medium tracking-[-0.005em]"
                        :class="
                            keysOnly
                                ? 'bg-[rgba(62,196,109,0.14)] text-status-live'
                                : 'bg-[rgba(240,148,60,0.14)] text-status-pending'
                        "
                        :title="
                            keysOnly
                                ? $t(':name only takes SSH keys', {
                                      name: machineName,
                                  })
                                : $t(':name also takes passwords over SSH', {
                                      name: machineName,
                                  })
                        "
                    >
                        <span class="size-1.5 rounded-full bg-current" />
                        {{ keysOnly ? $t('Keys only') : $t('Passwords on') }}
                    </span>
                </template>
                <template #actions>
                    <span
                        v-if="lastChange"
                        class="hidden truncate text-[12px]/4 tracking-[-0.005em] text-fg-muted sm:block"
                        >{{ lastChange }}</span
                    >
                </template>

                <div class="flex flex-col gap-6 lg:flex-row lg:items-start">
                    <ol class="flex min-w-0 flex-1 flex-col gap-3.5 pt-1">
                        <li
                            v-for="(step, index) in [
                                {
                                    title: $t('They ask with their public key'),
                                    detail: $t(
                                        'The line in ~/.ssh/id_ed25519.pub on their computer.',
                                    ),
                                },
                                {
                                    title: $t('An owner or admin approves it'),
                                    detail: $t(
                                        'Here, or from the email ServerOS sends them.',
                                    ),
                                },
                                {
                                    title: $t(
                                        'ServerOS adds the key to :name',
                                        {
                                            name: machineName,
                                        },
                                    ),
                                    detail: $t(
                                        'As :login, until someone removes it or it ends.',
                                        { login: access.login },
                                    ),
                                },
                            ]"
                            :key="index"
                            class="flex items-start gap-3"
                        >
                            <span
                                class="flex size-5 shrink-0 items-center justify-center rounded-full border border-[#d6d6d6] text-[11px]/[14px] text-fg-muted dark:border-[#424242]"
                                >{{ index + 1 }}</span
                            >
                            <span class="flex min-w-0 flex-col">
                                <span
                                    class="text-[13px]/5 tracking-[-0.005em] text-fg-primary"
                                    >{{ step.title }}</span
                                >
                                <span
                                    class="text-[12px]/4 tracking-[-0.005em] text-fg-muted"
                                    >{{ step.detail }}</span
                                >
                            </span>
                        </li>
                    </ol>
                    <div
                        class="flex w-full shrink-0 flex-col gap-0.5 rounded-[8px] border-[0.5px] border-edge-card bg-[#f3f3f3] px-3.5 py-3 font-ui-mono text-[12px]/5 shadow-field lg:w-[380px] dark:bg-[#111]"
                        :aria-label="$t('How they sign in')"
                    >
                        <p class="truncate">
                            <span class="text-fg-faint">$ </span>
                            <span class="text-fg-primary"
                                >cat ~/.ssh/id_ed25519.pub</span
                            >
                        </p>
                        <p class="truncate text-fg-muted">
                            <span class="text-fg-faint"># </span
                            >{{ $t('paste that line into ServerOS') }}
                        </p>
                        <p class="truncate text-fg-muted">
                            <span class="text-fg-faint"># </span
                            >{{ $t('once it’s approved:') }}
                        </p>
                        <p class="truncate">
                            <span class="text-fg-faint">$ </span>
                            <span class="text-fg-primary">{{ command }}</span>
                        </p>
                        <p class="truncate">
                            <span class="text-status-live">{{ prompt }}</span>
                            <span class="text-fg-muted">:~$ </span>
                            <span class="text-fg-secondary">▍</span>
                        </p>
                    </div>
                </div>
            </DashTray>

            <DashTray
                icon="user-fill"
                :title="$t('People')"
                :meta="
                    withAccess === 1
                        ? $t('1 with access')
                        : $t(':count with access', {
                              count: String(withAccess),
                          })
                "
                panel-class="px-[18px] pt-3 pb-3.5"
            >
                <template #actions>
                    <DashButton
                        v-if="access.canApprove"
                        size="sm"
                        :label="$t('Add people')"
                        @click="giving = true"
                    />
                    <DashButton
                        v-else-if="waitingForMe"
                        size="sm"
                        :label="$t('Add your key')"
                        @click="openKey('key')"
                    />
                    <DashButton
                        v-else-if="!askedByMe"
                        size="sm"
                        :label="$t('Ask for access')"
                        @click="openKey('ask')"
                    />
                </template>

                <div class="overflow-x-auto">
                    <div class="flex min-w-[660px] flex-col">
                        <div
                            class="flex items-center gap-3 border-b-[0.5px] border-edge-tabs pb-2 text-[12px]/4 tracking-[-0.005em] text-fg-muted"
                        >
                            <span class="min-w-0 flex-1">{{
                                $t('Person')
                            }}</span>
                            <span class="w-[112px] shrink-0">{{
                                $t('Type')
                            }}</span>
                            <span class="w-[128px] shrink-0">{{
                                $t('Logs in as')
                            }}</span>
                            <span class="w-[150px] shrink-0">{{
                                $t('Key')
                            }}</span>
                            <span class="w-[120px] shrink-0">{{
                                $t('Added')
                            }}</span>
                            <span class="w-7 shrink-0" />
                        </div>

                        <div
                            v-for="person in people"
                            :key="person.id"
                            class="flex h-[52px] items-center gap-3 border-b-[0.5px] border-edge-tabs last:border-b-0"
                        >
                            <span
                                class="flex min-w-0 flex-1 items-center gap-2.5"
                            >
                                <span
                                    v-if="person.external"
                                    class="flex size-[26px] shrink-0 items-center justify-center rounded-full border-[0.5px] border-dashed border-[#c4c4c4] bg-surface-badge text-[11px]/[14px] font-medium text-fg-secondary dark:border-[#424242] dark:bg-[#292929]"
                                    >{{ initials(person.name) }}</span
                                >
                                <DashAvatar
                                    v-else
                                    :src="person.avatar"
                                    :name="person.name"
                                    :size="26"
                                />
                                <span class="flex min-w-0 flex-col">
                                    <span
                                        class="truncate text-[13px]/5 tracking-[-0.005em] text-fg-primary"
                                        >{{ person.name }}</span
                                    >
                                    <span
                                        class="truncate text-[12px]/4 tracking-[-0.005em] text-fg-muted"
                                        >{{ person.email }}</span
                                    >
                                </span>
                            </span>
                            <span
                                class="flex w-[112px] shrink-0 flex-col items-start"
                            >
                                <template v-if="person.external">
                                    <span
                                        class="flex h-5 items-center rounded-[10px] bg-[rgba(245,168,51,0.14)] px-[7px] text-[12px]/4 font-medium tracking-[-0.005em] text-[#c27c12] dark:text-[#f5a833]"
                                        >{{ $t('External') }}</span
                                    >
                                    <span
                                        v-if="person.expiresAt"
                                        class="text-[12px]/4 tracking-[-0.005em] text-fg-muted"
                                        >{{
                                            $t('Until :date', {
                                                date: dayMonth(
                                                    person.expiresAt,
                                                ),
                                            })
                                        }}</span
                                    >
                                </template>
                                <span
                                    v-else
                                    class="text-[13px]/5 tracking-[-0.005em] text-fg-secondary"
                                    >{{ person.role ?? $t('Left') }}</span
                                >
                            </span>
                            <span
                                class="flex h-7 w-[128px] shrink-0 items-center rounded-[6px] border-[0.5px] border-edge-card bg-surface-field pr-2 pl-[9px] font-ui-mono text-[12px]/4 text-fg-primary shadow-field"
                                >{{ person.login }}</span
                            >
                            <span class="flex w-[150px] shrink-0 flex-col">
                                <template v-if="person.fingerprint">
                                    <span
                                        class="truncate font-ui-mono text-[11.5px]/5 text-fg-secondary"
                                        :title="person.fingerprint"
                                        >{{
                                            shortFingerprint(person.fingerprint)
                                        }}</span
                                    >
                                    <span
                                        class="truncate text-[12px]/4 tracking-[-0.005em] text-fg-muted"
                                        >{{
                                            [person.keyType, person.comment]
                                                .filter(Boolean)
                                                .join(' · ')
                                        }}</span
                                    >
                                </template>
                                <span
                                    v-else
                                    class="text-[13px]/5 text-fg-faint"
                                    >{{ $t('No key yet') }}</span
                                >
                            </span>
                            <span class="w-[120px] shrink-0">
                                <DashButton
                                    v-if="
                                        person.mine &&
                                        person.state === 'waiting_key'
                                    "
                                    size="sm"
                                    :label="$t('Add your key')"
                                    @click="openKey('key')"
                                />
                                <span
                                    v-else
                                    class="block truncate text-[13px]/5 tracking-[-0.005em]"
                                    :class="status(person).tone"
                                    :title="status(person).title"
                                    >{{ status(person).text }}</span
                                >
                            </span>
                            <span class="flex w-7 shrink-0 justify-end">
                                <DropdownMenu v-if="hasMenu(person)">
                                    <DropdownMenuTrigger as-child>
                                        <button
                                            type="button"
                                            class="flex size-7 cursor-pointer items-center justify-center rounded-[6px] text-fg-tertiary hover:bg-surface-hover hover:text-fg-primary focus-visible:outline-2 focus-visible:outline-(--ds-ring) data-[state=open]:bg-surface-hover"
                                            :aria-label="
                                                $t('More for :name', {
                                                    name: person.name,
                                                })
                                            "
                                        >
                                            <Ellipsis
                                                :size="16"
                                                :stroke-width="1.6"
                                            />
                                        </button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent
                                        class="ds-modal min-w-52 font-ui"
                                        align="end"
                                    >
                                        <template
                                            v-if="
                                                access.canApprove &&
                                                person.state === 'requested'
                                            "
                                        >
                                            <DropdownMenuItem as-child>
                                                <Link
                                                    :href="
                                                        route('show', person)
                                                            .url
                                                    "
                                                >
                                                    <FilledIcon
                                                        name="lock-fill"
                                                    />
                                                    {{ $t('Review request') }}
                                                </Link>
                                            </DropdownMenuItem>
                                            <DropdownMenuItem
                                                @select="
                                                    send(
                                                        'post',
                                                        route('approve', person)
                                                            .url,
                                                    )
                                                "
                                            >
                                                <FilledIcon name="check" />
                                                {{ $t('Approve') }}
                                            </DropdownMenuItem>
                                            <DropdownMenuItem
                                                @select="
                                                    send(
                                                        'post',
                                                        route('decline', person)
                                                            .url,
                                                    )
                                                "
                                            >
                                                <FilledIcon name="history" />
                                                {{ $t('Turn down') }}
                                            </DropdownMenuItem>
                                        </template>
                                        <DropdownMenuItem
                                            v-if="
                                                person.mine &&
                                                person.state === 'waiting_key'
                                            "
                                            @select="openKey('key')"
                                        >
                                            <FilledIcon name="key-fill" />
                                            {{ $t('Add your key') }}
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                            v-if="
                                                canChange(person) &&
                                                person.state === 'failed'
                                            "
                                            @select="
                                                send(
                                                    'post',
                                                    route('retry', person).url,
                                                )
                                            "
                                        >
                                            <FilledIcon name="history" />
                                            {{ $t('Try again') }}
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                            v-if="person.fingerprint"
                                            @select="copy(person.fingerprint)"
                                        >
                                            <FilledIcon name="copy" />
                                            {{ $t('Copy fingerprint') }}
                                        </DropdownMenuItem>
                                        <template
                                            v-if="
                                                canChange(person) &&
                                                ![
                                                    'adding',
                                                    'removing',
                                                ].includes(person.state)
                                            "
                                        >
                                            <DropdownMenuSeparator />
                                            <DropdownMenuItem
                                                class="text-(--ds-red) focus:bg-(--ds-red)/10 focus:text-(--ds-red)"
                                                @select="
                                                    send(
                                                        'delete',
                                                        route('destroy', person)
                                                            .url,
                                                    )
                                                "
                                            >
                                                <FilledIcon name="lock-fill" />
                                                {{
                                                    person.mine &&
                                                    person.state === 'requested'
                                                        ? $t('Withdraw request')
                                                        : $t('Remove access')
                                                }}
                                            </DropdownMenuItem>
                                        </template>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </span>
                        </div>

                        <div
                            v-if="!people.length"
                            class="flex flex-col gap-0.5 py-6 text-center"
                        >
                            <span class="text-[13px]/5 text-fg-secondary">{{
                                $t(
                                    'Nobody has SSH access through ServerOS yet.',
                                )
                            }}</span>
                            <span class="text-[12px]/4 text-fg-muted">{{
                                access.canApprove
                                    ? $t(
                                          'Add people, or approve someone who asks. Keys already on :name aren’t listed.',
                                          { name: machineName },
                                      )
                                    : $t(
                                          'Ask for access with your public key. Keys already on :name aren’t listed.',
                                          { name: machineName },
                                      )
                            }}</span>
                        </div>
                    </div>
                </div>
            </DashTray>

            <DashTray
                icon="activity-fill"
                :title="$t('Recent changes')"
                :meta="$t('Last 7 days')"
                panel-class="px-[18px] pt-3 pb-3.5"
            >
                <template #actions>
                    <Link
                        :href="activityUrl"
                        class="flex items-center gap-[9px] rounded-[5px] text-[13px]/5 tracking-[-0.005em] text-fg-secondary hover:text-fg-primary focus-visible:outline-2 focus-visible:outline-(--ds-ring)"
                    >
                        {{ $t('All in Activity') }}
                        <FilledIcon
                            name="chevron-right"
                            :size="14"
                            class="text-fg-muted"
                        />
                    </Link>
                </template>

                <ul class="flex flex-col">
                    <li
                        v-for="event in access.history"
                        :key="event.id"
                        class="flex items-center gap-3 border-b-[0.5px] border-edge-tabs py-[9px] last:border-b-0"
                    >
                        <span
                            v-if="
                                event.kind === 'revoke_all' ||
                                event.person.external
                            "
                            class="flex size-[26px] shrink-0 items-center justify-center rounded-full border-[0.5px] border-dashed border-[#c4c4c4] bg-surface-badge text-[11px]/[14px] font-medium text-fg-secondary dark:border-[#424242] dark:bg-[#292929]"
                        >
                            <FilledIcon
                                v-if="event.kind === 'revoke_all'"
                                name="key-fill"
                                :size="13"
                                class="text-fg-muted"
                            />
                            <template v-else>{{
                                initials(event.person.name)
                            }}</template>
                        </span>
                        <DashAvatar
                            v-else
                            :src="event.person.avatar"
                            :name="event.person.name"
                            :size="26"
                        />
                        <span class="flex min-w-0 flex-1 flex-col">
                            <span
                                class="truncate text-[13px]/5 tracking-[-0.005em] text-fg-primary"
                                >{{ eventTitle(event) }}</span
                            >
                            <span
                                class="truncate text-[12px]/4 tracking-[-0.005em] text-fg-muted"
                                :title="eventDetail(event)"
                                >{{ eventDetail(event) }}</span
                            >
                        </span>
                        <span
                            class="flex shrink-0 flex-col items-end text-right whitespace-nowrap"
                        >
                            <span
                                class="text-[13px]/5 tracking-[-0.005em] text-fg-secondary"
                                >{{ when(event.at) }}</span
                            >
                            <span
                                class="text-[12px]/4 tracking-[-0.005em]"
                                :class="eventStatus(event).tone"
                                >{{ eventStatus(event).text }}</span
                            >
                        </span>
                    </li>
                    <li
                        v-if="!access.history.length"
                        class="py-4 text-center text-[13px]/5 text-fg-muted"
                    >
                        {{ $t('No changes to SSH access in the last 7 days.') }}
                    </li>
                </ul>
            </DashTray>
        </div>

        <aside
            class="flex w-full shrink-0 flex-col gap-[22px] border-edge-tabs p-5 max-xl:border-t-[0.5px] xl:w-[316px] xl:border-l-[0.5px]"
        >
            <section class="flex flex-col gap-2.5">
                <h2
                    class="text-[13px]/5 font-medium tracking-[-0.005em] text-fg-primary"
                >
                    {{ $t('SSH server') }}
                </h2>
                <template v-if="access.ssh">
                    <div
                        v-for="row in [
                            {
                                key: 'passwords',
                                label: $t('Passwords'),
                                detail: $t('Signing in with a password'),
                                value:
                                    access.ssh.passwords === null
                                        ? '—'
                                        : access.ssh.passwords ||
                                            access.ssh.keyboardInteractive
                                          ? $t('On')
                                          : $t('Off'),
                                warn:
                                    !!access.ssh.passwords ||
                                    access.ssh.keyboardInteractive,
                            },
                            {
                                key: 'root',
                                label: $t('Root'),
                                detail: $t('Signing in as root'),
                                value: rootLabel(access.ssh.rootLogin),
                                warn: access.ssh.rootLogin === 'yes',
                            },
                            {
                                key: 'port',
                                label: $t('Port'),
                                detail: $t('Where sshd listens'),
                                value: access.ssh.ports.join(', ') || '—',
                                warn: false,
                            },
                        ]"
                        :key="row.key"
                        class="flex items-center gap-2.5"
                    >
                        <span class="flex min-w-0 flex-1 flex-col">
                            <span
                                class="text-[13px]/5 tracking-[-0.005em] text-fg-secondary"
                                >{{ row.label }}</span
                            >
                            <span
                                class="text-[12px]/4 tracking-[-0.005em] text-fg-muted"
                                >{{ row.detail }}</span
                            >
                        </span>
                        <span
                            class="text-[13px]/5 tracking-[-0.005em]"
                            :class="
                                row.warn
                                    ? 'text-status-pending'
                                    : 'text-fg-primary'
                            "
                            >{{ row.value }}</span
                        >
                    </div>
                </template>
                <p
                    v-else
                    class="text-[12px]/4 tracking-[-0.005em] text-fg-muted"
                >
                    {{
                        $t(
                            ':name hasn’t sent its SSH settings. They come with the security watch in its policy.',
                            { name: machineName },
                        )
                    }}
                </p>
            </section>

            <section class="flex flex-col gap-2.5">
                <h2
                    class="text-[13px]/5 font-medium tracking-[-0.005em] text-fg-primary"
                >
                    {{ $t('Failed sign-ins') }}
                </h2>
                <template v-if="access.signIns">
                    <div
                        class="grid grid-cols-2 overflow-hidden rounded-[7px] border-[0.5px] border-edge-card bg-surface-field shadow-field"
                    >
                        <span
                            v-for="(cell, index) in [
                                {
                                    label: $t('Failed'),
                                    value: access.signIns.failed,
                                },
                                {
                                    label: $t('Addresses'),
                                    value: access.signIns.sources,
                                },
                                {
                                    label: $t('Tried root'),
                                    value: access.signIns.root,
                                },
                                {
                                    label: $t('With a password'),
                                    value: access.signIns.password,
                                },
                            ]"
                            :key="index"
                            class="flex flex-col px-2.5 py-2"
                            :class="[
                                index % 2 === 1
                                    ? 'border-l-[0.5px] border-edge-card'
                                    : '',
                                index > 1
                                    ? 'border-t-[0.5px] border-edge-card'
                                    : '',
                            ]"
                        >
                            <span
                                class="text-[12px]/4 tracking-[-0.005em] text-fg-muted"
                                >{{ cell.label }}</span
                            >
                            <span
                                class="text-[15px]/5 font-medium tracking-[-0.01em] text-fg-primary tabular-nums"
                                >{{ cell.value.toLocaleString() }}</span
                            >
                        </span>
                    </div>
                    <p class="text-[12px]/4 tracking-[-0.005em] text-fg-muted">
                        {{
                            access.signIns.hours === 1
                                ? $t(
                                      'In the last hour, counted on :name. The addresses never leave it.',
                                      { name: machineName },
                                  )
                                : $t(
                                      'In the last :hours hours, counted on :name. The addresses never leave it.',
                                      {
                                          hours: String(access.signIns.hours),
                                          name: machineName,
                                      },
                                  )
                        }}
                    </p>
                </template>
                <p
                    v-else
                    class="text-[12px]/4 tracking-[-0.005em] text-fg-muted"
                >
                    {{
                        $t(
                            ':name hasn’t sent sign-in counts. They come with the security watch when its logs can be read.',
                            { name: machineName },
                        )
                    }}
                </p>
            </section>

            <section class="flex flex-col gap-2.5">
                <div class="flex items-center gap-2">
                    <h2
                        class="min-w-0 flex-1 text-[13px]/5 font-medium tracking-[-0.005em] text-fg-primary"
                    >
                        {{ $t('Keys added by hand') }}
                    </h2>
                    <button
                        type="button"
                        class="cursor-pointer rounded-[4px] text-[13px]/5 tracking-[-0.005em] text-fg-secondary underline underline-offset-2 hover:text-fg-primary focus-visible:outline-2 focus-visible:outline-(--ds-ring)"
                        @click="emit('tab', 'terminal')"
                    >
                        {{ $t('Terminal') }}
                    </button>
                </div>
                <div class="flex items-center gap-2.5">
                    <span
                        class="flex size-7 shrink-0 items-center justify-center rounded-[7px] border-[0.5px] border-[#d9d9d9] bg-surface-badge dark:border-[#383838]"
                    >
                        <FilledIcon
                            name="key-fill"
                            :size="14"
                            class="text-fg-muted"
                        />
                    </span>
                    <span class="flex min-w-0 flex-col">
                        <span
                            class="text-[13px]/5 tracking-[-0.005em] text-fg-primary"
                            >{{ $t('Not listed here') }}</span
                        >
                        <span
                            class="text-[12px]/4 tracking-[-0.005em] text-fg-muted"
                            >{{ $t('ServerOS can’t see them') }}</span
                        >
                    </span>
                </div>
                <p class="text-[12px]/4 tracking-[-0.005em] text-fg-muted">
                    {{
                        $t(
                            'People shows only keys ServerOS added. Keys that were already in authorized_keys on :name, or added there by hand, still work.',
                            { name: machineName },
                        )
                    }}
                </p>
            </section>

            <section
                v-if="access.canApprove && people.length"
                class="flex flex-col items-start gap-1"
            >
                <button
                    type="button"
                    class="cursor-pointer rounded-[4px] text-[13px]/5 tracking-[-0.005em] text-status-down hover:underline focus-visible:outline-2 focus-visible:outline-(--ds-ring)"
                    @click="confirmAll = true"
                >
                    {{ $t('Remove everyone') }}
                </button>
                <p class="text-[12px]/4 tracking-[-0.005em] text-fg-muted">
                    {{
                        $t('Takes every key ServerOS added off :name.', {
                            name: machineName,
                        })
                    }}
                </p>
            </section>
        </aside>

        <GiveAccessDialog
            v-if="access.canApprove"
            v-model:open="giving"
            :workspace-slug="workspaceSlug"
            :workspace-name="workspaceName"
            :machine-id="machineId"
            :machine-name="machineName"
            :login="access.login"
            :members="access.members"
            :expiry-days="access.expiryDays"
        />
        <AddKeyDialog
            v-model:open="addingKey"
            :workspace-slug="workspaceSlug"
            :machine-id="machineId"
            :machine-name="machineName"
            :login="access.login"
            :mode="keyMode"
        />
        <Dialog v-model:open="confirmAll">
            <DialogContent
                class="workspace-panel ds-modal gap-0 !rounded-[12px] p-0 font-ui text-fg-primary sm:max-w-md"
                :show-close-button="false"
            >
                <div class="flex flex-col gap-1 px-5 pt-[18px] pb-4">
                    <DialogTitle
                        class="text-[16px]/6 font-medium tracking-[-0.01em] text-fg-primary"
                        >{{
                            $t('Remove everyone from :name?', {
                                name: machineName,
                            })
                        }}</DialogTitle
                    >
                    <DialogDescription class="text-[13px]/5 text-fg-tertiary">
                        {{
                            $t(
                                'ServerOS takes off every key it added and drops requests that are waiting. Keys added by hand stay.',
                            )
                        }}
                    </DialogDescription>
                </div>
                <div
                    class="flex items-center justify-end gap-2 border-t-[0.5px] border-edge bg-surface-card-footer py-3 pr-4 pl-5"
                >
                    <DashButton
                        :label="$t('Cancel')"
                        @click="confirmAll = false"
                    />
                    <DashButton
                        variant="danger"
                        :label="$t('Remove everyone')"
                        :loading="busy"
                        @click="removeEveryone"
                    />
                </div>
            </DialogContent>
        </Dialog>
    </div>
</template>
