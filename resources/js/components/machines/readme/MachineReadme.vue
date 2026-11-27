<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import BrandLogo from '@/components/dash/BrandLogo.vue';
import DashButton from '@/components/dash/DashButton.vue';
import DashTray from '@/components/dash/DashTray.vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import { bytesLabel } from '@/components/machines/machineFormat';
import ArrowUpCircleFill from '@/components/machines/readme/ArrowUpCircleFill.vue';
import ReadmeText from '@/components/machines/readme/ReadmeText.vue';
import ReadmeWiring from '@/components/machines/readme/ReadmeWiring.vue';
import type {
    MachineReadmeData,
    ReadmeRisk,
    ReadmeService,
} from '@/components/machines/readme/readme';
import { useAssistant } from '@/composables/useAssistant';
import { timeAgo } from '@/lib/timeAgo';
import { toast } from '@/lib/toast';
import { onWorkspace } from '@/lib/workspaceRoute';
import codeRoutes from '@/routes/workspace/code';
import readmeRoutes from '@/routes/workspace/machines/readme';

const props = defineProps<{
    readme: MachineReadmeData | undefined;
    machineId: number;
    machineName: string;
    workspaceSlug: string;
    canManage: boolean;
}>();

const emit = defineEmits<{ updates: [] }>();

const SECTIONS = [
    { id: 'readme-wiring', label: () => trans('How it’s wired') },
    { id: 'readme-services', label: () => trans('What runs here') },
    { id: 'readme-data', label: () => trans('Where data lives') },
    { id: 'readme-risks', label: () => trans('What’s risky') },
    { id: 'readme-good', label: () => trans('Looks good') },
] as const;

const facts = computed(() => props.readme?.facts ?? null);
const words = computed(() => props.readme?.words ?? null);
const writing = computed(() => props.readme?.writing ?? false);
const requested = ref(false);
const committing = ref(false);
const question = ref('');
const active = ref<string>(SECTIONS[0].id);
const now = ref(Date.now());

const label = computed(() => {
    const machine = facts.value?.machine;
    if (!machine) return props.machineName;

    return [
        machine.name,
        [machine.provider, machine.plan].filter(Boolean).join(' '),
    ]
        .filter(Boolean)
        .join(' · ');
});

const written = computed(() => {
    void now.value;
    if (writing.value)
        return trans('ServerOS is writing this from what’s running now');
    const at = props.readme?.generatedAt;

    return at
        ? trans('Written by ServerOS from what’s running now · updated :ago', {
              ago: timeAgo(at),
          })
        : trans('Written by ServerOS from what’s running now');
});

const about = computed(() => {
    const name = props.machineName;
    if (writing.value)
        return trans(
            'ServerOS is reading :name’s processes, ports, config files and disks, and writing this in plain English.',
            { name },
        );
    switch (props.readme?.reason) {
        case 'no_model':
            return trans(
                'ServerOS read :name’s processes, ports, config files and disks. There’s no AI model on this panel, so it wrote the words itself. Add a key in Settings › AI and MCP for plainer English.',
                { name },
            );
        case 'quota':
            return trans(
                'ServerOS read :name’s processes, ports, config files and disks. This hour’s Ask AI messages were used up, so it wrote the words itself. It tries again when something changes.',
                { name },
            );
        case 'error':
            return trans(
                'ServerOS read :name’s processes, ports, config files and disks, but couldn’t get an answer from the AI model, so it wrote the words itself.',
                { name },
            );
        case 'rejected':
            return trans(
                'ServerOS read :name’s processes, ports, config files and disks. The AI model’s answer didn’t match the facts, so ServerOS wrote the words itself.',
                { name },
            );
        case 'slow':
            return trans(
                'ServerOS read :name’s processes, ports, config files and disks. The AI model is taking a while, so these are ServerOS’s own words for now.',
                { name },
            );
        default:
            return trans(
                'ServerOS read :name’s processes, ports, config files and disks, and wrote this in plain English. It updates itself when something changes.',
                { name },
            );
    }
});

const services = computed<ReadmeService[]>(() => facts.value?.services ?? []);

const howLabels: Record<string, () => string> = {
    system: () => trans('System'),
    docker: () => trans('Docker'),
    pm2: () => 'PM2',
    supervisor: () => 'Supervisor',
    screen: () => 'screen',
    cron: () => 'cron',
    manual: () => trans('By hand'),
    other: () => trans('Other'),
};

function how(service: ReadmeService): string {
    if (service.how.startsWith('site:')) {
        const server = service.how.slice(5);

        return server === 'apache'
            ? 'Apache'
            : server === 'caddy'
              ? 'Caddy'
              : server === 'traefik'
                ? 'Traefik'
                : server;
    }

    return (howLabels[service.how] ?? howLabels.other)();
}

const risks = computed<ReadmeRisk[]>(() => facts.value?.risks ?? []);
const riskMeta = computed(() => {
    const urgent = risks.value.filter(
        (risk) => risk.severity === 'urgent',
    ).length;
    const rest = risks.value.length - urgent;
    const parts = [];
    if (urgent) parts.push(trans(':count urgent', { count: String(urgent) }));
    if (rest) parts.push(trans(':count to fix', { count: String(rest) }));

    return parts.join(', ');
});

const riskDot: Record<ReadmeRisk['severity'], string> = {
    urgent: 'bg-status-down',
    fix: 'bg-[#f5a833]',
    notice: 'bg-[#737373]',
};

const dataDot: Record<string, string> = {
    good: 'bg-status-live',
    bad: 'bg-status-down',
    warn: 'bg-[#f5a833]',
    none: 'bg-[#737373]',
};

const placeholder = computed(() => {
    const f = facts.value;
    if (!f) return trans('What runs on :name?', { name: props.machineName });
    const dep = f.wiring.deps[0];
    const app = dep
        ? f.wiring.apps.find((item) => dep.from.includes(item.id))
        : undefined;
    if (dep && app)
        return trans('Why does :app need :dep?', {
            app: app.name,
            dep: dep.name,
        });
    const first = f.services.find((service) => service.kind !== 'site');
    if (first) return trans('What does :name do here?', { name: first.name });

    return trans('What runs on :name?', { name: props.machineName });
});

const assistant = useAssistant();

function ask(): void {
    const text = question.value.trim() || placeholder.value;
    question.value = '';
    void assistant.askAbout(text);
}

function askRisk(risk: ReadmeRisk): void {
    if (risk.ask) void assistant.askAbout(risk.ask);
}

function writeAgain(): void {
    if (requested.value) return;
    requested.value = true;
    router.post(
        onWorkspace(
            readmeRoutes.write({
                workspace: props.workspaceSlug,
                machine: props.machineId,
            }),
        ).url,
        {},
        {
            preserveScroll: true,
            preserveState: true,
            only: ['readme', 'flash'],
            onFinish: () => (requested.value = false),
        },
    );
}

function commit(): void {
    if (committing.value) return;
    committing.value = true;
    router.post(
        onWorkspace(
            readmeRoutes.github({
                workspace: props.workspaceSlug,
                machine: props.machineId,
            }),
        ).url,
        {},
        {
            preserveScroll: true,
            preserveState: true,
            only: ['readme', 'flash'],
            onFinish: () => (committing.value = false),
        },
    );
}

async function copy(): Promise<void> {
    const markdown = props.readme?.markdown;
    if (!markdown) {
        toast.info(trans('The readme is still being written.'));
        return;
    }
    try {
        await navigator.clipboard.writeText(markdown);
        toast.success(trans('Copied the readme as Markdown.'));
    } catch {
        toast.error(trans('Couldn’t copy it. Your browser didn’t allow it.'));
    }
}

const codeUrl = computed(
    () => onWorkspace(codeRoutes.index(props.workspaceSlug)).url,
);

function jump(id: string): void {
    document
        .getElementById(id)
        ?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    active.value = id;
}

function onRisk(risk: ReadmeRisk): void {
    if (risk.kind === 'updates' && props.canManage) {
        emit('updates');
        return;
    }
    if (risk.href) router.visit(risk.href);
}

let poll: ReturnType<typeof setInterval> | undefined;
let clock: ReturnType<typeof setInterval> | undefined;
let spy: IntersectionObserver | null = null;

watch(
    () => [props.readme === undefined, writing.value] as const,
    ([missing, busy]) => {
        clearInterval(poll);
        poll = undefined;
        if (missing) {
            router.reload({ only: ['readme'] });
        } else if (busy) {
            poll = setInterval(() => {
                if (document.visibilityState === 'visible')
                    router.reload({ only: ['readme'] });
            }, 2500);
        }
    },
    { immediate: true },
);

onMounted(() => {
    clock = setInterval(() => (now.value = Date.now()), 30_000);
    if (typeof IntersectionObserver === 'undefined') return;
    spy = new IntersectionObserver(
        (entries) => {
            const seen = entries
                .filter((entry) => entry.isIntersecting)
                .sort(
                    (a, b) =>
                        a.boundingClientRect.top - b.boundingClientRect.top,
                )[0];
            if (seen) active.value = seen.target.id;
        },
        { rootMargin: '-10% 0px -70% 0px' },
    );
    for (const section of SECTIONS) {
        const el = document.getElementById(section.id);
        if (el) spy.observe(el);
    }
});

watch(facts, () => {
    if (!spy) return;
    spy.disconnect();
    requestAnimationFrame(() => {
        for (const section of SECTIONS) {
            const el = document.getElementById(section.id);
            if (el) spy?.observe(el);
        }
    });
});

onBeforeUnmount(() => {
    clearInterval(poll);
    clearInterval(clock);
    spy?.disconnect();
});
</script>

<template>
    <div class="flex min-h-full max-xl:flex-col">
        <div
            class="flex min-w-0 grow flex-col gap-9 px-10 pt-7 pb-12 max-sm:px-4"
        >
            <div
                v-if="readme && !readme.scanned"
                class="text-[13px]/5 text-fg-muted"
            >
                {{
                    $t(
                        'ServerOS writes this readme after its first look around :name. It shows up here once the daemon reports what’s running.',
                        { name: machineName },
                    )
                }}
            </div>

            <template v-else-if="facts">
                <section
                    class="flex flex-col gap-3.5"
                    :aria-label="$t('Readme')"
                >
                    <p
                        class="flex items-center gap-[7px] text-[12px]/4 text-fg-muted"
                    >
                        <FilledIcon name="sparkle-fill" :size="13" />
                        {{ written }}
                    </p>
                    <p
                        v-if="words"
                        class="text-[16px]/[27px] text-fg-secondary"
                    >
                        <ReadmeText :text="words.intro" />
                    </p>
                    <p
                        v-else
                        class="animate-pulse text-[16px]/[27px] text-fg-faint"
                    >
                        {{ $t('Writing…') }}
                    </p>
                </section>

                <DashTray
                    id="readme-wiring"
                    class="scroll-mt-4"
                    icon="branch-fill"
                    :title="$t('How it’s wired')"
                >
                    <ReadmeWiring :facts="facts" :label="label" />
                    <p
                        class="border-t-[0.5px] border-edge-tray-panel px-4 pt-3 pb-3.5 text-[12px]/[18px]"
                        :class="
                            words
                                ? 'text-fg-muted'
                                : 'animate-pulse text-fg-faint'
                        "
                    >
                        <ReadmeText v-if="words" :text="words.wiring" />
                        <template v-else>{{ $t('Writing…') }}</template>
                    </p>
                </DashTray>

                <DashTray
                    id="readme-services"
                    class="scroll-mt-4"
                    icon="layers-fill"
                    :title="$t('What runs here')"
                    :meta="String(services.length)"
                >
                    <p
                        v-if="!services.length"
                        class="px-4 py-3.5 text-[13px]/5 text-fg-muted"
                    >
                        {{
                            $t('ServerOS didn’t find any services on :name.', {
                                name: machineName,
                            })
                        }}
                    </p>
                    <div
                        v-for="(service, index) in services"
                        :key="service.id"
                        class="flex items-center gap-3 py-[11px] pr-4 pl-3.5"
                        :class="
                            index
                                ? 'border-t-[0.5px] border-edge-tray-panel'
                                : ''
                        "
                    >
                        <span
                            class="flex size-[30px] shrink-0 items-center justify-center rounded-[8px] border-[0.5px] border-[#dcdcdc] bg-[#f4f4f4] dark:border-[#424242] dark:bg-[#262626]"
                        >
                            <FilledIcon
                                v-if="service.kind === 'site'"
                                name="docs-fill"
                                class="text-fg-muted"
                            />
                            <BrandLogo
                                v-else
                                :name="service.logo"
                                :src="service.iconUrl"
                            />
                        </span>
                        <div class="flex min-w-0 grow flex-col gap-0.5">
                            <span
                                class="truncate text-[13px]/[18px] font-medium text-fg-primary"
                                >{{ service.name }}</span
                            >
                            <span
                                class="text-[12px]/[17px]"
                                :class="
                                    words
                                        ? 'text-fg-muted'
                                        : 'animate-pulse text-fg-faint'
                                "
                            >
                                <ReadmeText
                                    v-if="words"
                                    :text="words.services[service.id] ?? ''"
                                />
                                <template v-else>{{ $t('Writing…') }}</template>
                            </span>
                        </div>
                        <div
                            class="flex w-[150px] shrink-0 items-center justify-end gap-2 text-right whitespace-nowrap max-sm:w-auto"
                        >
                            <span class="text-[12px]/4 text-fg-secondary">{{
                                how(service)
                            }}</span>
                            <span
                                v-if="service.ports.length"
                                class="truncate font-ui-mono text-[11px]/4 text-fg-muted"
                                >{{
                                    service.ports
                                        .map((port) => `:${port}`)
                                        .join(' ')
                                }}</span
                            >
                        </div>
                    </div>
                </DashTray>

                <DashTray
                    id="readme-data"
                    class="scroll-mt-4"
                    icon="database-fill"
                    :title="$t('Where data lives')"
                >
                    <p
                        v-if="!facts.data.length"
                        class="px-4 py-3.5 text-[13px]/5 text-fg-muted"
                    >
                        {{
                            $t('ServerOS didn’t find any data on :name.', {
                                name: machineName,
                            })
                        }}
                    </p>
                    <div v-else class="overflow-x-auto">
                        <div class="min-w-[640px]">
                            <div
                                class="flex items-center px-4 py-[9px] text-[12px]/4 text-fg-muted"
                            >
                                <span class="w-[150px] shrink-0">{{
                                    $t('What')
                                }}</span>
                                <span class="min-w-0 grow">{{
                                    $t('Where')
                                }}</span>
                                <span class="w-20 shrink-0">{{
                                    $t('Size')
                                }}</span>
                                <span class="w-[290px] shrink-0">{{
                                    $t('Backup')
                                }}</span>
                            </div>
                            <div
                                v-for="row in facts.data"
                                :key="row.id"
                                class="flex items-center border-t-[0.5px] border-edge-tray-panel px-4 py-[11px]"
                            >
                                <span
                                    class="w-[150px] shrink-0 truncate pr-3 text-[13px]/[18px] font-medium text-fg-primary"
                                    >{{ row.what }}</span
                                >
                                <span
                                    class="min-w-0 grow truncate pr-3 font-ui-mono text-[12px]/[18px] text-fg-secondary"
                                    :title="row.where"
                                    >{{ row.where }}</span
                                >
                                <span
                                    class="w-20 shrink-0 text-[13px]/[18px] text-fg-secondary"
                                    >{{
                                        row.size === null
                                            ? '–'
                                            : bytesLabel(row.size)
                                    }}</span
                                >
                                <span
                                    class="flex w-[290px] shrink-0 items-center gap-[7px]"
                                >
                                    <span
                                        class="size-1.5 shrink-0 rounded-full"
                                        :class="dataDot[row.tone]"
                                    />
                                    <span
                                        class="truncate text-[12px]/4 text-fg-secondary"
                                        >{{ row.backup }}</span
                                    >
                                </span>
                            </div>
                        </div>
                    </div>
                </DashTray>

                <DashTray
                    id="readme-risks"
                    class="scroll-mt-4"
                    icon="info-fill"
                    :title="$t('What’s risky')"
                >
                    <template #meta>
                        <span
                            :class="
                                risks.some((risk) => risk.severity === 'urgent')
                                    ? 'text-status-down'
                                    : ''
                            "
                            >{{ riskMeta || $t('Nothing') }}</span
                        >
                    </template>
                    <p
                        v-if="!risks.length"
                        class="px-4 py-3.5 text-[13px]/5 text-fg-muted"
                    >
                        {{
                            $t(
                                'Nothing ServerOS can see needs fixing on :name.',
                                { name: machineName },
                            )
                        }}
                    </p>
                    <div
                        v-for="(risk, index) in risks"
                        :key="risk.id"
                        class="flex items-center gap-3 py-3 pr-3.5 pl-4"
                        :class="
                            index
                                ? 'border-t-[0.5px] border-edge-tray-panel'
                                : ''
                        "
                    >
                        <span
                            class="size-[7px] shrink-0 rounded-full"
                            :class="riskDot[risk.severity]"
                            :aria-label="
                                risk.severity === 'urgent'
                                    ? $t('Urgent')
                                    : $t('To fix')
                            "
                        />
                        <div class="flex min-w-0 grow flex-col gap-0.5">
                            <span
                                class="text-[13px]/[18px] font-medium text-fg-primary"
                                >{{ risk.title }}</span
                            >
                            <span class="text-[12px]/[17px] text-fg-muted">{{
                                risk.detail
                            }}</span>
                        </div>
                        <div class="flex shrink-0 items-center gap-1.5">
                            <DashButton
                                v-if="risk.ask"
                                icon="sparkle-fill"
                                :aria-label="$t('Ask AI')"
                                :title="$t('Ask AI')"
                                @click="askRisk(risk)"
                            />
                            <DashButton
                                v-if="
                                    risk.action &&
                                    risk.kind === 'updates' &&
                                    canManage
                                "
                                :label="risk.action"
                                @click="onRisk(risk)"
                            />
                            <DashButton
                                v-else-if="risk.action && risk.href"
                                :label="
                                    risk.kind === 'updates'
                                        ? $t('See updates')
                                        : risk.action
                                "
                                :href="risk.href"
                            />
                        </div>
                    </div>
                </DashTray>

                <DashTray
                    id="readme-good"
                    class="scroll-mt-4"
                    icon="setup-fill"
                    :title="$t('Looks good')"
                    :meta="String(facts.good.length)"
                    panel-class="py-1.5"
                >
                    <p
                        v-if="!facts.good.length"
                        class="px-4 py-2 text-[13px]/5 text-fg-muted"
                    >
                        {{ $t('Nothing to show here yet.') }}
                    </p>
                    <div
                        v-for="item in facts.good"
                        :key="item.id"
                        class="flex items-center gap-2.5 px-4 py-[7px]"
                    >
                        <span
                            class="flex size-[18px] shrink-0 items-center justify-center rounded-full bg-status-live/14 text-status-live"
                        >
                            <FilledIcon name="check" :size="11" />
                        </span>
                        <span class="text-[13px]/[18px] text-fg-secondary">{{
                            item.text
                        }}</span>
                    </div>
                </DashTray>
            </template>

            <p v-else class="animate-pulse text-[16px]/[27px] text-fg-faint">
                {{ $t('Writing…') }}
            </p>
        </div>

        <div
            class="w-[316px] shrink-0 border-l-[0.5px] border-edge-tabs max-xl:w-full max-xl:border-t-[0.5px] max-xl:border-l-0"
        >
            <aside
                class="flex flex-col gap-[22px] p-5 xl:sticky xl:top-0"
                :aria-label="$t('About this readme')"
            >
                <section class="flex flex-col items-start gap-2.5">
                    <h2 class="text-[13px]/[18px] font-medium text-fg-primary">
                        {{ $t('About this readme') }}
                    </h2>
                    <p class="text-[12px]/[18px] text-fg-muted">{{ about }}</p>
                    <DashButton
                        :icon="ArrowUpCircleFill"
                        :label="writing ? $t('Writing…') : $t('Write it again')"
                        :loading="requested || writing"
                        :disabled="!readme?.scanned || readme.writesLeft === 0"
                        :title="
                            readme?.writesLeft === 0
                                ? $t(
                                      'It was written again a few times this hour. Try again later.',
                                  )
                                : undefined
                        "
                        @click="writeAgain"
                    />
                </section>

                <section class="flex flex-col items-start gap-2.5">
                    <h2 class="text-[13px]/[18px] font-medium text-fg-primary">
                        {{ $t('Keep a copy') }}
                    </h2>
                    <button
                        type="button"
                        class="flex cursor-pointer items-center gap-2 text-[13px]/[18px] text-fg-secondary hover:text-fg-primary disabled:cursor-default disabled:opacity-50"
                        :disabled="!readme?.markdown"
                        @click="copy"
                    >
                        <FilledIcon
                            name="copy"
                            :size="14"
                            class="text-fg-muted"
                        />
                        {{ $t('Copy as Markdown') }}
                    </button>
                    <button
                        v-if="readme?.repository?.connected && readme.canCommit"
                        type="button"
                        class="flex cursor-pointer items-center gap-2 text-left text-[13px]/[18px] text-fg-secondary hover:text-fg-primary disabled:cursor-default disabled:opacity-50"
                        :disabled="committing || !readme.markdown"
                        @click="commit"
                    >
                        <BrandLogo name="github" :size="14" />
                        {{
                            committing
                                ? $t('Adding to :repo…', {
                                      repo: readme.repository.repo,
                                  })
                                : $t('Add to :repo as :file', {
                                      repo: readme.repository.repo,
                                      file: readme.repository.file,
                                  })
                        }}
                    </button>
                    <Link
                        v-else
                        :href="codeUrl"
                        class="flex items-center gap-2 text-left text-[13px]/[18px] text-fg-secondary hover:text-fg-primary"
                        :title="
                            readme?.repository && !readme.canCommit
                                ? $t(
                                      'You need permission to manage machines to add it.',
                                  )
                                : undefined
                        "
                    >
                        <BrandLogo name="github" :size="14" />
                        {{
                            readme?.repository
                                ? readme.repository.connected
                                    ? $t('Add to :repo as :file', {
                                          repo: readme.repository.repo,
                                          file: readme.repository.file,
                                      })
                                    : $t(
                                          'Connect GitHub in Code to add it to :repo',
                                          {
                                              repo: readme.repository.repo,
                                          },
                                      )
                                : $t(
                                      'Set up a code repository to keep it in git',
                                  )
                        }}
                    </Link>
                    <a
                        v-if="readme?.repository?.pullRequest"
                        :href="readme.repository.pullRequest"
                        target="_blank"
                        rel="noopener"
                        class="pl-[22px] text-[12px]/4 text-fg-muted hover:text-fg-secondary"
                        >{{ $t('Open the pull request') }}</a
                    >
                </section>

                <section class="flex flex-col gap-2.5">
                    <h2 class="text-[13px]/[18px] font-medium text-fg-primary">
                        {{ $t('Ask about :name', { name: machineName }) }}
                    </h2>
                    <form
                        class="flex h-9 items-center gap-2 rounded-[9px] border-[0.5px] border-[#d4d4d4] bg-surface-field px-2.5 focus-within:border-edge-strong dark:border-[#333] dark:bg-[#131313]"
                        @submit.prevent="ask"
                    >
                        <FilledIcon
                            name="sparkle-fill"
                            :size="13"
                            class="text-fg-muted"
                        />
                        <input
                            v-model="question"
                            type="text"
                            class="min-w-0 grow bg-transparent text-[13px]/[18px] text-fg-primary outline-none placeholder:text-fg-faint"
                            :placeholder="placeholder"
                            :aria-label="
                                $t('Ask about :name', { name: machineName })
                            "
                            maxlength="500"
                        />
                    </form>
                </section>

                <nav
                    class="flex flex-col items-start gap-2.5"
                    :aria-label="$t('On this page')"
                >
                    <h2 class="text-[13px]/[18px] font-medium text-fg-primary">
                        {{ $t('On this page') }}
                    </h2>
                    <a
                        v-for="section in SECTIONS"
                        :key="section.id"
                        :href="`#${section.id}`"
                        class="text-[13px]/[18px] transition-colors hover:text-fg-primary"
                        :class="
                            active === section.id
                                ? 'text-fg-primary'
                                : 'text-fg-secondary'
                        "
                        @click.prevent="jump(section.id)"
                        >{{ section.label() }}</a
                    >
                </nav>
            </aside>
        </div>
    </div>
</template>
