import { ref } from 'vue';
import type { Ref } from 'vue';

const open = ref(false);

export function useCommandPalette(): { open: Ref<boolean>; show: () => void } {
    return {
        open,
        show: () => {
            open.value = true;
        },
    };
}
