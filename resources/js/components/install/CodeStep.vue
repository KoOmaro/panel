<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import DashButton from '@/components/dash/DashButton.vue';
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import CommandLine from './CommandLine.vue';
import StepHeading from './StepHeading.vue';
import * as ui from './styles';

defineProps<{ command: string; docker: boolean }>();
</script>

<template>
    <StepHeading
        :title="$t('Set up ServerOS')"
        :description="
            $t(
                'Enter the setup code to start. Only someone who can get into this server has it.',
            )
        "
    />

    <Form
        action="/install/code"
        method="post"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-4"
    >
        <div class="flex flex-col gap-2">
            <Label for="code" :class="ui.label">{{ $t('Setup code') }}</Label>
            <Input
                id="code"
                name="code"
                required
                v-focus
                autocomplete="off"
                autocapitalize="characters"
                spellcheck="false"
                placeholder="ABCD-EFGH-JKMN"
                :class="[ui.field, 'font-ui-mono tracking-[0.06em] uppercase']"
                :aria-invalid="!!(errors.code || errors.step)"
            />
            <InputError :message="errors.code ?? errors.step" />
        </div>

        <DashButton
            variant="primary"
            type="submit"
            block
            :class="ui.button"
            :label="$t('Continue')"
            :loading="processing"
        />
    </Form>

    <div class="flex flex-col gap-2.5" :class="ui.divider">
        <p class="text-[13px]/5 text-fg-muted">
            {{
                docker
                    ? $t(
                          'The install script showed it when it finished, and the web container prints it as it starts. To see it again, run this on the server:',
                      )
                    : $t(
                          'To see it, run this on the server, in the panel’s folder, as the web server’s user:',
                      )
            }}
        </p>
        <CommandLine :command="command" />
    </div>
</template>
