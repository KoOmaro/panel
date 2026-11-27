<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { SolarLayoutDashboard as LayoutDashboard } from '@/lib/solarIcons';
import Tile from '@/components/workspace/Tile.vue';
import { useAppearance } from '@/composables/useAppearance';
import { forcedAppearance } from '@/lib/workspaceTheme';
import type { WorkspaceTheme } from '@/lib/workspaceTheme';

const { appearance, updateAppearance } = useAppearance();
const page = usePage<{ workspaceTheme?: WorkspaceTheme | null }>();
const forced = computed(() =>
    forcedAppearance(page.props.workspaceTheme?.css ?? null),
);
</script>

<template>
    <Head :title="$t('Appearance')" />

    <Tile
        id="appearance"
        :title="$t('Theme')"
        :description="$t('How the dashboard looks for you, on this device.')"
        :icon="LayoutDashboard"
    >
        <div class="flex flex-col gap-6">
            <div class="flex flex-col gap-2">
                <div
                    class="ds-segments self-start"
                    role="group"
                    :aria-label="$t('Theme')"
                >
                    <button
                        v-for="[value, label] in [
                            ['light', 'Light'],
                            ['dark', 'Dark'],
                            ['system', 'System'],
                        ] as const"
                        :key="value"
                        type="button"
                        class="cursor-pointer"
                        :aria-pressed="appearance === value"
                        @click="updateAppearance(value)"
                    >
                        {{ $t(label) }}
                    </button>
                </div>
                <span v-if="forced" class="text-xs/4 text-(--ds-text-muted)">
                    {{
                        forced === 'dark'
                            ? $t(
                                  'This workspace’s theme is made for dark mode, so the dashboard stays dark here.',
                              )
                            : $t(
                                  'This workspace’s theme is made for light mode, so the dashboard stays light here.',
                              )
                    }}
                </span>
            </div>
        </div>
    </Tile>
</template>
