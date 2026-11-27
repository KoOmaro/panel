<script setup lang="ts">
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { ArrowUpRight } from '@lucide/vue';
import { computed, ref } from 'vue';
import { useSelfHosted } from '@/composables/useSelfHosted';
import BrandLogo from '@/components/dash/BrandLogo.vue';
import AiBrandIcon from '@/components/workspace/AiBrandIcon.vue';
import Tile from '@/components/workspace/Tile.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { appButtonClasses } from '@/lib/appButton';
import type { AiBrand } from '@/lib/aiBrands';
import { SolarPlug as Plug, SolarSparkles as Sparkles } from '@/lib/solarIcons';
import { onWorkspace, workspacePath } from '@/lib/workspaceRoute';
import aiRoutes from '@/routes/workspace/settings/ai';

export type AiProvider = {
    provider: 'anthropic' | 'openai' | 'custom' | 'openrouter' | 'ollama';
    label: string;
    defaultModel: string;
    connected: boolean;
    hint: string | null;
    model: string | null;
    baseUrl: string | null;
    by: string | null;
    connectedAt: string | null;
    accountId?: number | null;
    fallbacks?: string[];
};

const ORDER: AiProvider['provider'][] = [
    'anthropic',
    'openai',
    'openrouter',
    'ollama',
    'custom',
];

const props = defineProps<{
    providers: AiProvider[];
    agentUses: string;
    canManage: boolean;
}>();

const page = usePage<{ workspace: { slug: string } }>();

const listed = computed(() =>
    [...props.providers].sort(
        (a, b) => ORDER.indexOf(a.provider) - ORDER.indexOf(b.provider),
    ),
);
const logos: Partial<Record<AiProvider['provider'], string>> = {
    openrouter: 'openrouter',
    ollama: 'ollama',
};

const brands: Partial<Record<AiProvider['provider'], AiBrand>> = {
    anthropic: 'claude',
    openai: 'openai',
};
const about: Record<
    AiProvider['provider'],
    {
        name: string;
        uses: string;
        keysUrl: string | null;
        placeholder: string;
        model: string;
    }
> = {
    anthropic: {
        name: 'Anthropic',
        uses: 'Server fixes and Claude Code',
        keysUrl: 'https://console.anthropic.com/settings/keys',
        placeholder: 'sk-ant-api03-…',
        model: 'Model for server fixes',
    },
    openai: {
        name: 'OpenAI',
        uses: 'Server fixes and Codex',
        keysUrl: 'https://platform.openai.com/api-keys',
        placeholder: 'sk-proj-…',
        model: 'Model for server fixes',
    },
    custom: {
        name: 'OpenAI-compatible',
        uses: 'Server fixes and Ask AI',
        keysUrl: null,
        placeholder: 'sk-or-v1-…',
        model: 'Model',
    },
    openrouter: {
        name: 'OpenRouter',
        uses: 'Ask AI, server fixes and OpenCode',
        keysUrl: 'https://openrouter.ai/settings/keys',
        placeholder: 'sk-or-v1-…',
        model: 'Default model',
    },
    ollama: {
        name: 'Ollama',
        uses: 'Ask AI, server fixes and OpenCode on your models',
        keysUrl: null,
        placeholder: 'Optional',
        model: 'Default model',
    },
};

const addressed = (p: AiProvider | null): boolean =>
    p?.provider === 'custom' || p?.provider === 'ollama';

function host(url: string | null): string {
    try {
        return url ? new URL(url).host : '';
    } catch {
        return url ?? '';
    }
}

const openFor = ref<AiProvider['provider'] | null>(null);
const current = computed(
    () => props.providers.find((p) => p.provider === openFor.value) ?? null,
);
const open = computed({
    get: () => openFor.value !== null,
    set: (v: boolean) => {
        if (!v) openFor.value = null;
    },
});

const selfHosted = useSelfHosted();
const form = useForm({ key: '', model: '', url: '' });
const confirmingRemove = ref(false);

function show(p: AiProvider): void {
    form.reset();
    form.clearErrors();
    form.model = p.model ?? '';
    form.url = p.baseUrl ?? '';
    confirmingRemove.value = false;
    openFor.value = p.provider;
}

function save(): void {
    const p = current.value;
    if (!p) return;
    form.transform((data) =>
        addressed(p) ? data : { key: data.key, model: data.model },
    ).put(
        onWorkspace(
            aiRoutes.update({
                workspace: page.props.workspace.slug,
                provider: p.provider,
            }),
        ).url,
        {
            preserveScroll: true,
            onSuccess: () => (openFor.value = null),
        },
    );
}

function removeKey(): void {
    const p = current.value;
    if (!p) return;
    if (!confirmingRemove.value) {
        confirmingRemove.value = true;

        return;
    }
    router.delete(
        onWorkspace(
            aiRoutes.destroy({
                workspace: page.props.workspace.slug,
                provider: p.provider,
            }),
        ).url,
        {
            preserveScroll: true,
            onSuccess: () => (openFor.value = null),
        },
    );
}

function added(p: AiProvider): string {
    return p.connectedAt
        ? new Date(p.connectedAt).toLocaleDateString(undefined, {
              day: 'numeric',
              month: 'short',
              year: 'numeric',
          })
        : '';
}

const focusRing =
    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring)';
const field =
    'h-10 w-full rounded-lg border border-(--ds-border) bg-transparent px-3 font-ui-mono text-[13px]/5 placeholder:text-(--ds-text-faint) focus:border-(--ds-border-hover) focus:outline-none';
</script>

<template>
    <Tile
        :title="$t('Your AI providers')"
        :description="
            $t(
                'Run the agents on your own account. Your logs and code go to your provider, and the usage is yours.',
            )
        "
        :icon="Sparkles"
        :padded="false"
    >
        <ul>
            <li
                v-for="(p, i) in listed"
                :key="p.provider"
                :class="{ 'border-t border-(--ds-divider)': i > 0 }"
            >
                <button
                    type="button"
                    class="flex w-full items-center gap-3.5 px-5 py-3.5 text-left transition-colors enabled:cursor-pointer enabled:hover:bg-(--ds-row-hover)"
                    :class="focusRing"
                    :disabled="!canManage"
                    @click="show(p)"
                >
                    <span
                        class="grid size-9 shrink-0 place-items-center rounded-[10px] border border-(--ds-border) bg-(--ds-hover)"
                    >
                        <AiBrandIcon
                            v-if="brands[p.provider]"
                            :brand="brands[p.provider]!"
                            class="size-[18px]"
                        />
                        <BrandLogo
                            v-else-if="logos[p.provider]"
                            :name="logos[p.provider]"
                            :size="18"
                        />
                        <Plug
                            v-else
                            class="size-[18px] text-(--ds-text-muted)"
                            :stroke-width="1.75"
                        />
                    </span>
                    <span class="flex min-w-0 flex-1 flex-col">
                        <span class="text-[13.5px]/5 font-medium">{{
                            $t(about[p.provider].name)
                        }}</span>
                        <span
                            class="truncate text-[12.5px]/5 text-(--ds-text-muted)"
                        >
                            <template v-if="p.connected">
                                <template v-if="p.hint">
                                    <code class="font-ui-mono text-[12px]">{{
                                        p.hint
                                    }}</code>
                                    ·
                                </template>
                                {{ p.model ?? p.defaultModel }}
                                <template v-if="p.baseUrl">
                                    · {{ host(p.baseUrl) }}</template
                                >
                            </template>
                            <template v-else>{{
                                $t(about[p.provider].uses)
                            }}</template>
                        </span>
                    </span>
                    <span
                        v-if="p.connected"
                        class="flex items-center gap-1.5 text-[12.5px]/5 text-(--ds-green)"
                    >
                        <span
                            class="size-1.5 rounded-full bg-(--ds-green)"
                            aria-hidden="true"
                        />{{ $t('Connected') }}
                    </span>
                    <span
                        v-else-if="canManage"
                        :class="appButtonClasses('secondary', 'sm')"
                        aria-hidden="true"
                        >{{ $t('Connect') }}</span
                    >
                    <span
                        v-if="p.connected && canManage"
                        :class="appButtonClasses('secondary', 'sm')"
                        aria-hidden="true"
                        >{{ $t('Manage') }}</span
                    >
                </button>
            </li>
        </ul>
        <template #footer>
            <p class="text-[12.5px]/5 text-(--ds-text-muted)">
                {{ $t('Server fixes run on') }}
                <span class="font-medium text-(--ds-text)">{{
                    agentUses
                }}</span>
            </p>
        </template>
    </Tile>

    <Dialog v-model:open="open">
        <DialogContent
            v-if="current"
            class="ds-modal top-[16%] translate-y-0 gap-0 overflow-hidden p-0 font-ui text-(--ds-text) sm:max-w-md"
        >
            <form @submit.prevent="save">
                <div
                    class="flex flex-col items-center gap-3 px-6 pt-7 pb-5 text-center"
                >
                    <span
                        class="grid size-12 place-items-center rounded-[14px] border border-(--ds-border) bg-(--ds-hover)"
                    >
                        <AiBrandIcon
                            v-if="brands[current.provider]"
                            :brand="brands[current.provider]!"
                            class="size-6"
                        />
                        <BrandLogo
                            v-else-if="logos[current.provider]"
                            :name="logos[current.provider]"
                            :size="24"
                        />
                        <Plug
                            v-else
                            class="size-6 text-(--ds-text-muted)"
                            :stroke-width="1.75"
                        />
                    </span>
                    <div class="flex flex-col gap-1">
                        <DialogTitle class="text-[15px]/6 font-semibold">
                            {{
                                current.connected
                                    ? $t(about[current.provider].name)
                                    : $t('Connect :name', {
                                          name: $t(
                                              about[current.provider].name,
                                          ),
                                      })
                            }}
                        </DialogTitle>
                        <DialogDescription
                            class="text-[13px]/5 text-(--ds-text-muted)"
                        >
                            {{
                                current.connected
                                    ? $t(
                                          current.hint
                                              ? 'Key :hint, added :when:by.'
                                              : 'Key added :when:by.',
                                          {
                                              hint: String(current.hint),
                                              when: added(current),
                                              by: current.by
                                                  ? ' ' +
                                                    $t('by :name', {
                                                        name: current.by,
                                                    })
                                                  : '',
                                          },
                                      )
                                    : $t(':uses run on your :name account.', {
                                          uses: about[current.provider].uses,
                                          name: about[current.provider].name,
                                      })
                            }}
                        </DialogDescription>
                    </div>
                </div>

                <div class="flex flex-col gap-4 px-6 pb-6">
                    <label
                        v-if="addressed(current)"
                        class="flex flex-col gap-1.5"
                    >
                        <span
                            class="text-[12px]/5 font-medium text-(--ds-text-muted)"
                            >{{
                                current.provider === 'ollama'
                                    ? $t('Address')
                                    : $t('API address')
                            }}</span
                        >
                        <input
                            v-model="form.url"
                            type="url"
                            inputmode="url"
                            autocomplete="off"
                            spellcheck="false"
                            :class="field"
                            :placeholder="
                                current.provider === 'ollama'
                                    ? 'https://ollama.example.com'
                                    : 'https://openrouter.ai/api/v1'
                            "
                            required
                            :autofocus="!current.connected"
                        />
                        <span
                            v-if="form.errors.url"
                            class="text-[12px]/4 text-(--ds-red)"
                            >{{ form.errors.url }}</span
                        >
                        <span
                            v-else
                            class="text-[12px]/4 text-(--ds-text-faint)"
                            >{{
                                current.provider === 'ollama'
                                    ? $t(
                                          'Where Ollama listens. ServerOS lists its models from /api/tags to check it.',
                                      )
                                    : $t(
                                          'Any API that works like OpenAI’s: DeepSeek, Groq, a vLLM server and more.',
                                      )
                            }}<template v-if="selfHosted">
                                {{
                                    $t(
                                        'For one on your own network, like Ollama, set MONITORS_ALLOW_PRIVATE=true.',
                                    )
                                }}</template
                            ></span
                        >
                    </label>
                    <label class="flex flex-col gap-1.5">
                        <span
                            class="flex items-center justify-between text-[12px]/5 font-medium text-(--ds-text-muted)"
                        >
                            {{
                                current.provider === 'ollama'
                                    ? $t('Token, if a proxy asks for one')
                                    : current.connected
                                      ? $t('Replace the key')
                                      : $t('API key')
                            }}
                            <a
                                v-if="about[current.provider].keysUrl"
                                :href="
                                    about[current.provider].keysUrl ?? undefined
                                "
                                target="_blank"
                                rel="noopener"
                                class="inline-flex items-center gap-0.5 font-normal text-(--ds-text-faint) hover:text-(--ds-text)"
                                >{{ $t('Get a key')
                                }}<ArrowUpRight class="size-3"
                            /></a>
                        </span>
                        <input
                            v-model="form.key"
                            type="password"
                            autocomplete="off"
                            spellcheck="false"
                            :class="field"
                            :placeholder="
                                current.connected
                                    ? $t('Leave empty to keep it')
                                    : about[current.provider].placeholder
                            "
                            :required="
                                !current.connected &&
                                current.provider !== 'ollama'
                            "
                            :autofocus="
                                !current.connected && !addressed(current)
                            "
                        />
                        <span
                            v-if="form.errors.key"
                            class="text-[12px]/4 text-(--ds-red)"
                            >{{ form.errors.key }}</span
                        >
                    </label>
                    <label class="flex flex-col gap-1.5">
                        <span
                            class="text-[12px]/5 font-medium text-(--ds-text-muted)"
                            >{{ $t(about[current.provider].model) }}</span
                        >
                        <input
                            v-model="form.model"
                            type="text"
                            spellcheck="false"
                            :class="field"
                            :placeholder="
                                current.defaultModel ||
                                (current.provider === 'ollama'
                                    ? $t('The first model it lists')
                                    : 'deepseek/deepseek-chat')
                            "
                            :required="current.provider === 'custom'"
                        />
                        <span
                            v-if="form.errors.model"
                            class="text-[12px]/4 text-(--ds-red)"
                            >{{ form.errors.model }}</span
                        >
                    </label>
                    <ul
                        v-if="confirmingRemove && current.fallbacks?.length"
                        class="flex flex-col gap-1 rounded-[8px] border-[0.5px] border-[rgba(240,148,60,0.25)] bg-[rgba(240,148,60,0.07)] px-3 py-2.5 text-[12.5px]/[18px] text-(--ds-text)"
                        role="alert"
                    >
                        <li v-for="line in current.fallbacks" :key="line">
                            {{ line }}
                        </li>
                    </ul>
                    <Link
                        v-if="current.accountId"
                        :href="
                            workspacePath(`/integrations/${current.accountId}`)
                        "
                        class="inline-flex items-center gap-0.5 self-start text-[12.5px]/5 text-(--ds-text-muted) hover:text-(--ds-text)"
                        >{{ $t('Its models, token use and a test prompt')
                        }}<ArrowUpRight class="size-3"
                    /></Link>
                    <p class="text-[12px]/4 text-(--ds-text-faint)">
                        {{
                            $t(
                                'Checked with :name before it’s saved, then kept encrypted. It’s never shown again.',
                                {
                                    name: addressed(current)
                                        ? host(form.url) || $t('the provider')
                                        : about[current.provider].name,
                                },
                            )
                        }}
                    </p>
                </div>

                <div
                    class="flex items-center gap-2 border-t border-(--ds-border) px-6 py-3.5"
                >
                    <button
                        v-if="current.connected"
                        type="button"
                        :class="appButtonClasses('danger', 'md', focusRing)"
                        @click="removeKey"
                    >
                        {{
                            confirmingRemove
                                ? $t('Remove key, sure?')
                                : $t('Remove key')
                        }}
                    </button>
                    <button
                        type="button"
                        :class="
                            appButtonClasses(
                                'quiet',
                                'md',
                                focusRing,
                                'ml-auto',
                            )
                        "
                        @click="openFor = null"
                    >
                        {{ $t('Cancel') }}
                    </button>
                    <button
                        type="submit"
                        :class="appButtonClasses('primary', 'md', focusRing)"
                        :disabled="form.processing"
                    >
                        {{
                            form.processing
                                ? $t('Checking…')
                                : current.connected
                                  ? $t('Save')
                                  : $t('Connect')
                        }}
                    </button>
                </div>
            </form>
        </DialogContent>
    </Dialog>
</template>
