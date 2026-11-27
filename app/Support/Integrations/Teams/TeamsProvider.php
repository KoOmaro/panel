<?php

namespace App\Support\Integrations\Teams;

use App\Models\Integration;
use App\Support\Integrations\Capability;
use App\Support\Integrations\ConnectedAccount;
use App\Support\Integrations\ConnectField;
use App\Support\Integrations\HasAccountPage;
use App\Support\Integrations\PostsToChannels;
use App\Support\Integrations\Provider;
use App\Support\Integrations\Slack\SlackProvider;

/**
 * One Teams channel, through the webhook of a Teams workflow made from the
 * "Send webhook alerts to a channel" template (Office 365 connectors are
 * retired). Each account is a channel; it gets alerts, deploys or both.
 */
final class TeamsProvider extends Provider implements HasAccountPage, PostsToChannels
{
    public const string HELP_URL = 'https://support.microsoft.com/en-us/office/create-incoming-webhooks-with-workflows-for-microsoft-teams-8ae491c7-0394-4861-ba59-055e33f75498';

    public function key(): string
    {
        return 'teams';
    }

    public function name(): string
    {
        return 'Microsoft Teams';
    }

    public function fields(): array
    {
        return [
            new ConnectField(
                key: 'webhook_url',
                label: self::t('Workflow webhook link'),
                help: self::t('In Teams, open the channel’s ⋯ menu › Workflows, pick “Send webhook alerts to a channel”, then copy the link it makes. Name the account after the channel. Each channel is its own account.'),
                link: self::HELP_URL,
                linkLabel: self::t('How to make one'),
                placeholder: 'https://…powerplatform.com/powerautomate/automations/direct/workflows/…',
                rules: ['required', 'string', 'max:2000', 'starts_with:https://'],
            ),
        ];
    }

    public function capabilities(): array
    {
        return [Capability::ChannelAlerts, Capability::DeployNotices];
    }

    public function describe(Capability $capability): string
    {
        return match ($capability) {
            Capability::ChannelAlerts => self::t('Posts a card for each alert with a link to it in ServerOS, and a second card when a monitor or machine is fixed. A workflow can’t change a card it posted.'),
            Capability::DeployNotices => self::t('Posts a card for each deploy with its commit and who started it, and a link to it in ServerOS.'),
            default => $capability->description(),
        };
    }

    public function verify(array $credentials): ConnectedAccount
    {
        TeamsClient::check($credentials['webhook_url'] ?? '');

        return new ConnectedAccount(self::t('Teams channel'), ['checked' => 'format']);
    }

    public static function wants(Integration $integration, string $purpose): bool
    {
        return (bool) $integration->setting($purpose, true);
    }

    public function summary(Integration $integration): string
    {
        $alerts = self::wants($integration, 'alerts');
        $deploys = self::wants($integration, 'deploys');

        return match (true) {
            $alerts && $deploys => self::t('Alerts and deploys'),
            $alerts => self::t('Alerts'),
            $deploys => self::t('Deploys'),
            default => self::t('Posts nothing yet'),
        };
    }

    public function facts(Integration $integration): array
    {
        return [
            ['label' => self::t('Alerts'), 'value' => self::wants($integration, 'alerts') ? self::t('On') : self::t('Off')],
            ['label' => self::t('Deploys'), 'value' => self::wants($integration, 'deploys') ? self::t('On') : self::t('Off')],
        ];
    }

    public function usage(Integration $integration): array
    {
        return array_values(array_filter([
            self::wants($integration, 'alerts') ? self::t('Alerts are posted here as cards, and a second card says when a monitor or machine is fixed.') : null,
            self::wants($integration, 'deploys') ? self::t('Each deploy is posted here as a card.') : null,
            self::t('Buttons that act in Teams need a Teams bot, which ServerOS doesn’t have yet, so the cards link to ServerOS instead.'),
        ]));
    }

    public function dependents(Integration $integration): array
    {
        return self::wants($integration, 'alerts') || self::wants($integration, 'deploys') ? [self::t('This Teams channel stops getting cards.')] : [];
    }

    public function settingsRules(): array
    {
        return ['alerts' => ['required', 'boolean'], 'deploys' => ['required', 'boolean']];
    }

    public function settings(Integration $integration, array $data): array
    {
        return [...($integration->settings ?? []), 'alerts' => (bool) $data['alerts'], 'deploys' => (bool) $data['deploys']];
    }

    public function sendTest(Integration $integration): string
    {
        (new TeamsClient($integration->credential('webhook_url')))->postCard(TeamsCards::test($integration->workspace->name, $integration->workspace->url('/integrations/'.$integration->id)));

        return self::t('Teams took the test card. It shows in the channel in a moment.');
    }

    public function accountPage(Integration $integration): array
    {
        return [
            'kind' => 'teams',
            'alerts' => self::wants($integration, 'alerts'),
            'deploys' => self::wants($integration, 'deploys'),
            'posts' => SlackProvider::posts($integration, fn () => null),
        ];
    }
}
