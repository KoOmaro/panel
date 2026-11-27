<script setup lang="ts">
import { Form, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import DashButton from '@/components/dash/DashButton.vue';
import InputError from '@/components/InputError.vue';
import CheckRow from './CheckRow.vue';
import CommandLine from './CommandLine.vue';
import StepHeading from './StepHeading.vue';
import * as ui from './styles';
import type { CheckItem } from './types';

const props = defineProps<{ checks: CheckItem[] }>();

const failed = computed(() =>
    props.checks.some((check) => check.status === 'fail'),
);

const reloading = ref(false);
function checkAgain(): void {
    router.reload({
        onStart: () => (reloading.value = true),
        onFinish: () => (reloading.value = false),
    });
}
</script>

<template>
    <StepHeading
        :title="$t('Check the server')"
        :description="
            $t(
                'What ServerOS needs from this server. Anything in red has to be fixed first; the rest is advice.',
            )
        "
    />

    <ul :class="ui.rows">
        <CheckRow
            v-for="check in checks"
            :key="check.key"
            :status="check.status"
        >
            <p class="text-fg-muted">
                <span class="font-medium text-fg-primary">{{
                    check.label
                }}</span>
                {{ ' ' + check.detail }}
            </p>
            <p v-if="check.fix" class="text-fg-secondary">
                {{ check.fix }}
            </p>
            <CommandLine v-if="check.command" :command="check.command" />
        </CheckRow>
    </ul>

    <Form
        action="/install/check"
        method="post"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-3"
    >
        <InputError :message="errors.check" />
        <div class="flex gap-2">
            <DashButton
                :class="ui.button"
                :label="$t('Check again')"
                :loading="reloading"
                @click="checkAgain"
            />
            <DashButton
                variant="primary"
                type="submit"
                :class="[ui.button, 'grow']"
                :label="$t('Continue')"
                :loading="processing"
                :disabled="failed"
            />
        </div>
    </Form>
</template>
