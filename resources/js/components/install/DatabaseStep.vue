<script setup lang="ts">
import { Form, router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, ref, watch } from 'vue';
import DashButton from '@/components/dash/DashButton.vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { errorMessage } from '@/lib/errors';
import { postJson, postLines } from '@/lib/install';
import CheckRow from './CheckRow.vue';
import StatusLine from './StatusLine.vue';
import StepHeading from './StepHeading.vue';
import * as ui from './styles';
import { stepUrl } from './types';
import type { DatabaseDriver, DatabaseProps, Status } from './types';

const props = defineProps<{ database: DatabaseProps; docker: boolean }>();

const status = computed(() => props.database.status);
const choosing = ref(!status.value.connected && props.database.editable);
watch(
    () => status.value.connected,
    (connected) => {
        if (connected) choosing.value = false;
    },
);

const driver = ref<DatabaseDriver>(
    props.database.drivers[props.database.settings.driver]
        ? props.database.settings.driver
        : ((['pgsql', 'mysql', 'sqlite'] as const).find(
              (name) => props.database.drivers[name],
          ) ?? 'pgsql'),
);
const drivers: { key: DatabaseDriver; label: string }[] = [
    { key: 'pgsql', label: 'PostgreSQL' },
    { key: 'mysql', label: trans('MySQL or MariaDB') },
    { key: 'sqlite', label: 'SQLite' },
];

const current = computed(() =>
    props.database.settings.driver === driver.value
        ? props.database.settings
        : null,
);
const defaults = computed(() => ({
    host: current.value?.host ?? '127.0.0.1',
    port:
        current.value?.port ??
        (driver.value === 'pgsql'
            ? '5432'
            : driver.value === 'mysql'
              ? '3306'
              : ''),
    database:
        driver.value === 'sqlite'
            ? (current.value?.database ?? props.database.sqlitePath)
            : (current.value?.database ?? 'serveros'),
    username: current.value?.username ?? 'serveros',
}));

const inner = ref<HTMLElement | null>(null);
const testing = ref(false);
const tested = ref<{ status: Status; text: string } | null>(null);
function clearTested(): void {
    tested.value = null;
}

function fields(): Record<string, unknown> {
    const form = inner.value?.closest('form') ?? undefined;

    return Object.fromEntries(new FormData(form).entries());
}

async function test(): Promise<void> {
    testing.value = true;
    tested.value = null;
    try {
        const result = await postJson<{ ok: boolean; message: string }>(
            '/install/database/test',
            fields(),
        );
        tested.value = {
            status: result.ok ? 'ok' : 'fail',
            text: result.message,
        };
    } catch (error) {
        tested.value = { status: 'fail', text: errorMessage(error) };
    } finally {
        testing.value = false;
    }
}

const migrating = ref(false);
const total = ref(0);
const done = ref(0);
const migrateError = ref<string | null>(null);

async function migrate(): Promise<void> {
    migrating.value = true;
    migrateError.value = null;
    done.value = 0;
    let finished = false;
    try {
        await postLines('/install/database/migrate', (line) => {
            if ('total' in line) total.value = line.total;
            if ('done' in line) done.value = line.done;
            if ('finished' in line) finished = true;
            if ('error' in line) migrateError.value = line.error;
        });
    } catch (error) {
        migrateError.value = errorMessage(error);
    }
    migrating.value = false;
    if (finished && !migrateError.value) router.visit('/install');
}

const percent = computed(() =>
    total.value === 0 ? 0 : Math.round((done.value / total.value) * 100),
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
        :title="$t('Database')"
        :description="
            $t(
                'Where ServerOS keeps your workspaces, machines and everything else.',
            )
        "
    />

    <template v-if="status.ready && !choosing">
        <ul :class="ui.rows">
            <CheckRow status="ok">
                <p class="text-fg-muted">
                    <span class="font-medium text-fg-primary">{{
                        $t('Already set up.')
                    }}</span>
                    {{
                        ' ' +
                        (database.settings.driver === 'sqlite'
                            ? $t(':driver, in :file.', {
                                  driver: database.settings.label,
                                  file: String(
                                      database.settings.database ?? '',
                                  ),
                              })
                            : $t(':driver, :database on :host.', {
                                  driver: database.settings.label,
                                  database: String(
                                      database.settings.database ?? '',
                                  ),
                                  host: String(database.settings.host ?? ''),
                              }))
                    }}
                </p>
            </CheckRow>
        </ul>
        <div class="flex gap-2">
            <DashButton
                :href="stepUrl('check')"
                :class="ui.button"
                :label="$t('Back')"
            />
            <button
                v-if="database.editable"
                type="button"
                :class="ui.ghostButton"
                @click="choosing = true"
            >
                {{ $t('Use another database') }}
            </button>
            <DashButton
                variant="primary"
                :href="stepUrl('address')"
                :class="[ui.button, 'grow']"
                :label="$t('Continue')"
            />
        </div>
    </template>

    <template v-else-if="status.connected && !choosing">
        <ul :class="ui.rows">
            <CheckRow status="ok">
                <p class="text-fg-muted">
                    <span class="font-medium text-fg-primary">{{
                        $t('Connected to :driver.', {
                            driver: database.settings.label,
                        })
                    }}</span>
                    {{
                        ' ' +
                        $t(
                            'Next, ServerOS sets up its tables (:count steps). It takes a minute or so.',
                            { count: String(status.pending) },
                        )
                    }}
                </p>
                <div v-if="migrating || done > 0" class="flex flex-col gap-1.5">
                    <div
                        class="h-1.5 overflow-hidden rounded-full bg-edge-tile"
                        role="progressbar"
                        :aria-valuenow="percent"
                        aria-valuemin="0"
                        aria-valuemax="100"
                    >
                        <div
                            class="h-full rounded-full bg-dash-blue transition-[width] duration-200"
                            :style="{ width: `${percent}%` }"
                        />
                    </div>
                    <p :class="ui.hint">
                        {{
                            $t(':done of :total done', {
                                done: String(done),
                                total: String(total),
                            })
                        }}
                    </p>
                </div>
                <InputError :message="migrateError ?? undefined" />
            </CheckRow>
        </ul>
        <div class="flex gap-2">
            <DashButton
                :href="stepUrl('check')"
                :class="ui.button"
                :label="$t('Back')"
            />
            <button
                v-if="database.editable"
                type="button"
                :class="ui.ghostButton"
                :disabled="migrating"
                @click="choosing = true"
            >
                {{ $t('Use another database') }}
            </button>
            <DashButton
                variant="primary"
                :class="[ui.button, 'grow']"
                :label="
                    migrateError ? $t('Try again') : $t('Set up the tables')
                "
                :loading="migrating"
                @click="migrate"
            />
        </div>
    </template>

    <template v-else-if="!database.editable">
        <ul :class="ui.rows">
            <CheckRow status="fail">
                <p class="text-fg-primary">{{ status.error }}</p>
                <p class="text-fg-secondary">
                    {{
                        docker
                            ? $t(
                                  'The install sets the database itself. See what the db container says: serveros-panel logs db',
                              )
                            : $t(
                                  'The database settings come from outside the panel, so fix them there (DB_* settings), then check again.',
                              )
                    }}
                </p>
            </CheckRow>
        </ul>
        <div class="flex gap-2">
            <DashButton
                :href="stepUrl('check')"
                :class="ui.button"
                :label="$t('Back')"
            />
            <DashButton
                variant="primary"
                :class="[ui.button, 'grow']"
                :label="$t('Check again')"
                :loading="reloading"
                @click="checkAgain"
            />
        </div>
    </template>

    <Form
        v-else
        action="/install/database"
        method="post"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-4"
        @before="clearTested"
    >
        <div ref="inner" class="contents">
            <div
                class="ds-segments w-full!"
                role="group"
                :aria-label="$t('Database')"
            >
                <button
                    v-for="option in drivers"
                    :key="option.key"
                    type="button"
                    class="flex-1 cursor-pointer justify-center px-0! text-[13px]!"
                    :aria-pressed="driver === option.key"
                    :disabled="!database.drivers[option.key]"
                    :title="
                        database.drivers[option.key]
                            ? undefined
                            : $t(
                                  'PHP can’t talk to this one yet: see the server check.',
                              )
                    "
                    @click="driver = option.key"
                >
                    {{ option.label }}
                </button>
            </div>
            <input type="hidden" name="driver" :value="driver" />

            <template v-if="driver === 'sqlite'">
                <div class="flex flex-col gap-2">
                    <Label for="database" :class="ui.label">{{
                        $t('Database file')
                    }}</Label>
                    <Input
                        id="database"
                        :key="`database-${driver}`"
                        name="database"
                        required
                        :default-value="defaults.database"
                        :class="[ui.field, 'font-ui-mono text-[12.5px]']"
                    />
                    <p :class="ui.hint">
                        {{
                            $t(
                                'A file on this server, made if it isn’t there. Fine for trying it out; use PostgreSQL for a team.',
                            )
                        }}
                    </p>
                    <InputError :message="errors.database" />
                </div>
            </template>
            <template v-else>
                <div class="grid grid-cols-[1fr_6rem] gap-3">
                    <div class="flex flex-col gap-2">
                        <Label for="host" :class="ui.label">{{
                            $t('Host')
                        }}</Label>
                        <Input
                            id="host"
                            :key="`host-${driver}`"
                            name="host"
                            required
                            :class="ui.field"
                            :default-value="defaults.host"
                        />
                    </div>
                    <div class="flex flex-col gap-2">
                        <Label for="port" :class="ui.label">{{
                            $t('Port')
                        }}</Label>
                        <Input
                            id="port"
                            :key="`port-${driver}`"
                            name="port"
                            inputmode="numeric"
                            :class="ui.field"
                            :default-value="String(defaults.port)"
                        />
                    </div>
                </div>
                <InputError :message="errors.host ?? errors.port" />
                <div class="flex flex-col gap-2">
                    <Label for="database" :class="ui.label">{{
                        $t('Database name')
                    }}</Label>
                    <Input
                        id="database"
                        :key="`database-${driver}`"
                        name="database"
                        required
                        :class="ui.field"
                        :default-value="defaults.database"
                    />
                    <InputError :message="errors.database" />
                </div>
                <div class="flex flex-col gap-2">
                    <Label for="username" :class="ui.label">{{
                        $t('Username')
                    }}</Label>
                    <Input
                        id="username"
                        :key="`username-${driver}`"
                        name="username"
                        autocomplete="off"
                        :class="ui.field"
                        :default-value="defaults.username"
                    />
                    <InputError :message="errors.username" />
                </div>
                <div class="flex flex-col gap-2">
                    <Label for="db_password" :class="ui.label">{{
                        $t('Password')
                    }}</Label>
                    <PasswordInput
                        id="db_password"
                        name="db_password"
                        autocomplete="new-password"
                        :class="[ui.field, 'pr-10']"
                        :placeholder="
                            database.hasPassword
                                ? $t('Leave empty to keep the saved one')
                                : ''
                        "
                    />
                    <InputError :message="errors.db_password" />
                </div>
            </template>
        </div>

        <StatusLine
            v-if="tested || errors.connection"
            :status="errors.connection ? 'fail' : (tested?.status ?? 'fail')"
        >
            <p class="text-fg-secondary">
                {{ errors.connection ?? tested?.text }}
            </p>
        </StatusLine>

        <div class="flex gap-2">
            <DashButton
                :href="stepUrl('check')"
                :class="ui.button"
                :label="$t('Back')"
            />
            <DashButton
                :class="ui.button"
                :label="$t('Test connection')"
                :loading="testing"
                :disabled="processing"
                @click="test"
            />
            <DashButton
                variant="primary"
                type="submit"
                :class="[ui.button, 'grow']"
                :label="$t('Save')"
                :loading="processing"
                :disabled="testing"
            />
        </div>
    </Form>
</template>
