import { trans } from 'laravel-vue-i18n';

export type AccessState =
    | 'requested'
    | 'waiting_key'
    | 'adding'
    | 'active'
    | 'failed'
    | 'removing';

export type AccessPerson = {
    id: number;
    state: AccessState;
    name: string;
    email: string | null;
    avatar: string | null;
    external: boolean;
    role: string | null;
    mine: boolean;
    login: string;
    keyType: string | null;
    fingerprint: string | null;
    comment: string | null;
    note: string | null;
    error: string | null;
    requestedAt: string | null;
    addedAt: string | null;
    expiresAt: string | null;
};

export type AccessEvent = {
    id: number;
    kind: string;
    reason: string | null;
    at: string;
    person: { name: string | null; avatar: string | null; external: boolean };
    login: string | null;
    by: string | null;
    fingerprint: string | null;
    error: string | null;
    count: number | null;
};

export type AccessMember = {
    id: number;
    name: string;
    email: string;
    avatar: string | null;
    role: string | null;
};

export type MachineAccess = {
    canApprove: boolean;
    login: string;
    logins: string[];
    address: string | null;
    hostname: string;
    port: number | null;
    ssh: {
        passwords: boolean | null;
        keyboardInteractive: boolean;
        rootLogin: string | null;
        ports: number[];
    } | null;
    signIns: {
        failed: number;
        sources: number;
        root: number;
        password: number;
        hours: number;
    } | null;
    people: AccessPerson[];
    history: AccessEvent[];
    lastChange: { at: string; by: string | null } | null;
    members: AccessMember[];
    expiryDays: number[];
};

export function dayMonth(iso: string | null | undefined): string {
    return iso
        ? new Date(iso).toLocaleDateString(undefined, {
              day: 'numeric',
              month: 'short',
          })
        : '';
}

/** "SHA256:4hM2kQ…pQx": enough to tell keys apart in a table. */
export function shortFingerprint(fingerprint: string | null): string {
    if (!fingerprint) return '';
    const [kind, hash = ''] = fingerprint.split(':');

    return hash.length > 14
        ? `${kind}:${hash.slice(0, 8)}…${hash.slice(-4)}`
        : fingerprint;
}

export function initials(name: string | null | undefined): string {
    const words = (name ?? '').replace(/@.*/, '').split(/[\s._-]+/);

    return (
        words
            .filter(Boolean)
            .slice(0, 2)
            .map((word) => word[0]!.toUpperCase())
            .join('') || '?'
    );
}

export function sshCommand(access: MachineAccess, login: string): string {
    const host = access.address ?? access.hostname;

    return access.port
        ? `ssh -p ${access.port} ${login}@${host}`
        : `ssh ${login}@${host}`;
}

export function rootLabel(value: string | null): string {
    switch (value) {
        case 'no':
            return trans('Off');
        case 'yes':
            return trans('On');
        case 'prohibit-password':
        case 'without-password':
            return trans('Keys only');
        case 'forced-commands-only':
            return trans('Commands only');
        default:
            return '—';
    }
}
