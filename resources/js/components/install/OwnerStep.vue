<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { ref } from 'vue';
import DashButton from '@/components/dash/DashButton.vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import PasswordStrength from '@/components/PasswordStrength.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import StepHeading from './StepHeading.vue';
import * as ui from './styles';
import { stepUrl } from './types';
import type { OwnerProps } from './types';

const props = defineProps<{ owner: OwnerProps }>();

const password = ref('');
const workspace = ref(props.owner.saved?.workspace ?? '');
</script>

<template>
    <StepHeading
        :title="$t('Your account')"
        :description="
            $t(
                'You’ll own this install. Your email is trusted as it is, so you can sign in before email is set up.',
            )
        "
    />

    <Form
        action="/install/owner"
        method="post"
        :reset-on-error="['password', 'password_confirmation']"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-4"
    >
        <div class="flex flex-col gap-2">
            <Label for="name" :class="ui.label">{{ $t('Name') }}</Label>
            <Input
                id="name"
                name="name"
                required
                v-focus
                autocomplete="name"
                :class="ui.field"
                :default-value="owner.saved?.name ?? ''"
            />
            <InputError :message="errors.name" />
        </div>
        <div class="flex flex-col gap-2">
            <Label for="email" :class="ui.label">{{
                $t('Email address')
            }}</Label>
            <Input
                id="email"
                name="email"
                type="email"
                required
                autocomplete="email"
                :class="ui.field"
                :default-value="owner.saved?.email ?? ''"
            />
            <InputError :message="errors.email" />
        </div>
        <div class="flex flex-col gap-2">
            <Label for="password" :class="ui.label">{{ $t('Password') }}</Label>
            <PasswordInput
                id="password"
                name="password"
                required
                autocomplete="new-password"
                :class="[ui.field, 'pr-10']"
                :passwordrules="owner.passwordRules"
                @input="password = ($event.target as HTMLInputElement).value"
            />
            <InputError :message="errors.password" />
            <PasswordStrength
                :password="password"
                :rules="owner.passwordRules"
            />
        </div>
        <div class="flex flex-col gap-2">
            <Label for="password_confirmation" :class="ui.label">{{
                $t('Confirm password')
            }}</Label>
            <PasswordInput
                id="password_confirmation"
                name="password_confirmation"
                required
                autocomplete="new-password"
                :class="[ui.field, 'pr-10']"
                :passwordrules="owner.passwordRules"
            />
            <InputError :message="errors.password_confirmation" />
        </div>
        <div class="flex flex-col gap-2">
            <Label for="workspace" :class="ui.label">{{
                $t('Workspace name')
            }}</Label>
            <Input
                id="workspace"
                v-model="workspace"
                name="workspace"
                required
                :class="ui.field"
                :placeholder="$t('Acme')"
            />
            <p :class="ui.hint">
                {{
                    $t(
                        'Your first workspace, for your machines and your team. You can make more later.',
                    )
                }}
            </p>
            <InputError :message="errors.workspace" />
        </div>

        <div class="flex gap-2">
            <DashButton
                :href="stepUrl('mail')"
                :class="ui.button"
                :label="$t('Back')"
            />
            <DashButton
                variant="primary"
                type="submit"
                :class="[ui.button, 'grow']"
                :label="$t('Continue')"
                :loading="processing"
            />
        </div>
    </Form>
</template>
