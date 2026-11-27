export type ReadmeChip = {
    id: string;
    name: string;
    logo: string | null;
    port: number | null;
    note: 'static' | null;
};

export type ReadmeEntry = ReadmeChip & { state: 'live' | 'down' };

export type ReadmeApp = ReadmeChip & { routed: boolean };

export type ReadmeDep = ReadmeChip & {
    machine: { id: number; name: string } | null;
    from: string[];
    private: boolean;
};

export type ReadmeService = {
    id: string;
    key: string | null;
    name: string;
    kind: string;
    how: string;
    ports: number[];
    status: string;
    logo: string | null;
    iconUrl: string | null;
    image: string | null;
    app: string | null;
    framework: string | null;
    repo: string | null;
    branch: string | null;
    deploysOnPush: boolean;
    domains: string[];
    project: string | null;
    roots: string[];
};

export type ReadmeDataRow = {
    id: string;
    what: string;
    where: string;
    size: number | null;
    tone: 'good' | 'bad' | 'warn' | 'none';
    backup: string;
};

export type ReadmeRisk = {
    id: string;
    severity: 'urgent' | 'fix' | 'notice';
    title: string;
    detail: string;
    action: string | null;
    href: string | null;
    kind: 'updates' | 'link';
    ask: string | null;
};

export type ReadmeFacts = {
    machine: {
        id: number;
        name: string;
        provider: string | null;
        plan: string | null;
        region: string | null;
        city: string | null;
        country: string | null;
        os: string | null;
        environment: string;
        upDays: number | null;
    };
    sites: string[];
    services: ReadmeService[];
    wiring: {
        entry: ReadmeEntry | null;
        apps: ReadmeApp[];
        deps: ReadmeDep[];
    };
    open: number[] | null;
    data: ReadmeDataRow[];
    risks: ReadmeRisk[];
    good: { id: string; text: string }[];
};

export type ReadmeWords = {
    intro: string;
    wiring: string;
    services: Record<string, string>;
};

export type MachineReadmeData = {
    scanned: boolean;
    writing: boolean;
    generatedAt: string | null;
    source: 'ai' | 'panel' | null;
    reason: 'no_model' | 'quota' | 'error' | 'rejected' | 'slow' | null;
    facts: ReadmeFacts;
    words: ReadmeWords | null;
    markdown: string | null;
    repository: {
        repo: string;
        file: string;
        connected: boolean;
        pullRequest: string | null;
    } | null;
    canCommit: boolean;
    writesLeft: number;
};

export type ReadmeSegment = { text: string; mark: 'bold' | 'risk' | null };

export function segments(text: string): ReadmeSegment[] {
    const parts: ReadmeSegment[] = [];
    const pattern = /\*\*(.+?)\*\*|!!(.+?)!!/g;
    let last = 0;
    for (const match of text.matchAll(pattern)) {
        const at = match.index ?? 0;
        if (at > last) parts.push({ text: text.slice(last, at), mark: null });
        parts.push(
            match[1] !== undefined
                ? { text: match[1], mark: 'bold' }
                : { text: match[2] ?? '', mark: 'risk' },
        );
        last = at + match[0].length;
    }
    if (last < text.length) parts.push({ text: text.slice(last), mark: null });

    return parts;
}
