<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import HomeCard from '@/components/home/HomeCard.vue';
import { BUSY_CPU, WARM_CPU } from '@/components/home/home';
import type { HomeBusy } from '@/components/home/home';
import { onWorkspace } from '@/lib/workspaceRoute';
import machinesRoutes from '@/routes/workspace/machines';

const { machines } = defineProps<{ machines: HomeBusy[] }>();

const page = usePage<{ workspace: { slug: string } }>();
const slug = computed(() => page.props.workspace.slug);

const BAR_HEIGHT = 213;
const ticks = [
    { at: 100, label: '100%' },
    { at: 75, label: '75%' },
    { at: 50, label: '50%' },
    { at: 25, label: '25%' },
    { at: 0, label: '0' },
];

function href(machine: HomeBusy): string {
    return onWorkspace(
        machinesRoutes.show({ workspace: slug.value, machine: machine.id }),
    ).url;
}

function tone(cpu: number): string {
    if (cpu >= BUSY_CPU) return 'bg-status-down';
    if (cpu >= WARM_CPU) return 'bg-[#d4900f] dark:bg-[#f5a833]';

    return 'bg-[#d4d4d4] group-hover:bg-[#c4c4c4] dark:bg-[#4d4d4d] dark:group-hover:bg-[#5a5a5a]';
}

function height(cpu: number): string {
    return `${Math.max(2, Math.round((Math.min(cpu, 100) / 100) * BAR_HEIGHT))}px`;
}
</script>

<template>
    <HomeCard
        icon="machines-fill"
        :title="$t('Top 5 by CPU')"
        heading-id="home-busiest-heading"
    >
        <template #meta>{{ $t('now') }}</template>
        <div
            v-if="machines.length"
            class="flex grow gap-2.5 pt-4 pr-4 pb-3 pl-3.5"
        >
            <div
                class="relative h-[235px] w-7 shrink-0 text-[11px]/[14px] text-fg-faint"
                aria-hidden="true"
            >
                <span
                    v-for="tick in ticks"
                    :key="tick.at"
                    class="absolute left-0"
                    :style="{
                        top: `${((100 - tick.at) / 100) * BAR_HEIGHT - 7}px`,
                    }"
                    >{{ tick.label }}</span
                >
            </div>
            <ol class="flex h-[235px] min-w-0 grow items-end gap-3">
                <li
                    v-for="machine in machines"
                    :key="machine.id"
                    class="flex h-full min-w-0 flex-1"
                >
                    <Link
                        :href="href(machine)"
                        class="group flex h-full min-w-0 grow flex-col items-center justify-end gap-2 rounded-md focus-visible:outline-2 focus-visible:outline-(--ds-ring)"
                        :title="`${machine.name}: ${machine.cpu}%`"
                        :aria-label="
                            $t(':name, :cpu% CPU', {
                                name: machine.name,
                                cpu: String(machine.cpu),
                            })
                        "
                    >
                        <span
                            class="w-7 shrink-0 rounded-t-[5px] transition-colors"
                            :class="tone(machine.cpu)"
                            :style="{ height: height(machine.cpu) }"
                        />
                        <span
                            class="max-w-full truncate text-[11px]/[14px] text-fg-muted transition-colors group-hover:text-fg-secondary"
                            >{{ machine.name }}</span
                        >
                    </Link>
                </li>
            </ol>
        </div>
        <p
            v-else
            class="flex grow items-center justify-center px-4 py-10 text-[13px]/5 text-fg-muted"
        >
            {{ $t('No CPU readings yet.') }}
        </p>
    </HomeCard>
</template>
