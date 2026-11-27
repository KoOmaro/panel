<script setup lang="ts">
import { ArrowUpRight, SlidersHorizontal, Trash2, X } from '@lucide/vue';
import BrandLogo from '@/components/dash/BrandLogo.vue';
import {
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import { nodeLogo } from './format';
import type { NodeLink, ProjectNode } from './types';

const { node, unlinked, links } = defineProps<{
    node: ProjectNode;
    unlinked: ProjectNode[];
    links: NodeLink[];
}>();

defineEmits<{
    open: [];
    settings: [];
    connect: [resource: ProjectNode];
    disconnect: [link: NodeLink['link']];
    remove: [];
    delete: [];
}>();
</script>

<template>
    <DropdownMenuItem v-if="node.href" @select="$emit('open')">
        <ArrowUpRight class="size-4" :stroke-width="1.75" />
        {{ $t('Open') }}
    </DropdownMenuItem>
    <DropdownMenuItem v-if="node.kind === 'app'" @select="$emit('settings')">
        <SlidersHorizontal class="size-4" :stroke-width="1.75" />
        {{ $t('Settings…') }}
    </DropdownMenuItem>
    <DropdownMenuSeparator v-if="node.href || node.kind === 'app'" />
    <template v-if="node.kind === 'app' && unlinked.length">
        <DropdownMenuLabel class="text-xs font-normal text-fg-muted">{{
            $t('Connect to')
        }}</DropdownMenuLabel>
        <DropdownMenuItem
            v-for="resource in unlinked"
            :key="resource.id"
            @select="$emit('connect', resource)"
        >
            <BrandLogo :name="nodeLogo(resource)" />
            {{ resource.name }}
        </DropdownMenuItem>
    </template>
    <template v-if="links.length">
        <DropdownMenuLabel class="text-xs font-normal text-fg-muted">{{
            $t('Disconnect')
        }}</DropdownMenuLabel>
        <DropdownMenuItem
            v-for="item in links"
            :key="item.link.id"
            @select="$emit('disconnect', item.link)"
        >
            <X class="size-4" :stroke-width="1.75" />
            {{ item.other?.name }}
        </DropdownMenuItem>
    </template>
    <DropdownMenuSeparator
        v-if="links.length || (node.kind === 'app' && unlinked.length)"
    />
    <DropdownMenuItem @select="$emit('remove')">
        <X class="size-4" :stroke-width="1.75" />
        {{ $t('Take out of the project') }}
    </DropdownMenuItem>
    <DropdownMenuItem
        class="text-status-down focus:bg-status-down/10 focus:text-status-down"
        @select="$emit('delete')"
    >
        <Trash2 class="size-4 text-current" :stroke-width="1.75" />
        {{ $t('Delete…') }}
    </DropdownMenuItem>
</template>
