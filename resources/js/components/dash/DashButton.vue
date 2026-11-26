<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { LoaderCircle } from '@lucide/vue';
import { computed } from 'vue';
import type { Component } from 'vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import type { FilledIconName } from '@/components/icons/filledIcons';

type Props = {
    variant?: 'primary' | 'secondary' | 'danger';
    size?: 'md' | 'sm';
    icon?: Component | FilledIconName;
    label?: string;
    href?: string;
    external?: boolean;
    type?: 'button' | 'submit' | 'reset';
    disabled?: boolean;
    loading?: boolean;
    block?: boolean;
};

const {
    variant = 'secondary',
    size = 'md',
    icon,
    label,
    href,
    external = false,
    type = 'button',
    disabled = false,
    loading = false,
    block = false,
} = defineProps<Props>();

const slots = defineSlots<{ default?: () => unknown }>();

const iconName = computed(() => (typeof icon === 'string' ? icon : null));
const iconComponent = computed(() =>
    icon && typeof icon !== 'string' ? icon : null,
);
const inactive = computed(() => disabled || loading);
const classes = computed(() => [
    'dash-button',
    `dash-button--${variant}`,
    {
        'dash-button--sm': size === 'sm',
        'dash-button--icon': !label && !slots.default,
        'w-full': block,
    },
]);
</script>

<template>
    <a
        v-if="href && external"
        :href="href"
        target="_blank"
        rel="noopener"
        :class="classes"
        :aria-disabled="inactive || undefined"
    >
        <FilledIcon v-if="iconName" :name="iconName" />
        <component
            :is="iconComponent"
            v-else-if="iconComponent"
            :stroke-width="1.4"
        />
        <slot>{{ label }}</slot>
    </a>
    <Link
        v-else-if="href"
        :href="href"
        :class="classes"
        :aria-disabled="inactive || undefined"
    >
        <FilledIcon v-if="iconName" :name="iconName" />
        <component
            :is="iconComponent"
            v-else-if="iconComponent"
            :stroke-width="1.4"
        />
        <slot>{{ label }}</slot>
    </Link>
    <button
        v-else
        :type="type"
        :class="classes"
        :disabled="inactive"
        :aria-busy="loading || undefined"
    >
        <LoaderCircle v-if="loading" class="animate-spin" :stroke-width="1.4" />
        <FilledIcon v-else-if="iconName" :name="iconName" />
        <component
            :is="iconComponent"
            v-else-if="iconComponent"
            :stroke-width="1.4"
        />
        <slot>{{ label }}</slot>
    </button>
</template>
