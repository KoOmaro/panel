<?php

namespace App\Support\Integrations\Discord;

use App\Models\ChatIdentity;
use App\Models\ChatPost;
use App\Models\Integration;
use App\Support\Integrations\Capability;
use App\Support\Integrations\ConnectedAccount;
use App\Support\Integrations\HasAccountPage;
use App\Support\Integrations\IntegrationFailed;
use App\Support\Integrations\Provider;

/**
 * A Discord server (guild) the panel's bot was added to. Connecting is the
 * bot invite (DiscordConnectionController), so there's no form: the account
 * is the server's id, checked and its commands registered with the bot.
 */
final class DiscordProvider extends Provider implements HasAccountPage
{
    public function key(): string
    {
        return 'discord';
    }

    public function name(): string
    {
        return 'Discord';
    }

    public function fields(): array
    {
        return [];
    }

    public function capabilities(): array
    {
        return [Capability::ChannelAlerts, Capability::AlertButtons, Capability::ChatCommands, Capability::DeployNotices];
    }

    public function multipleAccounts(): bool
    {
        return false;
    }

    public function available(): bool
    {
        return DiscordApp::configured();
    }

    public function unavailableNote(): string
    {
        return self::t('Alerts go through a Discord webhook here. Buttons and commands need a Discord app set up on this ServerOS install.');
    }

    public function connectPath(): ?string
    {
        return DiscordApp::configured() ? '/integrations/discord/install' : null;
    }

    public function describe(Capability $capability): string
    {
        return match ($capability) {
            Capability::ChannelAlerts => self::t('Posts each alert to the channel you pick, and edits it to say it’s fixed when it’s back.'),
            Capability::AlertButtons => self::t('Check now, Restart the service and Mute 1 hour run as the person who presses them, once they’ve linked their Discord account and when their role can manage machines.'),
            Capability::DeployNotices => self::t('Posts each finished deploy to the channel you pick for deploys.'),
            default => $capability->description(),
        };
    }

    /** Checks the bot is still in the server, and registers its commands there again. */
    public function verify(array $credentials): ConnectedAccount
    {
        $guild = (string) ($credentials['guild_id'] ?? '');
        if (preg_match('/^\d{5,25}$/', $guild) !== 1) {
            throw new IntegrationFailed(self::t('That isn’t a Discord server.'), IntegrationFailed::INVALID);
        }
        $client = new DiscordClient;
        $found = $client->guild($guild);
        $commands = $client->registerCommands($guild);

        return new ConnectedAccount((string) ($found['name'] ?? 'Discord'), [
            'guild' => mb_substr((string) ($found['name'] ?? ''), 0, 100),
            'commands' => $commands,
            'commands_at' => now()->toIso8601String(),
        ]);
    }

    public function summary(Integration $integration): string
    {
        $alerts = $integration->setting('alerts_name');

        return is_string($alerts) && $alerts !== ''
            ? self::t('Alerts to #:channel', ['channel' => $alerts])
            : self::t('Pick a channel for alerts');
    }

    public function facts(Integration $integration): array
    {
        $none = self::t('Not picked');

        return [
            ['label' => self::t('Server'), 'value' => (string) ($integration->meta['guild'] ?? $integration->label)],
            ['label' => self::t('Alerts'), 'value' => self::channel($integration, 'alerts') ?? $none],
            ['label' => self::t('Deploys'), 'value' => self::channel($integration, 'deploys') ?? $none],
            ['label' => self::t('Linked members'), 'value' => (string) self::linked($integration)],
        ];
    }

    public function usage(Integration $integration): array
    {
        $alerts = self::channel($integration, 'alerts');
        $deploys = self::channel($integration, 'deploys');
        $linked = self::linked($integration);
        $posted = ChatPost::query()->where('integration_id', $integration->id)->where('created_at', '>=', now()->subDays(7))->count();

        return array_values(array_filter([
            $alerts === null
                ? self::t('Alerts don’t go to Discord yet: pick a channel for them.')
                : self::t('Alerts go to :channel with buttons to check, restart and mute, and are edited when they’re fixed.', ['channel' => $alerts]),
            $deploys === null ? null : self::t('Each finished deploy is posted to :channel.', ['channel' => $deploys]),
            (int) ($integration->meta['commands'] ?? 0) > 0 ? self::t('/status, /machine, /checknow and /mute work in the server.') : self::t('The server’s commands aren’t registered. Check again to register them.'),
            match ($linked) {
                0 => self::t('No member has linked their Discord account yet, so the buttons refuse everyone.'),
                1 => self::t('1 member has linked their Discord account.'),
                default => self::t(':count members have linked their Discord accounts.', ['count' => $linked]),
            },
            $posted > 0 ? trans_choice(':count alert posted in the last 7 days.|:count alerts posted in the last 7 days.', $posted, ['count' => $posted]) : null,
        ]));
    }

    public function dependents(Integration $integration): array
    {
        $out = [];
        if (($alerts = self::channel($integration, 'alerts')) !== null) {
            $out[] = self::t('Alerts stop going to :channel.', ['channel' => $alerts]);
        }
        if (($linked = self::linked($integration)) > 0) {
            $out[] = (string) trans_choice(':count linked Discord account is unlinked.|:count linked Discord accounts are unlinked.', $linked, ['count' => $linked]);
        }

        return $out;
    }

    public function disconnect(Integration $integration): string
    {
        $guild = $integration->credential('guild_id');
        ChatIdentity::query()->where('workspace_id', $integration->workspace_id)->where('chat', 'discord')->delete();
        try {
            $client = new DiscordClient;
            $client->removeCommands($guild);
            $client->leave($guild);
        } catch (IntegrationFailed) {
            return self::t('Disconnected :name. Discord didn’t answer, so remove the bot from the server in Discord.', ['name' => $integration->label]);
        }

        return self::t('Disconnected :name. The bot left the server and its commands are gone.', ['name' => $integration->label]);
    }

    public function accountPage(Integration $integration): array
    {
        return DiscordChat::accountProps($integration);
    }

    public static function channel(Integration $integration, string $which): ?string
    {
        $name = $integration->setting($which.'_name');

        return is_string($name) && $name !== '' && is_string($integration->setting($which)) ? '#'.$name : null;
    }

    private static function linked(Integration $integration): int
    {
        return ChatIdentity::query()->where('workspace_id', $integration->workspace_id)->where('chat', 'discord')->count();
    }
}
