<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import {
    LoaderCircle,
    Lock,
    ShieldCheck,
    SquareTerminal,
    X,
} from '@lucide/vue';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    useTemplateRef,
    watch,
} from 'vue';
import InputError from '@/components/InputError.vue';
import JobStatusBadge from '@/components/machines/JobStatusBadge.vue';
import {
    InputOTP,
    InputOTPGroup,
    InputOTPSlot,
} from '@/components/ui/input-otp';
import AppButton from '@/components/workspace/AppButton.vue';
import SubmitButton from '@/components/workspace/SubmitButton.vue';
import { useUpgradeLink } from '@/composables/useUpgradeLink';
import { describe, errorMessage, jobReason } from '@/lib/errors';
import { onWorkspace } from '@/lib/workspaceRoute';
import { edit as securityEdit } from '@/routes/security';
import consoleRoutes from '@/routes/workspace/console';

export type ConsoleJob = {
    id: string;
    kind: string;
    command: string | null;
    state: string;
    log: string[];
    result: Record<string, unknown> | null;
    error: { message?: string; next_step?: string } | null;
};

const open = defineModel<boolean>('open', { required: true });

const props = defineProps<{
    workspaceSlug: string;
    actionUrl: string;
    displayName: string;
    machineName: string;
    container: boolean;
    hasPlan: boolean;
    canRun: boolean;
    jobs: ConsoleJob[];
    consoleLock: {
        twoFactorEnabled: boolean;
        unlockedUntil: string | null;
        minutes: number;
    };
}>();

const emit = defineEmits<{ ran: [] }>();

const upgrade = useUpgradeLink();
const unlockCode = ref('');
const unlockError = ref<string | null>(null);
const unlocking = ref(false);
const now = ref(Date.now());
const command = ref('');
const commandError = ref<string | null>(null);
const running = ref(false);
const consoleInput = useTemplateRef<HTMLInputElement>('consoleInput');
const consoleBox = useTemplateRef<HTMLElement>('consoleBox');
let historyIndex = -1;
let clock: ReturnType<typeof setInterval> | undefined;

const unlockedMs = computed(() =>
    props.consoleLock.unlockedUntil
        ? Date.parse(props.consoleLock.unlockedUntil) - now.value
        : 0,
);
const unlocked = computed(() => unlockedMs.value > 0);
const countdown = computed(() => {
    const total = Math.max(0, Math.floor(unlockedMs.value / 1000));

    return `${Math.floor(total / 60)}:${String(total % 60).padStart(2, '0')}`;
});
const transcript = computed(() =>
    props.jobs
        .filter((job) => job.kind === 'service_exec')
        .slice()
        .reverse(),
);

onMounted(() => (clock = setInterval(() => (now.value = Date.now()), 1000)));
onBeforeUnmount(() => clearInterval(clock));

watch(open, (value) => {
    if (value) void nextTick(() => consoleInput.value?.focus());
});

function csrf(): string {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}

async function consoleRequest(path: 'unlock' | 'lock', body = {}) {
    return fetch(
        onWorkspace(
            path === 'unlock'
                ? consoleRoutes.unlock(props.workspaceSlug)
                : consoleRoutes.lock(props.workspaceSlug),
        ).url,
        {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf(),
            },
            body: JSON.stringify(body),
        },
    );
}

async function unlock(): Promise<void> {
    if (unlockCode.value.length !== 6 || unlocking.value) return;
    unlocking.value = true;
    unlockError.value = null;
    try {
        const response = await consoleRequest('unlock', {
            code: unlockCode.value,
        });
        const body = await response.json().catch(() => ({}));
        unlockCode.value = '';
        if (!response.ok) {
            unlockError.value = describe(response.status, body);

            return;
        }
        router.reload({
            only: ['consoleLock'],
            onFinish: () => nextTick(() => consoleInput.value?.focus()),
        });
    } catch (cause) {
        unlockError.value = errorMessage(cause);
    } finally {
        unlocking.value = false;
    }
}

async function lock(): Promise<void> {
    await consoleRequest('lock');
    router.reload({ only: ['consoleLock'] });
}

async function run(): Promise<void> {
    const text = command.value.trim();
    if (!text || running.value) return;
    running.value = true;
    commandError.value = null;
    try {
        const response = await fetch(props.actionUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf(),
            },
            body: JSON.stringify({ action: 'exec', command: text }),
        });
        const body = await response.json().catch(() => ({}));
        if (response.ok) {
            command.value = '';
            historyIndex = -1;
            emit('ran');
        } else if (body.code === 'console_locked') {
            router.reload({ only: ['consoleLock'] });
        } else if (body.errors) {
            commandError.value = Object.values(
                body.errors as Record<string, string[]>,
            )[0]?.[0];
        } else {
            commandError.value = describe(response.status, body);
        }
    } catch (cause) {
        commandError.value = errorMessage(cause);
    } finally {
        running.value = false;
    }
}

function recall(direction: 1 | -1): void {
    const past = transcript.value.map((job) => job.command ?? '').reverse();
    historyIndex = Math.max(
        -1,
        Math.min(past.length - 1, historyIndex + direction),
    );
    command.value = historyIndex === -1 ? '' : past[historyIndex];
}

watch(
    () => transcript.value.map((job) => job.log.length).join(),
    () =>
        nextTick(() => {
            if (consoleBox.value) {
                consoleBox.value.scrollTop = consoleBox.value.scrollHeight;
            }
        }),
);

function output(job: ConsoleJob): string[] {
    return job.log[0]?.startsWith('$ ') ? job.log.slice(1) : job.log;
}
</script>

<template>
    <Teleport to="body">
        <Transition name="console-sheet">
            <div
                v-if="open"
                class="fixed inset-0 z-50 flex flex-col justify-end"
                @keydown.esc="open = false"
            >
                <div
                    class="console-scrim absolute inset-0 bg-black/40"
                    aria-hidden="true"
                    @click="open = false"
                />
                <section
                    role="dialog"
                    aria-modal="true"
                    :aria-label="$t('Console')"
                    class="console-panel dark workspace-panel relative mx-2 mb-2 flex h-[min(80vh,780px)] flex-col overflow-hidden rounded-2xl border-[0.5px] border-edge-card bg-surface-sidebar font-ui text-fg-primary shadow-[0_-24px_80px_-20px_rgb(0_0_0/0.6)]"
                >
                    <header
                        class="flex h-11 shrink-0 items-center gap-2.5 border-b-[0.5px] border-edge bg-surface-main px-4"
                    >
                        <SquareTerminal
                            class="size-4 text-fg-muted"
                            :stroke-width="1.75"
                            aria-hidden="true"
                        />
                        <span class="font-ui-mono text-[13px]/5">{{
                            displayName
                        }}</span>
                        <span class="text-[12.5px] text-fg-faint">{{
                            $t('on :machine', { machine: machineName })
                        }}</span>
                        <span
                            v-if="hasPlan && unlocked"
                            class="ml-auto flex items-center gap-2 text-[12.5px] text-fg-faint"
                        >
                            <ShieldCheck
                                class="size-3.5 text-status-live"
                                :stroke-width="2"
                                aria-hidden="true"
                            />
                            {{ $t('Locks in') }}
                            <span class="font-ui-mono tabular-nums">{{
                                countdown
                            }}</span>
                            <button
                                type="button"
                                class="ml-1 flex size-7 cursor-pointer items-center justify-center rounded-md text-fg-muted hover:bg-surface-hover hover:text-fg-primary"
                                :title="$t('Lock now')"
                                :aria-label="$t('Lock now')"
                                @click="lock"
                            >
                                <Lock class="size-3.5" :stroke-width="1.75" />
                            </button>
                        </span>
                        <button
                            type="button"
                            class="flex size-7 cursor-pointer items-center justify-center rounded-md text-fg-muted hover:bg-surface-hover hover:text-fg-primary"
                            :class="{ 'ml-auto': !(hasPlan && unlocked) }"
                            :aria-label="$t('Close console')"
                            @click="open = false"
                        >
                            <X class="size-4" :stroke-width="1.75" />
                        </button>
                    </header>

                    <div
                        v-if="!hasPlan"
                        class="flex grow flex-col items-center justify-center gap-5 px-6 py-14 text-center"
                    >
                        <div class="flex max-w-md flex-col gap-1.5">
                            <h2 class="text-base font-semibold">
                                {{ $t('The console needs a plan') }}
                            </h2>
                            <p class="text-[13px]/5 text-fg-muted">
                                {{
                                    $t(
                                        ':displayName keeps running as it is. Choose a plan to run commands in it again.',
                                        { displayName },
                                    )
                                }}
                            </p>
                        </div>
                        <Link
                            v-if="upgrade"
                            :href="upgrade.href"
                            class="ds-raised-button text-[13px]!"
                            >{{ $t('Choose a plan') }}</Link
                        >
                    </div>

                    <div
                        v-else-if="!consoleLock.twoFactorEnabled || !unlocked"
                        class="flex grow flex-col items-center justify-center gap-5 px-6 py-14 text-center"
                    >
                        <span
                            class="ds-icon-chip size-12! rounded-xl!"
                            aria-hidden="true"
                        >
                            <ShieldCheck class="size-5" :stroke-width="2" />
                        </span>
                        <template v-if="!consoleLock.twoFactorEnabled">
                            <div class="flex max-w-md flex-col gap-1.5">
                                <h2 class="text-base font-semibold">
                                    {{
                                        $t(
                                            'The console needs two-factor authentication',
                                        )
                                    }}
                                </h2>
                                <p class="text-[13px]/5 text-fg-muted">
                                    {{
                                        $t(
                                            'It runs commands on a real machine, so a password alone isn’t enough. Turn on two-factor for your account, then come back to unlock it.',
                                        )
                                    }}
                                </p>
                            </div>
                            <AppButton
                                variant="primary"
                                size="sm"
                                :href="securityEdit()"
                            >
                                {{ $t('Set up two-factor') }}
                            </AppButton>
                        </template>
                        <form
                            v-else
                            class="flex flex-col items-center gap-4"
                            @submit.prevent="unlock"
                        >
                            <div class="flex max-w-md flex-col gap-1.5">
                                <h2 class="text-base font-semibold">
                                    {{ $t('Unlock the console') }}
                                </h2>
                                <p class="text-[13px]/5 text-fg-muted">
                                    {{
                                        $t(
                                            'Enter the 6-digit code from your authenticator app. The console stays unlocked for :minutes minutes on this device.',
                                            {
                                                minutes: String(
                                                    consoleLock.minutes,
                                                ),
                                            },
                                        )
                                    }}
                                </p>
                            </div>
                            <InputOTP
                                v-model="unlockCode"
                                :maxlength="6"
                                :disabled="unlocking"
                                autofocus
                                @complete="unlock"
                            >
                                <InputOTPGroup>
                                    <InputOTPSlot
                                        v-for="index in 6"
                                        :key="index"
                                        :index="index - 1"
                                    />
                                </InputOTPGroup>
                            </InputOTP>
                            <InputError :message="unlockError ?? undefined" />
                            <SubmitButton
                                :label="$t('Unlock')"
                                busy-label="Checking…"
                                :processing="unlocking"
                                :disabled="unlockCode.length !== 6"
                            />
                        </form>
                    </div>

                    <template v-else>
                        <div
                            ref="consoleBox"
                            class="min-h-0 grow overflow-auto px-5 pt-4 pb-2 font-ui-mono text-[12.5px]/5.5"
                            role="log"
                            :aria-label="$t('Command output')"
                        >
                            <p class="mb-3 text-fg-faint">
                                #
                                {{
                                    $t(
                                        'One-off commands with sh -c, up to a minute each, no interactive input. Each one is recorded with your name.',
                                    )
                                }}
                            </p>
                            <p v-if="!container" class="text-fg-faint">
                                {{
                                    $t(
                                        'Commands run inside containers, and this service isn’t one.',
                                    )
                                }}
                            </p>
                            <p
                                v-else-if="transcript.length === 0"
                                class="text-fg-faint"
                            >
                                {{ $t('Nothing run yet. Try') }}
                                <span class="text-fg-secondary">{{
                                    $t('ls -la')
                                }}</span>
                                {{ $t('or') }}
                                <span class="text-fg-secondary">{{
                                    $t('env')
                                }}</span
                                >.
                            </p>
                            <div
                                v-for="job in transcript"
                                :key="job.id"
                                class="mb-3 flex flex-col gap-0.5"
                            >
                                <div class="flex items-center gap-2">
                                    <span class="text-status-deploying">$</span>
                                    <span class="text-fg-primary">{{
                                        job.command
                                    }}</span>
                                    <JobStatusBadge
                                        v-if="job.state !== 'succeeded'"
                                        :state="job.state"
                                        class="ml-auto scale-90"
                                    />
                                    <span
                                        v-else-if="
                                            job.result?.exit_code !== undefined
                                        "
                                        class="ml-auto text-[11px]"
                                        :class="
                                            job.result.exit_code === 0
                                                ? 'text-fg-faint'
                                                : 'text-status-down'
                                        "
                                    >
                                        {{
                                            $t('exit :exit_code', {
                                                exit_code: String(
                                                    job.result.exit_code ?? '',
                                                ),
                                            })
                                        }}
                                    </span>
                                </div>
                                <div
                                    v-for="(line, i) in output(job)"
                                    :key="i"
                                    class="break-words whitespace-pre-wrap text-fg-secondary"
                                >
                                    {{ line }}
                                </div>
                                <p v-if="job.error" class="text-status-down">
                                    {{
                                        jobReason(
                                            job.error,
                                            $t('That didn’t finish.'),
                                            false,
                                        )
                                    }}
                                </p>
                            </div>
                        </div>
                        <form
                            class="flex shrink-0 items-center gap-2 border-t-[0.5px] border-edge px-5 py-3 font-ui-mono text-[13px]/5"
                            @submit.prevent="run"
                        >
                            <span class="text-status-live" aria-hidden="true"
                                >$</span
                            >
                            <span class="sr-only">{{ $t('Command') }}</span>
                            <input
                                ref="consoleInput"
                                v-model="command"
                                type="text"
                                autocomplete="off"
                                spellcheck="false"
                                :disabled="!canRun || !container"
                                :placeholder="$t('e.g. redis-cli INFO memory')"
                                class="h-6 min-w-0 grow bg-transparent text-fg-primary caret-fg-primary outline-none placeholder:text-fg-faint"
                                @keydown.up.prevent="recall(1)"
                                @keydown.down.prevent="recall(-1)"
                            />
                            <LoaderCircle
                                v-if="running"
                                class="size-3.5 animate-spin text-fg-faint"
                                :stroke-width="2"
                                aria-hidden="true"
                            />
                            <span v-else class="text-[11px] text-fg-faint">{{
                                $t('Enter to run')
                            }}</span>
                        </form>
                        <InputError
                            v-if="commandError"
                            class="px-5 pb-3"
                            :message="commandError"
                        />
                    </template>
                </section>
            </div>
        </Transition>
    </Teleport>
</template>
