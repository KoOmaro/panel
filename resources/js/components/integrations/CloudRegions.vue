<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, ref, watch } from 'vue';
import DashButton from '@/components/dash/DashButton.vue';
import DashCheckbox from '@/components/dash/DashCheckbox.vue';
import DashTray from '@/components/dash/DashTray.vue';
import type { CloudRegion } from '@/components/integrations/integrations';
import { onWorkspace } from '@/lib/workspaceRoute';
import serverRoutes from '@/routes/workspace/integrations/servers';
import type { Workspace } from '@/types';

const {
    regions,
    integrationId,
    disabled = false,
} = defineProps<{
    regions?: { list: CloudRegion[]; error: string | null };
    integrationId: number;
    disabled?: boolean;
}>();

const page = usePage<{ workspace: Workspace }>();
const picked = ref<Record<string, boolean>>({});
const saving = ref(false);
const error = ref('');

function reset(): void {
    picked.value = Object.fromEntries(
        (regions?.list ?? []).map((region) => [region.name, region.chosen]),
    );
}

watch(() => regions, reset, { immediate: true });

const chosen = computed(() =>
    (regions?.list ?? []).filter((region) => picked.value[region.name]),
);
const changed = computed(() =>
    (regions?.list ?? []).some(
        (region) => !!picked.value[region.name] !== region.chosen,
    ),
);
const meta = computed(() =>
    regions
        ? trans(':count of :total', {
              count: String(chosen.value.length),
              total: String(regions.list.length),
          })
        : null,
);

function locked(region: CloudRegion): string | null {
    if (region.default) return trans('The default region is always on.');
    if (region.chosen && region.linked.length)
        return trans(':machines linked here', {
            machines: region.linked.join(', '),
        });

    return null;
}

function save(): void {
    saving.value = true;
    error.value = '';
    router.put(
        onWorkspace(
            serverRoutes.regions({
                workspace: page.props.workspace.slug,
                integration: integrationId,
            }),
        ).url,
        { regions: chosen.value.map((region) => region.name) },
        {
            preserveScroll: true,
            only: ['regions', 'servers', 'integration', 'usage'],
            onError: (errors) => {
                error.value =
                    errors.regions ?? trans('That didn’t work. Try again.');
            },
            onFinish: () => (saving.value = false),
        },
    );
}
</script>

<template>
    <DashTray
        icon="globe-fill"
        :title="$t('Regions')"
        :meta="meta"
        panel-class="py-0.5"
    >
        <template v-if="changed" #actions>
            <DashButton size="sm" :label="$t('Undo')" @click="reset" />
            <DashButton
                size="sm"
                variant="primary"
                :label="$t('Save')"
                :loading="saving"
                :disabled="disabled"
                @click="save"
            />
        </template>
        <div
            v-if="!regions"
            class="flex flex-col"
            role="status"
            :aria-label="$t('Loading regions')"
        >
            <div
                v-for="n in 3"
                :key="n"
                class="flex items-center gap-3 border-t-[0.5px] border-edge-tray-panel px-3.5 py-3 first:border-t-0"
            >
                <span
                    class="h-3 w-32 animate-pulse rounded bg-surface-badge motion-reduce:animate-none"
                />
            </div>
        </div>
        <template v-else>
            <p
                v-if="regions.error || error"
                class="border-b-[0.5px] border-edge-tray-panel px-3.5 py-2.5 text-[12.5px]/[18px] text-status-down"
                role="alert"
            >
                {{ error || regions.error }}
            </p>
            <p
                class="border-b-[0.5px] border-edge-tray-panel px-3.5 py-2.5 text-[12.5px]/[18px] text-fg-muted"
            >
                {{
                    $t(
                        'ServerOS lists the servers in these regions and links them to your machines. New servers can go in any of them.',
                    )
                }}
            </p>
            <ul class="grid grid-cols-1 sm:grid-cols-2">
                <li
                    v-for="region in regions.list"
                    :key="region.name"
                    class="border-t-[0.5px] border-edge-tray-panel first:border-t-0 sm:[&:nth-child(2)]:border-t-0 sm:[&:nth-child(even)]:border-l-[0.5px]"
                >
                    <label
                        class="flex min-w-0 items-center gap-3 px-3.5 py-2.5"
                        :class="
                            locked(region) || disabled
                                ? 'cursor-default'
                                : 'cursor-pointer hover:bg-surface-hover'
                        "
                        :title="locked(region) ?? undefined"
                    >
                        <DashCheckbox
                            v-model="picked[region.name]"
                            :label="region.city"
                            :disabled="!!locked(region) || disabled"
                        />
                        <span class="flex min-w-0 flex-1 flex-col">
                            <span
                                class="truncate text-[13px]/5 font-medium tracking-[-0.01em] text-fg-primary"
                                >{{ region.city }}</span
                            >
                            <span
                                class="truncate font-ui-mono text-[11.5px]/4 text-fg-muted"
                                >{{ region.name
                                }}<template v-if="region.default">
                                    · {{ $t('default') }}</template
                                ></span
                            >
                        </span>
                        <span
                            v-if="region.chosen"
                            class="shrink-0 text-[12px]/4 text-fg-tertiary tabular-nums"
                            >{{
                                region.servers === 1
                                    ? $t('1 server')
                                    : $t(':count servers', {
                                          count: String(region.servers),
                                      })
                            }}</span
                        >
                    </label>
                </li>
            </ul>
        </template>
    </DashTray>
</template>
