<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';
import DashButton from '@/components/dash/DashButton.vue';
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { errorMessage } from '@/lib/errors';
import { postJson } from '@/lib/install';
import type { Check } from '@/lib/install';
import CheckLines from './CheckLines.vue';
import StepHeading from './StepHeading.vue';
import * as ui from './styles';
import { stepUrl } from './types';
import type { AddressProps } from './types';

const props = defineProps<{ address: AddressProps; docker: boolean }>();

const url = ref(
    props.address.editable ? props.address.suggested : props.address.url,
);
const checking = ref(false);
const lines = ref<Check[] | null>(null);

async function check(): Promise<void> {
    checking.value = true;
    try {
        lines.value = (
            await postJson<{ lines: Check[] }>('/install/address/check', {
                url: url.value,
            })
        ).lines;
    } catch (error) {
        lines.value = [{ status: 'fail', text: errorMessage(error) }];
    } finally {
        checking.value = false;
    }
}

onMounted(() => {
    if (!props.address.editable) void check();
});
</script>

<template>
    <StepHeading
        :title="$t('The panel’s address')"
        :description="
            $t(
                'Where you and your team open ServerOS. Workspaces open on its subdomains, like acme.panel.example.com.',
            )
        "
    />

    <Form
        action="/install/address"
        method="post"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-4"
    >
        <div class="flex flex-col gap-2">
            <Label for="url" :class="ui.label">{{ $t('Address') }}</Label>
            <Input
                id="url"
                v-model="url"
                name="url"
                required
                inputmode="url"
                autocomplete="off"
                spellcheck="false"
                placeholder="https://panel.example.com"
                :readonly="!address.editable"
                :class="[ui.field, !address.editable ? ui.readonlyField : '']"
                @input="lines = null"
            />
            <p v-if="!address.editable" :class="ui.hint">
                {{
                    docker
                        ? $t(
                              'Set by the install. To change it, set SERVEROS_DOMAIN in /opt/serveros/.env, then run: cd /opt/serveros && docker compose up -d',
                          )
                        : $t(
                              'Set outside the panel (APP_URL), so it’s changed there.',
                          )
                }}
            </p>
            <p v-if="address.tunnel" :class="ui.hint">
                {{ $t('Through a Cloudflare Tunnel') }}
            </p>
            <InputError :message="errors.url" />
        </div>

        <CheckLines v-if="lines" :lines="lines" />

        <div class="flex gap-2">
            <DashButton
                :href="stepUrl('database')"
                :class="ui.button"
                :label="$t('Back')"
            />
            <DashButton
                :class="ui.button"
                :label="$t('Check DNS')"
                :loading="checking"
                :disabled="!url"
                @click="check"
            />
            <DashButton
                variant="primary"
                type="submit"
                :class="[ui.button, 'grow']"
                :label="address.editable ? $t('Save') : $t('Continue')"
                :loading="processing"
            />
        </div>
    </Form>
</template>
