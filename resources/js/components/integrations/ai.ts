export type AiModelOption = { id: string; label: string };

export type AiProviderModels = {
    provider: string;
    label: string;
    default: string;
    models: AiModelOption[];
};

export type AiFeatureRow = {
    key: string;
    label: string;
    about: string;
    choice: string | null;
    uses: string;
    own: boolean;
    providers: string[];
};

export type AiUsageDay = {
    day: string;
    input: number;
    output: number;
    requests: number;
};

export type AiUsageModel = {
    model: string;
    requests: number;
    input: number;
    output: number;
    features: string[];
};

export type AiUsage = {
    days: AiUsageDay[];
    models: AiUsageModel[];
    total: { requests: number; input: number; output: number };
};

export type AiAccount = {
    kind: 'ai';
    provider: string;
    models: AiModelOption[];
    defaultModel: string | null;
    features: AiFeatureRow[];
    options: AiProviderModels[];
    usage: AiUsage;
    canManage: boolean;
};

export function isAiAccount(value: unknown): value is AiAccount {
    return (
        typeof value === 'object' &&
        value !== null &&
        (value as { kind?: unknown }).kind === 'ai'
    );
}

export function tokens(n: number): string {
    if (n >= 1_000_000) {
        return `${(n / 1_000_000).toFixed(n >= 10_000_000 ? 0 : 1)}M`;
    }
    if (n >= 1_000) {
        return `${(n / 1_000).toFixed(n >= 10_000 ? 0 : 1)}k`;
    }

    return String(n);
}
