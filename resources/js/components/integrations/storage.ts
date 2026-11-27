export type StorageBucket = {
    id: number;
    name: string;
    endpoint: string;
    region: string | null;
    location: string | null;
    jurisdiction: string | null;
    state: 'ready' | 'needs_keys' | 'failed';
    error: string | null;
    keepDays: number | null;
    keyMade: boolean;
    destination: { id: number; name: string } | null;
    machines: { id: number; name: string; schedule: string }[];
    waiting: number;
    usage: {
        bytes: number;
        files: number;
        more: boolean;
        checkedAt: string | null;
    } | null;
    usageError: string | null;
    cost: string | null;
    keysHelp: string | null;
};

export type StorageMachine = {
    id: number;
    name: string;
    destinationId: number | null;
    destination: string | null;
    schedule: string;
};

export type StorageOption = {
    key: string;
    label: string;
    help: string | null;
    options: { value: string; label: string }[];
};

export type BackupStorageAccount = {
    buckets: StorageBucket[];
    suggestedName: string;
    machines: StorageMachine[];
    keepOptions: number[];
    options: StorageOption[];
    price: string;
    notes: string[];
    keepNote: string | null;
};

export type StorageShortcut = {
    key: string;
    name: string;
    logo: string;
    accounts: { id: number; label: string; href: string }[];
    connect: string;
};

export const STORAGE_PROVIDERS = ['backblaze', 'r2'];

export function storedSize(bytes: number): string {
    const units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    let value = Math.max(0, bytes);
    let step = 0;
    while (value >= 1000 && step < units.length - 1) {
        value /= 1000;
        step++;
    }
    const shown =
        step === 0 || value >= 100
            ? Math.round(value)
            : value.toFixed(1).replace(/\.0$/, '');

    return `${shown} ${units[step]}`;
}
