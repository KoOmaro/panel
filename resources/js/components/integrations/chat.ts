export type ChatPostRow = {
    id: number;
    subject: string;
    event: string;
    state: 'open' | 'resolved' | 'sent' | 'failed';
    error: string | null;
    channel: string;
    at: string | null;
    url: string | null;
};

export type SlackChannel = {
    id: string;
    name: string;
    private: boolean;
    member: boolean;
};

export type SlackPerson = {
    id: number;
    slack: string | null;
    member: string;
    avatar: string | null;
    since: string | null;
};

export type SlackAccount = {
    kind: 'slack';
    team: string;
    teamUrl: string | null;
    channels: SlackChannel[];
    channelsError: string | null;
    alerts: { id: string; name: string } | null;
    deploys: { id: string; name: string } | null;
    command: string;
    emailLinking: boolean;
    people: SlackPerson[];
    posts: ChatPostRow[];
};

export type TeamsAccount = {
    kind: 'teams';
    alerts: boolean;
    deploys: boolean;
    posts: ChatPostRow[];
};

export function isChatAccount(
    account: unknown,
): account is SlackAccount | TeamsAccount {
    const kind = (account as { kind?: unknown } | null | undefined)?.kind;

    return kind === 'slack' || kind === 'teams';
}
