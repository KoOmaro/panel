<?php

namespace App\Http\Controllers;

use App\Models\Machine;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Deploys\AppReleases;
use App\Support\SetupGuide;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SetupGuideController extends Controller
{
    private const int LOGOS = 40;

    public function __invoke(Request $request, Workspace $workspace): Response
    {
        $user = $request->user();

        return Inertia::render('workspace/SetupGuide', [
            'workspace' => $workspace->toPageProps(),
            'guide' => SetupGuide::build($workspace, $user),
            'hidden' => SetupGuide::hidden($workspace, $user),
            'results' => fn () => $this->results($workspace, $user),
            'discordUrl' => config('serveros.brand.discord_url'),
        ]);
    }

    /**
     * @return array<string, array{summary: string|null, logos: list<array{name: string, icon: string|null}>, people?: list<array{name: string, avatar: string|null}>}>
     */
    private function results(Workspace $workspace, User $user): array
    {
        $machines = $workspace->machines()->get();
        $services = $workspace->countedMachines()->get()
            ->flatMap(fn (Machine $machine) => AppReleases::visible($machine))
            ->reject(fn (array $service) => ($service['hidden'] ?? false) === true);
        $running = $services->filter(fn (array $service) => ($service['status'] ?? null) === 'running')->count();
        $intervals = $workspace->monitors()->pluck('interval_seconds')->unique()->values();
        $monitors = $workspace->monitors()->count();
        $lastBackup = $machines->max('last_backup_at');
        $members = $workspace->members()->orderBy('user_workspace.created_at')->get();
        $invited = $workspace->invitations()->count();

        return [
            'machine' => [
                'summary' => trans_choice(':count machine connected|:count machines connected', $machines->count()),
                'logos' => array_values($machines->countBy('provider')->sortDesc()->keys()
                    ->map(fn (string $provider) => ['name' => $provider, 'icon' => null])->take(3)->all()),
            ],
            'service' => [
                'summary' => trans_choice(':count service running|:count services running', $running),
                'logos' => array_values($services
                    ->map(fn (array $service) => [
                        'name' => trim(((string) ($service['name'] ?? '')).' '.((string) ($service['details']['image'] ?? ''))),
                        'icon' => is_string($service['icon'] ?? null) ? $service['icon'] : null,
                    ])
                    ->unique('name')->take(self::LOGOS)->all()),
            ],
            'monitor' => [
                'summary' => $monitors === 0 ? null : trim(trans_choice(':count monitor|:count monitors', $monitors).', '.$this->interval($intervals->count() === 1 ? (int) $intervals->first() : null), ', '),
                'logos' => [],
            ],
            'backups' => [
                'summary' => $lastBackup === null ? null : $this->t('Last backup :time', ['time' => $lastBackup->diffForHumans()]),
                'logos' => [],
            ],
            'members' => [
                'summary' => $invited > 0
                    ? $this->t(':members, :invited', [
                        'members' => trans_choice(':count member|:count members', $members->count()),
                        'invited' => $this->t(':count invited', ['count' => $invited]),
                    ])
                    : trans_choice(':count member|:count members', $members->count()),
                'logos' => [],
                'people' => array_values($members->take(3)->map(fn (User $member) => ['name' => $member->name, 'avatar' => $member->avatar])->all()),
            ],
            'security' => [
                'summary' => match (true) {
                    $user->hasEnabledTwoFactorAuthentication() => $this->t('Two-factor is on'),
                    $user->passkeys()->exists() => $this->t('You sign in with a passkey'),
                    default => null,
                },
                'logos' => [],
            ],
            'plan' => [
                'summary' => $workspace->effectivePlan() === null ? null : $this->t('On the :plan plan', ['plan' => $workspace->effectivePlan()->label()]),
                'logos' => [],
            ],
        ];
    }

    private function interval(?int $seconds): string
    {
        return match (true) {
            $seconds === null => '',
            $seconds === 60 => $this->t('checked every minute'),
            $seconds % 60 === 0 => $this->t('checked every :count minutes', ['count' => intdiv($seconds, 60)]),
            default => $this->t('checked every :count seconds', ['count' => $seconds]),
        };
    }

    /**
     * @param  array<string, string|int>  $replace
     */
    private function t(string $text, array $replace = []): string
    {
        return is_string($line = __($text, $replace)) ? $line : $text;
    }
}
