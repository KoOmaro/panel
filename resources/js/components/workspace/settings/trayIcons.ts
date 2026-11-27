import type { Component } from 'vue';
import type { FilledIconName } from '@/components/icons/filledIcons';
import {
    SolarBell,
    SolarChartNoAxesColumn,
    SolarCode,
    SolarDatabase,
    SolarDownload,
    SolarGitBranch,
    SolarGlobe,
    SolarHistory,
    SolarKey,
    SolarKeyRound,
    SolarLayers,
    SolarLayoutDashboard,
    SolarLink2,
    SolarMail,
    SolarMonitor,
    SolarPlug,
    SolarShieldCheck,
    SolarSparkles,
    SolarTriangleAlert,
    SolarUserPlus,
    SolarUserRound,
    SolarUsers,
} from '@/lib/solarIcons';

const TRAY_ICONS = new Map<Component, FilledIconName>([
    [SolarBell, 'info-fill'],
    [SolarChartNoAxesColumn, 'activity-fill'],
    [SolarCode, 'code-fill'],
    [SolarDatabase, 'database-fill'],
    [SolarDownload, 'update-fill'],
    [SolarGitBranch, 'branch-fill'],
    [SolarGlobe, 'globe-fill'],
    [SolarHistory, 'activity-fill'],
    [SolarKey, 'key-fill'],
    [SolarKeyRound, 'key-fill'],
    [SolarLayers, 'layers-fill'],
    [SolarLayoutDashboard, 'layout-grid'],
    [SolarLink2, 'user-fill'],
    [SolarMail, 'user-plus'],
    [SolarMonitor, 'lock-fill'],
    [SolarPlug, 'sparkle-fill'],
    [SolarShieldCheck, 'lock-fill'],
    [SolarSparkles, 'sparkle-fill'],
    [SolarTriangleAlert, 'info-fill'],
    [SolarUserPlus, 'user-plus'],
    [SolarUserRound, 'user-fill'],
    [SolarUsers, 'user-fill'],
]);

export function trayIcon(
    icon: Component | FilledIconName | undefined,
): FilledIconName | Component | undefined {
    if (icon === undefined || typeof icon === 'string') {
        return icon;
    }

    return TRAY_ICONS.get(icon) ?? icon;
}
