<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, watch } from 'vue';
import DashButton from '@/components/dash/DashButton.vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import IntegrationLogo from '@/components/integrations/IntegrationLogo.vue';
import type {
    ConnectField,
    ProviderInfo,
} from '@/components/integrations/integrations';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { SelectMenu } from '@/components/ui/select';
import { toast } from '@/lib/toast';
import { onWorkspace } from '@/lib/workspaceRoute';
import integrationRoutes from '@/routes/workspace/integrations';
import type { Workspace } from '@/types';

const { provider, logo = null } = defineProps<{
    provider: ProviderInfo | null;
    logo?: string | null;
}>();

const open = defineModel<boolean>('open', { default: false });
const page = usePage<{ workspace: Workspace }>();

const form = useForm<{
    label: string;
    credentials: Record<string, string>;
    from: string;
}>({
    label: '',
    credentials: {},
    from: '',
});

watch(open, (shown) => {
    if (!shown) return;
    form.reset();
    form.clearErrors();
    form.credentials = Object.fromEntries(
        (provider?.fields ?? []).map((field) => [field.key, field.value ?? '']),
    );
    form.from = String(provider?.reuse?.accounts[0]?.id ?? '');
});

const errors = computed(() => form.errors as Record<string, string>);

const reuseOptions = computed(() =>
    provider?.reuse
        ? [
              ...provider.reuse.accounts.map((account) => ({
                  value: String(account.id),
                  label: trans('Use the token of :account (:provider)', {
                      account: account.label,
                      provider: provider.reuse?.provider ?? '',
                  }),
              })),
              { value: '', label: trans('Paste a new token') },
          ]
        : [],
);
const reusing = computed(() => form.from !== '');
const shownFields = computed(() =>
    (provider?.fields ?? []).filter(
        (field) => !(reusing.value && field.secret),
    ),
);
const reuseError = computed(() =>
    reusing.value
        ? (errors.value.from ??
          (provider?.fields ?? [])
              .filter((field) => field.secret)
              .map((field) => errors.value[`credentials.${field.key}`])
              .find(Boolean) ??
          null)
        : null,
);

function linkOf(field: ConnectField): string | null {
    const chosen = field.options?.find(
        (option) => option.value === form.credentials[field.key],
    );

    return chosen?.link ?? field.link;
}

async function copySnippet(field: ConnectField): Promise<void> {
    try {
        await navigator.clipboard.writeText(field.snippet ?? '');
        toast.success(
            trans(':name copied', { name: field.snippetLabel ?? '' }),
        );
    } catch {
        toast.error(trans('Could not copy. Select it and copy it manually.'));
    }
}

function submit(): void {
    if (!provider) return;
    form.transform((data) => ({
        label: data.label,
        credentials: data.credentials,
        ...(data.from === '' ? {} : { from: data.from }),
    })).post(
        onWorkspace(
            integrationRoutes.connect({
                workspace: page.props.workspace.slug,
                provider: provider.key,
            }),
        ).url,
        {
            preserveScroll: true,
            onSuccess: () => (open.value = false),
        },
    );
}

const fieldClass =
    'h-8 w-full rounded-[7px] border-[0.5px] border-edge-field bg-surface-field px-2.5 text-[13px]/5 text-fg-primary shadow-field outline-none placeholder:text-fg-faint focus:border-[rgba(51,97,255,0.7)]';
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="workspace-panel ds-modal max-h-[calc(100svh-2rem)] gap-0 overflow-y-auto !rounded-[12px] p-0 font-ui text-fg-primary sm:max-w-[480px]"
            :show-close-button="false"
        >
            <form
                v-if="provider"
                class="flex flex-col"
                @submit.prevent="submit"
            >
                <div class="flex items-start gap-3 pt-[18px] pr-4 pb-4 pl-5">
                    <IntegrationLogo :logo="logo ?? provider.key" />
                    <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                        <DialogTitle
                            class="text-[16px]/6 font-medium tracking-[-0.01em] text-fg-primary"
                        >
                            {{ $t('Connect :name', { name: provider.name }) }}
                        </DialogTitle>
                        <DialogDescription
                            class="text-[13px]/5 text-fg-tertiary"
                        >
                            {{
                                $t(
                                    'ServerOS checks it with :name before saving it.',
                                    { name: provider.name },
                                )
                            }}
                        </DialogDescription>
                    </div>
                    <kbd
                        class="flex h-5 items-center rounded-[5px] border-[0.5px] border-edge-strong px-1.5 font-ui text-[11px]/[14px] text-fg-muted"
                        >Esc</kbd
                    >
                </div>

                <div class="flex flex-col gap-4 px-5 pb-[18px]">
                    <label
                        v-if="reuseOptions.length"
                        class="flex flex-col gap-1.5"
                    >
                        <span class="text-[12.5px]/4 text-fg-tertiary">{{
                            $t('Token')
                        }}</span>
                        <SelectMenu
                            v-model="form.from"
                            :options="reuseOptions"
                            :aria-label="$t('Token')"
                            :class="fieldClass"
                        />
                        <span
                            v-if="reuseError"
                            class="text-[12px]/4 text-status-down"
                            role="alert"
                            >{{ reuseError }}</span
                        >
                        <span
                            v-if="reusing"
                            class="text-[12px]/4 text-fg-muted"
                            >{{
                                $t(
                                    'ServerOS checks the token can do this before saving it. If it can’t, add the permissions in :provider or paste a new token.',
                                    {
                                        provider:
                                            provider.reuse?.provider ?? '',
                                    },
                                )
                            }}</span
                        >
                    </label>
                    <template
                        v-for="(field, index) in shownFields"
                        :key="field.key"
                    >
                        <label class="flex flex-col gap-1.5">
                            <span
                                class="flex items-center justify-between gap-3 text-[12.5px]/4 text-fg-tertiary"
                            >
                                {{ field.label }}
                                <a
                                    v-if="linkOf(field)"
                                    :href="linkOf(field) ?? undefined"
                                    target="_blank"
                                    rel="noopener"
                                    class="inline-flex items-center gap-1 text-fg-muted hover:text-fg-primary"
                                    >{{ field.linkLabel ?? linkOf(field)
                                    }}<FilledIcon name="open-fill" :size="12"
                                /></a>
                            </span>
                            <SelectMenu
                                v-if="field.options?.length"
                                v-model="form.credentials[field.key]"
                                :options="field.options"
                                :aria-label="field.label"
                                :required="field.required !== false"
                                :class="fieldClass"
                            />
                            <input
                                v-else
                                v-model="form.credentials[field.key]"
                                :type="field.secret ? 'password' : 'text'"
                                autocomplete="off"
                                spellcheck="false"
                                :placeholder="field.placeholder ?? ''"
                                :autofocus="index === 0"
                                :class="[
                                    fieldClass,
                                    field.secret ? 'font-ui-mono' : '',
                                ]"
                                :aria-invalid="
                                    !!errors[`credentials.${field.key}`]
                                "
                                :required="field.required !== false"
                            />
                            <span
                                v-if="errors[`credentials.${field.key}`]"
                                class="text-[12px]/4 text-status-down"
                                role="alert"
                                >{{ errors[`credentials.${field.key}`] }}</span
                            >
                            <span
                                v-if="field.help"
                                class="text-[12px]/4 text-fg-muted"
                                >{{ field.help }}</span
                            >
                        </label>
                        <details
                            v-if="field.snippet"
                            class="group -mt-2 rounded-[8px] border-[0.5px] border-edge-field bg-surface-field shadow-field"
                        >
                            <summary
                                class="flex cursor-pointer list-none items-center gap-1.5 px-2.5 py-1.5 text-[12px]/4 text-fg-tertiary hover:text-fg-primary [&::-webkit-details-marker]:hidden"
                            >
                                <FilledIcon
                                    name="chevron-right"
                                    :size="12"
                                    class="transition-transform group-open:rotate-90"
                                />
                                <span class="flex-1">{{
                                    $t('Show the :name', {
                                        name: field.snippetLabel ?? '',
                                    })
                                }}</span>
                                <DashButton
                                    size="sm"
                                    :label="$t('Copy')"
                                    @click.prevent="copySnippet(field)"
                                />
                            </summary>
                            <pre
                                class="max-h-48 overflow-auto border-t-[0.5px] border-edge-field px-2.5 py-2 font-ui-mono text-[11.5px]/[17px] whitespace-pre text-fg-secondary"
                                >{{ field.snippet }}</pre>
                        </details>
                    </template>

                    <label class="flex flex-col gap-1.5">
                        <span class="text-[12.5px]/4 text-fg-tertiary">{{
                            $t('Name')
                        }}</span>
                        <input
                            v-model="form.label"
                            type="text"
                            maxlength="80"
                            :placeholder="
                                provider.multiple
                                    ? $t('Optional, like the project’s name')
                                    : provider.name
                            "
                            :class="fieldClass"
                        />
                        <span
                            v-if="errors.label"
                            class="text-[12px]/4 text-status-down"
                            role="alert"
                            >{{ errors.label }}</span
                        >
                    </label>

                    <ul
                        v-if="provider.capabilities.length"
                        class="flex flex-col gap-1.5 rounded-[10px] border-[0.5px] border-edge-card bg-surface-card px-3.5 py-3"
                        :aria-label="$t('What it can do')"
                    >
                        <li
                            v-for="capability in provider.capabilities"
                            :key="capability.key"
                            class="flex items-start gap-2 text-[12.5px]/[18px]"
                        >
                            <FilledIcon
                                name="check"
                                :size="14"
                                class="mt-0.5 text-status-live"
                            />
                            <span class="text-fg-secondary">{{
                                capability.label
                            }}</span>
                        </li>
                    </ul>
                </div>

                <div
                    class="flex items-center gap-2 border-t-[0.5px] border-edge bg-surface-card-footer py-3 pr-4 pl-5"
                >
                    <span class="flex-1 text-[12px]/4 text-fg-muted">{{
                        $t('Kept encrypted. It’s never shown again.')
                    }}</span>
                    <DashButton :label="$t('Cancel')" @click="open = false" />
                    <DashButton
                        type="submit"
                        variant="primary"
                        :label="
                            form.processing ? $t('Checking…') : $t('Connect')
                        "
                        :loading="form.processing"
                    />
                </div>
            </form>
        </DialogContent>
    </Dialog>
</template>
