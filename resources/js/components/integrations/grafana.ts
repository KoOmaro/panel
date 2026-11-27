export type GrafanaDataSource = {
    uid: string;
    name: string;
    isDefault: boolean;
    cloud: boolean;
};

export type MetricsToken = {
    id: number;
    name: string;
    prefix: string;
    lastUsedAt: string | null;
    createdAt: string | null;
};

export type GrafanaAccount = {
    address: string;
    cloud: boolean;
    dataSources: GrafanaDataSource[];
    dataSourcesError: string | null;
    suggestedUid: string | null;
    dashboard: {
        href: string;
        version: number;
        at: string;
        datasource: { uid: string; name: string } | null;
    } | null;
    push: {
        endpoint: string;
        instance: string;
        lastAt: string | null;
        lastCount: number | null;
        error: string | null;
        failing: boolean;
    } | null;
    scrape: {
        url: string;
        perMinute: number;
        config: string;
        tokens: MetricsToken[];
    };
    series: string[];
};
