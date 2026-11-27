<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed, onMounted, ref, watch } from 'vue';
import DashButton from '@/components/dash/DashButton.vue';
import DashTray from '@/components/dash/DashTray.vue';
import StatusBadge from '@/components/dash/StatusBadge.vue';
import type { DashStatus } from '@/components/dash/StatusBadge.vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import StorageBucketDialog from '@/components/integrations/StorageBucketDialog.vue';
import type {
    BackupStorageAccount,
    StorageBucket,
} from '@/components/integrations/storage';
import { storedSize } from '@/components/integrations/storage';
import { timeAgo } from '@/lib/timeAgo';
import { onWorkspace, workspacePath } from '@/lib/workspaceRoute';
import storageRoutes from '@/routes/workspace/integrations/storage';
import type { Workspace } from '@/types';

const {
    account,
    integrationId,
    providerName,
    disabled = false,
} = defineProps<{
    account: BackupStorageAccount | undefined;
    integrationId: number;
    providerName: string;
    disabled?: boolean;
}>();

const page = usePage<{ workspace: Workspace }>();
const args = computed(() => ({
    workspace: page.props.workspace.slug,
    integration: integrationId,
}));

const buckets = computed(() => account?.buckets ?? []);
const meta = computed(() => {
    if (!account) return null;
    const machines = new Set(
        buckets.value.flatMap((b) => b.machines.map((m) => m.id)),
    ).size;

    return buckets.value.length === 1
        ? trans('1 bucket, :machines backing up', {
              machines: String(machines),
          })
        : trans(':count buckets, :machines backing up', {
              count: String(buckets.value.length),
              machines: String(machines),
          });
});

const dialogOpen = ref(false);
const editing = ref<StorageBucket | null>(null);
const wantsSetup = ref(false);

function setUp(): void {
    editing.value = null;
    dialogOpen.value = true;
}

function edit(bucket: StorageBucket): void {
    editing.value = bucket;
    dialogOpen.value = true;
}

onMounted(() => {
    const url = new URL(window.location.href);
    if (!url.searchParams.has('setup')) return;
    wantsSetup.value = true;
    url.searchParams.delete('setup');
    window.history.replaceState(window.history.state, '', url.toString());
});

watch(
    () => [account, wantsSetup.value] as const,
    ([loaded, wanted]) => {
        if (!loaded || !wanted || disabled) return;
        wantsSetup.value = false;
        setUp();
    },
    { immediate: true },
);

const retrying = ref<number | null>(null);
const retryError = ref<Record<number, string>>({});

function retry(bucket: StorageBucket): void {
    retrying.value = bucket.id;
    router.post(
        onWorkspace(storageRoutes.retry({ ...args.value, bucket: bucket.id }))
            .url,
        {},
        {
            preserveScroll: true,
            onError: (errors) =>
                (retryError.value = {
                    ...retryError.value,
                    [bucket.id]: errors.storage ?? trans('That didn’t work.'),
                }),
            onSuccess: () => {
                retryError.value = Object.fromEntries(
                    Object.entries(retryError.value).filter(
                        ([id]) => Number(id) !== bucket.id,
                    ),
                );
            },
            onFinish: () => (retrying.value = null),
        },
    );
}

const keyForms = ref<Record<number, { access: string; secret: string }>>({});
const keyErrors = ref<Record<number, Record<string, string>>>({});
const savingKeys = ref<number | null>(null);

function keyForm(bucket: StorageBucket): { access: string; secret: string } {
    keyForms.value[bucket.id] ??= { access: '', secret: '' };

    return keyForms.value[bucket.id];
}

function saveKeys(bucket: StorageBucket): void {
    const form = keyForm(bucket);
    savingKeys.value = bucket.id;
    router.post(
        onWorkspace(storageRoutes.keys({ ...args.value, bucket: bucket.id }))
            .url,
        { access_key: form.access.trim(), secret_key: form.secret.trim() },
        {
            preserveScroll: true,
            onError: (errors) =>
                (keyErrors.value = { ...keyErrors.value, [bucket.id]: errors }),
            onSuccess: () => {
                keyForms.value[bucket.id] = { access: '', secret: '' };
                keyErrors.value = { ...keyErrors.value, [bucket.id]: {} };
            },
            onFinish: () => (savingKeys.value = null),
        },
    );
}

const states = computed<
    Record<StorageBucket['state'], { status: DashStatus; label: string }>
>(() => ({
    ready: { status: 'live', label: trans('Ready') },
    needs_keys: { status: 'pending', label: trans('Needs a key') },
    failed: { status: 'down', label: trans('Not finished') },
}));

function where(bucket: StorageBucket): string {
    return [
        bucket.endpoint,
        bucket.jurisdiction === 'eu' ? trans('EU') : null,
        bucket.keepDays
            ? trans('deletes backups after :days days', {
                  days: String(bucket.keepDays),
              })
            : trans('keeps every backup'),
    ]
        .filter(Boolean)
        .join(' · ');
}

function machinesLine(bucket: StorageBucket): string {
    const names = bucket.machines.map((m) => m.name);
    if (bucket.state !== 'ready' && bucket.waiting > 0) {
        return bucket.waiting === 1
            ? trans('1 machine backs up here once it’s ready')
            : trans(':count machines back up here once it’s ready', {
                  count: String(bucket.waiting),
              });
    }
    if (names.length === 0) return trans('No machine backs up here yet');
    if (names.length <= 3)
        return trans('Backups of :machines', { machines: names.join(', ') });

    return trans('Backups of :machines and :count more', {
        machines: names.slice(0, 2).join(', '),
        count: String(names.length - 2),
    });
}

function usageLine(bucket: StorageBucket): string | null {
    if (!bucket.usage) return null;
    const files =
        bucket.usage.files === 1
            ? trans('1 file')
            : trans(':count files', {
                  count: bucket.usage.files.toLocaleString(),
              });

    return `${storedSize(bucket.usage.bytes)} · ${bucket.usage.more ? trans('over :files', { files }) : files}`;
}

const fieldClass =
    'h-8 w-full min-w-0 rounded-[7px] border-[0.5px] border-edge-field bg-surface-field px-2.5 font-ui-mono text-[12.5px]/5 text-fg-primary shadow-field outline-none placeholder:font-ui placeholder:text-fg-faint focus:border-[rgba(51,97,255,0.7)]';
</script>

<template>
    <DashTray
        icon="database-fill"
        :title="$t('Buckets')"
        :meta="meta"
        panel-class="py-0.5"
    >
        <template v-if="account" #actions>
            <DashButton
                size="sm"
                :variant="buckets.length ? 'secondary' : 'primary'"
                :icon="Plus"
                :label="
                    buckets.length ? $t('New bucket') : $t('Set up backups')
                "
                :disabled="disabled"
                @click="setUp"
            />
        </template>

        <div
            v-if="account === undefined"
            class="flex flex-col"
            role="status"
            :aria-label="$t('Loading buckets')"
        >
            <div
                v-for="n in 2"
                :key="n"
                class="flex items-center gap-3 border-t-[0.5px] border-edge-tray-panel px-3.5 py-3 first:border-t-0"
            >
                <span
                    class="h-3 w-44 animate-pulse rounded bg-surface-badge motion-reduce:animate-none"
                />
                <span
                    class="h-3 w-28 animate-pulse rounded bg-surface-badge motion-reduce:animate-none"
                />
            </div>
        </div>
        <template v-else>
            <p
                v-for="note in account.notes"
                :key="note"
                class="border-b-[0.5px] border-edge-tray-panel px-3.5 py-2.5 text-[12.5px]/[18px] text-fg-muted"
            >
                {{ note }}
            </p>
            <ul v-if="buckets.length" class="flex flex-col">
                <li
                    v-for="bucket in buckets"
                    :key="bucket.id"
                    class="flex flex-col gap-2.5 border-t-[0.5px] border-edge-tray-panel px-3.5 py-3 first:border-t-0"
                >
                    <div class="flex min-w-0 items-start gap-3">
                        <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                            <div class="flex min-w-0 items-center gap-2">
                                <span
                                    class="truncate font-ui-mono text-[13px]/5 text-fg-primary"
                                    >{{ bucket.name }}</span
                                >
                                <StatusBadge
                                    :status="states[bucket.state].status"
                                    :label="states[bucket.state].label"
                                />
                            </div>
                            <span
                                class="truncate text-[12px]/4 text-fg-muted"
                                >{{ where(bucket) }}</span
                            >
                            <span
                                class="truncate text-[12px]/4 text-fg-tertiary"
                            >
                                {{ machinesLine(bucket) }}
                                <template v-if="bucket.destination">
                                    ·
                                    <Link
                                        :href="
                                            workspacePath(
                                                '/backups/destinations',
                                            )
                                        "
                                        class="hover:text-fg-primary hover:underline"
                                        >{{ bucket.destination.name }}</Link
                                    >
                                </template>
                            </span>
                        </div>
                        <div
                            v-if="bucket.state === 'ready'"
                            class="flex shrink-0 flex-col items-end gap-0.5 text-right max-sm:hidden"
                        >
                            <span
                                class="text-[13px]/5 text-fg-secondary tabular-nums"
                                >{{ usageLine(bucket) ?? '–' }}</span
                            >
                            <span
                                v-if="bucket.cost"
                                class="text-[12px]/4 text-fg-muted tabular-nums"
                                :title="
                                    $t(
                                        'An estimate at :price, for storage alone, before any free allowance. Your bill at :provider is what counts.',
                                        {
                                            price: account.price,
                                            provider: providerName,
                                        },
                                    )
                                "
                                >{{
                                    $t('About :cost a month', {
                                        cost: bucket.cost,
                                    })
                                }}</span
                            >
                        </div>
                        <DashButton
                            v-if="bucket.state === 'ready'"
                            size="sm"
                            :label="$t('Edit')"
                            :aria-label="
                                $t('Edit :bucket', { bucket: bucket.name })
                            "
                            :disabled="disabled"
                            @click="edit(bucket)"
                        />
                        <DashButton
                            v-else-if="bucket.state === 'failed'"
                            size="sm"
                            :label="$t('Try again')"
                            :loading="retrying === bucket.id"
                            :disabled="disabled"
                            @click="retry(bucket)"
                        />
                    </div>

                    <p
                        v-if="bucket.error || retryError[bucket.id]"
                        class="text-[12.5px]/[18px] text-status-down"
                        role="alert"
                    >
                        {{ retryError[bucket.id] ?? bucket.error }}
                    </p>
                    <p
                        v-if="bucket.usageError"
                        class="text-[12px]/4 text-fg-muted"
                    >
                        {{ bucket.usageError }}
                        <template v-if="bucket.usage?.checkedAt">
                            {{
                                $t('The size shown is from :when.', {
                                    when: timeAgo(bucket.usage.checkedAt),
                                })
                            }}
                        </template>
                    </p>

                    <form
                        v-if="bucket.state === 'needs_keys'"
                        class="flex flex-col gap-2.5 rounded-[8px] border-[0.5px] border-[rgba(240,148,60,0.25)] bg-[rgba(240,148,60,0.07)] p-3"
                        @submit.prevent="saveKeys(bucket)"
                    >
                        <p class="text-[12.5px]/[18px] text-fg-secondary">
                            {{ bucket.keysHelp }}
                        </p>
                        <div class="grid gap-2 sm:grid-cols-2">
                            <label class="flex min-w-0 flex-col gap-1">
                                <span class="text-[12px]/4 text-fg-tertiary">{{
                                    $t('Access Key ID')
                                }}</span>
                                <input
                                    v-model="keyForm(bucket).access"
                                    autocomplete="off"
                                    spellcheck="false"
                                    required
                                    :class="fieldClass"
                                />
                            </label>
                            <label class="flex min-w-0 flex-col gap-1">
                                <span class="text-[12px]/4 text-fg-tertiary">{{
                                    $t('Secret Access Key')
                                }}</span>
                                <input
                                    v-model="keyForm(bucket).secret"
                                    type="password"
                                    autocomplete="new-password"
                                    required
                                    :class="fieldClass"
                                />
                            </label>
                        </div>
                        <p
                            v-for="message in keyErrors[bucket.id] ?? {}"
                            :key="message"
                            class="text-[12px]/4 text-status-down"
                            role="alert"
                        >
                            {{ message }}
                        </p>
                        <div class="flex justify-end gap-2">
                            <DashButton
                                size="sm"
                                :label="$t('Try making one again')"
                                :loading="retrying === bucket.id"
                                :disabled="disabled"
                                @click="retry(bucket)"
                            />
                            <DashButton
                                type="submit"
                                size="sm"
                                variant="primary"
                                :label="$t('Check and use it')"
                                :loading="savingKeys === bucket.id"
                                :disabled="disabled"
                            />
                        </div>
                    </form>
                </li>
            </ul>
            <div v-else class="flex flex-col items-start gap-1 px-3.5 py-3">
                <p class="text-[13px]/5 text-fg-secondary">
                    {{ $t('No bucket here yet.') }}
                </p>
                <p class="text-[12.5px]/[18px] text-fg-muted">
                    {{
                        $t(
                            'Set up backups and ServerOS makes a private bucket, a key that can only reach it and the backup destination, then sends the machines you pick there.',
                        )
                    }}
                </p>
            </div>
            <p
                v-if="buckets.some((b) => b.cost)"
                class="border-t-[0.5px] border-edge-tray-panel px-3.5 py-2.5 text-[12px]/4 text-fg-muted"
            >
                {{
                    $t(
                        'Costs are estimates at :price, for storage alone, before any free allowance.',
                        { price: account.price },
                    )
                }}
            </p>
        </template>
    </DashTray>

    <StorageBucketDialog
        v-if="account"
        v-model:open="dialogOpen"
        :account="account"
        :bucket="editing"
        :integration-id="integrationId"
        :provider-name="providerName"
    />
</template>
