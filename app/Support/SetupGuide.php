<?php

namespace App\Support;

use App\Models\User;
use App\Models\Workspace;
use App\Support\Extensions\Extensions;

final class SetupGuide
{
    /**
     * @return array{percent: int, groups: list<array{key: string, title: string, locked: string|null, steps: list<array{key: string, title: string, body: string, href: string, done: bool}>}>}|null
     */
    public static function for(Workspace $workspace, User $user): ?array
    {
        if (self::hidden($workspace, $user)) {
            return null;
        }

        $guide = self::build($workspace, $user);

        return self::complete($guide) ? null : $guide;
    }

    /**
     * @return array{guide: array{percent: int, groups: list<array{key: string, title: string, locked: string|null, steps: list<array{key: string, title: string, body: string, href: string, done: bool}>}>}|null, left: int}
     */
    public static function sidebar(Workspace $workspace, User $user): array
    {
        $guide = self::build($workspace, $user);
        $left = count(array_filter(
            array_merge(...array_column($guide['groups'], 'steps')),
            fn (array $step) => ! $step['done'],
        ));

        return [
            'guide' => $left === 0 || self::hidden($workspace, $user) ? null : $guide,
            'left' => $left,
        ];
    }

    public static function hidden(Workspace $workspace, User $user): bool
    {
        return $workspace->members()
            ->whereKey($user->id)
            ->whereNotNull('user_workspace.onboarding_dismissed_at')
            ->exists();
    }

    /**
     * @param  array{percent: int}  $guide
     */
    public static function complete(array $guide): bool
    {
        return $guide['percent'] === 100;
    }

    /**
     * @return array{percent: int, groups: list<array{key: string, title: string, locked: string|null, steps: list<array{key: string, title: string, body: string, href: string, done: bool}>}>}
     */
    public static function build(Workspace $workspace, User $user): array
    {
        $machines = $workspace->machines()
            ->toBase()
            ->selectRaw('count(*) as machines, coalesce(sum(services_count), 0) as services, count(last_backup_at) as backed_up')
            ->first();
        $hasMachine = (int) ($machines->machines ?? 0) > 0;

        $groups = [
            [
                'key' => 'connect',
                'title' => self::t('Connect your servers'),
                'locked' => null,
                'steps' => [
                    self::step('machine', 'Connect a machine', 'Bring a VPS from any provider. What’s already running stays put.', $workspace->url('machines'), $hasMachine),
                    self::step('service', 'Adopt or deploy a service', 'Pick up the apps already on it, or ship a new one.', $workspace->url('services'), self::managesAService($workspace)),
                ],
            ],
            [
                'key' => 'safe',
                'title' => self::t('Keep it running'),
                'locked' => null,
                'steps' => [
                    self::step('monitor', 'Add a monitor', 'Hear about it when a site or port stops answering.', $workspace->url('monitors'), $workspace->monitors()->exists()),
                    self::step('backups', 'Back up a machine', 'So a bad deploy is one click to undo.', $workspace->url('backups'), (int) ($machines->backed_up ?? 0) > 0),
                ],
            ],
            [
                'key' => 'team',
                'title' => self::t('Bring your team'),
                'locked' => null,
                'steps' => [
                    self::step('members', 'Invite a teammate', 'Share the workspace, with a role each.', $workspace->url('settings/members'), $workspace->members()->count() > 1 || $workspace->invitations()->exists()),
                    self::step('security', 'Turn on two-factor', 'Or add a passkey and sign in with it.', $workspace->url('settings/security'), $user->hasEnabledTwoFactorAuthentication() || $user->passkeys()->exists()),
                ],
            ],
        ];

        $upgrade = app(Extensions::class)->upgradeLink();
        if ($upgrade !== null && ! Edition::selfHosted()) {
            $groups[] = [
                'key' => 'plan',
                'title' => self::t('Choose a plan'),
                'locked' => $hasMachine ? null : self::t('Unlocks once a machine is connected'),
                'steps' => [
                    self::step('plan', 'Pick a plan', 'Priced by how many machines you connect.', $upgrade->path($workspace), $workspace->plan !== null),
                ],
            ];
        }

        $steps = array_merge(...array_column($groups, 'steps'));
        $done = count(array_filter($steps, fn (array $step) => $step['done']));

        return [
            'percent' => (int) floor($done / count($steps) * 100),
            'groups' => $groups,
        ];
    }

    public static function managesAService(Workspace $workspace): bool
    {
        foreach ($workspace->machines()->whereNotNull('inventory')->get(['inventory']) as $machine) {
            $services = is_array($machine->inventory) ? ($machine->inventory['services'] ?? []) : [];
            foreach (is_array($services) ? $services : [] as $service) {
                if (is_array($service) && ($service['details']['managed'] ?? null) === 'true') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array{key: string, title: string, body: string, href: string, done: bool}
     */
    private static function step(string $key, string $title, string $body, string $href, bool $done): array
    {
        return ['key' => $key, 'title' => self::t($title), 'body' => self::t($body), 'href' => $href, 'done' => $done];
    }

    private static function t(string $text): string
    {
        return is_string($line = __($text)) ? $line : $text;
    }
}
