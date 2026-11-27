<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { Mail } from '@lucide/vue';
import { computed, ref, useTemplateRef, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import BrandLogo from '@/components/dash/BrandLogo.vue';
import DashButton from '@/components/dash/DashButton.vue';
import DashSwitch from '@/components/dash/DashSwitch.vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import AlertsDialog from '@/components/monitors/AlertsDialog.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import AppButton from '@/components/workspace/AppButton.vue';
import DangerButton from '@/components/workspace/DangerButton.vue';
import PanelUpdateTile from '@/components/workspace/PanelUpdateTile.vue';
import type { PanelUpdateState } from '@/components/workspace/PanelUpdateTile.vue';
import SubmitButton from '@/components/workspace/SubmitButton.vue';
import SettingsItemRow from '@/components/workspace/settings/SettingsItemRow.vue';
import SettingsSection from '@/components/workspace/settings/SettingsSection.vue';
import { onWorkspace, workspaceAddress } from '@/lib/workspaceRoute';
import githubRoutes from '@/routes/workspace/github';
import alertRoutes from '@/routes/workspace/monitors/alerts';
import registryRoutes from '@/routes/workspace/registries';
import settings from '@/routes/workspace/settings';
import logo from '@/routes/workspace/settings/logo';
import type { Workspace } from '@/types';

type Props = {
    workspace: Workspace;
    canManageSettings: boolean;
    canDelete: boolean;
    canManageAlerts: boolean;
    alerts: {
        email: boolean;
        webhook: string | null;
        webhookSet: boolean;
        telegram: { chat: string | null; set: boolean };
        muted: string[];
    };
    github:
        | {
              id: number;
              account: string;
              organisation: boolean;
              repositories: number | null;
              manageUrl: string;
          }[]
        | null;
    githubSetup: {
        ready: boolean;
        canSetUp: boolean;
        fromEnvironment: boolean;
    } | null;
    panelUpdate: PanelUpdateState | null;
    registries:
        | {
              id: number;
              host: string;
              label: string;
              username: string;
              addedAt: string | null;
          }[]
        | null;
};

const props = defineProps<Props>();

const page = usePage();
const slug = props.workspace.slug;

const nameForm = useForm({ name: props.workspace.name });

watch(
    () => props.workspace.name,
    (name) => {
        nameForm.defaults({ name });
        nameForm.reset();
    },
);

function saveName(): void {
    nameForm.patch(onWorkspace(settings.update(slug)).url, {
        preserveScroll: true,
    });
}

const address = computed(() => {
    const full = workspaceAddress(props.workspace.url);
    const at = full.lastIndexOf(slug);

    return at === -1
        ? { before: full, slug: '', after: '' }
        : {
              before: full.slice(0, at),
              slug,
              after: full.slice(at + slug.length),
          };
});

const logoInput = useTemplateRef<HTMLInputElement>('logoInput');
const uploadingLogo = ref(false);

function uploadLogo(event: Event): void {
    const file = (event.target as HTMLInputElement).files?.[0];

    if (!file) {
        return;
    }

    router.post(
        onWorkspace(logo.update(slug)).url,
        { logo: file },
        {
            forceFormData: true,
            preserveScroll: true,
            onStart: () => (uploadingLogo.value = true),
            onFinish: () => {
                uploadingLogo.value = false;

                if (logoInput.value) {
                    logoInput.value.value = '';
                }
            },
        },
    );
}

function removeLogo(): void {
    router.delete(onWorkspace(logo.destroy(slug)).url, {
        preserveScroll: true,
    });
}

const alertsOpen = ref(false);
const emailAlerts = ref(props.alerts.email);
const savingEmail = ref(false);

watch(
    () => props.alerts.email,
    (on) => (emailAlerts.value = on),
);

function setEmailAlerts(on: boolean): void {
    emailAlerts.value = on;
    router.put(
        onWorkspace(alertRoutes.update(slug)).url,
        { email: on, webhook: props.alerts.webhook },
        {
            preserveScroll: true,
            onStart: () => (savingEmail.value = true),
            onFinish: () => (savingEmail.value = false),
            onError: () => (emailAlerts.value = props.alerts.email),
        },
    );
}

const webhook = computed<{
    brand: 'slack' | 'discord' | null;
    title: string;
    description: string;
}>(() => {
    const url = props.alerts.webhook;

    if (!props.alerts.webhookSet) {
        return {
            brand: null,
            title: trans('Slack, Discord or a webhook'),
            description: trans('Not connected'),
        };
    }

    let host = '';

    try {
        host = url ? new URL(url).hostname : '';
    } catch {
        host = '';
    }

    if (host === 'hooks.slack.com') {
        return {
            brand: 'slack',
            title: 'Slack',
            description: trans('Posts to your Slack channel'),
        };
    }

    if (/(^|\.)discord(app)?\.com$/.test(host)) {
        return {
            brand: 'discord',
            title: 'Discord',
            description: trans('Posts to your Discord channel'),
        };
    }

    return {
        brand: null,
        title: trans('Webhook'),
        description: host
            ? trans('Posts to :host', { host })
            : trans('Posts to your webhook'),
    };
});

const mutedLine = computed(() =>
    props.alerts.muted.length === 0
        ? trans('Every kind of alert is on.')
        : trans(
              props.alerts.muted.length === 1
                  ? ':count kind is switched off.'
                  : ':count kinds are switched off.',
              { count: String(props.alerts.muted.length) },
          ),
);

function githubLine(account: {
    organisation: boolean;
    repositories: number | null;
}): string {
    const kind = account.organisation
        ? trans('Organisation')
        : trans('Personal account');
    const reach =
        account.repositories === null
            ? trans('all repositories')
            : account.repositories === 1
              ? trans('1 repository')
              : trans(':count repositories', {
                    count: String(account.repositories),
                });

    return `${kind} · ${reach}`;
}

function disconnectGitHub(id: number, account: string): void {
    if (
        !window.confirm(
            trans(
                'Disconnect :account? Its private repositories can’t be deployed until it’s connected again.',
                { account: String(account) },
            ),
        )
    ) {
        return;
    }

    router.delete(
        onWorkspace(githubRoutes.destroy({ workspace: slug, installation: id }))
            .url,
        { preserveScroll: true },
    );
}

const registryOpen = ref(false);
const registryForm = useForm({ host: '', username: '', password: '' });

watch(registryOpen, (open) => {
    if (!open) return;
    registryForm.reset();
    registryForm.clearErrors();
});

function addRegistry(): void {
    registryForm.post(onWorkspace(registryRoutes.store(slug)).url, {
        preserveScroll: true,
        onSuccess: () => (registryOpen.value = false),
    });
}

function removeRegistry(id: number, label: string): void {
    if (
        !window.confirm(
            trans(
                'Remove the login for :name? Deploys that pull private images from it will fail until it’s added again.',
                { name: label },
            ),
        )
    ) {
        return;
    }

    router.delete(
        onWorkspace(registryRoutes.destroy({ workspace: slug, registry: id }))
            .url,
        { preserveScroll: true },
    );
}

const confirmingDelete = ref(false);
const deleteForm = useForm({ confirmation: '' });

function deleteWorkspace(): void {
    if (deleteForm.processing) {
        return;
    }

    deleteForm.delete(onWorkspace(settings.destroy(slug)).url, {
        preserveScroll: true,
    });
}

watch(confirmingDelete, (open) => {
    if (!open) {
        deleteForm.reset();
        deleteForm.clearErrors();
    }
});

const fieldLabel =
    'text-[13px]/5 font-medium tracking-[-0.01em] text-fg-primary';
</script>

<template>
    <Head :title="$t('Workspace settings')" />

    <SettingsSection
        id="workspace"
        icon="settings-fill"
        :title="$t('Workspace')"
        :description="
            canManageSettings
                ? $t('How the workspace appears to everyone in it.')
                : $t(
                      'How the workspace appears to everyone in it. Only owners and admins can change these.',
                  )
        "
        :padded="false"
    >
        <form
            id="workspace-form"
            class="dash-card-row"
            @submit.prevent="saveName"
        >
            <label for="workspace-name" :class="fieldLabel">{{
                $t('Name')
            }}</label>
            <div class="dash-field">
                <input
                    id="workspace-name"
                    v-model="nameForm.name"
                    name="name"
                    required
                    maxlength="255"
                    :disabled="!canManageSettings"
                />
            </div>
            <InputError :message="nameForm.errors.name" />
        </form>

        <div class="dash-card-row">
            <label for="workspace-address" :class="fieldLabel">{{
                $t('Address')
            }}</label>
            <div
                class="dash-field bg-surface-field-readonly! font-ui-mono tracking-normal"
            >
                <span
                    id="workspace-address"
                    class="min-w-0 truncate"
                    role="textbox"
                    aria-readonly="true"
                    ><span class="text-fg-faint">{{ address.before }}</span
                    ><span class="text-fg-tertiary">{{ address.slug }}</span
                    ><span class="text-fg-faint">{{
                        address.after
                    }}</span></span
                >
            </div>
            <p
                class="text-[12.5px]/[18px] tracking-[-0.005em] text-fg-tertiary"
            >
                {{ $t('The address can’t be changed yet.') }}
            </p>
        </div>

        <SettingsItemRow
            :title="$t('Logo')"
            :description="
                $t('Shown in the sidebar, on your status page and in emails.')
            "
        >
            <template #lead>
                <span
                    class="flex size-9 shrink-0 items-center justify-center overflow-hidden rounded-[8px] text-[16px]/5 font-medium tracking-[-0.01em] text-white shadow-[0_1px_1.5px_rgb(0_0_0/0.5)]"
                    :class="
                        workspace.logoUrl
                            ? 'bg-surface-card'
                            : 'bg-linear-to-b from-[#3d6bff] to-[#2a4fd6] shadow-[0_1px_1.5px_rgb(0_0_0/0.5),inset_0_1px_0_rgb(255_255_255/0.22)]'
                    "
                    aria-hidden="true"
                >
                    <img
                        v-if="workspace.logoUrl"
                        :src="workspace.logoUrl"
                        alt=""
                        class="size-full object-cover"
                    />
                    <template v-else>{{
                        workspace.name.charAt(0).toUpperCase()
                    }}</template>
                </span>
            </template>
            <InputError :message="page.props.errors.logo" class="mt-1" />
            <template v-if="canManageSettings" #actions>
                <input
                    ref="logoInput"
                    type="file"
                    name="logo"
                    accept="image/png,image/jpeg,image/webp,image/gif"
                    class="sr-only"
                    tabindex="-1"
                    aria-hidden="true"
                    @change="uploadLogo"
                />
                <DashButton
                    :label="workspace.logoUrl ? $t('Change') : $t('Upload')"
                    :loading="uploadingLogo"
                    @click="logoInput?.click()"
                />
                <DashButton
                    v-if="workspace.logoUrl"
                    :label="$t('Remove')"
                    @click="removeLogo"
                />
            </template>
        </SettingsItemRow>

        <template v-if="canManageSettings" #footer>
            <DashButton
                variant="primary"
                type="submit"
                form="workspace-form"
                :label="$t('Save')"
                :loading="nameForm.processing"
            />
        </template>
    </SettingsSection>

    <SettingsSection
        v-if="canManageAlerts"
        id="alerts"
        icon="info-fill"
        :title="$t('Alerts')"
        :description="$t('Who hears about it when something breaks.')"
        :padded="false"
    >
        <SettingsItemRow
            :title="$t('Email')"
            :description="$t('To owners and admins')"
        >
            <template #icon>
                <Mail
                    class="size-[15px] text-fg-secondary"
                    :stroke-width="1.25"
                />
            </template>
            <template #actions>
                <DashSwitch
                    :model-value="emailAlerts"
                    :label="$t('Email alerts')"
                    :disabled="savingEmail"
                    @update:model-value="setEmailAlerts"
                />
            </template>
        </SettingsItemRow>

        <SettingsItemRow
            :title="webhook.title"
            :description="webhook.description"
        >
            <template #icon>
                <BrandLogo v-if="webhook.brand" :name="webhook.brand" />
                <FilledIcon v-else name="globe-fill" class="text-fg-tertiary" />
            </template>
            <template #actions>
                <DashButton
                    :label="alerts.webhookSet ? $t('Change') : $t('Connect')"
                    @click="alertsOpen = true"
                />
            </template>
        </SettingsItemRow>

        <SettingsItemRow
            title="Telegram"
            :description="
                alerts.telegram.set
                    ? alerts.telegram.chat
                        ? $t('Posts to chat :chat', {
                              chat: alerts.telegram.chat,
                          })
                        : $t('Connected')
                    : $t('Not connected')
            "
        >
            <template #icon>
                <BrandLogo name="telegram" />
            </template>
            <template #actions>
                <DashButton
                    :label="alerts.telegram.set ? $t('Change') : $t('Connect')"
                    @click="alertsOpen = true"
                />
            </template>
        </SettingsItemRow>

        <SettingsItemRow
            :title="$t('What you hear about')"
            :description="mutedLine"
        >
            <template #icon>
                <FilledIcon name="sliders" class="text-fg-tertiary" />
            </template>
            <template #actions>
                <DashButton :label="$t('Choose')" @click="alertsOpen = true" />
            </template>
        </SettingsItemRow>
    </SettingsSection>
    <AlertsDialog v-model:open="alertsOpen" :alerts="alerts" />

    <SettingsSection
        v-if="github"
        id="github"
        logo="github"
        title="GitHub"
        :description="
            $t('Deploy from your repositories and get pull request previews.')
        "
        :padded="false"
    >
        <template v-if="githubSetup && !githubSetup.ready">
            <SettingsItemRow
                :title="$t('No GitHub App yet')"
                :description="
                    githubSetup.canSetUp
                        ? $t(
                              'This install needs a GitHub App of its own before it can deploy private repositories. Setting one up takes about a minute, and we’ll walk you through it.',
                          )
                        : $t(
                              'This install doesn’t have a GitHub App yet. Ask the workspace owner to set one up in Developers › GitHub App. Public repositories deploy without it.',
                          )
                "
            >
                <template #icon>
                    <BrandLogo name="github" />
                </template>
                <template v-if="githubSetup.canSetUp" #actions>
                    <DashButton
                        :href="
                            onWorkspace(githubRoutes.app(workspace.slug)).url
                        "
                        :label="$t('Set up GitHub')"
                    />
                </template>
            </SettingsItemRow>
        </template>

        <template v-else>
            <SettingsItemRow
                v-for="account in github"
                :key="account.id"
                :title="account.account"
                :description="githubLine(account)"
            >
                <template #icon>
                    <BrandLogo name="github" />
                </template>
                <template #actions>
                    <DashButton
                        :href="account.manageUrl"
                        external
                        :label="$t('Choose repositories')"
                    />
                    <DashButton
                        :label="$t('Disconnect')"
                        @click="disconnectGitHub(account.id, account.account)"
                    />
                </template>
            </SettingsItemRow>
            <SettingsItemRow
                v-if="github.length === 0"
                :title="$t('Not connected')"
                :description="$t('Public repositories deploy without it.')"
            >
                <template #icon>
                    <BrandLogo name="github" />
                </template>
            </SettingsItemRow>
        </template>

        <template v-if="!githubSetup || githubSetup.ready" #footer>
            <div class="flex grow flex-wrap items-center gap-2">
                <a
                    :href="
                        onWorkspace(githubRoutes.connect(workspace.slug)).url
                    "
                    class="dash-button dash-button--secondary"
                >
                    {{
                        github.length
                            ? $t('Connect another account')
                            : $t('Connect GitHub')
                    }}
                </a>
                <Link
                    v-if="githubSetup?.canSetUp"
                    :href="onWorkspace(githubRoutes.app(workspace.slug)).url"
                    class="px-2 text-[13px]/5 font-medium text-fg-tertiary hover:text-fg-primary"
                >
                    {{ $t('GitHub App') }}
                </Link>
            </div>
        </template>
    </SettingsSection>

    <SettingsSection
        v-if="registries"
        id="registries"
        icon="package-fill"
        :title="$t('Container registries')"
        :description="
            $t('Logins for private images. Public images deploy without one.')
        "
        :padded="false"
    >
        <SettingsItemRow
            v-for="registry in registries"
            :key="registry.id"
            :title="registry.host"
            :description="$t('As :username', { username: registry.username })"
        >
            <template #icon>
                <BrandLogo :name="`${registry.label} ${registry.host}`" />
            </template>
            <template #actions>
                <DashButton
                    :label="$t('Remove')"
                    @click="removeRegistry(registry.id, registry.label)"
                />
            </template>
        </SettingsItemRow>
        <SettingsItemRow
            v-if="registries.length === 0"
            :title="$t('None yet')"
            :description="$t('Public images deploy without a login.')"
        >
            <template #icon>
                <FilledIcon name="box" class="text-fg-tertiary" />
            </template>
        </SettingsItemRow>

        <template #footer>
            <div class="flex grow items-center">
                <DashButton
                    :label="$t('Add a registry login')"
                    @click="registryOpen = true"
                />
            </div>
        </template>
    </SettingsSection>

    <PanelUpdateTile v-if="panelUpdate" :state="panelUpdate" />

    <SettingsSection
        v-if="canDelete"
        id="danger-zone"
        icon="info-fill"
        :title="$t('Danger zone')"
        :description="$t('Actions here can’t be undone.')"
        :padded="false"
        danger
    >
        <SettingsItemRow
            :title="$t('Delete workspace')"
            :description="
                $t(
                    'Removes :name, its machine connections, monitors and backup settings. Your servers keep running.',
                    { name: workspace.name },
                )
            "
        >
            <template #actions>
                <DashButton @click="confirmingDelete = true">
                    <span class="text-status-down">{{
                        $t('Delete workspace')
                    }}</span>
                </DashButton>
            </template>
        </SettingsItemRow>
    </SettingsSection>

    <Dialog v-model:open="registryOpen">
        <DialogContent
            class="workspace-panel ds-modal font-ui text-foreground sm:max-w-md"
        >
            <DialogHeader>
                <DialogTitle>{{ $t('Add a registry login') }}</DialogTitle>
                <DialogDescription>
                    {{
                        $t(
                            'For Docker Hub, GitHub’s registry (ghcr.io) or your own. Use a token that can only read images. It’s sent to a machine only while it deploys, and never shown again.',
                        )
                    }}
                </DialogDescription>
            </DialogHeader>
            <form class="flex flex-col gap-4" @submit.prevent="addRegistry">
                <div class="grid gap-2">
                    <label for="registry-host" class="text-[13px]/5">{{
                        $t('Registry')
                    }}</label>
                    <Input
                        id="registry-host"
                        v-model="registryForm.host"
                        :placeholder="
                            $t('docker.io, ghcr.io or registry.example.com')
                        "
                        autocomplete="off"
                        autocapitalize="off"
                        spellcheck="false"
                        class="font-ui-mono"
                    />
                    <InputError :message="registryForm.errors.host" />
                </div>
                <div class="grid gap-2">
                    <label for="registry-username" class="text-[13px]/5">{{
                        $t('Username')
                    }}</label>
                    <Input
                        id="registry-username"
                        v-model="registryForm.username"
                        autocomplete="off"
                        autocapitalize="off"
                        spellcheck="false"
                    />
                    <InputError :message="registryForm.errors.username" />
                </div>
                <div class="grid gap-2">
                    <label for="registry-password" class="text-[13px]/5">{{
                        $t('Password or token')
                    }}</label>
                    <Input
                        id="registry-password"
                        v-model="registryForm.password"
                        type="password"
                        autocomplete="new-password"
                    />
                    <InputError :message="registryForm.errors.password" />
                </div>
                <div class="flex justify-end gap-2">
                    <AppButton size="sm" @click="registryOpen = false">
                        {{ ' ' + $t('Cancel') + ' ' }}
                    </AppButton>
                    <SubmitButton
                        :label="$t('Save')"
                        :busy-label="$t('Saving…')"
                        :processing="registryForm.processing"
                    />
                </div>
            </form>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="confirmingDelete">
        <DialogContent
            class="workspace-panel ds-modal font-ui text-foreground sm:max-w-md"
        >
            <DialogHeader>
                <DialogTitle>{{
                    $t('Delete :name?', { name: workspace.name })
                }}</DialogTitle>
                <DialogDescription>
                    {{
                        $t(
                            "This permanently deletes the workspace, its machines, members and invitations. It can't be undone.",
                        )
                    }}
                </DialogDescription>
            </DialogHeader>
            <form class="flex flex-col gap-4" @submit.prevent="deleteWorkspace">
                <div class="grid gap-2">
                    <label for="delete-confirmation" class="text-[13px]/5">
                        {{ ' ' + $t('Type') }}
                        <span class="font-ui-mono text-(--ds-text)">{{
                            workspace.slug
                        }}</span>
                        {{ $t('to confirm.') + ' ' }}
                    </label>
                    <Input
                        id="delete-confirmation"
                        v-model="deleteForm.confirmation"
                        autocomplete="off"
                        autocapitalize="off"
                        spellcheck="false"
                        class="font-ui-mono"
                    />
                    <InputError :message="deleteForm.errors.confirmation" />
                </div>
                <div class="flex justify-end gap-2">
                    <AppButton size="sm" @click="confirmingDelete = false">
                        {{ ' ' + $t('Cancel') + ' ' }}
                    </AppButton>
                    <DangerButton
                        :label="$t('Delete workspace')"
                        :processing="deleteForm.processing"
                        :disabled="
                            !deleteForm.processing &&
                            deleteForm.confirmation !== workspace.slug
                        "
                        :error="deleteForm.errors.confirmation"
                    />
                </div>
            </form>
        </DialogContent>
    </Dialog>
</template>
