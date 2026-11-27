<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import BrandLogo from '@/components/dash/BrandLogo.vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import type { MachineTailnet } from '@/components/integrations/tailscale';
import { toast } from '@/lib/toast';

const { device, settingsUrl = null } = defineProps<{
    device: NonNullable<MachineTailnet['device']>;
    settingsUrl?: string | null;
}>();

async function copy(text: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(text);
        toast.success(trans('Copied.'));
    } catch {
        toast.error(trans('Couldn’t copy. Select it and copy it yourself.'));
    }
}
</script>

<template>
    <section class="flex flex-col gap-2.5" :aria-label="$t('On the tailnet')">
        <div class="flex items-center gap-2">
            <h2
                class="min-w-0 flex-1 truncate text-[13px]/5 font-medium tracking-[-0.01em] text-fg-tertiary"
            >
                {{ $t('On the tailnet') }}
            </h2>
            <Link
                v-if="settingsUrl"
                :href="`${settingsUrl}#tailscale`"
                class="flex h-[26px] items-center gap-1 rounded-[7px] border-[0.5px] border-edge-control bg-surface-control pr-[7px] pl-[9px] text-[12px]/4 font-medium text-fg-secondary hover:text-fg-primary focus-visible:outline-2 focus-visible:outline-(--ds-ring)"
            >
                {{ $t('Settings') }}
                <FilledIcon name="chevron-right" class="text-fg-muted" />
            </Link>
        </div>
        <div class="flex items-center gap-2.5">
            <BrandLogo name="tailscale" />
            <span class="flex min-w-0 flex-1 flex-col text-[13px]">
                <button
                    type="button"
                    class="cursor-copy truncate text-left font-ui-mono leading-[18px] font-medium tracking-[-0.01em] text-fg-primary"
                    :title="$t('Copy the address')"
                    @click="copy(device.address ?? device.name)"
                >
                    {{ device.address ?? '–' }}
                </button>
                <button
                    type="button"
                    class="cursor-copy truncate text-left leading-5 text-fg-tertiary"
                    :title="$t('Copy the tailnet name')"
                    @click="copy(device.name)"
                >
                    {{ device.name }}
                </button>
            </span>
            <span
                class="size-1.5 shrink-0 rounded-full"
                :class="device.online ? 'bg-status-live' : 'bg-status-stopped'"
                :title="device.online ? $t('Connected') : $t('Not connected')"
            />
        </div>
    </section>
</template>
