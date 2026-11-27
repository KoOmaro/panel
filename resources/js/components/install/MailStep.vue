<script setup lang="ts">
import { Form, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import DashButton from '@/components/dash/DashButton.vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { errorMessage } from '@/lib/errors';
import { postJson } from '@/lib/install';
import type { Check } from '@/lib/install';
import CheckLines from './CheckLines.vue';
import StepHeading from './StepHeading.vue';
import * as ui from './styles';
import { stepUrl } from './types';
import type { MailProps } from './types';

const props = defineProps<{ mail: MailProps; docker: boolean }>();

const inner = ref<HTMLElement | null>(null);
const to = ref('');
const sending = ref(false);
const sent = ref<Check[] | null>(null);
const skipping = ref(false);

async function sendTest(): Promise<void> {
    const form = inner.value?.closest('form') ?? undefined;
    sending.value = true;
    sent.value = null;
    try {
        const result = await postJson<{ ok: boolean; message: string }>(
            '/install/mail/test',
            {
                ...Object.fromEntries(new FormData(form).entries()),
                to: to.value,
            },
        );
        sent.value = [
            { status: result.ok ? 'ok' : 'fail', text: result.message },
        ];
    } catch (error) {
        sent.value = [{ status: 'fail', text: errorMessage(error) }];
    } finally {
        sending.value = false;
    }
}

function skip(): void {
    router.post(
        '/install/mail/skip',
        {},
        {
            onStart: () => (skipping.value = true),
            onFinish: () => (skipping.value = false),
        },
    );
}

const port = String(props.mail.port ?? '587');
</script>

<template>
    <StepHeading
        :title="$t('Email')"
        :description="
            $t(
                'For invitations, alerts and password resets. You can skip it for now: until it’s set up, emails only go to the panel’s log.',
            )
        "
    />

    <div v-if="!mail.editable" class="flex flex-col gap-4">
        <p class="text-[13px]/5 text-fg-secondary">
            {{
                $t(
                    'Email is set outside the panel (the MAIL_* settings), so it’s changed there.',
                )
            }}
        </p>
        <div class="flex gap-2">
            <DashButton
                :href="stepUrl('address')"
                :class="ui.button"
                :label="$t('Back')"
            />
            <DashButton
                variant="primary"
                :class="[ui.button, 'grow']"
                :label="$t('Continue')"
                :loading="skipping"
                @click="skip"
            />
        </div>
    </div>

    <Form
        v-else
        action="/install/mail"
        method="post"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-4"
    >
        <div ref="inner" class="contents">
            <div class="grid grid-cols-[1fr_6rem] gap-3">
                <div class="flex flex-col gap-2">
                    <Label for="host" :class="ui.label">{{
                        $t('SMTP server')
                    }}</Label>
                    <Input
                        id="host"
                        name="host"
                        required
                        autocomplete="off"
                        spellcheck="false"
                        placeholder="smtp.example.com"
                        :class="ui.field"
                        :default-value="mail.host ?? ''"
                    />
                </div>
                <div class="flex flex-col gap-2">
                    <Label for="port" :class="ui.label">{{ $t('Port') }}</Label>
                    <Input
                        id="port"
                        name="port"
                        required
                        inputmode="numeric"
                        :class="ui.field"
                        :default-value="port"
                    />
                </div>
            </div>
            <InputError :message="errors.host ?? errors.port" />
            <div class="flex flex-col gap-2">
                <Label for="username" :class="ui.label">{{
                    $t('Username')
                }}</Label>
                <Input
                    id="username"
                    name="username"
                    autocomplete="off"
                    :class="ui.field"
                    :default-value="mail.username ?? ''"
                />
                <InputError :message="errors.username" />
            </div>
            <div class="flex flex-col gap-2">
                <Label for="mail_password" :class="ui.label">{{
                    $t('Password')
                }}</Label>
                <PasswordInput
                    id="mail_password"
                    name="mail_password"
                    autocomplete="new-password"
                    :class="[ui.field, 'pr-10']"
                    :placeholder="
                        mail.hasPassword
                            ? $t('Leave empty to keep the saved one')
                            : ''
                    "
                />
                <InputError :message="errors.mail_password" />
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div class="flex flex-col gap-2">
                    <Label for="from_address" :class="ui.label">{{
                        $t('From address')
                    }}</Label>
                    <Input
                        id="from_address"
                        name="from_address"
                        type="email"
                        required
                        placeholder="serveros@example.com"
                        :class="ui.field"
                        :default-value="mail.fromAddress ?? ''"
                    />
                </div>
                <div class="flex flex-col gap-2">
                    <Label for="from_name" :class="ui.label">{{
                        $t('From name')
                    }}</Label>
                    <Input
                        id="from_name"
                        name="from_name"
                        :class="ui.field"
                        :default-value="mail.fromName"
                    />
                </div>
            </div>
            <InputError :message="errors.from_address ?? errors.from_name" />
        </div>

        <div class="flex flex-col gap-2" :class="ui.divider">
            <Label for="to" :class="ui.label">{{ $t('Send a test to') }}</Label>
            <div class="flex gap-2">
                <Input
                    id="to"
                    v-model="to"
                    type="email"
                    autocomplete="email"
                    placeholder="you@example.com"
                    :class="[ui.field, 'grow']"
                />
                <DashButton
                    :class="ui.button"
                    :label="$t('Send')"
                    :loading="sending"
                    :disabled="!to"
                    @click="sendTest"
                />
            </div>
        </div>
        <CheckLines v-if="sent" :lines="sent" />

        <div class="flex gap-2">
            <DashButton
                :href="stepUrl('address')"
                :class="ui.button"
                :label="$t('Back')"
            />
            <button
                type="button"
                :class="ui.ghostButton"
                :disabled="skipping || processing"
                @click="skip"
            >
                {{ $t('Skip for now') }}
            </button>
            <DashButton
                variant="primary"
                type="submit"
                :class="[ui.button, 'grow']"
                :label="$t('Save')"
                :loading="processing"
            />
        </div>
    </Form>
</template>
