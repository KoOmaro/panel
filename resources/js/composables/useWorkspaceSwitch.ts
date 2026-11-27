import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import type { WorkspaceSummary } from '@/types';

const SKELETON_MS = 900;

const switchingTo = ref<WorkspaceSummary | null>(null);

export function useWorkspaceSwitch() {
    function switchTo(workspace: WorkspaceSummary): void {
        switchingTo.value = workspace;
        const sameHost =
            new URL(workspace.switchUrl, window.location.href).origin ===
            window.location.origin;
        window.setTimeout(
            () =>
                sameHost
                    ? router.visit(workspace.switchUrl)
                    : window.location.assign(workspace.switchUrl),
            SKELETON_MS,
        );
    }

    return { switchingTo, switchTo };
}

if (typeof window !== 'undefined') {
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) {
            switchingTo.value = null;
        }
    });

    router.on('navigate', () => (switchingTo.value = null));
}
