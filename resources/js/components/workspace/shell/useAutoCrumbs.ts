import { usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';
import type { ComputedRef } from 'vue';
import { useWorkspaceNav } from '@/components/workspace/workspaceNav';
import type { DashCrumb } from '@/composables/useDashShell';
import { onWorkspace } from '@/lib/workspaceRoute';
import machinesRoutes from '@/routes/workspace/machines';
import type { Workspace } from '@/types';

const settingsLabels: Record<string, string> = {
    General: 'General',
    Members: 'Members',
    Profile: 'Profile',
    Security: 'Security',
    Appearance: 'Appearance',
    Theme: 'Theme',
    MachinePolicies: 'Machine policies',
    StatusPage: 'Status page',
    Domains: 'Domains',
    LogStorage: 'Log storage',
    License: 'License',
    Licenses: 'Licenses',
    CommunityLicense: 'License',
    Mcp: 'AI and MCP',
    Api: 'API',
    GitHubApp: 'GitHub App',
};

const codeLabels: Record<string, string> = {
    WorkspaceCode: 'Code',
    SetUpCode: 'Set up code',
    ReviewChanges: 'Review changes',
};

export function useAutoCrumbs(): ComputedRef<DashCrumb[]> {
    const page = usePage<{
        workspace: Workspace;
        machine?: { id: number; name: string; provider?: string | null };
        service?: { name: string };
        project?: { name: string };
        monitor?: { name: string };
        workflow?: { name: string };
        ticket?: { reference: string };
        task?: { title: string };
    }>();
    const nav = useWorkspaceNav();

    return computed<DashCrumb[]>(() => {
        const { component, props } = page;
        const slug = props.workspace?.slug;
        const items = nav.value;
        const find = (key: string) => items.find((item) => item.key === key);
        const crumb = (key: string): DashCrumb => {
            const item = find(key);

            return item
                ? { label: item.label, href: item.href }
                : { label: trans(key) };
        };
        const child = (group: string, key: string): DashCrumb => {
            const found = find(group)?.children?.find(
                (entry) => entry.key === key,
            );

            return found
                ? { label: found.label, href: found.href }
                : { label: trans(key) };
        };

        if (!slug) {
            return [];
        }

        const machineCrumb = (): DashCrumb | null =>
            props.machine
                ? {
                      label: props.machine.name,
                      logo: props.machine.provider ?? undefined,
                      href: onWorkspace(
                          machinesRoutes.show({
                              workspace: slug,
                              machine: props.machine.id,
                          }),
                      ).url,
                  }
                : null;

        switch (component) {
            case 'workspace/MachineShow':
                return [crumb('machines'), machineCrumb()].filter(
                    (entry): entry is DashCrumb => entry !== null,
                );
            case 'workspace/MachineSettings':
                return [
                    crumb('machines'),
                    machineCrumb(),
                    { label: trans('Settings') },
                ].filter((entry): entry is DashCrumb => entry !== null);
            case 'workspace/ServiceShow':
                return [
                    child('machines', 'services'),
                    { label: props.service?.name ?? trans('Service') },
                ];
            case 'workspace/ProjectShow':
                return [
                    crumb('projects'),
                    { label: props.project?.name ?? trans('Project') },
                ];
            case 'workspace/MonitorShow':
                return [
                    crumb('monitors'),
                    { label: props.monitor?.name ?? trans('Monitor') },
                ];
            case 'workspace/WorkflowEditor':
                return [
                    crumb('workflows'),
                    { label: props.workflow?.name ?? trans('Workflow') },
                ];
            case 'workspace/AgentTaskShow':
                return [
                    child('developers', 'agents'),
                    { label: props.task?.title ?? trans('Task') },
                ];
            case 'workspace/SupportShow':
                return [
                    crumb('help'),
                    { label: props.ticket?.reference ?? trans('Ticket') },
                ];
            case 'workspace/SupportNew':
                return [crumb('help'), { label: trans('New ticket') }];
            case 'workspace/Logs':
                return [{ label: crumb('logs').label }];
            case 'workspace/Workflows':
                return [{ label: crumb('workflows').label }];
            case 'workspace/Fixes':
                return [{ label: crumb('fixes').label }];
            case 'workspace/Billing':
                return [crumb('settings'), { label: trans('Billing') }];
        }

        if (component.startsWith('workspace/code/')) {
            const name = component.split('/').pop() ?? '';

            return name === 'WorkspaceCode'
                ? [{ label: trans('Code') }]
                : [
                      child('developers', 'code'),
                      { label: trans(codeLabels[name] ?? name) },
                  ];
        }

        if (component.startsWith('workspace/settings/')) {
            const name = component.split('/').pop() ?? '';

            return [
                crumb('settings'),
                { label: trans(settingsLabels[name] ?? name) },
            ];
        }

        const current =
            items.find((item) => item.current) ??
            items
                .flatMap((item) => item.children ?? [])
                .find((entry) => entry.current);

        if (current) {
            return [{ label: current.label }];
        }

        const owned = items.find(
            (item) => item.key === component.split('/').pop()?.toLowerCase(),
        );

        return [
            { label: owned?.label ?? trans(component.split('/').pop() ?? '') },
        ];
    });
}
