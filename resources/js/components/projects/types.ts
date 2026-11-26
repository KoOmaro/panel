export type DashStatus = 'live' | 'deploying' | 'pending' | 'down' | 'stopped';

export type NodeKind = 'app' | 'database' | 'cache' | 'storage' | 'websockets';

export type Software = { slug: string; name: string };

export type Person = { name: string; avatar: string | null };

export type ProjectNode = {
    id: number;
    environment: number;
    origin: number | null;
    data: {
        state: 'waiting' | 'copying' | 'copied' | 'failed';
        error: string | null;
        at: string | null;
    } | null;
    name: string;
    kind: NodeKind;
    template: string | null;
    machine: { id: number; name: string; provider: string | null };
    status: 'running' | 'stopped' | null;
    failed: boolean;
    state: DashStatus;
    software: Software | null;
    image: string | null;
    domain: string | null;
    href: string | null;
    port: number | null;
};

export type ProjectLink = {
    id: number;
    app: number;
    resource: number;
    state: 'pending' | 'applied' | 'failed';
    error: string | null;
    env: string[];
};

export type Member = {
    machine: number;
    name: string;
    provider: string | null;
    address: string;
    state: 'pending' | 'up' | 'failed' | 'offline';
    error: string | null;
};

export type Environment = {
    id: number;
    name: string;
    source: number | null;
    pullRequest: number | null;
    head: string | null;
    refresh: 'daily' | 'weekly' | null;
    refreshedAt: string | null;
};

export type StackItem = {
    name: string;
    kind: NodeKind;
    template: string | null;
    label: string | null;
};

export type Summary = {
    state: DashStatus | null;
    app: number | null;
    kind: Software | null;
    image: string | null;
    machine: { id: number; name: string; provider: string | null } | null;
    domain: string | null;
    by: Person | null;
    at: string | null;
    stack: StackItem[];
};

export type MachineGroup = {
    id: number;
    name: string;
    provider: string | null;
    nodes: ProjectNode[];
    member: Member | null;
};

export type NodeLink = { link: ProjectLink; other: ProjectNode | undefined };
