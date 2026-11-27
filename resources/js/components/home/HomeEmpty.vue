<script setup lang="ts">
import { Plug, Plus } from '@lucide/vue';
import DashButton from '@/components/dash/DashButton.vue';
import EmptyArt from '@/components/dash/EmptyArt.vue';

const { canManage } = defineProps<{ canManage: boolean }>();

const emit = defineEmits<{ connect: []; create: [] }>();
</script>

<template>
    <section
        class="flex flex-col items-center"
        aria-labelledby="home-empty-title"
    >
        <EmptyArt kind="art" class="h-auto max-w-full!" />
        <div class="flex max-w-[372px] flex-col items-center gap-1 pt-1">
            <h1
                id="home-empty-title"
                class="text-center text-[16px]/6 font-medium tracking-[-0.015em] text-fg-primary"
            >
                {{ $t('Welcome to ServerOS') }}
            </h1>
            <p
                class="text-center text-[13px]/5 font-medium tracking-[-0.005em] text-pretty text-fg-muted"
            >
                {{
                    $t(
                        'Start with a project: an app and what it uses, like its database and cache. Then connect the machine it runs on.',
                    )
                }}
            </p>
            <div
                v-if="canManage"
                class="flex flex-wrap justify-center gap-2 pt-4"
            >
                <DashButton
                    :icon="Plug"
                    :label="$t('Connect a machine')"
                    aria-haspopup="dialog"
                    @click="emit('connect')"
                />
                <DashButton
                    variant="primary"
                    :icon="Plus"
                    :label="$t('New project')"
                    aria-haspopup="dialog"
                    @click="emit('create')"
                />
            </div>
        </div>
    </section>
</template>
