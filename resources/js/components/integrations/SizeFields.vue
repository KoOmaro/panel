<script setup lang="ts">
import type { ServerSize } from '@/components/integrations/integrations';

const {
    maxCores = null,
    maxMemoryGb = null,
    minDiskGb = 1,
    errors = {},
} = defineProps<{
    maxCores?: number | null;
    maxMemoryGb?: number | null;
    minDiskGb?: number;
    errors?: Record<string, string>;
}>();

const size = defineModel<ServerSize>({ required: true });

function set(key: keyof ServerSize, value: string): void {
    const number = Number(value);
    size.value = {
        ...size.value,
        [key]: Number.isFinite(number) ? number : 0,
    };
}

const fieldClass =
    'h-8 w-full rounded-[7px] border-[0.5px] border-edge-field bg-surface-field px-2.5 text-[13px]/5 text-fg-primary tabular-nums shadow-field outline-none placeholder:text-fg-faint focus:border-[rgba(51,97,255,0.7)]';
const labelClass = 'text-[12.5px]/4 text-fg-tertiary';
const hintClass = 'text-[11.5px]/4 text-fg-muted';
const errorClass = 'text-[12px]/4 text-status-down';
</script>

<template>
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <label class="flex flex-col gap-1.5">
            <span :class="labelClass">{{ $t('CPU cores') }}</span>
            <input
                :value="size.cores"
                type="number"
                min="1"
                :max="maxCores ?? undefined"
                step="1"
                required
                :class="fieldClass"
                @input="set('cores', ($event.target as HTMLInputElement).value)"
            />
            <span v-if="errors.cores" :class="errorClass" role="alert">{{
                errors.cores
            }}</span>
            <span v-else-if="maxCores" :class="hintClass">{{
                $t('Up to :count', { count: String(maxCores) })
            }}</span>
        </label>
        <label class="flex flex-col gap-1.5">
            <span :class="labelClass">{{ $t('Memory (GB)') }}</span>
            <input
                :value="size.memoryGb"
                type="number"
                min="0.5"
                :max="maxMemoryGb ?? undefined"
                step="0.5"
                required
                :class="fieldClass"
                @input="
                    set('memoryGb', ($event.target as HTMLInputElement).value)
                "
            />
            <span v-if="errors.memory_gb" :class="errorClass" role="alert">{{
                errors.memory_gb
            }}</span>
            <span v-else-if="maxMemoryGb" :class="hintClass">{{
                $t('Up to :size GB', { size: String(maxMemoryGb) })
            }}</span>
        </label>
        <label class="flex flex-col gap-1.5">
            <span :class="labelClass">{{ $t('Disk (GB)') }}</span>
            <input
                :value="size.diskGb"
                type="number"
                :min="minDiskGb"
                step="1"
                required
                :class="fieldClass"
                @input="
                    set('diskGb', ($event.target as HTMLInputElement).value)
                "
            />
            <span v-if="errors.disk_gb" :class="errorClass" role="alert">{{
                errors.disk_gb
            }}</span>
            <span v-else :class="hintClass">{{
                $t('At least :size GB', { size: String(minDiskGb) })
            }}</span>
        </label>
    </div>
</template>
