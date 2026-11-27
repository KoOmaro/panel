<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import DashButton from '@/components/dash/DashButton.vue';
import InputError from '@/components/InputError.vue';
import StepHeading from './StepHeading.vue';
import * as ui from './styles';
import { stepUrl } from './types';
import type { Summary } from './types';

defineProps<{ summary: Summary }>();
</script>

<template>
    <StepHeading
        :title="$t('Ready to finish')"
        :description="
            $t(
                'Finishing makes your account and workspace, and closes this installer for good. The setup code stops working.',
            )
        "
    />

    <dl :class="[ui.rows, 'text-[13px]/5']">
        <div
            class="flex gap-4 border-t-[0.5px] border-edge-tray-panel py-[11px]"
        >
            <dt class="w-24 shrink-0 text-fg-muted">{{ $t('Address') }}</dt>
            <dd class="min-w-0 break-words text-fg-primary">
                {{ summary.address }}
            </dd>
        </div>
        <div
            class="flex gap-4 border-t-[0.5px] border-edge-tray-panel py-[11px]"
        >
            <dt class="w-24 shrink-0 text-fg-muted">{{ $t('Database') }}</dt>
            <dd class="min-w-0 break-words text-fg-primary">
                {{ summary.database }}
            </dd>
        </div>
        <div
            class="flex gap-4 border-t-[0.5px] border-edge-tray-panel py-[11px]"
        >
            <dt class="w-24 shrink-0 text-fg-muted">{{ $t('Email') }}</dt>
            <dd class="min-w-0 break-words text-fg-primary">
                {{ summary.mail ?? $t('Not set up: emails go to the log') }}
            </dd>
        </div>
        <div
            class="flex gap-4 border-t-[0.5px] border-edge-tray-panel py-[11px]"
        >
            <dt class="w-24 shrink-0 text-fg-muted">{{ $t('Owner') }}</dt>
            <dd class="min-w-0 break-words text-fg-primary">
                {{ summary.owner.name }}
                <span class="text-fg-muted">{{
                    ' ' + summary.owner.email
                }}</span>
            </dd>
        </div>
        <div
            class="flex gap-4 border-t-[0.5px] border-edge-tray-panel py-[11px]"
        >
            <dt class="w-24 shrink-0 text-fg-muted">{{ $t('Workspace') }}</dt>
            <dd class="min-w-0 break-words text-fg-primary">
                {{ summary.workspace.name }}
                <span class="text-fg-muted">{{
                    ' ' + summary.workspace.address
                }}</span>
            </dd>
        </div>
    </dl>

    <Form
        action="/install/finish"
        method="post"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-3"
    >
        <InputError :message="errors.email ?? errors.step" />
        <div class="flex gap-2">
            <DashButton
                :href="stepUrl('owner')"
                :class="ui.button"
                :label="$t('Back')"
            />
            <DashButton
                variant="primary"
                type="submit"
                :class="[ui.button, 'grow']"
                :label="$t('Finish setup')"
                :loading="processing"
            />
        </div>
    </Form>
</template>
