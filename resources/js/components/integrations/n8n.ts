export type N8nWebhook = {
    node: string;
    method: string | null;
    answers: boolean;
};

export type N8nWorkflow = {
    id: string;
    name: string;
    active: boolean;
    href: string;
    webhook: N8nWebhook | null;
    problem: string | null;
};

export type N8nAccountWorkflow = N8nWorkflow & {
    usedBy: { id: number; name: string; enabled: boolean; href: string }[];
};

export type N8nExample = {
    key: string;
    title: string;
    description: string;
    access: 'read' | 'write';
    json: string;
};

export type N8nAccount = {
    instance: string;
    error: string | null;
    workflows: N8nAccountWorkflow[];
    api: {
        baseUrl: string;
        keysHref: string;
        docsUrl: string;
        examples: N8nExample[];
    };
    starter: {
        name: string;
        href: string;
        at: string;
        key: {
            name: string;
            prefix: string;
            lastUsedAt: string | null;
        } | null;
        credential: boolean;
    } | null;
};
