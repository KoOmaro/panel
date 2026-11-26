<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { Sparkles } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';
import DashButton from '@/components/dash/DashButton.vue';
import { useAssistant } from '@/composables/useAssistant';
import type { HealthIssue } from '@/lib/healthIssues';
import { onWorkspace, workspacePath } from '@/lib/workspaceRoute';
import issueRoutes from '@/routes/workspace/issues';

const { issues, canDismiss } = defineProps<{
    issues: HealthIssue[];
    canDismiss: boolean;
}>();

const page = usePage<{
    workspace: { slug: string };
    workspaceBase?: string | null;
}>();
const assistant = useAssistant();

const SHOWN = 4;
const expanded = ref(false);
const shown = computed(() =>
    expanded.value ? issues : issues.slice(0, SHOWN),
);
const opened = ref<string | null>(null);
const dismissing = ref<string[]>([]);

function href(path: string): string {
    const base = page.props.workspaceBase ?? '';
    if (/^https?:\/\//.test(path) || (base && path.startsWith(`${base}/`))) {
        return path;
    }

    return workspacePath(path);
}

function dismiss(ids: string[]): void {
    dismissing.value = ids;
    router.post(
        onWorkspace(issueRoutes.dismiss(page.props.workspace.slug)).url,
        { ids },
        {
            preserveScroll: true,
            preserveState: true,
            only: ['issues'],
            onFinish: () => (dismissing.value = []),
        },
    );
}

const tone: Record<HealthIssue['severity'], string> = {
    critical: 'bg-status-down',
    warning: 'bg-status-pending',
    notice: 'bg-fg-faint',
};

function since(iso: string | null): string | null {
    if (!iso) return null;
    const seconds = Math.max(
        0,
        Math.round((Date.now() - new Date(iso).getTime()) / 1000),
    );
    if (seconds < 60) return trans('just now');
    if (seconds < 3600) return `${Math.floor(seconds / 60)} min`;
    if (seconds < 86400) return `${Math.floor(seconds / 3600)} h`;

    return `${Math.floor(seconds / 86400)} d`;
}
</script>

<template>
    <section class="dash-card" aria-labelledby="issues-heading">
        <div class="flex items-center gap-2 px-[18px] py-3">
            <h2
                id="issues-heading"
                class="text-[14px]/5 font-medium text-fg-primary"
            >
                {{ $t('Needs attention') }}
            </h2>
            <span class="text-[13px]/5 text-fg-tertiary tabular-nums">{{
                issues.length
            }}</span>
            <button
                v-if="canDismiss && issues.length > 1"
                type="button"
                class="ml-auto cursor-pointer rounded-md text-[12px]/4 font-medium text-fg-muted transition-colors hover:text-fg-primary focus-visible:outline-2 focus-visible:outline-(--ds-ring) disabled:opacity-50"
                :disabled="dismissing.length > 0"
                @click="dismiss(issues.map((issue) => issue.id))"
            >
                {{ $t('Mark all as read') }}
            </button>
        </div>
        <ul>
            <li
                v-for="issue in shown"
                :key="issue.id"
                class="flex items-center gap-3 border-t-[0.5px] border-edge-tabs px-[18px] py-3"
            >
                <span
                    class="flex size-5 shrink-0 items-center justify-center"
                    :aria-label="issue.severity"
                >
                    <span
                        class="size-2 rounded-full"
                        :class="tone[issue.severity]"
                    />
                </span>
                <button
                    type="button"
                    class="flex min-w-0 grow cursor-pointer flex-col items-start text-left focus-visible:outline-2 focus-visible:outline-(--ds-ring)"
                    :aria-expanded="opened === issue.id"
                    @click="opened = opened === issue.id ? null : issue.id"
                >
                    <span
                        class="max-w-full truncate text-[13px]/5 font-medium text-fg-primary"
                    >
                        {{ issue.title }}
                        <span
                            v-if="since(issue.since)"
                            class="ml-1 font-normal text-fg-muted tabular-nums"
                            >{{ since(issue.since) }}</span
                        >
                    </span>
                    <span
                        class="max-w-full text-[12px]/4 text-pretty text-fg-tertiary"
                        :class="{ truncate: opened !== issue.id }"
                    >
                        {{ issue.detail }}
                    </span>
                </button>
                <div class="flex shrink-0 items-center gap-1.5">
                    <DashButton
                        v-if="issue.ask"
                        size="sm"
                        :icon="Sparkles"
                        :label="$t('Fix with Ask AI')"
                        :disabled="assistant.streaming.value"
                        class="max-md:hidden!"
                        @click="assistant.askAbout(issue.ask)"
                    />
                    <DashButton
                        size="sm"
                        :href="href(issue.href)"
                        :label="issue.action"
                    />
                    <DashButton
                        v-if="canDismiss"
                        size="sm"
                        icon="check"
                        :disabled="dismissing.includes(issue.id)"
                        :title="$t('Mark as read')"
                        :aria-label="
                            $t('Mark as read: :title', {
                                title: String(issue.title),
                            })
                        "
                        @click="dismiss([issue.id])"
                    />
                </div>
            </li>
        </ul>
        <button
            v-if="issues.length > SHOWN"
            type="button"
            class="w-full cursor-pointer border-t-[0.5px] border-edge-tabs px-[18px] py-2.5 text-left text-[12px]/4 font-medium text-fg-tertiary transition-colors hover:bg-surface-hover hover:text-fg-primary focus-visible:outline-2 focus-visible:outline-(--ds-ring)"
            @click="expanded = !expanded"
        >
            {{
                expanded
                    ? $t('Show fewer')
                    : $t('Show :value more', {
                          value: String(issues.length - SHOWN),
                      })
            }}
        </button>
    </section>
</template>
