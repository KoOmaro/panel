import { computed, getCurrentInstance, onBeforeUnmount, shallowRef } from 'vue';
import type { ComputedRef } from 'vue';
import type { FilledIconName } from '@/components/icons/filledIcons';

export type DashCrumb = {
    label: string;
    href?: string;
    icon?: FilledIconName;
    logo?: string;
    image?: string | null;
};

export type DashSidebarMode = 'app' | 'none' | 'custom';

export type DashShellEntry = {
    crumbs?: DashCrumb[] | null;
    sidebar?: DashSidebarMode;
};

type Registration = { id: number; read: () => DashShellEntry };

const registrations = shallowRef<Registration[]>([]);
let nextId = 0;

export function registerDashShell(read: () => DashShellEntry): void {
    const id = ++nextId;

    registrations.value = [...registrations.value, { id, read }];

    if (getCurrentInstance()) {
        onBeforeUnmount(() => {
            registrations.value = registrations.value.filter(
                (entry) => entry.id !== id,
            );
        });
    }
}

export function useDashShell(): {
    crumbs: ComputedRef<DashCrumb[] | null>;
    sidebar: ComputedRef<DashSidebarMode | null>;
} {
    const entries = computed(() =>
        registrations.value.map((entry) => entry.read()),
    );

    return {
        crumbs: computed(
            () =>
                entries.value.findLast((entry) => entry.crumbs != null)
                    ?.crumbs ?? null,
        ),
        sidebar: computed(
            () =>
                entries.value.findLast((entry) => entry.sidebar != null)
                    ?.sidebar ?? null,
        ),
    };
}
