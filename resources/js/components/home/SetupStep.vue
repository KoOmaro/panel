<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import DashAvatar from '@/components/dash/DashAvatar.vue';
import DashButton from '@/components/dash/DashButton.vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import StepLogos from '@/components/home/StepLogos.vue';
import StepMarker from '@/components/home/StepMarker.vue';

export type SetupStepState = 'done' | 'next' | 'todo' | 'locked';

export type SetupStepResult = {
    summary: string | null;
    logos: { name: string; icon: string | null }[];
    people?: { name: string; avatar: string | null }[];
};

const {
    number,
    state,
    title,
    body,
    href,
    result = null,
    locked = null,
    meta = null,
    nextLabel,
    todoLabel,
    dialog = false,
} = defineProps<{
    number: number;
    state: SetupStepState;
    title: string;
    body: string;
    href: string;
    result?: SetupStepResult | null;
    locked?: string | null;
    meta?: string | null;
    nextLabel: string;
    todoLabel: string;
    dialog?: boolean;
}>();

const emit = defineEmits<{ act: [event: MouseEvent] }>();

defineSlots<{ extra?: () => unknown; art?: () => unknown }>();
</script>

<template>
    <Link
        v-if="state === 'done'"
        :href="href"
        class="flex min-w-0 items-center gap-3 border-t-[0.5px] border-edge-tabs px-[18px] py-3 transition-colors duration-100 hover:bg-surface-hover focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-(--ds-ring)"
    >
        <StepMarker state="done" :number="number" />
        <span class="flex min-w-0 grow flex-col">
            <span class="truncate text-[13px]/5 font-medium text-fg-tertiary">{{
                title
            }}</span>
            <span class="truncate text-[12px]/4 text-fg-muted">{{
                result?.summary ?? body
            }}</span>
        </span>
        <span
            v-if="result?.people?.length"
            class="isolate flex shrink-0 items-center max-sm:hidden"
        >
            <span
                v-for="(person, index) in result.people"
                :key="index"
                class="flex rounded-full border-2 border-surface-card not-last:-mr-1"
                :style="{ zIndex: result.people.length - index }"
            >
                <DashAvatar :src="person.avatar" :name="person.name" />
            </span>
        </span>
        <StepLogos
            v-else-if="result?.logos.length"
            :logos="result.logos"
            class="max-sm:hidden"
        />
        <FilledIcon name="chevron-right" class="text-fg-secondary" />
    </Link>

    <div
        v-else-if="state === 'next'"
        class="flex min-w-0 items-center gap-3 border-t-[0.5px] border-edge-tabs bg-surface-hover px-[18px] py-5"
    >
        <div class="flex min-w-0 items-start gap-3">
            <StepMarker state="next" :number="number" />
            <div class="flex max-w-[372px] min-w-0 flex-col gap-1">
                <h3
                    class="text-[15px]/5 font-medium tracking-[-0.01em] text-fg-primary"
                >
                    {{ title }}
                </h3>
                <p class="text-[13px]/5 text-fg-tertiary">{{ body }}</p>
                <slot name="extra" />
                <div class="flex flex-wrap items-center gap-3 pt-3.5">
                    <DashButton
                        v-if="dialog"
                        variant="primary"
                        :label="nextLabel"
                        aria-haspopup="dialog"
                        @click="emit('act', $event)"
                    />
                    <DashButton
                        v-else
                        variant="primary"
                        :label="nextLabel"
                        :href="href"
                    />
                    <span v-if="meta" class="text-[12px]/4 text-fg-tertiary">{{
                        meta
                    }}</span>
                </div>
            </div>
        </div>
        <div
            v-if="$slots.art"
            class="ml-auto flex shrink-0 items-center max-lg:hidden"
            aria-hidden="true"
        >
            <slot name="art" />
        </div>
    </div>

    <div
        v-else
        class="flex min-w-0 items-center gap-3 border-t-[0.5px] border-edge-tabs px-[18px] py-3"
    >
        <StepMarker state="todo" :number="number" />
        <span class="flex min-w-0 grow flex-col">
            <span
                class="truncate text-[13px]/5 font-medium"
                :class="
                    state === 'locked' ? 'text-fg-tertiary' : 'text-fg-primary'
                "
                >{{ title }}</span
            >
            <span class="truncate text-[13px]/5 text-fg-tertiary">{{
                body
            }}</span>
        </span>
        <span
            v-if="state === 'locked'"
            class="flex shrink-0 items-center gap-1.5 text-[12px]/4 text-fg-tertiary"
        >
            <FilledIcon name="lock-fill" :size="14" class="text-fg-muted" />
            <span class="max-sm:hidden">{{ locked }}</span>
        </span>
        <template v-else>
            <span
                v-if="meta"
                class="shrink-0 text-[12px]/4 text-fg-tertiary max-md:hidden"
                >{{ meta }}</span
            >
            <DashButton
                v-if="dialog"
                :label="todoLabel"
                aria-haspopup="dialog"
                @click="emit('act', $event)"
            />
            <DashButton v-else :label="todoLabel" :href="href" />
        </template>
    </div>
</template>
