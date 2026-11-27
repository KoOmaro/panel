import type { OperationStep } from '@/components/integrations/integrations';
import { describe } from '@/lib/errors';

export type TailnetChange = {
    id: number;
    kind: 'join' | 'leave';
    state: 'running' | 'done' | 'failed';
    step: string;
    manual: boolean;
    canRunCommands: boolean;
    steps: OperationStep[];
    phase: string | null;
    jobId: string | null;
    hostname: string;
    error: string | null;
    result: { name?: string; address?: string; cleanup?: string };
    keyExpiresAt: string | null;
    createdAt: string | null;
    finishedAt: string | null;
};

export type TailnetDevice = {
    id: number;
    deviceId: string;
    name: string;
    hostname: string;
    address: string | null;
    addresses: string[];
    tags: string[];
    os: string | null;
    version: string | null;
    online: boolean;
    authorized: boolean;
    external: boolean;
    lastSeen: string | null;
    linkedBy: 'joined' | 'address' | 'hostname' | null;
};

export type TailscaleAccountRef = {
    id: number;
    label: string;
    tags: string[];
    failing: boolean;
};

export type MachineTailnet = {
    accounts: TailscaleAccountRef[];
    device:
        | (TailnetDevice & { integration: { id: number; label: string } })
        | null;
    change: TailnetChange | null;
    blocked: string | null;
    installed: 'serveros' | 'native' | null;
    hostname: string;
};

export type TailscaleAccount = {
    tailnet: string;
    tags: string[];
    devices: (TailnetDevice & {
        machine: { id: number; name: string; href: string } | null;
    })[];
    machines: {
        id: number;
        name: string;
        provider: string | null;
        blocked: string | null;
        installed: 'native' | null;
    }[];
    changes: (TailnetChange & { machine: { id: number; name: string } })[];
    error: string | null;
};

export type TailnetCommands = {
    commands: string[];
    expiresAt: string | null;
};

export function changeActive(change: TailnetChange | null | undefined) {
    return change?.state === 'running';
}

function csrf(): string {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}

export class TailnetError extends Error {
    constructor(
        message: string,
        public manual = false,
    ) {
        super(message);
    }
}

export async function tailnetRequest<T>(
    url: string,
    method: 'GET' | 'POST' | 'PUT' | 'DELETE',
    body?: Record<string, unknown>,
): Promise<{ status: number; body: T }> {
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrf(),
        },
        ...(body && method !== 'GET' ? { body: JSON.stringify(body) } : {}),
    });
    const json = (await response.json().catch(() => ({}))) as T & {
        error?: string;
        manual?: boolean;
    };
    if (!response.ok && response.status !== 409) {
        throw new TailnetError(describe(response.status, json));
    }

    return { status: response.status, body: json };
}
