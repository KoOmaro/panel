import { trans } from 'laravel-vue-i18n';
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { Component, ComputedRef } from 'vue';
import type { FilledIconName } from '@/components/icons/filledIcons';
import { extensionIcon } from '@/lib/extensionIcons';
import { onWorkspace, workspacePath } from '@/lib/workspaceRoute';
import { home } from '@/routes/workspace';
import code from '@/routes/workspace/code';
import members from '@/routes/workspace/members';
import settings from '@/routes/workspace/settings';
import type { Workspace } from '@/types';

export type Section = {
    label: string;
    anchor: string;
    keywords?: string;
};

export type NavIcon = FilledIconName | Component;

export type NavItem = {
    key: string;
    label: string;
    description: string | null;
    icon: NavIcon;
    href: string;
    current: boolean;
    keywords: string;
    sections: Section[];
};

export type Result = {
    key: string;
    label: string;
    page: string | null;
    icon: NavIcon;
    href: string;
};

export type GroupKey = 'workspace' | 'machines' | 'developers' | 'account';

export type Group = {
    key: GroupKey;
    label: string;
    href: string;
    inSidebar: boolean;
    items: NavItem[];
};

type ExtensionSettingsItem = {
    key: string;
    label: string;
    description?: string;
    icon: string;
    path: string;
    group: string;
    component: string;
    keywords: string;
    sections: Section[];
};

const EXTENSION_ICONS: Record<string, FilledIconName> = {
    billing: 'setup-fill',
    'credit-card': 'setup-fill',
    key: 'key-fill',
    lock: 'lock-fill',
    globe: 'globe-fill',
    database: 'database-fill',
    code: 'code-fill',
    users: 'user-fill',
};

function extensionNavIcon(name: string): NavIcon {
    return EXTENSION_ICONS[name] ?? extensionIcon(name);
}

export function useSettingsNav(): ComputedRef<Group[]> {
    const page = usePage<{
        workspace: Workspace;
        extensions?: { settings: ExtensionSettingsItem[] } | null;
    }>();

    return computed<Group[]>(() =>
        withExtensions(
            ownGroups(page.props.workspace, page.component),
            page.props.extensions?.settings ?? [],
            page.component,
        ),
    );
}

export function backHref(slug: string): string {
    return onWorkspace(home(slug)).url;
}

function withExtensions(
    own: Group[],
    extensions: ExtensionSettingsItem[],
    component: string,
): Group[] {
    return own.map((group) => ({
        ...group,
        items: [
            ...group.items,
            ...extensions
                .filter((extension) => extension.group === group.key)
                .map((extension) => ({
                    label: extension.label,
                    description: extension.description || null,
                    icon: extensionNavIcon(extension.icon),
                    href: workspacePath(extension.path),
                    key: extension.component,
                    current: component === extension.component,
                    keywords: extension.keywords,
                    sections: extension.sections,
                })),
        ],
    }));
}

function ownGroups(workspace: Workspace, component: string): Group[] {
    const slug = workspace.slug;
    const item = (
        label: string,
        icon: NavIcon,
        href: string,
        name: string,
        keywords: string,
        sections: Section[] = [],
        description: string | null = null,
    ): NavItem => ({
        label,
        description,
        icon,
        href,
        key: `workspace/settings/${name}`,
        current: component === `workspace/settings/${name}`,
        keywords,
        sections,
    });

    return [
        {
            key: 'workspace',
            label: trans('Workspace'),
            href: onWorkspace(settings.general(slug)).url,
            inSidebar: true,
            items: [
                item(
                    trans('General'),
                    'settings-fill',
                    onWorkspace(settings.general(slug)).url,
                    'General',
                    'name logo address',
                    [
                        {
                            label: trans('Alerts'),
                            anchor: 'alerts',
                            keywords:
                                'notifications email webhook slack discord telegram mute',
                        },
                        {
                            label: 'GitHub',
                            anchor: 'github',
                            keywords: 'git repositories repos deploy connect',
                        },
                        {
                            label: trans('Container registries'),
                            anchor: 'registries',
                            keywords: 'docker images ghcr private login',
                        },
                        {
                            label: trans('Danger zone'),
                            anchor: 'danger-zone',
                            keywords: 'delete workspace remove',
                        },
                    ],
                    trans('These apply to everyone in :name.', {
                        name: workspace.name,
                    }),
                ),
                item(
                    trans('Members'),
                    'user-fill',
                    onWorkspace(members.index(slug)).url,
                    'Members',
                    'team people users',
                    [
                        {
                            label: trans('Invite people'),
                            anchor: 'invite',
                            keywords: 'add teammate email',
                        },
                        {
                            label: trans('Pending invites'),
                            anchor: 'invites',
                            keywords: 'invitations',
                        },
                        {
                            label: trans('Roles'),
                            anchor: 'roles',
                            keywords: 'permissions admin owner access',
                        },
                    ],
                    trans('Who’s in :name, and what they can do.', {
                        name: workspace.name,
                    }),
                ),
                item(
                    trans('Domains'),
                    'globe-fill',
                    onWorkspace(settings.domains(slug)).url,
                    'Domains',
                    'custom domain dns cname ssl',
                ),
                item(
                    trans('Status page'),
                    'monitors-fill',
                    onWorkspace(settings.statusPage(slug)).url,
                    'StatusPage',
                    'public uptime incidents',
                ),
                item(
                    trans('Theme'),
                    'layers-fill',
                    onWorkspace(settings.theme(slug)).url,
                    'Theme',
                    'custom css colours colors brand look style template ai',
                ),
            ],
        },
        {
            key: 'machines',
            label: trans('Machines'),
            href: onWorkspace(settings.machinePolicies(slug)).url,
            inSidebar: true,
            items: [
                item(
                    trans('Machine policies'),
                    'machines-fill',
                    onWorkspace(settings.machinePolicies(slug)).url,
                    'MachinePolicies',
                    'daemon updates releases collect metrics defaults groups',
                ),
                item(
                    trans('Log storage'),
                    'database-fill',
                    onWorkspace(settings.logStorage(slug)).url,
                    'LogStorage',
                    'logs s3 bucket retention',
                ),
            ],
        },
        {
            key: 'developers',
            label: trans('Developers'),
            href: onWorkspace(code.index(slug)).url,
            inSidebar: false,
            items: [
                item(
                    trans('API keys'),
                    'terminal-fill',
                    onWorkspace(settings.api(slug)).url,
                    'Api',
                    'api rest tokens',
                    [
                        {
                            label: trans('API keys'),
                            anchor: 'api-keys',
                            keywords: 'tokens',
                        },
                        {
                            label: trans('Recent requests'),
                            anchor: 'requests',
                        },
                    ],
                    trans('Use ServerOS from your own scripts and tools.'),
                ),
                item(
                    trans('AI and MCP'),
                    'sparkle-fill',
                    onWorkspace(settings.mcp(slug)).url,
                    'Mcp',
                    'claude chatgpt cursor assistant',
                    [
                        {
                            label: trans('Connect an AI app'),
                            anchor: 'connect',
                        },
                        {
                            label: trans('AI tool calls'),
                            anchor: 'tool-calls',
                            keywords: 'usage limit',
                        },
                        {
                            label: trans('Keys and connected apps'),
                            anchor: 'keys',
                            keywords: 'tokens',
                        },
                    ],
                    trans('Let your AI apps work with :name.', {
                        name: workspace.name,
                    }),
                ),
            ],
        },
        {
            key: 'account',
            label: trans('Your account'),
            href: onWorkspace(settings.profile(slug)).url,
            inSidebar: true,
            items: [
                item(
                    trans('Profile'),
                    'user-fill',
                    onWorkspace(settings.profile(slug)).url,
                    'Profile',
                    'name avatar picture',
                    [
                        {
                            label: trans('Your emails from this workspace'),
                            anchor: 'emails',
                            keywords: 'notifications digest unsubscribe',
                        },
                    ],
                    trans(
                        'Only you see these. They follow you to every workspace.',
                    ),
                ),
                item(
                    trans('Appearance'),
                    'layout-grid',
                    onWorkspace(settings.appearance(slug)).url,
                    'Appearance',
                    'theme dark light mode',
                ),
                item(
                    trans('Security'),
                    'lock-fill',
                    onWorkspace(settings.security(slug)).url,
                    'Security',
                    'login sign in',
                    [
                        {
                            label: trans('Workspace security'),
                            anchor: 'workspace-security',
                            keywords: 'require two-factor 2fa',
                        },
                        {
                            label: trans('Password'),
                            anchor: 'password',
                        },
                        {
                            label: trans('Connected accounts'),
                            anchor: 'connected-accounts',
                            keywords: 'google github sign in',
                        },
                        {
                            label: trans('Two-factor authentication'),
                            anchor: 'two-factor',
                            keywords: '2fa authenticator otp recovery codes',
                        },
                        {
                            label: trans('Passkeys'),
                            anchor: 'passkeys',
                            keywords: 'touch id face id security key',
                        },
                        {
                            label: trans('Signed-in devices'),
                            anchor: 'devices',
                            keywords: 'sessions sign out',
                        },
                        {
                            label: trans('Recent security activity'),
                            anchor: 'security-activity',
                            keywords: 'log history',
                        },
                    ],
                ),
            ],
        },
    ];
}
