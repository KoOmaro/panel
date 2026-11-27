export type DockerHubRepository = {
    namespace: string;
    name: string;
    repository: string;
    private: boolean;
    description: string | null;
    pushedAt: string | null;
    pulls: number | null;
    url: string;
};

export type DockerHubTag = {
    name: string;
    pushedAt: string | null;
    size: number | null;
    digest: string | null;
    platforms: string[];
    port: number | null;
};

export type DockerHubList = {
    connected: boolean;
    account: { id: number; label: string; username: string } | null;
    repositories: DockerHubRepository[];
    error: string | null;
};

export type DockerHubPick = {
    repository: string;
    tag: DockerHubTag;
};

export type DockerHubApp = {
    image: string;
    repository: string;
    tag: string;
    url: string | null;
    running: string;
    latest: string | null;
    behind: boolean;
    error: string | null;
    pushedAt: string | null;
    size: number | null;
    updating: string | null;
    account: { id: number; label: string } | null;
    push: {
        on: boolean;
        available: boolean;
        url: string | null;
        firstDeliveryAt: string | null;
        lastDeliveryAt: string | null;
        lastPushAt: string | null;
    } | null;
};

export type DockerHubAccount = {
    login: { state: 'linked' | 'other' | 'none'; username: string | null };
    namespaces: { name: string; count: number }[];
    repositories: DockerHubRepository[];
    total: number;
    error: string | null;
    apps: {
        machine: string;
        app: string;
        href: string;
        image: string;
        redeploy: boolean;
        firstDeliveryAt: string | null;
        lastPushAt: string | null;
    }[];
};

/** sha256:abc… as the first 12 hex digits, like Docker Hub shows it. */
export function shortDigest(digest: string | null | undefined): string {
    return digest ? digest.replace(/^sha256:/, '').slice(0, 12) : '';
}
