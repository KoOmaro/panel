<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { ArrowUpRight, Check } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed, nextTick, ref, useTemplateRef } from 'vue';
import BrandLogo from '@/components/dash/BrandLogo.vue';
import DashButton from '@/components/dash/DashButton.vue';
import DashSwitch from '@/components/dash/DashSwitch.vue';
import StatusBadge from '@/components/dash/StatusBadge.vue';
import type { DashStatus } from '@/components/dash/StatusBadge.vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import type { Machine } from '@/components/machines/machines';
import FieldSelect from '@/components/machines/settings/FieldSelect.vue';
import type { FieldOption } from '@/components/machines/settings/FieldSelect.vue';
import DashTray from '@/components/dash/DashTray.vue';
import SettingsRow from '@/components/machines/settings/SettingsRow.vue';
import EmbedOptions from '@/components/status/EmbedOptions.vue';
import { useUpgradeLink } from '@/composables/useUpgradeLink';
import { SolarCopy as Copy } from '@/lib/solarIcons';
import { onWorkspace } from '@/lib/workspaceRoute';
import machinesRoutes from '@/routes/workspace/machines';
import monitorsRoutes from '@/routes/workspace/monitors';

export type StatusService = {
    key: string;
    name: string;
    kind: string;
    image: string | null;
    status: string;
    shown: boolean;
    quiet: boolean;
    label: string | null;
    suggested: string;
};

export type StatusMonitor = {
    key: string;
    id: number;
    name: string;
    target: string;
    status: 'pending' | 'up' | 'down';
    shown: boolean;
    quiet: boolean;
    label: string | null;
};

export type StatusPageProps = {
    exists: boolean;
    enabled: boolean;
    public: boolean;
    slug: string;
    title: string | null;
    pageTitle: string | null;
    iconUrl: string | null;
    faviconUrl: string | null;
    description: string | null;
    includeNew: boolean;
    showUptime: boolean;
    notice: string | null;
    noticeKind: 'info' | 'maintenance' | 'incident';
    url: string;
    badgeUrl: string;
    jsonUrl: string;
    subscribers: number;
    services: StatusService[];
    monitors: StatusMonitor[];
};

const props = defineProps<{
    machine: Machine;
    workspaceSlug: string;
    statusPage: StatusPageProps;
    planAllows: boolean;
    canManage: boolean;
}>();

const emit = defineEmits<{ back: []; saved: [] }>();

const upgrade = useUpgradeLink();

const args = computed(() => ({
    workspace: props.workspaceSlug,
    machine: props.machine.id!,
}));

const editable = computed(() => props.canManage && props.planAllows);

const form = useForm({
    enabled: props.statusPage.enabled,
    public: props.statusPage.public,
    slug: props.statusPage.slug,
    title: props.statusPage.title ?? '',
    page_title: props.statusPage.pageTitle ?? '',
    description: props.statusPage.description ?? '',
    include_new: props.statusPage.includeNew,
    show_uptime: props.statusPage.showUptime,
    notice: props.statusPage.notice ?? '',
    notice_kind: props.statusPage.noticeKind,
    services: props.statusPage.services.map((service) => ({
        key: service.key,
        shown: service.shown,
        quiet: service.quiet,
        label: service.label ?? '',
    })),
    monitors: props.statusPage.monitors.map((monitor) => ({
        key: monitor.key,
        shown: monitor.shown,
        quiet: monitor.quiet,
        label: monitor.label ?? '',
    })),
});

form.transform(({ monitors, services, ...data }) => ({
    ...data,
    services: [...services, ...monitors],
}));

let queued = false;

async function save(): Promise<void> {
    await nextTick();
    if (!editable.value || !form.isDirty) return;
    if (form.processing) {
        queued = true;

        return;
    }
    const sent = JSON.parse(JSON.stringify(form.data()));
    form.put(onWorkspace(machinesRoutes.statusPage.update(args.value)).url, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            form.defaults(sent);
            emit('saved');
        },
        onFinish: () => {
            if (queued) {
                queued = false;
                save();
            }
        },
    });
}

function set<K extends 'enabled' | 'public' | 'include_new' | 'show_uptime'>(
    key: K,
    value: boolean,
): void {
    form[key] = value;
    save();
}

function blurOnEnter(event: KeyboardEvent): void {
    (event.target as HTMLInputElement).blur();
}

const live = computed(
    () => props.statusPage.exists && props.statusPage.enabled,
);

const host = computed(() => {
    try {
        return new URL(props.statusPage.url).host;
    } catch {
        return '';
    }
});

const liveUrl = computed(() =>
    props.statusPage.url.replace(/[^/]+$/, form.slug),
);

function tidySlug(): void {
    form.slug = form.slug
        .toLowerCase()
        .replace(/[^a-z0-9-]+/g, '-')
        .replace(/-+/g, '-')
        .replace(/^-|-$/g, '');
    save();
}

const copied = ref(false);

async function copyAddress(): Promise<void> {
    try {
        await navigator.clipboard.writeText(liveUrl.value);
        copied.value = true;
        setTimeout(() => (copied.value = false), 1600);
    } catch {}
}

const visibilityOptions = computed<FieldOption<'public' | 'members'>[]>(() => [
    {
        value: 'public',
        label: trans('Public'),
        hint: trans('Anyone with the link, no sign-in'),
    },
    {
        value: 'members',
        label: trans('Workspace only'),
        hint: trans('Only members of this workspace, signed in'),
    },
]);

const noticeOptions = computed<
    FieldOption<'info' | 'maintenance' | 'incident'>[]
>(() => [
    { value: 'info', label: trans('Notice') },
    { value: 'maintenance', label: trans('Maintenance') },
    { value: 'incident', label: trans('Incident') },
]);

type Visibility = 'off' | 'on' | 'problems';

const showOptions = computed<FieldOption<Visibility>[]>(() => [
    { value: 'on', label: trans('Shown') },
    {
        value: 'problems',
        label: trans('Problems'),
        hint: trans('Only while it isn’t working'),
    },
    { value: 'off', label: trans('Hidden') },
]);

function visibilityOf(item: { shown: boolean; quiet: boolean }): Visibility {
    return !item.shown ? 'off' : item.quiet ? 'problems' : 'on';
}

function setVisibility(
    item: { shown: boolean; quiet: boolean },
    value: Visibility,
): void {
    item.shown = value !== 'off';
    item.quiet = value === 'problems';
    save();
}

const shownCount = computed(
    () => form.services.filter((service) => service.shown).length,
);

function setAll(shown: boolean): void {
    form.services.forEach((service) => (service.shown = shown));
    save();
}

const online = computed(
    () =>
        props.machine.connection === 'online' ||
        props.machine.connection === 'degraded',
);

function serviceStatus(status: string): { status: DashStatus; label: string } {
    if (!online.value) return { status: 'stopped', label: trans('Unknown') };
    if (status === 'running') return { status: 'live', label: trans('Up') };
    if (status === 'restarting')
        return { status: 'pending', label: trans('Restarting') };

    return { status: 'down', label: trans('Down') };
}

function monitorStatus(status: StatusMonitor['status']): {
    status: DashStatus;
    label: string;
} {
    if (status === 'up') return { status: 'live', label: trans('Up') };
    if (status === 'down') return { status: 'down', label: trans('Down') };

    return { status: 'stopped', label: trans('Pending') };
}

const monitorsUrl = computed(
    () => onWorkspace(monitorsRoutes.index(props.workspaceSlug)).url,
);

type ImageKind = 'icon' | 'favicon';
const iconInput = useTemplateRef<HTMLInputElement>('iconInput');
const faviconInput = useTemplateRef<HTMLInputElement>('faviconInput');
const imageBusy = ref<ImageKind | null>(null);
const imageError = ref<Partial<Record<ImageKind, string>>>({});

function imageUrl(kind: ImageKind): string {
    return onWorkspace(
        machinesRoutes.statusPage.image.update({ ...args.value, kind }),
    ).url;
}

function upload(kind: ImageKind, event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    if (!file) return;
    router.post(
        imageUrl(kind),
        { [kind]: file },
        {
            forceFormData: true,
            preserveScroll: true,
            preserveState: true,
            onStart: () => {
                imageBusy.value = kind;
                imageError.value = { ...imageError.value, [kind]: undefined };
            },
            onError: (bag) =>
                (imageError.value = { ...imageError.value, [kind]: bag[kind] }),
            onFinish: () => {
                imageBusy.value = null;
                input.value = '';
            },
        },
    );
}

function removeImage(kind: ImageKind): void {
    router.delete(
        onWorkspace(
            machinesRoutes.statusPage.image.destroy({ ...args.value, kind }),
        ).url,
        {
            preserveScroll: true,
            preserveState: true,
            onStart: () => (imageBusy.value = kind),
            onFinish: () => (imageBusy.value = null),
        },
    );
}

const sectionTitle =
    'text-[13px]/5 font-medium tracking-[-0.005em] text-fg-primary';
const rowLabel =
    'min-w-0 flex-1 text-[13px]/5 tracking-[-0.005em] text-fg-muted';
const rowValue =
    'text-[13px]/5 tracking-[-0.005em] whitespace-nowrap text-fg-secondary';
</script>

<template>
    <div class="flex min-w-0 flex-1 flex-col gap-3 px-6 py-4">
        <input
            ref="iconInput"
            type="file"
            accept="image/png,image/jpeg,image/webp,image/gif"
            class="sr-only"
            tabindex="-1"
            aria-hidden="true"
            @change="(event) => upload('icon', event)"
        />
        <input
            ref="faviconInput"
            type="file"
            accept="image/png,image/x-icon,image/vnd.microsoft.icon,image/jpeg,image/webp,image/gif"
            class="sr-only"
            tabindex="-1"
            aria-hidden="true"
            @change="(event) => upload('favicon', event)"
        />
        <div
            v-if="!planAllows"
            class="flex flex-wrap items-center justify-between gap-3 rounded-[10px] border-[0.5px] border-edge-strong bg-surface-card px-[18px] py-3 shadow-card"
        >
            <p class="text-[13px]/5 text-fg-secondary">
                {{ $t('Status pages are part of Pro and Scale.') }}
            </p>
            <DashButton
                v-if="upgrade"
                size="sm"
                variant="primary"
                :href="upgrade.href"
                :label="$t('See plans')"
            />
        </div>

        <DashTray
            panel-class="gap-1 px-[18px] pt-3 pb-1"
            icon="monitors-fill"
            :title="$t('Status page')"
        >
            <SettingsRow
                :label="$t('Turned on')"
                :hint="
                    live
                        ? $t('Live at :host/status/:slug', {
                              host,
                              slug: statusPage.slug,
                          })
                        : $t('Off. Nobody can open it until you turn it on.')
                "
                :error="form.errors.enabled"
            >
                <DashSwitch
                    :model-value="form.enabled"
                    :label="$t('Status page on')"
                    :disabled="!editable"
                    @update:model-value="(on) => set('enabled', on)"
                />
            </SettingsRow>
            <SettingsRow :label="$t('Visibility')">
                <FieldSelect
                    :model-value="form.public ? 'public' : 'members'"
                    :options="visibilityOptions"
                    :label="$t('Visibility')"
                    :disabled="!editable"
                    @update:model-value="
                        (value) => set('public', value === 'public')
                    "
                />
            </SettingsRow>
            <SettingsRow
                :label="$t('Address')"
                label-for="status-slug"
                :error="form.errors.slug"
            >
                <div
                    class="dash-field w-[320px] max-w-full gap-0 font-ui-mono text-[12px]/4"
                >
                    <span class="shrink-0 truncate text-fg-faint"
                        >{{ host }}/status/</span
                    >
                    <input
                        id="status-slug"
                        v-model="form.slug"
                        maxlength="60"
                        spellcheck="false"
                        autocomplete="off"
                        :disabled="!editable"
                        @blur="tidySlug"
                        @keydown.enter.prevent="blurOnEnter"
                    />
                    <button
                        type="button"
                        class="ml-1 flex size-5 shrink-0 cursor-pointer items-center justify-center rounded-[4px] text-fg-muted hover:text-fg-primary"
                        :aria-label="$t('Copy the address')"
                        @click="copyAddress"
                    >
                        <Check
                            v-if="copied"
                            class="size-3.5 text-status-live"
                            :stroke-width="2.25"
                        />
                        <Copy v-else class="size-3.5" :stroke-width="1.75" />
                    </button>
                </div>
            </SettingsRow>
            <SettingsRow
                :label="$t('Name')"
                :hint="$t('At the top of the page')"
                label-for="status-title"
                :error="form.errors.title"
            >
                <div class="dash-field w-[232px] max-w-full">
                    <input
                        id="status-title"
                        v-model="form.title"
                        maxlength="80"
                        :placeholder="machine.name"
                        :disabled="!editable"
                        @blur="save"
                        @keydown.enter.prevent="blurOnEnter"
                    />
                </div>
            </SettingsRow>
            <SettingsRow
                :label="$t('Page title')"
                :hint="$t('In the browser tab and link previews')"
                label-for="status-page-title"
                :error="form.errors.page_title"
            >
                <div class="dash-field w-[232px] max-w-full">
                    <input
                        id="status-page-title"
                        v-model="form.page_title"
                        maxlength="120"
                        :placeholder="form.title || machine.name"
                        :disabled="!editable"
                        @blur="save"
                        @keydown.enter.prevent="blurOnEnter"
                    />
                </div>
            </SettingsRow>
            <SettingsRow
                :label="$t('Description')"
                :hint="$t('A line under the name')"
                label-for="status-description"
                :error="form.errors.description"
            >
                <div class="dash-field w-[320px] max-w-full">
                    <input
                        id="status-description"
                        v-model="form.description"
                        maxlength="280"
                        :placeholder="$t('Everything behind app.example.com')"
                        :disabled="!editable"
                        @blur="save"
                        @keydown.enter.prevent="blurOnEnter"
                    />
                </div>
            </SettingsRow>
            <SettingsRow
                v-for="kind in ['icon', 'favicon'] as const"
                :key="kind"
                :label="kind === 'icon' ? $t('Icon') : $t('Favicon')"
                :hint="
                    kind === 'icon'
                        ? $t('Beside the name. Defaults to the workspace logo.')
                        : $t('A square PNG or ICO, up to 1 MB')
                "
                :error="imageError[kind]"
            >
                <span
                    class="flex size-7 shrink-0 items-center justify-center overflow-hidden rounded-[7px] border-[0.5px] border-[#d4d4d4] bg-[#f2f2f2] shadow-card dark:border-[#383838] dark:bg-[#242424]"
                >
                    <img
                        v-if="
                            kind === 'icon'
                                ? statusPage.iconUrl
                                : statusPage.faviconUrl
                        "
                        :src="
                            (kind === 'icon'
                                ? statusPage.iconUrl
                                : statusPage.faviconUrl) ?? undefined
                        "
                        alt=""
                        class="size-full object-cover"
                    />
                    <FilledIcon
                        v-else
                        name="globe-fill"
                        :size="14"
                        class="text-fg-faint"
                    />
                </span>
                <template v-if="editable">
                    <DashButton
                        v-if="
                            kind === 'icon'
                                ? statusPage.iconUrl
                                : statusPage.faviconUrl
                        "
                        size="sm"
                        :label="$t('Remove')"
                        :disabled="imageBusy !== null"
                        @click="removeImage(kind)"
                    />
                    <DashButton
                        size="sm"
                        :label="
                            (
                                kind === 'icon'
                                    ? statusPage.iconUrl
                                    : statusPage.faviconUrl
                            )
                                ? $t('Change')
                                : $t('Upload')
                        "
                        :loading="imageBusy === kind"
                        @click="
                            (kind === 'icon'
                                ? iconInput
                                : faviconInput
                            )?.click()
                        "
                    />
                </template>
            </SettingsRow>
        </DashTray>

        <DashTray
            panel-class="gap-1 px-[18px] pt-3 pb-1"
            icon="info-fill"
            :title="$t('Notice')"
        >
            <SettingsRow
                :label="$t('Kind')"
                :hint="$t('Shown at the top of the page until you clear it')"
            >
                <FieldSelect
                    :model-value="form.notice_kind"
                    :options="noticeOptions"
                    :label="$t('Kind of notice')"
                    :disabled="!editable"
                    @update:model-value="
                        (value) => {
                            form.notice_kind = value;
                            save();
                        }
                    "
                />
            </SettingsRow>
            <SettingsRow stack :error="form.errors.notice">
                <template #label>
                    <label for="status-notice" class="sr-only">{{
                        $t('Notice')
                    }}</label>
                </template>
                <div class="dash-field h-auto w-full py-1.5">
                    <textarea
                        id="status-notice"
                        v-model="form.notice"
                        rows="2"
                        maxlength="500"
                        class="resize-none"
                        :placeholder="
                            $t('Database upgrade on Sunday 02:00 to 02:30 UTC.')
                        "
                        :disabled="!editable"
                        @blur="save"
                    />
                </div>
            </SettingsRow>
        </DashTray>

        <DashTray
            panel-class="gap-1 px-[18px] pt-3 pb-1"
            icon="layers-fill"
            :title="$t('On the page')"
        >
            <SettingsRow
                :label="$t('New services')"
                :hint="
                    $t(
                        'Anything deployed or monitored later joins the page on its own',
                    )
                "
            >
                <DashSwitch
                    :model-value="form.include_new"
                    :label="$t('Add new services automatically')"
                    :disabled="!editable"
                    @update:model-value="(on) => set('include_new', on)"
                />
            </SettingsRow>
            <SettingsRow
                :label="$t('Uptime history')"
                :hint="
                    $t(
                        '90 days of bars under each service, counted while the page is on',
                    )
                "
            >
                <DashSwitch
                    :model-value="form.show_uptime"
                    :label="$t('Show uptime history')"
                    :disabled="!editable"
                    @update:model-value="(on) => set('show_uptime', on)"
                />
            </SettingsRow>
        </DashTray>

        <DashTray
            panel-class="gap-1 px-[18px] pt-3 pb-1"
            icon="activity-fill"
            :title="$t('Monitors on the page')"
        >
            <template #actions>
                <Link
                    :href="monitorsUrl"
                    class="text-[12px]/4 text-fg-muted underline underline-offset-2 hover:text-fg-secondary"
                    >{{ $t('Manage monitors') }}</Link
                >
            </template>
            <SettingsRow
                v-if="form.monitors.length === 0"
                :label="$t('No monitors yet')"
                :hint="
                    $t(
                        'Monitors checked from outside show real response times and any downtime here. Add one and set “Runs on” to :name.',
                        { name: machine.name },
                    )
                "
            />
            <SettingsRow
                v-for="(monitor, index) in form.monitors"
                :key="monitor.key"
            >
                <template #label>
                    <span
                        class="truncate text-[13px]/5 tracking-[-0.005em]"
                        :class="
                            monitor.shown ? 'text-fg-primary' : 'text-fg-faint'
                        "
                        >{{ statusPage.monitors[index].name }}</span
                    >
                    <span
                        class="truncate font-ui-mono text-[11.5px]/4 text-fg-muted"
                        :title="statusPage.monitors[index].target"
                        >{{ statusPage.monitors[index].target }}</span
                    >
                </template>
                <div class="dash-field hidden w-44 md:flex">
                    <input
                        v-model="monitor.label"
                        maxlength="60"
                        :placeholder="statusPage.monitors[index].name"
                        :aria-label="
                            $t('Name on the page for :value', {
                                value: statusPage.monitors[index].name,
                            })
                        "
                        :disabled="!editable || !monitor.shown"
                        @blur="save"
                        @keydown.enter.prevent="blurOnEnter"
                    />
                </div>
                <StatusBadge
                    v-bind="monitorStatus(statusPage.monitors[index].status)"
                />
                <FieldSelect
                    :model-value="visibilityOf(monitor)"
                    :options="showOptions"
                    :width="120"
                    :label="
                        $t('Show :value', {
                            value: statusPage.monitors[index].name,
                        })
                    "
                    :disabled="!editable"
                    @update:model-value="
                        (value) => setVisibility(monitor, value)
                    "
                />
            </SettingsRow>
        </DashTray>

        <DashTray
            panel-class="gap-1 px-[18px] pt-3 pb-1"
            icon="package-fill"
            :title="$t('Services on the page')"
        >
            <template v-if="editable && form.services.length" #actions>
                <button
                    type="button"
                    class="cursor-pointer text-[12px]/4 text-fg-muted underline underline-offset-2 hover:text-fg-secondary"
                    @click="setAll(shownCount < form.services.length)"
                >
                    {{
                        shownCount < form.services.length
                            ? $t('Show all')
                            : $t('Hide all')
                    }}
                </button>
            </template>
            <SettingsRow
                v-if="form.services.length === 0"
                :label="$t('No services found yet')"
                :hint="$t('They appear here after the machine’s next scan.')"
            />
            <SettingsRow
                v-for="(service, index) in form.services"
                :key="service.key"
            >
                <template #label>
                    <span class="flex min-w-0 items-center gap-2">
                        <BrandLogo
                            :name="`${statusPage.services[index].name} ${statusPage.services[index].image ?? ''}`"
                        />
                        <span
                            class="truncate text-[13px]/5 tracking-[-0.005em]"
                            :class="
                                service.shown
                                    ? 'text-fg-primary'
                                    : 'text-fg-faint'
                            "
                            :title="statusPage.services[index].name"
                            >{{ statusPage.services[index].name }}</span
                        >
                    </span>
                </template>
                <div class="dash-field hidden w-44 md:flex">
                    <input
                        v-model="service.label"
                        maxlength="60"
                        :placeholder="statusPage.services[index].suggested"
                        :aria-label="
                            $t('Name on the page for :value', {
                                value: statusPage.services[index].name,
                            })
                        "
                        :disabled="!editable || !service.shown"
                        @blur="save"
                        @keydown.enter.prevent="blurOnEnter"
                    />
                </div>
                <StatusBadge
                    v-bind="serviceStatus(statusPage.services[index].status)"
                />
                <FieldSelect
                    :model-value="visibilityOf(service)"
                    :options="showOptions"
                    :width="120"
                    :label="
                        $t('Show :value', {
                            value: statusPage.services[index].name,
                        })
                    "
                    :disabled="!editable"
                    @update:model-value="
                        (value) => setVisibility(service, value)
                    "
                />
            </SettingsRow>
        </DashTray>

        <DashTray
            panel-class="gap-1 px-[18px] pt-3 pb-1"
            v-if="live && statusPage.public"
            icon="code-fill"
            :title="$t('Embed')"
        >
            <div class="pt-1 pb-3.5">
                <EmbedOptions
                    :url="statusPage.url"
                    :title="statusPage.title || machine.name"
                />
            </div>
        </DashTray>
    </div>

    <aside
        class="flex w-full shrink-0 flex-col gap-[22px] border-edge-tabs p-5 max-xl:border-t-[0.5px] xl:w-[316px] xl:border-l-[0.5px]"
    >
        <button
            type="button"
            class="flex w-fit cursor-pointer items-center gap-1.5 text-[13px]/5 tracking-[-0.005em] text-fg-secondary hover:text-fg-primary"
            @click="emit('back')"
        >
            <FilledIcon
                name="chevron-right"
                :size="14"
                class="rotate-180 text-fg-muted"
            />
            {{ $t('All settings') }}
        </button>
        <section class="flex flex-col gap-2.5" :aria-label="$t('Status page')">
            <h2 :class="sectionTitle">{{ $t('Status page') }}</h2>
            <div class="flex items-center gap-2">
                <span :class="rowLabel">{{ $t('State') }}</span>
                <span
                    class="size-1.5 shrink-0 rounded-full"
                    :class="live ? 'bg-status-live' : 'bg-fg-faint'"
                    aria-hidden="true"
                />
                <span :class="rowValue">{{
                    live ? $t('Live') : $t('Off')
                }}</span>
            </div>
            <div class="flex items-center gap-2">
                <span :class="rowLabel">{{ $t('Visibility') }}</span>
                <span :class="rowValue">{{
                    statusPage.public ? $t('Public') : $t('Workspace only')
                }}</span>
            </div>
            <div
                v-if="live && statusPage.public"
                class="flex items-center gap-2"
            >
                <span :class="rowLabel">{{ $t('Subscribers') }}</span>
                <span :class="rowValue">{{
                    statusPage.subscribers.toLocaleString()
                }}</span>
            </div>
            <p
                v-if="live && statusPage.public"
                class="text-[12px]/4 text-fg-muted"
            >
                {{
                    $t(
                        'Visitors can ask for an email when something goes wrong and when it’s fixed.',
                    )
                }}
            </p>
            <DashButton
                v-if="live"
                :href="statusPage.url"
                external
                size="sm"
                :icon="ArrowUpRight"
                :label="$t('View page')"
                class="w-fit"
            />
        </section>
    </aside>
</template>
