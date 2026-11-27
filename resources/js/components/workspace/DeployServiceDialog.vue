<script setup lang="ts">
import { ArrowLeft, Check, LoaderCircle } from '@lucide/vue';
import { usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import {
    DialogContent,
    DialogDescription,
    DialogOverlay,
    DialogPortal,
    DialogRoot,
    DialogTitle,
} from 'reka-ui';
import { computed, nextTick, ref, watch } from 'vue';
import BrandLogo from '@/components/dash/BrandLogo.vue';
import DashButton from '@/components/dash/DashButton.vue';
import DashCheckbox from '@/components/dash/DashCheckbox.vue';
import CategoryRail from '@/components/deploy/CategoryRail.vue';
import {
    OWN_CODE,
    RAIL,
    SECTION_ORDER,
    featuredRank,
    isSshRepository,
    looksLikeRepository,
    repositoryLabel,
} from '@/components/deploy/catalog';
import PickerButton from '@/components/deploy/PickerButton.vue';
import RepoList from '@/components/deploy/RepoList.vue';
import type {
    GitHubRepositories,
    GitLabRepositories,
} from '@/components/deploy/RepoList.vue';
import TemplateCatalogue from '@/components/deploy/TemplateCatalogue.vue';
import type { CatalogueGroup } from '@/components/deploy/TemplateCatalogue.vue';
import TemplateLogo from '@/components/deploy/TemplateLogo.vue';
import UploadStage from '@/components/deploy/UploadStage.vue';
import type { CloudflareCheck } from '@/components/integrations/cloudflare';
import CloudflareDnsNote from '@/components/integrations/CloudflareDnsNote.vue';
import type {
    StageChip,
    StagePhase,
} from '@/components/deploy/UploadStage.vue';
import {
    PackError,
    archiveName,
    fromDrop,
    fromInput,
    isZip,
    pack,
} from '@/components/deploy/zip';
import type { PickedFile } from '@/components/deploy/zip';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { SelectMenu } from '@/components/ui/select';
import type { SelectMenuOption } from '@/components/ui/select';
import { useZipUpload } from '@/composables/useZipUpload';
import { describe, errorMessage } from '@/lib/errors';
import {
    SolarCircleAlert as CircleAlert,
    SolarCircleCheck as CircleCheck,
    SolarCopy as Copy,
    SolarLockKeyhole as LockKeyhole,
} from '@/lib/solarIcons';
import { toast } from '@/lib/toast';
import { onWorkspace, workspacePath } from '@/lib/workspaceRoute';
import githubRoutes from '@/routes/workspace/github';
import gitlabRoutes from '@/routes/workspace/gitlab';
import machinesRoutes from '@/routes/workspace/machines';
import servicesRoutes from '@/routes/workspace/services';

export type TemplateField = {
    key: string;
    label: string;
    hint: string | null;
    secret: boolean;
    required: boolean;
    accept: boolean;
    generated: boolean;
    default: string;
};

export type ServiceTemplate = {
    slug: string;
    name: string;
    category: string;
    description: string;
    icon: string;
    kind: 'compose' | 'app';
    port: number;
    web: boolean;
    portEditable: boolean;
    health: string | null;
    compose?: string | null;
    notes: string | null;
    env: TemplateField[];
};

type DeployResult = {
    jobId: string;
    template: string;
    generated: { key: string; label: string; value: string }[];
    kept?: string[];
    url: string | null;
    localUrl: string | null;
    tunnel: string | null;
    port: number;
    notes: string | null;
    commit?: string | null;
    machineId?: number;
};

type DeployMachine = {
    id: number;
    name: string;
    provider: string | null;
    online: boolean;
};

const props = defineProps<{
    templates: ServiceTemplate[];
    machineId: number;
    /** A template's slug to open on, like coding-agent from ?deploy=coding-agent. */
    startWith?: string | null;
    /** Opened from somewhere else (like the Agents machine picker): back closes it, so you land where you came from. */
    backCloses?: boolean;
}>();

const emit = defineEmits<{ deployed: [result: DeployResult] }>();

const page = usePage<{ githubApp: boolean; workspace: { slug: string } }>();

const open = defineModel<boolean>('open', { default: false });

function csrf(): string {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}

function request(url: string, body?: unknown): Promise<Response> {
    return fetch(url, {
        method: body === undefined ? 'GET' : 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            ...(body === undefined
                ? {}
                : {
                      'Content-Type': 'application/json',
                      'X-CSRF-TOKEN': csrf(),
                  }),
        },
        body: body === undefined ? undefined : JSON.stringify(body),
    });
}

const step = ref<'pick' | 'configure' | 'done'>('pick');

const target = ref(props.machineId);
watch(
    () => props.machineId,
    (id) => (target.value = id),
);
const machines = ref<DeployMachine[]>([]);
const machine = computed(
    () => machines.value.find((entry) => entry.id === target.value) ?? null,
);
const machineName = computed(() => machine.value?.name ?? '');
const canSwitchMachine = computed(
    () => !props.backCloses && machines.value.length > 1,
);

const query = ref('');
const category = ref('All');
const picked = ref<string | null>(null);
const searchInput = ref<HTMLInputElement | null>(null);
const isOwnCode = computed(() => category.value === OWN_CODE);

const counts = computed(() => {
    const totals: Record<string, number> = { All: props.templates.length };
    for (const template of props.templates) {
        totals[template.category] = (totals[template.category] ?? 0) + 1;
    }

    return totals;
});

const ranked = computed(() =>
    [...props.templates].sort(
        (a, b) => featuredRank(a.slug) - featuredRank(b.slug),
    ),
);

const groups = computed<CatalogueGroup[]>(() => {
    const words = query.value.toLowerCase().split(/\s+/).filter(Boolean);
    const browsing = category.value === 'All' && words.length === 0;
    const matches = ranked.value.filter((template) => {
        if (category.value !== 'All' && template.category !== category.value) {
            return false;
        }
        const haystack =
            `${template.name} ${template.slug} ${template.description} ${template.category}`.toLowerCase();

        return words.every((word) => haystack.includes(word));
    });
    const order =
        category.value === 'All'
            ? [
                  ...SECTION_ORDER,
                  ...Object.keys(counts.value).filter(
                      (name) => name !== 'All' && !SECTION_ORDER.includes(name),
                  ),
              ]
            : [category.value];

    return order
        .map((name) => {
            const items = matches.filter(
                (template) => template.category === name,
            );

            return {
                category: name,
                count: browsing ? (counts.value[name] ?? 0) : items.length,
                items: browsing ? items.slice(0, 6) : items,
                more: browsing && items.length > 6,
            };
        })
        .filter((group) => group.items.length > 0);
});

const visible = computed(() => groups.value.flatMap((group) => group.items));

const pickedTemplate = computed(
    () =>
        props.templates.find((template) => template.slug === picked.value) ??
        null,
);

watch(query, () => (picked.value = visible.value[0]?.slug ?? null));
watch(
    visible,
    (list) => {
        if (!list.some((template) => template.slug === picked.value)) {
            picked.value = list[0]?.slug ?? null;
        }
    },
    { immediate: true },
);

const showUploadStrip = computed(
    () => category.value === 'All' && query.value.trim() === '',
);

const railOptions = computed(() => [
    ...RAIL.map((option) => option.value),
    OWN_CODE,
]);

function railLabel(value: string): string {
    if (value === 'All') return trans('All services');
    if (value === OWN_CODE) return trans('Your own code');

    return trans(value);
}

function seeAll(name: string): void {
    category.value = name;
}

function columns(): number {
    if (window.matchMedia('(min-width: 640px)').matches) return 3;

    return window.matchMedia('(min-width: 560px)').matches ? 2 : 1;
}

function movePick(by: number): void {
    const list = visible.value;
    if (!list.length) return;
    const at = list.findIndex((template) => template.slug === picked.value);
    const next = Math.min(list.length - 1, Math.max(0, (at < 0 ? 0 : at) + by));
    picked.value = list[next].slug;
    nextTick(() =>
        document
            .getElementById(`template-${picked.value}`)
            ?.scrollIntoView({ block: 'nearest' }),
    );
}

function onSearchKeydown(event: KeyboardEvent): void {
    const text = query.value.trim();

    if (event.key === 'Enter' && looksLikeRepository(text)) {
        event.preventDefault();
        query.value = '';
        importRepository(text);

        return;
    }

    if (isOwnCode.value) return;

    const step = columns();
    const moves: Record<string, number> = {
        ArrowDown: step,
        ArrowUp: -step,
        ArrowRight: 1,
        ArrowLeft: -1,
    };
    const move = moves[event.key];
    const caretKey =
        (event.key === 'ArrowLeft' || event.key === 'ArrowRight') &&
        query.value !== '';

    if (move !== undefined && !caretKey) {
        event.preventDefault();
        movePick(move);
    } else if (event.key === 'Enter' && pickedTemplate.value) {
        event.preventDefault();
        choose(pickedTemplate.value);
    }
}

function onSearchPaste(event: ClipboardEvent): void {
    const text = event.clipboardData?.getData('text')?.trim() ?? '';
    if (looksLikeRepository(text) && query.value.trim() === '') {
        event.preventDefault();
        importRepository(text);
    }
}

watch(query, (text) => {
    if (text.trim() && isOwnCode.value && !looksLikeRepository(text)) {
        category.value = 'All';
    }
});

type Detection = {
    detected: boolean;
    template: string | null;
    framework: string | null;
    type: string | null;
    repo: string;
    branch: string;
    commit: string;
    baseDir?: string | null;
    reasons: string[];
    php?: string | null;
    healthPath?: string | null;
    composeFile?: string | null;
    composePort?: number | null;
    upload?: { filename: string; files: number; expiresAt: string };
};

const detecting = ref(false);
const detection = ref<Detection | null>(null);
const detectError = ref<string | null>(null);
const customSlug = ref('');
const source = ref<{
    kind: 'upload' | 'repo';
    label: string;
    url?: string;
} | null>(null);
const repoQuery = ref('');

const appTemplates = computed(() =>
    props.templates.filter((template) => template.kind === 'app'),
);

const appTemplateOptions = computed<SelectMenuOption<string>[]>(() =>
    appTemplates.value.map((template) => ({
        value: template.slug,
        label: template.name,
    })),
);

const customTemplate = computed(
    () =>
        props.templates.find(
            (template) => template.slug === customSlug.value,
        ) ?? null,
);

const github = ref<GitHubRepositories | null>(null);
const gitlab = ref<GitLabRepositories | null>(null);
const branches = ref<{ repo: string; names: string[] } | null>(null);

const branchOptions = computed<SelectMenuOption<string>[]>(() => {
    const found = detection.value;

    if (!found || found.upload || branches.value?.repo !== found.repo) {
        return [];
    }

    const names =
        !found.branch || branches.value.names.includes(found.branch)
            ? branches.value.names
            : [found.branch, ...branches.value.names];

    return names.map((name) => ({ value: name, label: name }));
});

async function loadRepositories(): Promise<void> {
    await Promise.all([loadGitHub(), loadGitLab()]);
}

async function loadGitLab(): Promise<void> {
    if (gitlab.value) {
        return;
    }

    try {
        const response = await request(
            onWorkspace(gitlabRoutes.repositories(page.props.workspace.slug))
                .url,
        );

        gitlab.value = response.ok
            ? await response.json()
            : { connected: false, accounts: [], repositories: [] };
    } catch {
        gitlab.value = { connected: false, accounts: [], repositories: [] };
    }
}

async function loadGitHub(): Promise<void> {
    if (github.value || !page.props.githubApp) {
        return;
    }

    try {
        const response = await request(
            onWorkspace(githubRoutes.repositories(page.props.workspace.slug))
                .url,
        );

        github.value = response.ok
            ? await response.json()
            : { connected: false, accounts: [], repositories: [] };
    } catch {
        github.value = { connected: false, accounts: [], repositories: [] };
    }
}

async function loadBranches(found: Detection): Promise<void> {
    const match = found.upload
        ? null
        : found.repo.match(/^https:\/\/github\.com\/([^/]+)\/(.+?)(?:\.git)?$/);
    const fromGitLab =
        !found.upload && !match && found.repo.startsWith('https://');

    if (!match && !fromGitLab) {
        return;
    }

    try {
        const response = await request(
            onWorkspace(
                match
                    ? githubRoutes.branches({
                          workspace: page.props.workspace.slug,
                          owner: match[1],
                          repo: match[2],
                      })
                    : gitlabRoutes.branches(page.props.workspace.slug, {
                          query: { repo: found.repo },
                      }),
            ).url,
        );
        const names = response.ok ? (await response.json()).branches : null;

        // An answer for a repository that's no longer shown is dropped.
        if (names && detection.value?.repo === found.repo) {
            branches.value = { repo: found.repo, names };
        }
    } catch {}
}

function switchBranch(branch: string): void {
    if (!detection.value || branch === detection.value.branch) {
        return;
    }

    const folder = detection.value.baseDir;
    void detect(
        `${detection.value.repo.replace(/\.git$/, '')}/tree/${branch}${folder ? `/${folder}` : ''}`,
    );
}

// The app's folder in a monorepo: detection runs again for that folder.
const folderInput = ref('');
watch(
    () => detection.value?.baseDir,
    (folder) => (folderInput.value = folder ?? ''),
);

function switchFolder(): void {
    if (!detection.value || detection.value.upload) return;
    const folder = folderInput.value.trim().replace(/^\/+|\/+$/g, '');
    if (folder === (detection.value.baseDir ?? '')) return;
    void detect(
        `${detection.value.repo.replace(/\.git$/, '')}/tree/${detection.value.branch}${folder ? `/${folder}` : ''}`,
    );
}

function resetOwnCode(): void {
    detection.value = null;
    detectError.value = null;
    source.value = null;
    ssh.value = null;
    customSlug.value = '';
}

function importRepository(text: string): void {
    const repository = text.trim();
    if (!repository || busy.value) return;

    category.value = OWN_CODE;
    repoQuery.value = '';

    if (isSshRepository(repository)) {
        startSsh(repository);

        return;
    }

    void detect(repository);
}

async function detect(repository: string): Promise<void> {
    if (detecting.value) {
        return;
    }

    ssh.value = null;
    detecting.value = true;
    detectError.value = null;
    detection.value = null;
    source.value = {
        kind: 'repo',
        label: repositoryLabel(repository).split('/tree/')[0],
        url: repository,
    };

    try {
        const response = await request(
            onWorkspace(servicesRoutes.detect(page.props.workspace.slug)).url,
            { repository },
        );
        const body = await response.json().catch(() => ({}));

        if (!response.ok) {
            detectError.value = describe(response.status, body);
            source.value = null;

            return;
        }

        detection.value = body as Detection;
        customSlug.value = body.template ?? '';
        void loadBranches(body as Detection);
    } catch (cause) {
        detectError.value = errorMessage(cause);
        source.value = null;
    } finally {
        detecting.value = false;
    }
}

// A private repository on another git host, over SSH: the machine makes a
// deploy key for the app, the person adds it to the repository, and the
// machine looks up the branch's commit with it, so the deploy is pinned.
type SshSource = {
    repo: string;
    service: string;
    publicKey: string | null;
    branch: string;
    commit: string | null;
    ref: string | null;
    busy: 'key' | 'resolve' | null;
    error: string | null;
};
const ssh = ref<SshSource | null>(null);
const sshSetup = ref('');
// The deploy key belongs to the app's name, so it can't change after.
const lockedService = ref(false);

function startSsh(repo: string): void {
    resetOwnCode();
    sshSetup.value = '';
    ssh.value = {
        repo,
        service: freeName(repoServiceName(repo) || 'app'),
        publicKey: null,
        branch: '',
        commit: null,
        ref: null,
        busy: null,
        error: null,
    };
}

type KeyAnswer = {
    jobId?: string;
    state?: string;
    publicKey?: string;
    commit?: string;
    ref?: string | null;
    error?: string;
    nextStep?: string;
};

async function runKeyJob(
    path: 'key' | 'resolve',
    body: Record<string, string>,
): Promise<KeyAnswer | null> {
    const current = ssh.value;
    if (!current) return null;
    current.error = null;
    const machineId = target.value;
    const args = { workspace: page.props.workspace.slug, machine: machineId };
    const response = await request(
        onWorkspace(
            path === 'key'
                ? machinesRoutes.deploy.key(args)
                : machinesRoutes.deploy.resolve(args),
        ).url,
        body,
    ).catch(() => null);
    if (!response) {
        current.error = trans('Couldn’t reach ServerOS. Try again.');
        return null;
    }
    let answer = (await response.json().catch(() => ({}))) as KeyAnswer;
    if (!response.ok) {
        current.error = describe(response.status, answer);
        return null;
    }

    const deadline = Date.now() + 60_000;
    while (
        (answer.state === 'queued' || answer.state === 'running') &&
        answer.jobId &&
        Date.now() < deadline
    ) {
        await new Promise((resolve) => setTimeout(resolve, 2000));
        const next = await request(
            onWorkspace(
                machinesRoutes.deploy.job({
                    workspace: page.props.workspace.slug,
                    machine: machineId,
                    job: answer.jobId,
                }),
            ).url,
        ).catch(() => null);
        if (next?.ok) answer = (await next.json()) as KeyAnswer;
    }
    if (answer.state === 'queued' || answer.state === 'running') {
        current.error = trans(
            'The machine hasn’t answered yet. Check it’s online, then try again.',
        );
        return null;
    }
    if (answer.error) {
        current.error = [answer.error, answer.nextStep]
            .filter(Boolean)
            .join('. ');
        return null;
    }

    return answer;
}

async function makeKey(): Promise<void> {
    const current = ssh.value;
    if (!current || current.busy) return;
    current.busy = 'key';
    const answer = await runKeyJob('key', { service: current.service });
    current.busy = null;
    if (answer?.publicKey) current.publicKey = answer.publicKey;
}

async function lookUpBranch(): Promise<void> {
    const current = ssh.value;
    if (!current || current.busy) return;
    current.busy = 'resolve';
    const body: Record<string, string> = {
        service: current.service,
        repo: current.repo,
    };
    if (current.branch) body.ref = current.branch;
    const answer = await runKeyJob('resolve', body);
    current.busy = null;
    if (answer?.commit) {
        current.commit = answer.commit;
        current.ref = answer.ref ?? null;
    }
}

function continueSsh(): void {
    const current = ssh.value;
    const template = appTemplates.value.find(
        (candidate) => candidate.slug === sshSetup.value,
    );
    if (!current?.commit || !template) return;
    choose(template, { repo: current.repo, commit: current.commit });
    form.value.service = current.service;
    suggested.value = { service: current.service, port: form.value.port };
    lockedService.value = true;
}

const zip = useZipUpload();
const packing = ref(false);
const packProgress = ref(0);
const fileInput = ref<HTMLInputElement | null>(null);
const folderPicker = ref<HTMLInputElement | null>(null);

const busy = computed(
    () => packing.value || zip.uploading.value || detecting.value,
);

function uploadLabel(files: PickedFile[]): string {
    if (files.length === 1) return files[0].file.name;
    const name = archiveName(files);

    return name === 'app.zip'
        ? trans(':count files', { count: String(files.length) })
        : name.replace(/\.zip$/, '');
}

async function takeFiles(files: PickedFile[]): Promise<void> {
    if (busy.value) return;
    category.value = OWN_CODE;
    resetOwnCode();

    if (files.length === 0) {
        detectError.value = trans(
            'Nothing to upload once dependencies and build folders are left out.',
        );

        return;
    }

    source.value = { kind: 'upload', label: uploadLabel(files) };
    let file = files[0].file;

    if (!(files.length === 1 && isZip(file))) {
        packing.value = true;
        packProgress.value = 0;
        try {
            file = await pack(files, (done) => (packProgress.value = done));
        } catch (cause) {
            detectError.value =
                cause instanceof PackError
                    ? cause.message
                    : errorMessage(cause);
            source.value = null;

            return;
        } finally {
            packing.value = false;
        }
    }

    const found = await zip.upload(target.value, file);
    if (fileInput.value) fileInput.value.value = '';
    if (folderPicker.value) folderPicker.value.value = '';
    if (!found) {
        detectError.value = zip.error.value;
        source.value = null;

        return;
    }
    detection.value = found as Detection;
    customSlug.value = found.template ?? '';
}

async function onDrop(transfer: DataTransfer | null): Promise<void> {
    void takeFiles(await fromDrop(transfer));
}

function onPick(event: Event): void {
    void takeFiles(fromInput((event.target as HTMLInputElement).files));
}

function continueCustom(): void {
    const found = detection.value;
    const template = customTemplate.value;
    if (!found || !template) {
        return;
    }

    choose(template, {
        repo: found.repo,
        commit: found.commit,
        // A Compose file is named by its path from the root instead.
        baseDir: template.compose ? null : (found.baseDir ?? null),
    });

    if (template.compose) {
        form.value.compose_file = found.composeFile ?? template.compose;
        form.value.port = found.composePort ?? form.value.port;
    }
    if (found.healthPath && template.slug === found.template) {
        form.value.health_path = found.healthPath;
    }

    if (found.upload) {
        nameBase.value = ownBase.value;
        form.value.service = freeName(ownBase.value);
    }
    suggested.value = { service: form.value.service, port: form.value.port };
}

const stagePhase = computed<StagePhase>(() => {
    if (packing.value) return 'packing';
    if (zip.uploading.value) {
        return zip.progress.value < 1 ? 'uploading' : 'detecting';
    }
    if (detecting.value) return 'detecting';
    if (detection.value) {
        return detection.value.detected ? 'found' : 'unknown';
    }

    return 'idle';
});

const stageProgress = computed(() =>
    packing.value ? packProgress.value : zip.progress.value,
);

function foundText(found: Detection): string {
    if (found.template === 'custom-dockerfile')
        return trans('Found a Dockerfile');
    if (found.template === 'compose-file') return trans('Found a Compose file');
    const framework = found.framework ?? '';
    if (found.type === 'API')
        return trans('Found a :framework API', { framework });
    if (found.type === 'Static site') {
        return trans('Found a :framework site', { framework });
    }

    return trans('Found a :framework app', { framework });
}

const stageStatus = computed(() => {
    const label = source.value?.label ?? '';

    switch (stagePhase.value) {
        case 'packing':
            return trans('Packing :name…', { name: label });
        case 'uploading':
            return trans('Uploading :name…', { name: label });
        case 'detecting':
            return trans('Looking inside…');
        case 'found':
            return detection.value ? foundText(detection.value) : '';
        case 'unknown':
            return trans('Couldn’t tell what this is');
        default:
            return '';
    }
});

const detectedTemplate = computed(
    () =>
        props.templates.find(
            (template) => template.slug === detection.value?.template,
        ) ?? null,
);

const stageChips = computed<StageChip[]>(() => {
    const found = detection.value;
    if (!found) return [];
    const chips: StageChip[] = [];

    if (found.detected && found.framework) {
        chips.push({
            label: found.framework,
            icon: detectedTemplate.value?.icon ?? 'docker',
        });
    }
    if (found.detected && found.type && found.type !== 'App') {
        chips.push({ label: found.type });
    }
    if (found.php) chips.push({ label: `PHP ${found.php}` });
    if (found.composeFile && found.template !== 'compose-file') {
        chips.push({ label: found.composeFile, icon: 'docker' });
    }
    if (found.upload) {
        chips.push({
            label: trans(':count files', { count: String(found.upload.files) }),
        });
    } else if (found.branch) {
        chips.push({
            label: `${found.branch} · ${found.commit.slice(0, 7)}`,
            glyph: 'branch-fill',
        });
    }

    return chips;
});

const stageNote = computed(() => {
    const found = detection.value;
    if (!found) return null;
    if (!found.detected) {
        return trans(
            'Pick the setup that fits below, or add a Dockerfile to the project.',
        );
    }

    return found.reasons.join(' ') || null;
});

const ownBase = computed(() => {
    const found = detection.value;
    if (!found) return '';
    const name = found.upload
        ? repoServiceName(found.upload.filename.replace(/\.zip$/i, ''))
        : repoServiceName(found.repo);

    return name || 'app';
});
const ownName = computed(() => (ownBase.value ? freeName(ownBase.value) : ''));

const ownReady = computed(
    () => Boolean(detection.value && customTemplate.value) && !busy.value,
);

const ownSummary = computed(() => {
    const template = customTemplate.value;
    if (!template) return '';
    const what =
        detection.value?.detected && template.slug === detection.value.template
            ? (detection.value.framework ?? template.name)
            : template.name;

    return machineName.value
        ? trans(':what, on :machine', { what, machine: machineName.value })
        : what;
});

watch(category, (next, previous) => {
    if (next === OWN_CODE) {
        void loadRepositories();
    }
    if (previous === OWN_CODE && !busy.value) {
        ssh.value = null;
    }
});

const selected = ref<ServiceTemplate | null>(null);
const form = ref({
    service: '',
    port: '' as string | number,
    repo: '',
    commit: '',
    base_dir: '',
    health_path: '',
    compose_file: '',
    domain: '',
    wildcard: false,
    proxy: '',
    https: true,
    env: {} as Record<string, string | boolean>,
    extra_env: '',
});
const uploadSource = computed(() =>
    detection.value?.upload && form.value.repo === detection.value.repo
        ? detection.value.upload
        : null,
);
const showExtraEnv = ref(false);
const errors = ref<Record<string, string>>({});

watch(
    () => errors.value.form,
    (message) => {
        if (message) {
            toast.error(message);
        }
    },
);
const submitting = ref(false);

const taken = ref<{ services: string[]; ports: number[] }>({
    services: [],
    ports: [],
});

type Proxy = 'caddy' | 'nginx' | 'apache' | 'traefik';

const proxyNames: Record<Proxy, string> = {
    caddy: 'Caddy',
    nginx: 'nginx',
    apache: 'Apache',
    traefik: 'Traefik',
};
const machineProxy = ref<string | null>(null);
const canChooseProxy = ref(false);
const canChooseHttps = ref(false);
// Like 203-0-113-5.sslip.io: names under it resolve to the machine's public address.
const freeAddress = ref<string | null>(null);
const deployedProxy = ref<Proxy>('caddy');

const currentProxy = computed<Proxy>(() =>
    machineProxy.value && machineProxy.value in proxyNames
        ? (machineProxy.value as Proxy)
        : 'caddy',
);
const chosenProxy = computed<Proxy>(() =>
    form.value.proxy in proxyNames
        ? (form.value.proxy as Proxy)
        : currentProxy.value,
);
const proxyOptions = computed<SelectMenuOption<Proxy>[]>(() => [
    {
        value: 'caddy',
        label: 'Caddy',
        hint: trans('HTTPS on its own, wildcard subdomains too'),
    },
    {
        value: 'nginx',
        label: 'nginx',
        hint: trans('HTTPS from Let’s Encrypt, through certbot'),
    },
    {
        value: 'apache',
        label: 'Apache',
        hint: trans('HTTPS from Let’s Encrypt, through certbot'),
    },
    {
        value: 'traefik',
        label: 'Traefik',
        hint: trans('HTTPS on its own'),
    },
]);
const suggested = ref<{ service: string; port: string | number } | null>(null);
const nameBase = ref('');

function freeName(base: string): string {
    if (!taken.value.services.includes(base)) {
        return base;
    }

    const stem = base.slice(0, 59);
    let n = 2;

    while (taken.value.services.includes(`${stem}-${n}`)) {
        n++;
    }

    return `${stem}-${n}`;
}

function freePort(port: number): number {
    let free = port;

    while (taken.value.ports.includes(free) && free < 65535) {
        free++;
    }

    return free;
}

async function loadTaken(): Promise<void> {
    const machineId = target.value;

    try {
        const response = await request(
            onWorkspace(
                machinesRoutes.deploy.taken({
                    workspace: page.props.workspace.slug,
                    machine: machineId,
                }),
            ).url,
        );

        if (!response.ok || machineId !== target.value) {
            return;
        }

        const body = (await response.json()) as {
            services: string[];
            ports: number[];
            proxy?: string | null;
            canChooseProxy?: boolean;
            canChooseHttps?: boolean;
            freeAddress?: string | null;
            machines?: DeployMachine[];
        };
        taken.value = { services: body.services, ports: body.ports };
        freeAddress.value = body.freeAddress ?? null;
        machineProxy.value = body.proxy ?? null;
        canChooseProxy.value = Boolean(body.canChooseProxy);
        canChooseHttps.value = Boolean(body.canChooseHttps);
        if (body.machines?.length) machines.value = body.machines;
    } catch {
        return;
    }

    const template = selected.value;

    if (
        step.value === 'configure' &&
        template &&
        suggested.value &&
        form.value.service === suggested.value.service &&
        form.value.port === suggested.value.port &&
        !lockedService.value
    ) {
        form.value.service = freeName(
            nameBase.value || suggested.value.service,
        );

        if (template.portEditable && template.kind !== 'app') {
            form.value.port = freePort(template.port);
        }

        suggested.value = {
            service: form.value.service,
            port: form.value.port,
        };
    }
}

watch(target, () => {
    if (open.value) void loadTaken();
});

const replacesExisting = computed(
    () =>
        step.value === 'configure' &&
        taken.value.services.includes(form.value.service),
);

const portTaken = computed(
    () =>
        Boolean(selected.value?.portEditable) &&
        selected.value?.kind !== 'app' &&
        !replacesExisting.value &&
        taken.value.ports.includes(Number(form.value.port)),
);

function repoServiceName(repo: string): string {
    const name = repo
        .replace(/\.git$/, '')
        .split('/')
        .pop()
        ?.toLowerCase()
        .replace(/[^a-z0-9-]+/g, '-')
        .replace(/^[^a-z]+|-+$/g, '');

    return (name ?? '').slice(0, 63);
}

function serviceNameFor(template: ServiceTemplate): string {
    const name =
        template.slug === 'custom-dockerfile' || template.compose
            ? 'app'
            : template.slug;

    return name.replace(/[^a-z0-9-]/g, '-').slice(0, 63);
}

function choose(
    template: ServiceTemplate | null | undefined,
    from: {
        repo: string;
        commit: string;
        baseDir?: string | null;
    } | null = null,
): void {
    if (!template) {
        return;
    }

    selected.value = template;
    lockedService.value = false;
    nameBase.value = from
        ? repoServiceName(from.repo) || serviceNameFor(template)
        : serviceNameFor(template);
    form.value = {
        service: freeName(nameBase.value),
        port:
            template.portEditable && template.kind !== 'app'
                ? freePort(template.port)
                : template.port,
        repo: from?.repo ?? '',
        commit: from?.commit ?? '',
        base_dir: from?.baseDir ?? '',
        health_path: template.health ?? '/',
        compose_file: template.compose ?? '',
        domain: '',
        wildcard: false,
        proxy: '',
        https: true,
        env: Object.fromEntries(
            template.env.map((field) => [
                field.key,
                field.accept ? false : field.default,
            ]),
        ),
        extra_env: '',
    };
    suggested.value = { service: form.value.service, port: form.value.port };
    showExtraEnv.value = false;
    errors.value = {};
    dns.value = null;
    step.value = 'configure';
}

function back(): void {
    if (props.backCloses) {
        open.value = false;

        return;
    }
    step.value = 'pick';
    nextTick(() => searchInput.value?.focus());
}

function errorFor(key: string): string | undefined {
    return errors.value[key];
}

const missingAcceptance = computed(
    () =>
        selected.value?.env.some(
            (field) => field.accept && form.value.env[field.key] !== true,
        ) ?? false,
);

type DomainCheck = {
    domain: string;
    status: 'ready' | 'missing' | 'elsewhere' | 'proxied';
    expected: string | null;
    found: string[];
    record: { type: string; name: string; value: string } | null;
    cloudflare?: CloudflareCheck | null;
    wildcard?: Omit<DomainCheck, 'wildcard'>;
};

const DOMAIN_PATTERN =
    /^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i;

const dns = ref<DomainCheck | null>(null);
const dnsChecking = ref(false);
let dnsTimer: ReturnType<typeof setTimeout> | undefined;
let dnsRequest = 0;

function bareDomain(value: string): string {
    return value
        .trim()
        .toLowerCase()
        .replace(/^[a-z]+:\/\//, '')
        .replace(/[/?#].*$/, '')
        .replace(/:\d+$/, '');
}

async function checkDomain(): Promise<void> {
    const domain = bareDomain(form.value.domain);
    const requestId = ++dnsRequest;

    if (!DOMAIN_PATTERN.test(domain)) {
        dns.value = null;
        dnsChecking.value = false;

        return;
    }

    dnsChecking.value = true;

    try {
        const response = await request(
            onWorkspace(
                machinesRoutes.domainCheck({
                    workspace: page.props.workspace.slug,
                    machine: target.value,
                }),
            ).url,
            { domain, wildcard: form.value.wildcard },
        );

        if (requestId === dnsRequest) {
            dns.value = response.ok
                ? ((await response.json()) as DomainCheck)
                : null;
        }
    } catch {
        if (requestId === dnsRequest) {
            dns.value = null;
        }
    } finally {
        if (requestId === dnsRequest) {
            dnsChecking.value = false;
        }
    }
}

watch(
    () => [form.value.domain, form.value.wildcard, target.value],
    () => {
        clearTimeout(dnsTimer);
        dns.value = null;
        dnsTimer = setTimeout(checkDomain, 500);
    },
);

async function deploy(): Promise<void> {
    const template = selected.value;

    if (!template || submitting.value) {
        return;
    }

    submitting.value = true;
    errors.value = {};
    deployedProxy.value = canChooseProxy.value
        ? chosenProxy.value
        : currentProxy.value;
    const machineId = target.value;

    const env = Object.fromEntries(
        Object.entries(form.value.env).map(([key, value]) => [
            key,
            typeof value === 'boolean' ? (value ? 'true' : '') : value,
        ]),
    );

    try {
        const response = await request(
            onWorkspace(
                machinesRoutes.deploy({
                    workspace: page.props.workspace.slug,
                    machine: machineId,
                }),
            ).url,
            {
                template: template.slug,
                service: form.value.service,
                port: template.portEditable ? form.value.port : null,
                repo: template.kind === 'app' ? form.value.repo : null,
                commit: template.kind === 'app' ? form.value.commit : null,
                health_path:
                    template.kind === 'app' ? form.value.health_path : null,
                compose_file: template.compose
                    ? form.value.compose_file || null
                    : null,
                base_dir:
                    template.kind === 'app' && !template.compose
                        ? form.value.base_dir || null
                        : null,
                domain:
                    template.web && form.value.domain
                        ? bareDomain(form.value.domain)
                        : null,
                wildcard: Boolean(
                    template.web && form.value.domain && form.value.wildcard,
                ),
                proxy:
                    template.web &&
                    form.value.domain &&
                    canChooseProxy.value &&
                    chosenProxy.value !== currentProxy.value
                        ? chosenProxy.value
                        : null,
                https:
                    template.web && form.value.domain && canChooseHttps.value
                        ? form.value.https
                        : null,
                env,
                extra_env: form.value.extra_env || null,
            },
        );
        const body = await response.json().catch(() => ({}));

        if (response.status === 422 && body.errors) {
            errors.value = Object.fromEntries(
                Object.entries(body.errors as Record<string, string[]>).map(
                    ([key, messages]) => [key, messages[0]],
                ),
            );

            return;
        }

        if (!response.ok) {
            errors.value = { form: describe(response.status, body) };

            return;
        }

        result.value = { ...(body as DeployResult), machineId };
        step.value = 'done';
        emit('deployed', result.value);
    } catch (cause) {
        errors.value = { form: errorMessage(cause) };
    } finally {
        submitting.value = false;
    }
}

const result = ref<DeployResult | null>(null);
const copied = ref<string | null>(null);

async function copy(key: string, value: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(value);
        copied.value = key;
        setTimeout(() => {
            if (copied.value === key) {
                copied.value = null;
            }
        }, 1600);
    } catch {}
}

watch(
    open,
    (isOpen) => {
        if (isOpen) {
            step.value = 'pick';
            target.value = props.machineId;
            query.value = '';
            category.value = 'All';
            selected.value = null;
            repoQuery.value = '';
            resetOwnCode();
            result.value = null;
            errors.value = {};
            dns.value = null;
            void loadTaken();
        }
    },
    { immediate: true },
);

// Opened from a link that names a template: go straight to setting it up.
watch(
    open,
    (isOpen) => {
        if (isOpen && props.startWith && step.value === 'pick') {
            choose(props.templates.find((t) => t.slug === props.startWith));
        }
    },
    { immediate: true },
);

const label = 'text-[13px]/5 font-medium tracking-[-0.01em] text-fg-secondary';
const hint = 'text-[12px]/4 tracking-[-0.005em] text-fg-muted';
const field = 'dash-field w-full';
const error = 'text-[12px]/4 text-status-down';
const select =
    'dash-field w-full cursor-pointer justify-between pr-2.5 text-left outline-none focus-visible:border-edge-checkbox-hover';
const iconButton =
    'flex size-7 shrink-0 cursor-pointer items-center justify-center rounded-[6px] text-fg-muted transition-colors hover:bg-black/5 hover:text-fg-primary focus-visible:outline-2 focus-visible:outline-(--ds-ring) dark:hover:bg-white/5';
</script>

<template>
    <DialogRoot v-model:open="open">
        <DialogPortal>
            <DialogOverlay
                class="fixed inset-0 z-50 bg-black/[0.62] backdrop-blur-[3px] data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:animate-in data-[state=open]:fade-in-0"
            />
            <DialogContent
                class="workspace-panel fixed top-1/2 left-1/2 z-50 flex h-[min(650px,calc(100dvh-2rem))] w-[min(920px,calc(100vw-2rem))] -translate-x-1/2 -translate-y-1/2 flex-col overflow-clip rounded-[12px] border-[0.5px] border-[#e2e2e2] bg-white font-ui text-fg-primary shadow-[0_24px_48px_-12px_rgb(0_0_0/0.25),0_1px_2px_rgb(0_0_0/0.1)] outline-none data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=closed]:zoom-out-[0.98] data-[state=open]:animate-in data-[state=open]:fade-in-0 data-[state=open]:zoom-in-[0.98] dark:border-[#2c2c2c] dark:bg-[#161616] dark:shadow-[0_24px_48px_-12px_rgb(0_0_0/0.55),0_1px_2px_rgb(0_0_0/0.5)]"
                @open-auto-focus.prevent="searchInput?.focus()"
            >
                <input
                    ref="fileInput"
                    type="file"
                    multiple
                    class="sr-only"
                    tabindex="-1"
                    aria-hidden="true"
                    @change="onPick"
                />
                <input
                    ref="folderPicker"
                    type="file"
                    webkitdirectory
                    class="sr-only"
                    tabindex="-1"
                    aria-hidden="true"
                    @change="onPick"
                />

                <!-- Header -->
                <header
                    class="flex shrink-0 flex-wrap items-center gap-x-3.5 gap-y-3 border-b-[0.5px] border-[#e8e8e8] py-[18px] pr-[18px] pl-5 dark:border-[#262626]"
                >
                    <button
                        v-if="step === 'configure'"
                        type="button"
                        :class="iconButton"
                        :aria-label="
                            backCloses ? $t('Back') : $t('Back to the library')
                        "
                        @click="back"
                    >
                        <ArrowLeft class="size-4" :stroke-width="1.75" />
                    </button>
                    <span
                        class="flex size-[38px] shrink-0 items-center justify-center rounded-[7px] shadow-[0_1px_2px_rgb(0_0_0/0.08),0_0_0_0.5px_rgb(0_0_0/0.08)] dark:shadow-[inset_0_1px_0_rgb(255_255_255/0.06),0_3px_6px_-2px_rgb(0_0_0/0.35),0_1px_1.5px_rgb(0_0_0/0.55)]"
                        :class="
                            step === 'done'
                                ? 'bg-[#3ec46d]/15 text-[#3ec46d]'
                                : 'bg-[#f2f2f2] text-fg-tertiary dark:bg-[#222]'
                        "
                        aria-hidden="true"
                    >
                        <svg
                            v-if="step === 'done'"
                            viewBox="0 0 24 24"
                            class="size-[18px]"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2.4"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        >
                            <path
                                class="mi-draw"
                                pathLength="1"
                                d="M5 12.5 10 17.5 19 7"
                            />
                        </svg>
                        <TemplateLogo
                            v-else-if="step === 'configure' && selected"
                            :icon="selected.icon"
                        />
                        <FilledIcon v-else name="package-fill" :size="18" />
                    </span>
                    <div class="flex min-w-0 flex-1 basis-56 flex-col gap-px">
                        <DialogTitle
                            class="truncate text-[15px]/[22px] font-medium tracking-[-0.01em] text-fg-primary"
                        >
                            <template v-if="step === 'configure' && selected">
                                {{
                                    $t('Set up :name', { name: selected.name })
                                }}
                            </template>
                            <template v-else-if="step === 'done' && result">
                                {{
                                    result.commit
                                        ? $t('Deploying :service at :commit', {
                                              service: form.service,
                                              commit: result.commit.slice(0, 7),
                                          })
                                        : $t('Deploying :service', {
                                              service: form.service,
                                          })
                                }}
                            </template>
                            <template v-else>{{
                                $t('Deploy a service')
                            }}</template>
                        </DialogTitle>
                        <DialogDescription
                            class="truncate text-[13px]/[18px] tracking-[-0.005em] text-fg-tertiary"
                        >
                            <template v-if="step === 'configure' && selected">
                                {{ selected.description }}
                            </template>
                            <template v-else-if="step === 'done'">
                                {{
                                    $t(
                                        'Follow it in the Deployments tab. The first deploy pulls images, so give it a minute.',
                                    )
                                }}
                            </template>
                            <template v-else-if="machineName">
                                {{
                                    $t(
                                        'Runs on :machine. Pick a service, or bring your own code.',
                                        { machine: machineName },
                                    )
                                }}
                            </template>
                            <template v-else>
                                {{
                                    $t(
                                        'Pick a service, or bring your own code.',
                                    )
                                }}
                            </template>
                        </DialogDescription>
                    </div>
                    <label
                        v-if="step === 'pick'"
                        class="order-last flex h-[34px] w-full items-center gap-2 rounded-[6px] border-[0.5px] border-[#dcdcdc] bg-[#f5f5f5] px-2.5 shadow-[inset_0_1px_2px_rgb(0_0_0/0.06)] focus-within:border-[#a3a3a3] sm:order-none sm:w-[272px] dark:border-[#2a2a2a] dark:bg-[#101010] dark:shadow-[inset_0_1px_2px_rgb(0_0_0/0.5)] dark:focus-within:border-[#4a4a4a]"
                    >
                        <FilledIcon
                            name="search-fill"
                            :size="15"
                            class="shrink-0 text-fg-tertiary"
                        />
                        <span class="sr-only">{{ $t('Search services') }}</span>
                        <input
                            ref="searchInput"
                            v-model="query"
                            type="text"
                            role="combobox"
                            aria-expanded="true"
                            aria-controls="deploy-catalogue"
                            :aria-activedescendant="
                                picked && !isOwnCode
                                    ? `template-${picked}`
                                    : undefined
                            "
                            autocomplete="off"
                            spellcheck="false"
                            :placeholder="$t('Search, or paste a GitHub link')"
                            class="h-full min-w-0 flex-1 bg-transparent text-[13px]/5 tracking-[-0.005em] text-fg-primary outline-none placeholder:text-fg-faint"
                            @keydown="onSearchKeydown"
                            @paste="onSearchPaste"
                        />
                    </label>
                    <kbd
                        class="hidden shrink-0 rounded-[5px] border-[0.5px] border-[#dcdcdc] bg-[#f7f7f7] px-[7px] py-0.5 font-ui-mono text-[11px]/4 text-fg-tertiary shadow-[0_1px_1.5px_rgb(0_0_0/0.08)] sm:block dark:border-[#333] dark:bg-[#1f1f1f] dark:shadow-[inset_0_1px_0_rgb(255_255_255/0.05),0_1px_1.5px_rgb(0_0_0/0.5)]"
                        aria-hidden="true"
                        >Esc</kbd
                    >
                </header>

                <!-- Pick: rail + catalogue or your own code -->
                <div
                    v-if="step === 'pick'"
                    class="flex min-h-0 flex-1 flex-col sm:flex-row"
                >
                    <div
                        class="flex shrink-0 gap-1 overflow-x-auto border-b-[0.5px] border-[#e8e8e8] px-3 py-2 sm:hidden dark:border-[#262626]"
                        role="group"
                        :aria-label="$t('Category')"
                    >
                        <button
                            v-for="option in railOptions"
                            :key="option"
                            type="button"
                            class="h-7 shrink-0 cursor-pointer rounded-[6px] px-2.5 text-[12.5px]/4 font-medium whitespace-nowrap text-fg-tertiary aria-pressed:bg-[#f2f2f2] aria-pressed:text-fg-primary dark:aria-pressed:bg-[#212121]"
                            :aria-pressed="category === option"
                            @click="category = option"
                        >
                            {{ railLabel(option) }}
                        </button>
                    </div>
                    <CategoryRail v-model="category" :counts="counts" />

                    <div
                        id="deploy-catalogue"
                        class="min-h-0 min-w-0 flex-1 overflow-y-auto"
                    >
                        <div
                            v-if="!isOwnCode && looksLikeRepository(query)"
                            class="flex flex-col items-center gap-1 px-5 py-16 text-center"
                        >
                            <p
                                class="text-[13px]/5 font-medium text-fg-primary"
                            >
                                {{ $t('Deploy this repository?') }}
                            </p>
                            <p class="text-[12.5px]/4 text-fg-muted">
                                {{
                                    $t(
                                        'Press Enter and ServerOS looks inside it, then sets it up.',
                                    )
                                }}
                            </p>
                        </div>
                        <TemplateCatalogue
                            v-else-if="!isOwnCode"
                            :groups="groups"
                            :picked="picked"
                            :show-upload="showUploadStrip"
                            :query="query"
                            @pick="(template) => (picked = template.slug)"
                            @choose="choose"
                            @see-all="seeAll"
                            @drop="onDrop"
                            @choose-files="fileInput?.click()"
                        />

                        <div
                            v-if="isOwnCode"
                            class="flex flex-col gap-3.5 px-5 pt-[18px] pb-3"
                        >
                            <h3
                                class="text-[16px]/6 font-medium tracking-[-0.015em] text-fg-primary"
                            >
                                {{ $t('Your own code') }}
                            </h3>

                            <!-- SSH: a deploy key for a repository on another git host -->
                            <div
                                v-if="ssh"
                                class="flex flex-col gap-3 rounded-[10px] border-[0.5px] border-[#e2e2e2] bg-[#fafafa] p-4 text-[13px]/5 dark:border-[#2a2a2a] dark:bg-[#1a1a1a]"
                            >
                                <p class="break-all text-fg-secondary">
                                    {{
                                        $t(
                                            'Deploying :repo over SSH. The machine makes a deploy key for the app, you add it to the repository, and the machine fetches the code with it.',
                                            { repo: ssh.repo },
                                        )
                                    }}
                                </p>
                                <div class="flex flex-col gap-1.5">
                                    <label for="ssh-service" :class="label">{{
                                        $t('App name')
                                    }}</label>
                                    <div :class="field">
                                        <input
                                            id="ssh-service"
                                            v-model.trim="ssh.service"
                                            maxlength="63"
                                            pattern="[a-z][a-z0-9-]*"
                                            class="font-ui-mono"
                                            :disabled="
                                                !!ssh.publicKey ||
                                                ssh.busy !== null
                                            "
                                        />
                                    </div>
                                </div>
                                <template v-if="ssh.publicKey">
                                    <div class="flex flex-col gap-1.5">
                                        <span :class="label">{{
                                            $t('Deploy key')
                                        }}</span>
                                        <div class="flex items-start gap-2">
                                            <code
                                                class="min-w-0 flex-1 rounded-[7px] border-[0.5px] border-edge-field bg-surface-field p-2 font-ui-mono text-[11.5px]/4 break-all text-fg-secondary"
                                                >{{ ssh.publicKey }}</code
                                            >
                                            <button
                                                type="button"
                                                :class="iconButton"
                                                :aria-label="
                                                    $t('Copy the deploy key')
                                                "
                                                @click="
                                                    copy(
                                                        'ssh-key',
                                                        ssh.publicKey,
                                                    )
                                                "
                                            >
                                                <Check
                                                    v-if="copied === 'ssh-key'"
                                                    class="mi-pop size-3.5 text-status-live"
                                                    :stroke-width="2.25"
                                                />
                                                <Copy
                                                    v-else
                                                    class="mi-copy size-3.5"
                                                    :stroke-width="1.75"
                                                />
                                            </button>
                                        </div>
                                        <p :class="hint">
                                            {{
                                                $t(
                                                    'Add it to the repository as a read-only deploy key: Settings → Deploy keys on GitHub and Gitea, Settings → Repository → Deploy keys on GitLab, Repository settings → Access keys on Bitbucket.',
                                                )
                                            }}
                                        </p>
                                    </div>
                                    <div class="flex flex-col gap-1.5">
                                        <label for="ssh-branch" :class="label">
                                            {{ $t('Branch') }}
                                            <span
                                                class="font-normal text-fg-faint"
                                                >{{
                                                    ' ' +
                                                    $t(
                                                        '(empty for the default one)',
                                                    )
                                                }}</span
                                            >
                                        </label>
                                        <div class="flex gap-2">
                                            <div :class="field">
                                                <input
                                                    id="ssh-branch"
                                                    v-model.trim="ssh.branch"
                                                    class="font-ui-mono"
                                                    :placeholder="$t('main')"
                                                    autocomplete="off"
                                                    spellcheck="false"
                                                    @keydown.enter.prevent="
                                                        lookUpBranch
                                                    "
                                                />
                                            </div>
                                            <DashButton
                                                :loading="
                                                    ssh.busy === 'resolve'
                                                "
                                                :disabled="ssh.busy !== null"
                                                :label="
                                                    ssh.busy === 'resolve'
                                                        ? $t('Checking…')
                                                        : ssh.commit
                                                          ? $t('Check again')
                                                          : $t(
                                                                'Key added, check',
                                                            )
                                                "
                                                @click="lookUpBranch"
                                            />
                                        </div>
                                    </div>
                                    <template v-if="ssh.commit">
                                        <p class="text-fg-secondary">
                                            {{
                                                $t('Found :ref at :commit.', {
                                                    ref: (
                                                        ssh.ref ?? ''
                                                    ).replace(
                                                        /^refs\/(heads|tags)\//,
                                                        '',
                                                    ),
                                                    commit: ssh.commit.slice(
                                                        0,
                                                        7,
                                                    ),
                                                })
                                            }}
                                        </p>
                                        <div class="flex flex-col gap-1.5">
                                            <label
                                                for="ssh-setup"
                                                :class="label"
                                                >{{ $t('Setup') }}</label
                                            >
                                            <SelectMenu
                                                id="ssh-setup"
                                                v-model="sshSetup"
                                                :options="appTemplateOptions"
                                                :placeholder="
                                                    $t('Choose a setup')
                                                "
                                                :class="select"
                                            />
                                        </div>
                                    </template>
                                </template>
                                <p v-if="ssh.error" :class="error">
                                    {{ ssh.error }}
                                </p>
                                <div class="flex justify-end gap-2">
                                    <DashButton
                                        size="sm"
                                        :label="$t('Try another')"
                                        @click="
                                            ssh = null;
                                            repoQuery = '';
                                        "
                                    />
                                    <DashButton
                                        v-if="!ssh.publicKey"
                                        size="sm"
                                        :loading="ssh.busy === 'key'"
                                        :disabled="
                                            ssh.busy !== null || !ssh.service
                                        "
                                        :label="
                                            ssh.busy === 'key'
                                                ? $t('Making the key…')
                                                : $t('Make a deploy key')
                                        "
                                        @click="makeKey"
                                    />
                                </div>
                            </div>

                            <UploadStage
                                v-else
                                :phase="stagePhase"
                                :progress="stageProgress"
                                :show-percent="source?.kind !== 'repo'"
                                :file-label="source?.label ?? ''"
                                :machine-label="machineName"
                                :status="stageStatus"
                                :chips="stageChips"
                                :note="stageNote"
                                :busy="busy"
                                @drop="onDrop"
                                @choose-files="fileInput?.click()"
                                @choose-folder="folderPicker?.click()"
                            />

                            <p v-if="detectError" :class="error" role="alert">
                                {{ detectError }}
                            </p>

                            <div
                                v-if="detection && !busy"
                                class="grid grid-cols-1 items-end gap-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto]"
                            >
                                <div class="flex flex-col gap-1.5">
                                    <label for="custom-setup" :class="label">
                                        {{
                                            detection.detected
                                                ? $t(
                                                      'Not right? Use a different setup',
                                                  )
                                                : $t('Setup')
                                        }}
                                    </label>
                                    <SelectMenu
                                        id="custom-setup"
                                        v-model="customSlug"
                                        :options="appTemplateOptions"
                                        :placeholder="$t('Choose a setup')"
                                        :class="select"
                                    />
                                </div>
                                <div
                                    v-if="branchOptions.length > 1"
                                    class="flex flex-col gap-1.5"
                                >
                                    <label for="custom-branch" :class="label">{{
                                        $t('Branch')
                                    }}</label>
                                    <SelectMenu
                                        id="custom-branch"
                                        :model-value="detection.branch"
                                        :options="branchOptions"
                                        :class="select"
                                        @update:model-value="switchBranch"
                                    />
                                </div>
                                <div
                                    v-else-if="!detection.upload"
                                    class="flex flex-col gap-1.5"
                                >
                                    <label for="custom-folder" :class="label">
                                        {{ $t('Folder') }}
                                        <span
                                            class="font-normal text-fg-faint"
                                            >{{
                                                ' ' +
                                                $t('(for an app in a monorepo)')
                                            }}</span
                                        >
                                    </label>
                                    <div :class="field">
                                        <input
                                            id="custom-folder"
                                            v-model="folderInput"
                                            class="font-ui-mono"
                                            :placeholder="$t('apps/web')"
                                            autocomplete="off"
                                            spellcheck="false"
                                            @keydown.enter.prevent="
                                                switchFolder
                                            "
                                            @blur="switchFolder"
                                        />
                                    </div>
                                </div>
                                <span v-else />
                                <DashButton
                                    :label="$t('Try another')"
                                    @click="
                                        resetOwnCode();
                                        repoQuery = '';
                                    "
                                />
                                <div
                                    v-if="branchOptions.length > 1"
                                    class="flex flex-col gap-1.5 sm:col-span-2"
                                >
                                    <label for="custom-folder" :class="label">
                                        {{ $t('Folder') }}
                                        <span
                                            class="font-normal text-fg-faint"
                                            >{{
                                                ' ' +
                                                $t('(for an app in a monorepo)')
                                            }}</span
                                        >
                                    </label>
                                    <div :class="field">
                                        <input
                                            id="custom-folder"
                                            v-model="folderInput"
                                            class="font-ui-mono"
                                            :placeholder="$t('apps/web')"
                                            autocomplete="off"
                                            spellcheck="false"
                                            @keydown.enter.prevent="
                                                switchFolder
                                            "
                                            @blur="switchFolder"
                                        />
                                    </div>
                                </div>
                            </div>

                            <div
                                class="flex items-center gap-3"
                                role="separator"
                            >
                                <span
                                    class="h-[0.5px] flex-1 bg-[#e2e2e2] dark:bg-[#2a2a2a]"
                                />
                                <span
                                    class="text-[12px]/4 tracking-[-0.005em] text-fg-faint"
                                    >{{
                                        gitlab?.connected
                                            ? github?.connected
                                                ? $t(
                                                      'or import from GitHub or GitLab',
                                                  )
                                                : $t('or import from GitLab')
                                            : $t('or import from GitHub')
                                    }}</span
                                >
                                <span
                                    class="h-[0.5px] flex-1 bg-[#e2e2e2] dark:bg-[#2a2a2a]"
                                />
                            </div>

                            <RepoList
                                v-model:query="repoQuery"
                                :github="github"
                                :gitlab="gitlab"
                                :available="page.props.githubApp"
                                :connect-url="
                                    workspacePath('/settings/github/connect')
                                "
                                :gitlab-connect-url="
                                    workspacePath('/integrations')
                                "
                                :busy="detecting ? (source?.url ?? null) : null"
                                :disabled="busy"
                                @import="importRepository"
                            />
                            <p :class="hint">
                                {{
                                    gitlab?.connected
                                        ? $t(
                                              'On Gitea, Bitbucket or your own git server? Paste its SSH address, like git@git.example.com:you/app.git, to deploy it with a deploy key.',
                                          )
                                        : $t(
                                              'On GitLab, Gitea, Bitbucket or your own git server? Paste its SSH address, like git@gitlab.com:you/app.git, to deploy it with a deploy key.',
                                          )
                                }}
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Configure -->
                <form
                    v-else-if="step === 'configure' && selected"
                    id="deploy-configure"
                    class="min-h-0 flex-1 overflow-y-auto"
                    @submit.prevent="deploy"
                >
                    <div
                        class="mx-auto flex w-full max-w-[640px] flex-col gap-5 px-5 py-5"
                    >
                        <p v-if="selected.notes" :class="hint">
                            {{ selected.notes }}
                        </p>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="flex flex-col gap-1.5">
                                <label for="deploy-service" :class="label">{{
                                    $t('Service name')
                                }}</label>
                                <div :class="field">
                                    <input
                                        id="deploy-service"
                                        v-model.trim="form.service"
                                        :readonly="lockedService"
                                        :title="
                                            lockedService
                                                ? $t(
                                                      'The deploy key belongs to this name.',
                                                  )
                                                : undefined
                                        "
                                        required
                                        maxlength="63"
                                        pattern="[a-z][a-z0-9-]*"
                                        class="font-ui-mono"
                                    />
                                </div>
                                <p v-if="errorFor('service')" :class="error">
                                    {{ errorFor('service') }}
                                </p>
                                <p v-else-if="replacesExisting" :class="hint">
                                    {{
                                        $t(
                                            ':service is already on this machine. Deploying with this name replaces it and keeps its data. Pick another name to add a second one.',
                                            { service: form.service },
                                        )
                                    }}
                                </p>
                            </div>
                            <div
                                v-if="selected.portEditable"
                                class="flex flex-col gap-1.5"
                            >
                                <label for="deploy-port" :class="label">
                                    {{
                                        selected.compose
                                            ? $t(
                                                  'Port it publishes on this machine',
                                              )
                                            : selected.kind === 'app'
                                              ? $t('Port your app listens on')
                                              : $t('Port on this machine')
                                    }}
                                </label>
                                <div :class="field">
                                    <input
                                        id="deploy-port"
                                        v-model.number="form.port"
                                        type="number"
                                        min="1"
                                        max="65535"
                                        class="tabular-nums"
                                    />
                                </div>
                                <p v-if="errorFor('port')" :class="error">
                                    {{ errorFor('port') }}
                                </p>
                                <p v-else-if="portTaken" :class="hint">
                                    {{
                                        $t(
                                            'Something on this machine already uses port :port.',
                                            { port: String(form.port) },
                                        )
                                    }}
                                </p>
                            </div>
                        </div>

                        <template v-if="selected.kind === 'app'">
                            <div
                                v-if="uploadSource"
                                class="flex flex-col gap-1.5"
                            >
                                <span :class="label">{{ $t('Code') }}</span>
                                <div
                                    class="dash-field bg-surface-field-readonly text-fg-secondary"
                                >
                                    <FilledIcon
                                        name="zip-fill"
                                        class="shrink-0 text-fg-muted"
                                    />
                                    <span class="min-w-0 truncate">{{
                                        $t(':name, :count files, uploaded', {
                                            name: uploadSource.filename,
                                            count: String(uploadSource.files),
                                        })
                                    }}</span>
                                </div>
                                <p v-if="errorFor('repo')" :class="error">
                                    {{ errorFor('repo') }}
                                </p>
                            </div>
                            <div v-else class="flex flex-col gap-1.5">
                                <label for="deploy-repo" :class="label">{{
                                    $t('Git repository (public HTTPS)')
                                }}</label>
                                <div :class="field">
                                    <input
                                        id="deploy-repo"
                                        v-model.trim="form.repo"
                                        type="url"
                                        required
                                        :placeholder="
                                            $t('https://github.com/you/app.git')
                                        "
                                    />
                                </div>
                                <p v-if="errorFor('repo')" :class="error">
                                    {{ errorFor('repo') }}
                                </p>
                            </div>
                            <div
                                v-if="selected.compose"
                                class="flex flex-col gap-1.5"
                            >
                                <label
                                    for="deploy-compose-file"
                                    :class="label"
                                    >{{ $t('Compose file') }}</label
                                >
                                <div :class="field">
                                    <input
                                        id="deploy-compose-file"
                                        v-model.trim="form.compose_file"
                                        :placeholder="selected.compose"
                                        class="font-ui-mono"
                                    />
                                </div>
                                <p
                                    v-if="errorFor('compose_file')"
                                    :class="error"
                                >
                                    {{ errorFor('compose_file') }}
                                </p>
                                <p :class="hint">
                                    {{
                                        $t(
                                            'Its path in the repository. Every service in it runs as one stack.',
                                        )
                                    }}
                                </p>
                            </div>
                            <div
                                class="grid gap-4"
                                :class="
                                    selected.web &&
                                    !uploadSource &&
                                    'sm:grid-cols-[2fr_1fr]'
                                "
                            >
                                <div
                                    v-if="!uploadSource"
                                    class="flex flex-col gap-1.5"
                                >
                                    <label for="deploy-commit" :class="label">{{
                                        $t('Branch, tag or commit')
                                    }}</label>
                                    <div :class="field">
                                        <input
                                            id="deploy-commit"
                                            v-model.trim="form.commit"
                                            maxlength="255"
                                            :placeholder="
                                                $t(
                                                    'Latest commit on the default branch',
                                                )
                                            "
                                            class="font-ui-mono"
                                        />
                                    </div>
                                    <p v-if="errorFor('commit')" :class="error">
                                        {{ errorFor('commit') }}
                                    </p>
                                </div>
                                <div
                                    v-if="selected.web"
                                    class="flex flex-col gap-1.5"
                                >
                                    <label for="deploy-health" :class="label">{{
                                        $t('Health path')
                                    }}</label>
                                    <div :class="field">
                                        <input
                                            id="deploy-health"
                                            v-model.trim="form.health_path"
                                            class="font-ui-mono"
                                        />
                                    </div>
                                    <p
                                        v-if="errorFor('health_path')"
                                        :class="error"
                                    >
                                        {{ errorFor('health_path') }}
                                    </p>
                                </div>
                            </div>
                        </template>

                        <div v-if="selected.web" class="flex flex-col gap-1.5">
                            <label for="deploy-domain" :class="label">
                                {{ $t('Domain') }}
                                <span class="font-normal text-fg-faint">{{
                                    ' ' + $t('(optional)')
                                }}</span>
                            </label>
                            <div :class="field">
                                <input
                                    id="deploy-domain"
                                    v-model.trim="form.domain"
                                    :placeholder="$t('app.example.com')"
                                    autocomplete="off"
                                    spellcheck="false"
                                />
                            </div>
                            <p v-if="errorFor('domain')" :class="error">
                                {{ errorFor('domain') }}
                            </p>
                            <p
                                v-if="!form.domain"
                                class="flex items-start gap-1.5"
                                :class="hint"
                            >
                                <LockKeyhole
                                    class="mt-px size-3.5 shrink-0"
                                    :stroke-width="1.75"
                                    aria-hidden="true"
                                />
                                <span>
                                    {{
                                        $t(
                                            'Point a domain at this machine and it gets HTTPS automatically, with a certificate that renews itself.',
                                        ) + ' '
                                    }}
                                    <button
                                        v-if="freeAddress && form.service"
                                        type="button"
                                        class="cursor-pointer text-fg-secondary underline decoration-fg-faint underline-offset-2 hover:text-fg-primary"
                                        @click="
                                            form.domain = `${form.service}.${freeAddress}`
                                        "
                                    >
                                        {{
                                            $t('No domain yet? Use :address', {
                                                address: `${form.service}.${freeAddress}`,
                                            })
                                        }}
                                    </button>
                                </span>
                            </p>
                            <p
                                v-else-if="dnsChecking && !dns"
                                class="flex items-center gap-1.5"
                                :class="hint"
                                aria-live="polite"
                            >
                                <LoaderCircle
                                    class="size-3.5 animate-spin"
                                    :stroke-width="1.75"
                                    aria-hidden="true"
                                />
                                {{ $t('Checking DNS…') }}
                            </p>
                            <CloudflareDnsNote
                                v-else-if="dns?.cloudflare"
                                :cloudflare="dns.cloudflare"
                                :wildcard-cloudflare="dns.wildcard?.cloudflare"
                                :workspace-slug="page.props.workspace.slug"
                                :machine-id="target"
                                :service="form.service"
                                :domain="dns.domain"
                                :wildcard="form.wildcard"
                                @pointed="
                                    (check) => (dns = check as DomainCheck)
                                "
                            />
                            <div
                                v-else-if="dns"
                                class="flex flex-col gap-2.5 rounded-[10px] border-[0.5px] px-3.5 py-3 text-[12px]/4"
                                :class="
                                    dns.status === 'ready'
                                        ? 'border-status-live/30 bg-status-live/8'
                                        : 'border-status-pending/30 bg-status-pending/8'
                                "
                                aria-live="polite"
                            >
                                <p
                                    class="flex items-start gap-1.5 text-fg-secondary"
                                >
                                    <CircleCheck
                                        v-if="dns.status === 'ready'"
                                        class="mt-px size-3.5 shrink-0 text-status-live"
                                        :stroke-width="2"
                                        aria-hidden="true"
                                    />
                                    <CircleAlert
                                        v-else
                                        class="mt-px size-3.5 shrink-0 text-status-pending"
                                        :stroke-width="2"
                                        aria-hidden="true"
                                    />
                                    <span v-if="dns.status === 'ready'">
                                        <span class="font-ui-mono">{{
                                            dns.domain
                                        }}</span>
                                        {{
                                            ' ' +
                                            $t(
                                                'points to this machine. HTTPS is set up as it deploys.',
                                            )
                                        }}
                                    </span>
                                    <span v-else-if="dns.status === 'missing'">
                                        {{ $t('No DNS record for') + ' ' }}
                                        <span class="font-ui-mono">{{
                                            dns.domain
                                        }}</span>
                                        {{
                                            ' ' +
                                            $t(
                                                'yet. Add this one where you manage the domain:',
                                            )
                                        }}
                                    </span>
                                    <span v-else-if="dns.status === 'proxied'">
                                        {{
                                            $t(
                                                'It’s behind Cloudflare’s proxy, which blocks the certificate. Set the record to DNS only (grey cloud):',
                                            )
                                        }}
                                    </span>
                                    <span v-else>
                                        {{
                                            $t(
                                                'It points to :value, not this machine. Change the record to:',
                                                { value: dns.found.join(', ') },
                                            )
                                        }}
                                    </span>
                                </p>
                                <template
                                    v-if="dns.status !== 'ready' && dns.record"
                                >
                                    <dl
                                        class="grid grid-cols-[auto_1fr_auto] items-center gap-x-3 gap-y-1 rounded-[7px] border-[0.5px] border-edge-field bg-surface-field px-3 py-2 font-ui-mono text-[12px]/5"
                                    >
                                        <dt class="text-fg-faint">
                                            {{ $t('Type') }}
                                        </dt>
                                        <dd class="col-span-2">
                                            {{ dns.record.type }}
                                        </dd>
                                        <dt class="text-fg-faint">
                                            {{ $t('Name') }}
                                        </dt>
                                        <dd class="col-span-2 truncate">
                                            {{ dns.record.name }}
                                        </dd>
                                        <dt class="text-fg-faint">
                                            {{ $t('Value') }}
                                        </dt>
                                        <dd class="truncate">
                                            {{ dns.record.value }}
                                        </dd>
                                        <button
                                            type="button"
                                            :class="iconButton"
                                            :aria-label="
                                                $t('Copy the IP address')
                                            "
                                            @click="
                                                copy('dns', dns.record.value)
                                            "
                                        >
                                            <Check
                                                v-if="copied === 'dns'"
                                                class="mi-pop size-3.5 text-status-live"
                                                :stroke-width="2.25"
                                            />
                                            <Copy
                                                v-else
                                                class="mi-copy size-3.5"
                                                :stroke-width="1.75"
                                            />
                                        </button>
                                    </dl>
                                    <div
                                        class="flex items-center justify-between gap-3"
                                    >
                                        <span class="text-fg-muted">
                                            {{
                                                $t(
                                                    'You can deploy now. HTTPS starts once the record points here, which can take a few minutes.',
                                                )
                                            }}
                                        </span>
                                        <DashButton
                                            size="sm"
                                            :loading="dnsChecking"
                                            :label="
                                                dnsChecking
                                                    ? $t('Checking…')
                                                    : $t('Check again')
                                            "
                                            @click="checkDomain"
                                        />
                                    </div>
                                </template>
                            </div>
                            <div
                                v-if="form.domain && canChooseProxy"
                                class="mt-1 flex flex-col gap-1.5"
                            >
                                <label for="deploy-proxy" :class="label">{{
                                    $t('Reverse proxy')
                                }}</label>
                                <SelectMenu
                                    id="deploy-proxy"
                                    :model-value="chosenProxy"
                                    :options="proxyOptions"
                                    :class="select"
                                    @update:model-value="
                                        (value) => (form.proxy = value)
                                    "
                                />
                                <p
                                    v-if="chosenProxy !== currentProxy"
                                    :class="hint"
                                >
                                    {{
                                        $t(
                                            'This machine uses :from now. Deploying moves every site on it to :to and stops :from, since only one of them can use ports 80 and 443.',
                                            {
                                                from: proxyNames[currentProxy],
                                                to: proxyNames[chosenProxy],
                                            },
                                        )
                                    }}
                                </p>
                                <p v-if="errorFor('proxy')" :class="error">
                                    {{ errorFor('proxy') }}
                                </p>
                            </div>
                            <label
                                v-if="form.domain && canChooseHttps"
                                class="mt-1 flex cursor-pointer items-start gap-2.5 text-[13px]/5 select-none"
                            >
                                <DashCheckbox
                                    v-model="form.https"
                                    class="mt-[3px]"
                                    :label="$t('Serve over HTTPS')"
                                />
                                <span class="flex flex-col gap-0.5">
                                    <span class="text-fg-primary">{{
                                        $t('Serve over HTTPS')
                                    }}</span>
                                    <span :class="hint">{{
                                        form.https
                                            ? $t(
                                                  ':proxy gets a certificate and sends HTTP to HTTPS, as soon as the domain points here.',
                                                  {
                                                      proxy: proxyNames[
                                                          chosenProxy
                                                      ],
                                                  },
                                              )
                                            : $t(
                                                  'Plain HTTP, no certificate. For when a CDN or proxy in front does HTTPS.',
                                              )
                                    }}</span>
                                </span>
                            </label>
                            <p v-if="errorFor('https')" :class="error">
                                {{ errorFor('https') }}
                            </p>
                            <label
                                v-if="form.domain"
                                class="mt-1 flex cursor-pointer items-start gap-2.5 text-[13px]/5 select-none"
                            >
                                <DashCheckbox
                                    v-model="form.wildcard"
                                    class="mt-[3px]"
                                    :label="$t('Allow wildcard subdomains')"
                                />
                                <span class="flex flex-col gap-0.5">
                                    <span class="text-fg-primary">{{
                                        $t('Allow wildcard subdomains')
                                    }}</span>
                                    <span :class="hint">{{
                                        $t(
                                            chosenProxy === 'caddy' &&
                                                form.https
                                                ? 'Every subdomain, like tenant.:domain, is served by this app and gets its own certificate the first time it’s visited. No DNS provider needed.'
                                                : 'Every subdomain, like tenant.:domain, is served by this app over plain HTTP: a certificate for every subdomain needs Caddy.',
                                            {
                                                domain:
                                                    bareDomain(form.domain) ||
                                                    'example.com',
                                            },
                                        )
                                    }}</span>
                                </span>
                            </label>
                            <p v-if="errorFor('wildcard')" :class="error">
                                {{ errorFor('wildcard') }}
                            </p>
                            <p
                                v-if="
                                    form.wildcard &&
                                    dns?.wildcard &&
                                    !dns.wildcard.cloudflare &&
                                    dns.wildcard.status !== 'ready' &&
                                    dns.wildcard.record
                                "
                                class="text-[12px]/4 text-fg-muted"
                                aria-live="polite"
                            >
                                {{
                                    dns.wildcard.status === 'proxied'
                                        ? $t(
                                              'Subdomains are behind Cloudflare’s proxy, which blocks their certificates. Set it to DNS only:',
                                          )
                                        : $t('Subdomains need a record too:')
                                }}
                                <span class="font-ui-mono text-fg-primary"
                                    >{{ dns.wildcard.record.type }}
                                    {{ dns.wildcard.record.name }} →
                                    {{ dns.wildcard.record.value }}</span
                                >
                            </p>
                        </div>

                        <section
                            v-if="selected.env.length"
                            class="flex flex-col gap-3"
                            aria-labelledby="deploy-settings"
                        >
                            <h3
                                id="deploy-settings"
                                class="text-[11px]/[14px] font-medium tracking-[0.08em] text-fg-muted uppercase"
                            >
                                {{ $t('Settings') }}
                            </h3>
                            <template
                                v-for="entry in selected.env"
                                :key="entry.key"
                            >
                                <label
                                    v-if="entry.accept"
                                    class="flex cursor-pointer items-start gap-2.5 text-[13px]/5 text-fg-secondary select-none"
                                >
                                    <DashCheckbox
                                        v-model="form.env[entry.key] as boolean"
                                        class="mt-[3px]"
                                        :label="entry.label"
                                    />
                                    <span>{{ entry.label }}</span>
                                </label>
                                <div v-else class="flex flex-col gap-1.5">
                                    <label
                                        :for="`deploy-env-${entry.key}`"
                                        :class="label"
                                    >
                                        {{ entry.label }}
                                        <span
                                            class="ml-1 font-ui-mono text-[11px] font-normal text-fg-faint"
                                            >{{ entry.key }}</span
                                        >
                                    </label>
                                    <div :class="field">
                                        <input
                                            :id="`deploy-env-${entry.key}`"
                                            v-model="
                                                form.env[entry.key] as string
                                            "
                                            :placeholder="
                                                entry.generated
                                                    ? $t('Generated on deploy')
                                                    : undefined
                                            "
                                            autocomplete="off"
                                            spellcheck="false"
                                        />
                                    </div>
                                    <p v-if="entry.hint" :class="hint">
                                        {{ entry.hint }}
                                    </p>
                                </div>
                                <p
                                    v-if="errorFor(`env.${entry.key}`)"
                                    :class="error"
                                >
                                    {{ errorFor(`env.${entry.key}`) }}
                                </p>
                            </template>
                        </section>

                        <div
                            v-if="selected.kind === 'app'"
                            class="flex flex-col gap-1.5"
                        >
                            <button
                                v-if="!showExtraEnv"
                                type="button"
                                class="cursor-pointer self-start text-[13px]/5 font-medium text-dash-blue hover:underline hover:underline-offset-4"
                                @click="showExtraEnv = true"
                            >
                                {{ $t('Add environment variables') }}
                            </button>
                            <template v-else>
                                <label for="deploy-extra-env" :class="label">{{
                                    $t('More environment variables')
                                }}</label>
                                <textarea
                                    id="deploy-extra-env"
                                    v-model="form.extra_env"
                                    rows="4"
                                    spellcheck="false"
                                    placeholder="KEY=value, one per line"
                                    class="dash-field h-auto! py-2 font-ui-mono text-[12px]/5 outline-none"
                                />
                                <p v-if="selected.compose" :class="hint">
                                    {{
                                        $t(
                                            'They fill in ${NAME} in the Compose file. Pass them to a service under environment, like DATABASE_URL: ${DATABASE_URL}.',
                                        )
                                    }}
                                </p>
                                <p v-else :class="hint">
                                    {{
                                        $t(
                                            'Available while the app runs. Public ones (NEXT_PUBLIC_…, VITE_…) are also there while it builds.',
                                        )
                                    }}
                                </p>
                                <p v-if="errorFor('extra_env')" :class="error">
                                    {{ errorFor('extra_env') }}
                                </p>
                            </template>
                        </div>

                        <p v-if="errorFor('form')" :class="error" role="alert">
                            {{ errorFor('form') }}
                        </p>
                    </div>
                </form>

                <!-- Done -->
                <div
                    v-else-if="step === 'done' && result"
                    class="min-h-0 flex-1 overflow-y-auto"
                >
                    <div
                        class="mx-auto flex w-full max-w-[640px] flex-col gap-4 px-5 py-5 text-[13px]/5"
                    >
                        <p v-if="result.kept?.length" class="text-fg-secondary">
                            {{
                                $t(
                                    'Kept :keys from the last deploy, so what’s already stored and connected keeps working.',
                                    { keys: result.kept.join(', ') },
                                )
                            }}
                        </p>
                        <div
                            v-if="result.generated.length"
                            class="flex flex-col gap-2"
                        >
                            <p class="text-fg-secondary">
                                {{
                                    $t(
                                        'Save these now. ServerOS doesn’t keep them, so this is the only time you’ll see them.',
                                    )
                                }}
                            </p>
                            <ul class="dash-card">
                                <li
                                    v-for="secret in result.generated"
                                    :key="secret.key"
                                    class="flex items-center gap-3 border-t-[0.5px] border-edge-row px-3.5 py-2.5 first:border-t-0"
                                >
                                    <span class="flex min-w-0 flex-1 flex-col">
                                        <span
                                            class="text-[12px]/4 text-fg-muted"
                                            >{{ secret.label }}</span
                                        >
                                        <span
                                            class="truncate font-ui-mono text-[13px]/5 text-fg-primary select-all"
                                            >{{ secret.value }}</span
                                        >
                                    </span>
                                    <button
                                        type="button"
                                        :class="iconButton"
                                        :aria-label="
                                            $t('Copy :label', {
                                                label: String(secret.label),
                                            })
                                        "
                                        @click="copy(secret.key, secret.value)"
                                    >
                                        <Check
                                            v-if="copied === secret.key"
                                            class="mi-pop size-3.5 text-status-live"
                                            :stroke-width="2.25"
                                        />
                                        <Copy
                                            v-else
                                            class="mi-copy size-3.5"
                                            :stroke-width="1.75"
                                        />
                                    </button>
                                </li>
                            </ul>
                        </div>

                        <p v-if="result.url" class="text-fg-muted">
                            {{ $t('It will answer at') + ' ' }}
                            <span class="font-ui-mono text-fg-primary">{{
                                result.url
                            }}</span>
                        </p>
                        <p
                            v-if="result.url?.startsWith('https://')"
                            class="flex items-start gap-1.5 text-fg-muted"
                        >
                            <LockKeyhole
                                class="mt-0.5 size-3.5 shrink-0"
                                :stroke-width="1.75"
                                aria-hidden="true"
                            />
                            {{
                                $t(
                                    ':proxy on the machine gets the certificate and redirects HTTP to HTTPS, as soon as the domain points here.',
                                    { proxy: proxyNames[deployedProxy] },
                                )
                            }}
                        </p>
                        <div
                            v-if="!result.url && result.localUrl"
                            class="flex flex-col gap-2 text-fg-muted"
                        >
                            <p>
                                {{
                                    $t(
                                        'With no domain it only listens on the machine itself, at',
                                    ) + ' '
                                }}
                                <span class="font-ui-mono text-fg-primary">{{
                                    result.localUrl
                                }}</span
                                >{{
                                    $t(
                                        '. Add a domain to open it from anywhere, or reach it from your computer through an SSH tunnel, then open that address:',
                                    )
                                }}
                            </p>
                            <div
                                v-if="result.tunnel"
                                class="dash-field h-auto! py-1 pr-1"
                            >
                                <code
                                    class="min-w-0 flex-1 truncate font-ui-mono text-[12px]/5 text-fg-primary select-all"
                                    >{{ result.tunnel }}</code
                                >
                                <button
                                    type="button"
                                    :class="iconButton"
                                    :aria-label="
                                        $t('Copy the SSH tunnel command')
                                    "
                                    @click="copy('tunnel', result.tunnel)"
                                >
                                    <Check
                                        v-if="copied === 'tunnel'"
                                        class="mi-pop size-3.5 text-status-live"
                                        :stroke-width="2.25"
                                    />
                                    <Copy
                                        v-else
                                        class="mi-copy size-3.5"
                                        :stroke-width="1.75"
                                    />
                                </button>
                            </div>
                        </div>
                        <p v-if="result.notes" class="text-fg-muted">
                            {{ result.notes }}
                        </p>
                    </div>
                </div>

                <!-- Footer -->
                <footer
                    class="flex h-[60px] shrink-0 items-center gap-2.5 border-t-[0.5px] border-[#e8e8e8] bg-[#fafafa] pr-3.5 pl-5 dark:border-[#262626] dark:bg-[#151515]"
                >
                    <template v-if="step === 'pick' && isOwnCode">
                        <div
                            v-if="ssh"
                            class="min-w-0 flex-1 truncate text-[13px]/5 tracking-[-0.005em] text-fg-tertiary"
                        >
                            {{
                                ssh.commit
                                    ? $t('Choose a setup, then continue.')
                                    : ssh.publicKey
                                      ? $t(
                                            'Add the deploy key, then check the branch.',
                                        )
                                      : $t(
                                            'Make a deploy key, then add it to the repository.',
                                        )
                            }}
                        </div>
                        <div
                            v-else-if="ownReady && customTemplate"
                            :key="`ready-${detection?.commit}`"
                            class="deploy-footer-ready flex min-w-0 flex-1 items-center gap-2"
                        >
                            <span
                                class="flex size-[26px] shrink-0 items-center justify-center rounded-[6px] bg-[#f0f0f0] shadow-[0_1px_1.5px_rgb(0_0_0/0.08)] dark:bg-[#2d2d2d] dark:shadow-[inset_0_1px_0_rgb(255_255_255/0.05),0_1px_1.5px_rgb(0_0_0/0.5)]"
                            >
                                <TemplateLogo
                                    :icon="customTemplate.icon"
                                    :size="16"
                                />
                            </span>
                            <span
                                class="shrink-0 text-[13px]/5 font-medium tracking-[-0.01em] text-fg-primary"
                                >{{ ownName }}</span
                            >
                            <span
                                class="min-w-0 truncate text-[13px]/5 tracking-[-0.005em] text-fg-tertiary"
                                >{{ ownSummary }}</span
                            >
                        </div>
                        <p
                            v-else
                            class="min-w-0 flex-1 truncate text-[13px]/5 tracking-[-0.005em] text-fg-tertiary"
                        >
                            {{
                                busy
                                    ? $t(
                                          'Hang on while ServerOS looks at your code.',
                                      )
                                    : $t(
                                          'Add your code to see what ServerOS will set up.',
                                      )
                            }}
                        </p>
                        <DashButton
                            :label="$t('Cancel')"
                            @click="open = false"
                        />
                        <DashButton
                            v-if="ssh"
                            variant="primary"
                            :disabled="!ssh.commit || !sshSetup"
                            :label="$t('Continue')"
                            @click="continueSsh"
                        />
                        <DashButton
                            v-else
                            :key="ownReady ? `lit-${detection?.commit}` : 'off'"
                            variant="primary"
                            :class="ownReady && 'deploy-footer-lit'"
                            :disabled="!ownReady"
                            :label="
                                ownReady
                                    ? $t('Set up :name', { name: ownName })
                                    : $t('Set up')
                            "
                            @click="continueCustom"
                        />
                    </template>

                    <template v-else-if="step === 'pick'">
                        <template v-if="pickedTemplate">
                            <span
                                class="hidden size-[26px] shrink-0 items-center justify-center rounded-[6px] bg-[#f0f0f0] shadow-[0_1px_1.5px_rgb(0_0_0/0.08)] min-[560px]:flex dark:bg-[#2d2d2d] dark:shadow-[inset_0_1px_0_rgb(255_255_255/0.05),0_1px_1.5px_rgb(0_0_0/0.5)]"
                            >
                                <TemplateLogo
                                    :icon="pickedTemplate.icon"
                                    :size="16"
                                />
                            </span>
                            <span
                                class="hidden min-w-0 truncate text-[13px]/5 font-medium tracking-[-0.01em] text-fg-primary min-[560px]:block"
                                >{{ pickedTemplate.name }}</span
                            >
                        </template>
                        <span
                            class="hidden shrink-0 text-[13px]/5 tracking-[-0.005em] text-fg-tertiary min-[700px]:block"
                            >{{ $t('will run on') }}</span
                        >
                        <DropdownMenu v-if="canSwitchMachine">
                            <DropdownMenuTrigger as-child>
                                <PickerButton
                                    class="hidden min-[700px]:flex"
                                    :label="machineName"
                                    :logo="machine?.provider"
                                />
                            </DropdownMenuTrigger>
                            <DropdownMenuContent
                                class="ds-modal max-h-[50svh] min-w-52 overflow-y-auto font-ui"
                                align="start"
                                side="top"
                            >
                                <DropdownMenuItem
                                    v-for="entry in machines"
                                    :key="entry.id"
                                    :disabled="
                                        !entry.online && entry.id !== target
                                    "
                                    @select="target = entry.id"
                                >
                                    <BrandLogo
                                        :name="entry.provider"
                                        :size="14"
                                    />
                                    <span class="truncate">{{
                                        entry.name
                                    }}</span>
                                    <span
                                        v-if="!entry.online"
                                        class="ml-auto pl-3 text-[12px] text-fg-faint"
                                        >{{ $t('Offline') }}</span
                                    >
                                    <Check
                                        v-else-if="entry.id === target"
                                        class="ml-auto size-3.5"
                                    />
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                        <PickerButton
                            v-else-if="machineName"
                            class="hidden min-[700px]:flex"
                            :label="machineName"
                            :logo="machine?.provider"
                            :menu="false"
                            disabled
                        />
                        <span class="flex-1" />
                        <DashButton
                            :label="$t('Cancel')"
                            @click="open = false"
                        />
                        <DashButton
                            variant="primary"
                            :disabled="!pickedTemplate"
                            :label="
                                pickedTemplate
                                    ? $t('Set up :name', {
                                          name: pickedTemplate.name,
                                      })
                                    : $t('Set up')
                            "
                            @click="choose(pickedTemplate)"
                        />
                    </template>

                    <template v-else-if="step === 'configure' && selected">
                        <span
                            class="hidden size-[26px] shrink-0 items-center justify-center rounded-[6px] bg-[#f0f0f0] shadow-[0_1px_1.5px_rgb(0_0_0/0.08)] min-[560px]:flex dark:bg-[#2d2d2d] dark:shadow-[inset_0_1px_0_rgb(255_255_255/0.05),0_1px_1.5px_rgb(0_0_0/0.5)]"
                        >
                            <TemplateLogo :icon="selected.icon" :size="16" />
                        </span>
                        <span
                            class="hidden min-w-0 truncate font-ui-mono text-[12.5px]/5 text-fg-primary min-[560px]:block"
                            >{{ form.service || selected.name }}</span
                        >
                        <span
                            class="hidden shrink-0 text-[13px]/5 tracking-[-0.005em] text-fg-tertiary min-[700px]:block"
                            >{{ $t('will run on') }}</span
                        >
                        <DropdownMenu v-if="canSwitchMachine && !lockedService">
                            <DropdownMenuTrigger as-child>
                                <PickerButton
                                    class="hidden min-[700px]:flex"
                                    :label="machineName"
                                    :logo="machine?.provider"
                                />
                            </DropdownMenuTrigger>
                            <DropdownMenuContent
                                class="ds-modal max-h-[50svh] min-w-52 overflow-y-auto font-ui"
                                align="start"
                                side="top"
                            >
                                <DropdownMenuItem
                                    v-for="entry in machines"
                                    :key="entry.id"
                                    :disabled="
                                        !entry.online && entry.id !== target
                                    "
                                    @select="target = entry.id"
                                >
                                    <BrandLogo
                                        :name="entry.provider"
                                        :size="14"
                                    />
                                    <span class="truncate">{{
                                        entry.name
                                    }}</span>
                                    <span
                                        v-if="!entry.online"
                                        class="ml-auto pl-3 text-[12px] text-fg-faint"
                                        >{{ $t('Offline') }}</span
                                    >
                                    <Check
                                        v-else-if="entry.id === target"
                                        class="ml-auto size-3.5"
                                    />
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                        <PickerButton
                            v-else-if="machineName"
                            class="hidden min-[700px]:flex"
                            :label="machineName"
                            :logo="machine?.provider"
                            :menu="false"
                            disabled
                        />
                        <span class="flex-1" />
                        <DashButton
                            :label="$t('Cancel')"
                            @click="open = false"
                        />
                        <DashButton
                            type="submit"
                            form="deploy-configure"
                            variant="primary"
                            :loading="submitting"
                            :disabled="missingAcceptance"
                            :label="
                                submitting
                                    ? $t('Queuing…')
                                    : $t('Deploy :name', {
                                          name:
                                              selected.kind === 'app' &&
                                              form.service
                                                  ? form.service
                                                  : selected.name,
                                      })
                            "
                        />
                    </template>

                    <template v-else-if="step === 'done'">
                        <span
                            v-if="machineName"
                            class="min-w-0 flex-1 truncate text-[13px]/5 tracking-[-0.005em] text-fg-tertiary"
                            >{{
                                $t('On :machine', { machine: machineName })
                            }}</span
                        >
                        <span v-else class="flex-1" />
                        <DashButton
                            v-if="!backCloses"
                            :label="$t('Deploy another')"
                            @click="back"
                        />
                        <DashButton
                            variant="primary"
                            :label="$t('Done')"
                            @click="open = false"
                        />
                    </template>
                </footer>
            </DialogContent>
        </DialogPortal>
    </DialogRoot>
</template>

<style scoped>
.deploy-footer-ready {
    animation: deploy-footer-in 250ms cubic-bezier(0, 0, 0.58, 1) 850ms both;
}

@keyframes deploy-footer-in {
    from {
        opacity: 0;
        translate: 0 6px;
    }
    80% {
        opacity: 1;
    }
    to {
        opacity: 1;
        translate: 0 0;
    }
}

.deploy-footer-lit {
    animation:
        deploy-lit 200ms cubic-bezier(0, 0, 0.58, 1) 900ms both,
        deploy-bump 300ms 1050ms both;
}

@keyframes deploy-lit {
    from {
        opacity: 0.5;
    }
    to {
        opacity: 1;
    }
}

@keyframes deploy-bump {
    0% {
        scale: 1;
        animation-timing-function: cubic-bezier(0, 0, 0.58, 1);
    }
    40% {
        scale: 1.04;
        animation-timing-function: cubic-bezier(0.42, 0, 0.58, 1);
    }
    100% {
        scale: 1;
    }
}

@media (prefers-reduced-motion: reduce) {
    .deploy-footer-ready,
    .deploy-footer-lit {
        animation: none;
    }
}
</style>
