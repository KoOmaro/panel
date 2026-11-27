import type { FilledIconName } from '@/components/icons/filledIcons';

export type IntegrationTarget = {
    href?: string;
    alerts?: boolean;
};

export type IntegrationAction = IntegrationTarget & {
    label: string;
    unavailable?: boolean;
    hint?: { text: string; href: string | null; label: string | null };
};

export type Integration = {
    key: string;
    name: string;
    category: string;
    logo: string | null;
    initials: string | null;
    description: string;
    tags: string[];
    connected: boolean;
    action: IntegrationAction | null;
};

export type IntegrationCategory = {
    key: string;
    tab: string;
    title: string;
    subtitle: string;
    icon: FilledIconName;
};

export type IntegrationConnection = {
    key: string;
    name: string;
    logo: string | null;
    detail: string;
    target: IntegrationTarget | null;
};

export const LOGO_IMAGES: Record<string, string> = {
    synteq: '/images/integrations/synteq.png',
};
