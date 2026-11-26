import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { ComputedRef } from 'vue';
import { workspacePath } from '@/lib/workspaceRoute';
import type { Workspace } from '@/types';

export function licenseSettingsPath(): string {
    return workspacePath('/settings/license');
}

export function useSelfHosted(): ComputedRef<boolean> {
    const page = usePage<{ workspace?: Workspace }>();

    return computed(() => page.props.workspace?.billing?.selfHosted === true);
}
