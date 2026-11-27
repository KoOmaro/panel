<script setup lang="ts">
import { router, useForm, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, ref, watch } from 'vue';
import DashButton from '@/components/dash/DashButton.vue';
import DashSwitch from '@/components/dash/DashSwitch.vue';
import DashTray from '@/components/dash/DashTray.vue';
import StatusBadge from '@/components/dash/StatusBadge.vue';
import type { DashStatus } from '@/components/dash/StatusBadge.vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import type {
    ChatPostRow,
    SlackAccount,
    TeamsAccount,
} from '@/components/integrations/chat';
import { SelectMenu } from '@/components/ui/select';
import { timeAgo } from '@/lib/timeAgo';
import { onWorkspace } from '@/lib/workspaceRoute';
import channelRoutes from '@/routes/workspace/integrations/channels';
import type { Workspace } from '@/types';

const {
    account,
    integrationId,
    disabled = false,
} = defineProps<{
    account: SlackAccount | TeamsAccount | undefined;
    integrationId: number;
    disabled?: boolean;
}>();

const page = usePage<{ workspace: Workspace }>();
const args = computed(() => ({
    workspace: page.props.workspace.slug,
    integration: integrationId,
}));
const slack = computed(() =>
    account?.kind === 'slack' ? (account as SlackAccount) : null,
);
const teams = computed(() =>
    account?.kind === 'teams' ? (account as TeamsAccount) : null,
);

const slackForm = useForm({ alerts: '', deploys: '' });
const teamsForm = useForm({ alerts: true, deploys: true });

watch(
    () => account,
    () => {
        if (slack.value) {
            slackForm.defaults({
                alerts: slack.value.alerts?.id ?? '',
                deploys: slack.value.deploys?.id ?? '',
            });
            slackForm.reset();
        }
        if (teams.value) {
            teamsForm.defaults({
                alerts: teams.value.alerts,
                deploys: teams.value.deploys,
            });
            teamsForm.reset();
        }
    },
    { immediate: true },
);

const form = computed(() => (slack.value ? slackForm : teamsForm));

const channelOptions = computed(() => [
    { value: '', label: trans('No channel') },
    ...(slack.value?.channels ?? []).map((channel) => ({
        value: channel.id,
        label: `${channel.private ? '🔒 ' : '#'}${channel.name}`,
    })),
]);

function outside(id: string): string | null {
    const channel = slack.value?.channels.find((c) => c.id === id);

    return channel && !channel.member ? channel.name : null;
}

function save(): void {
    const url = onWorkspace(channelRoutes.update(args.value)).url;
    if (slack.value) {
        slackForm
            .transform((data) => ({
                alerts: data.alerts || null,
                deploys: data.deploys || null,
            }))
            .put(url, { preserveScroll: true });
    } else {
        teamsForm.put(url, { preserveScroll: true });
    }
}

const testing = ref(false);

function sendTest(): void {
    router.post(
        onWorkspace(channelRoutes.test(args.value)).url,
        {},
        {
            preserveScroll: true,
            onStart: () => (testing.value = true),
            onFinish: () => (testing.value = false),
        },
    );
}

const refreshing = ref(false);

function refresh(): void {
    router.reload({
        only: ['account'],
        onStart: () => (refreshing.value = true),
        onFinish: () => (refreshing.value = false),
    });
}

const errors = computed(() => form.value.errors as Record<string, string>);
const firstError = computed(() => Object.values(errors.value)[0] ?? null);

const tone: Record<ChatPostRow['state'], DashStatus> = {
    open: 'down',
    resolved: 'live',
    sent: 'live',
    failed: 'down',
};

function stateLabel(post: ChatPostRow): string {
    switch (post.state) {
        case 'open':
            return trans('Open');
        case 'resolved':
            return trans('Fixed');
        case 'failed':
            return trans('Failed');
        default:
            return trans('Posted');
    }
}

const commands = computed(() =>
    slack.value
        ? [
              {
                  text: `${slack.value.command} status`,
                  help: trans('What’s down: machines, services and monitors'),
              },
              {
                  text: `${slack.value.command} machine web-1`,
                  help: trans('How one machine is doing'),
              },
              {
                  text: `${slack.value.command} deploy shop`,
                  help: trans(
                      'Deploys an app’s latest code once you confirm. Add “on web-1” when it runs on several machines.',
                  ),
              },
          ]
        : [],
);

const row =
    'flex items-start justify-between gap-4 border-t-[0.5px] border-edge-tray-panel px-3.5 py-3 first:border-t-0';
const rowTitle = 'text-[13px]/5 tracking-[-0.005em] text-fg-primary';
const rowHelp = 'text-[12px]/4 tracking-[-0.005em] text-fg-muted';
</script>

<template>
    <DashTray
        v-if="account === undefined"
        icon="sliders"
        :title="$t('Where it posts')"
        panel-class="py-0.5"
    >
        <div class="flex flex-col" role="status" :aria-label="$t('Loading')">
            <div
                v-for="n in 2"
                :key="n"
                class="flex items-center gap-3 border-t-[0.5px] border-edge-tray-panel px-3.5 py-3 first:border-t-0"
            >
                <span
                    class="h-3 w-32 animate-pulse rounded bg-surface-badge motion-reduce:animate-none"
                />
                <span class="grow" />
                <span
                    class="h-6 w-44 animate-pulse rounded bg-surface-badge motion-reduce:animate-none"
                />
            </div>
        </div>
    </DashTray>

    <template v-else>
        <DashTray
            icon="sliders"
            :title="$t('Where it posts')"
            :meta="slack ? slack.team : undefined"
            panel-class="py-0.5"
        >
            <template #actions>
                <DashButton
                    v-if="slack"
                    size="sm"
                    icon="history"
                    :aria-label="$t('Look for channels again')"
                    :loading="refreshing"
                    @click="refresh"
                />
                <DashButton
                    size="sm"
                    :label="$t('Send a test')"
                    :loading="testing"
                    :disabled="disabled"
                    @click="sendTest"
                />
            </template>

            <form class="flex flex-col" @submit.prevent="save">
                <template v-if="slack">
                    <p
                        v-if="slack.channelsError"
                        class="px-3.5 py-2.5 text-[12.5px]/[18px] text-status-down"
                        role="alert"
                    >
                        {{ slack.channelsError }}
                    </p>
                    <div
                        v-for="purpose in ['alerts', 'deploys'] as const"
                        :key="purpose"
                        :class="row"
                    >
                        <span class="flex min-w-0 flex-col gap-0.5">
                            <span :class="rowTitle">{{
                                purpose === 'alerts'
                                    ? $t('Alerts')
                                    : $t('Deploys')
                            }}</span>
                            <span :class="rowHelp">{{
                                purpose === 'alerts'
                                    ? $t(
                                          'Each alert, with Check now, Restart and Mute 1 hour where they fit. A monitor or machine alert changes to fixed when it’s back.',
                                      )
                                    : $t(
                                          'Each finished deploy, with a button to roll back to the version before. Failed deploys go here instead of to alerts.',
                                      )
                            }}</span>
                            <span
                                v-if="outside(slackForm[purpose])"
                                class="text-[12px]/4 text-status-pending"
                                >{{
                                    $t(
                                        'The app isn’t in #:channel yet. Type /invite @ServerOS there, or posts won’t arrive.',
                                        {
                                            channel:
                                                outside(slackForm[purpose]) ??
                                                '',
                                        },
                                    )
                                }}</span
                            >
                        </span>
                        <SelectMenu
                            v-model="slackForm[purpose]"
                            class="ds-raised-button h-8 w-52 shrink-0 text-[13px]"
                            :options="channelOptions"
                            :placeholder="$t('No channel')"
                            :disabled="disabled"
                        />
                    </div>
                </template>

                <template v-else-if="teams">
                    <div :class="row">
                        <span class="flex min-w-0 flex-col gap-0.5">
                            <span :class="rowTitle">{{ $t('Alerts') }}</span>
                            <span :class="rowHelp">{{
                                $t(
                                    'A card for each alert, and a second card when a monitor or machine is fixed. Teams can’t change a card a workflow posted.',
                                )
                            }}</span>
                        </span>
                        <DashSwitch
                            v-model="teamsForm.alerts"
                            :label="$t('Alerts')"
                            :disabled="disabled"
                        />
                    </div>
                    <div :class="row">
                        <span class="flex min-w-0 flex-col gap-0.5">
                            <span :class="rowTitle">{{ $t('Deploys') }}</span>
                            <span :class="rowHelp">{{
                                $t(
                                    'A card for each finished deploy. Failed deploys come here instead of as alerts.',
                                )
                            }}</span>
                        </span>
                        <DashSwitch
                            v-model="teamsForm.deploys"
                            :label="$t('Deploys')"
                            :disabled="disabled"
                        />
                    </div>
                </template>

                <div
                    class="flex items-center gap-2.5 border-t-[0.5px] border-edge-tray-panel px-3.5 py-2.5"
                >
                    <span
                        class="min-w-0 flex-1 text-[12px]/4"
                        :class="
                            firstError ? 'text-status-down' : 'text-fg-muted'
                        "
                        :role="firstError ? 'alert' : undefined"
                        >{{
                            firstError ??
                            (form.isDirty ? $t('Not saved yet.') : $t('Saved.'))
                        }}</span
                    >
                    <DashButton
                        type="submit"
                        size="sm"
                        variant="primary"
                        :label="$t('Save')"
                        :loading="form.processing"
                        :disabled="disabled || !form.isDirty"
                    />
                </div>
            </form>
        </DashTray>

        <DashTray
            v-if="slack"
            icon="user-fill"
            :title="$t('People')"
            :meta="
                slack.people.length
                    ? String(slack.people.length)
                    : $t('Nobody linked yet')
            "
            panel-class="py-0.5"
        >
            <p
                class="px-3.5 py-2.5 text-[12.5px]/[18px] text-fg-muted"
                :class="{
                    'border-b-[0.5px] border-edge-tray-panel':
                        slack.people.length,
                }"
            >
                {{
                    slack.emailLinking
                        ? $t(
                              'Buttons and commands run as the person who uses them. A Slack account whose email is a member’s verified email links itself the first time; anyone else gets a one-time link to sign in.',
                          )
                        : $t(
                              'Buttons and commands run as the person who uses them. The first time, Slack sends them a one-time link to sign in to ServerOS and link their account.',
                          )
                }}
            </p>
            <ul v-if="slack.people.length" class="flex flex-col">
                <li
                    v-for="person in slack.people"
                    :key="person.id"
                    class="flex min-w-0 items-center gap-2.5 border-t-[0.5px] border-edge-tray-panel px-3.5 py-2.5 first:border-t-0"
                >
                    <img
                        v-if="person.avatar"
                        :src="person.avatar"
                        alt=""
                        class="size-5 shrink-0 rounded-full object-cover"
                    />
                    <FilledIcon
                        v-else
                        name="user-fill"
                        :size="16"
                        class="shrink-0 text-fg-muted"
                    />
                    <span
                        class="min-w-0 flex-1 truncate text-[13px]/5 text-fg-primary"
                        >{{ person.member }}</span
                    >
                    <span
                        class="truncate font-ui-mono text-[12px]/4 text-fg-tertiary"
                        >@{{ person.slack ?? '?' }}</span
                    >
                    <span
                        class="w-24 shrink-0 text-right text-[12px]/4 text-fg-muted max-sm:hidden"
                        >{{ timeAgo(person.since) }}</span
                    >
                </li>
            </ul>
        </DashTray>

        <DashTray
            v-if="slack"
            icon="terminal-fill"
            :title="slack.command"
            :meta="$t('Only the person who types it sees the answer')"
            panel-class="py-0.5"
        >
            <ul class="flex flex-col">
                <li
                    v-for="command in commands"
                    :key="command.text"
                    class="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-0.5 border-t-[0.5px] border-edge-tray-panel px-3.5 py-2.5 first:border-t-0"
                >
                    <code
                        class="font-ui-mono text-[12px]/5 whitespace-nowrap text-fg-primary"
                        >{{ command.text }}</code
                    >
                    <span class="min-w-0 text-[12.5px]/[18px] text-fg-muted">{{
                        command.help
                    }}</span>
                </li>
            </ul>
        </DashTray>

        <DashTray
            icon="activity-fill"
            :title="$t('Recent posts')"
            :meta="
                account.posts.length ? String(account.posts.length) : undefined
            "
            panel-class="py-0.5"
        >
            <ul v-if="account.posts.length" class="flex flex-col">
                <li
                    v-for="post in account.posts"
                    :key="post.id"
                    class="flex flex-col gap-1 border-t-[0.5px] border-edge-tray-panel px-3.5 py-2.5 first:border-t-0"
                >
                    <div class="flex min-w-0 items-center gap-2.5">
                        <span
                            class="min-w-0 flex-1 truncate text-[13px]/5 text-fg-primary"
                            >{{ post.subject }}</span
                        >
                        <span
                            class="shrink-0 text-[12px]/4 text-fg-muted max-sm:hidden"
                            >{{ timeAgo(post.at) }}</span
                        >
                        <StatusBadge
                            :status="tone[post.state]"
                            :label="stateLabel(post)"
                        />
                        <a
                            v-if="post.url"
                            :href="post.url"
                            target="_blank"
                            rel="noopener"
                            class="flex shrink-0 items-center gap-1 text-[12px]/4 text-fg-muted hover:text-fg-primary"
                            >{{ $t('Open in Slack')
                            }}<FilledIcon name="open-fill" :size="12"
                        /></a>
                    </div>
                    <p v-if="post.error" class="text-[12px]/4 text-status-down">
                        {{ post.error }}
                    </p>
                </li>
            </ul>
            <p v-else class="px-3.5 py-3 text-[13px]/5 text-fg-muted">
                {{
                    $t(
                        'Nothing posted yet. Alerts and deploys show here as they go out, or send a test.',
                    )
                }}
            </p>
        </DashTray>
    </template>
</template>
