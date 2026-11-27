<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';
import DashTray from '@/components/dash/DashTray.vue';
import type { AiUsage } from '@/components/integrations/ai';
import { tokens } from '@/components/integrations/ai';

const { usage } = defineProps<{ usage: AiUsage }>();

const busiest = computed(() =>
    Math.max(1, ...usage.days.map((day) => day.input + day.output)),
);

const meta = computed(() =>
    usage.total.requests === 0
        ? trans('Last 30 days')
        : trans(':tokens tokens in the last 30 days', {
              tokens: tokens(usage.total.input + usage.total.output),
          }),
);

function dayLabel(day: string): string {
    return new Date(`${day}T12:00:00`).toLocaleDateString(undefined, {
        day: 'numeric',
        month: 'short',
    });
}
</script>

<template>
    <DashTray
        icon="activity-fill"
        :title="$t('Usage')"
        :meta="meta"
        panel-class="py-0.5"
    >
        <div
            v-if="usage.total.requests === 0"
            class="px-3.5 py-3 text-[13px]/5 text-fg-muted"
        >
            {{
                $t(
                    'Nothing yet. Tokens show here as Ask AI, server fixes, readmes and tests use this key.',
                )
            }}
        </div>
        <template v-else>
            <div class="flex flex-col gap-2 px-3.5 pt-3 pb-2.5">
                <div class="flex flex-wrap items-baseline gap-x-5 gap-y-1">
                    <span class="text-[12px]/4 text-fg-muted"
                        ><span
                            class="text-[13px]/5 font-medium text-fg-primary tabular-nums"
                            >{{ tokens(usage.total.input) }}</span
                        >
                        {{ $t('in') }}</span
                    >
                    <span class="text-[12px]/4 text-fg-muted"
                        ><span
                            class="text-[13px]/5 font-medium text-fg-primary tabular-nums"
                            >{{ tokens(usage.total.output) }}</span
                        >
                        {{ $t('out') }}</span
                    >
                    <span class="text-[12px]/4 text-fg-muted"
                        ><span
                            class="text-[13px]/5 font-medium text-fg-primary tabular-nums"
                            >{{ usage.total.requests }}</span
                        >
                        {{
                            usage.total.requests === 1
                                ? $t('request')
                                : $t('requests')
                        }}</span
                    >
                    <span class="grow" />
                    <span
                        class="flex items-center gap-3 text-[11.5px]/4 text-fg-muted"
                        aria-hidden="true"
                    >
                        <span class="flex items-center gap-1.5"
                            ><span
                                class="size-2 rounded-[2px] bg-dash-blue/45"
                            />{{ $t('Input') }}</span
                        >
                        <span class="flex items-center gap-1.5"
                            ><span
                                class="size-2 rounded-[2px] bg-dash-blue"
                            />{{ $t('Output') }}</span
                        >
                    </span>
                </div>
                <div
                    class="flex h-16 items-end gap-[3px]"
                    role="img"
                    :aria-label="
                        $t(':tokens tokens in the last 30 days', {
                            tokens: tokens(
                                usage.total.input + usage.total.output,
                            ),
                        })
                    "
                >
                    <span
                        v-for="day in usage.days"
                        :key="day.day"
                        class="flex min-w-0 flex-1 flex-col justify-end"
                        :title="`${dayLabel(day.day)}: ${tokens(day.input)} ${$t('in')}, ${tokens(day.output)} ${$t('out')}`"
                    >
                        <span
                            v-if="day.output > 0"
                            class="rounded-t-[2px] bg-dash-blue"
                            :style="{
                                height: `${Math.max(1, (day.output / busiest) * 64)}px`,
                            }"
                        />
                        <span
                            v-if="day.input > 0"
                            class="bg-dash-blue/45"
                            :class="{ 'rounded-t-[2px]': day.output === 0 }"
                            :style="{
                                height: `${Math.max(1, (day.input / busiest) * 64)}px`,
                            }"
                        />
                        <span
                            v-if="day.input + day.output === 0"
                            class="h-px bg-edge-tray-panel"
                        />
                    </span>
                </div>
                <div
                    class="flex justify-between text-[11px]/[14px] text-fg-faint"
                    aria-hidden="true"
                >
                    <span>{{ dayLabel(usage.days[0]?.day ?? '') }}</span>
                    <span>{{ $t('Today') }}</span>
                </div>
            </div>
            <ul class="flex flex-col border-t-[0.5px] border-edge-tray-panel">
                <li
                    v-for="row in usage.models"
                    :key="row.model"
                    class="flex min-w-0 items-center gap-3 border-t-[0.5px] border-edge-tray-panel px-3.5 py-2.5 first:border-t-0"
                >
                    <span class="flex min-w-0 flex-1 flex-col">
                        <span
                            class="truncate font-ui-mono text-[12px]/5 text-fg-primary"
                            >{{ row.model }}</span
                        >
                        <span class="truncate text-[12px]/4 text-fg-muted">{{
                            row.features.join(', ')
                        }}</span>
                    </span>
                    <span
                        class="w-[72px] shrink-0 text-right text-[12px]/4 text-fg-tertiary tabular-nums max-sm:hidden"
                        >{{
                            row.requests === 1
                                ? $t('1 request')
                                : $t(':count requests', {
                                      count: String(row.requests),
                                  })
                        }}</span
                    >
                    <span
                        class="w-[120px] shrink-0 text-right text-[12px]/4 text-fg-secondary tabular-nums"
                        >{{ tokens(row.input) }} {{ $t('in') }} ·
                        {{ tokens(row.output) }} {{ $t('out') }}</span
                    >
                </li>
            </ul>
        </template>
    </DashTray>
</template>
