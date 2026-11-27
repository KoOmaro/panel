<script setup lang="ts">
import DashButton from '@/components/dash/DashButton.vue';
import DashTray from '@/components/dash/DashTray.vue';
import type {
    AppVariable,
    LinkedDatabase,
} from '@/components/service/serviceTypes';

defineProps<{
    variables: AppVariable[] | null;
    linked: LinkedDatabase;
    canManage: boolean;
    online: boolean;
    container?: boolean;
}>();

defineEmits<{ edit: []; config: [] }>();
</script>

<template>
    <DashTray
        icon="key-fill"
        :title="$t('Environment variables')"
        class="shrink-0"
    >
        <template v-if="variables && canManage" #actions>
            <DashButton
                size="sm"
                :label="$t('Edit')"
                :disabled="!online"
                @click="$emit('edit')"
            />
        </template>
        <template v-else-if="container" #actions>
            <DashButton
                size="sm"
                :label="$t('Show config')"
                @click="$emit('config')"
            />
        </template>

        <div class="px-[18px] pt-3 pb-3.5">
            <dl v-if="variables?.length" class="flex flex-col text-[12px]/4">
                <div
                    v-for="variable in variables"
                    :key="variable.name"
                    class="flex min-w-0 items-center gap-3 border-b-[0.5px] border-edge-tabs py-2 last:border-b-0"
                >
                    <dt
                        class="w-[220px] shrink-0 truncate font-ui-mono text-fg-primary max-sm:w-32"
                        :title="variable.name"
                    >
                        {{ variable.name }}
                    </dt>
                    <dd
                        v-if="variable.from"
                        class="min-w-0 flex-1 truncate tracking-[-0.005em] text-fg-secondary"
                    >
                        {{ $t('from') + ' ' }}
                        <a
                            v-if="linked?.url && linked.name === variable.from"
                            :href="linked.url"
                            class="underline decoration-from-font underline-offset-2 hover:text-fg-primary"
                            >{{
                                $t(':name on :machine', {
                                    name: variable.from,
                                    machine: linked.machine,
                                })
                            }}</a
                        >
                        <span v-else>{{ variable.from }}</span>
                    </dd>
                    <dd
                        v-else
                        class="min-w-0 flex-1 truncate font-ui-mono text-fg-muted"
                        :title="$t('Values are never shown here')"
                    >
                        ••••••••••••••••
                    </dd>
                </div>
            </dl>
            <p v-else class="py-2 text-[13px]/5 text-fg-muted">
                {{
                    variables
                        ? $t('None yet. Saving one deploys it again.')
                        : container
                          ? $t(
                                'ServerOS keeps variables for the apps it deploys. This container’s own are in its config.',
                            )
                          : $t(
                                'ServerOS keeps variables for the apps it deploys. This service sets its own.',
                            )
                }}
            </p>
        </div>
    </DashTray>
</template>
