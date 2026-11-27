<?php

namespace App\Support\Integrations\Slack;

/**
 * The Slack app this install connects Slack workspaces through: its keys
 * (config services.slack_app) and the addresses Slack calls back on, which
 * the app's manifest lists (docs/integrations.md).
 */
final class SlackApp
{
    /** What the bot token may do: post and edit its own messages, take /serveros, and list channels to pick from. */
    public const array SCOPES = ['chat:write', 'commands', 'channels:read', 'groups:read'];

    /** Only to link Slack people to members by their email; without them people link with a one-time link instead. */
    public const array EMAIL_SCOPES = ['users:read', 'users:read.email'];

    public const string COMMAND = '/serveros';

    public static function configured(): bool
    {
        return self::clientId() !== '' && self::clientSecret() !== '' && self::signingSecret() !== '';
    }

    public static function clientId(): string
    {
        return trim((string) config('services.slack_app.client_id'));
    }

    public static function clientSecret(): string
    {
        return trim((string) config('services.slack_app.client_secret'));
    }

    public static function signingSecret(): string
    {
        return trim((string) config('services.slack_app.signing_secret'));
    }

    public static function redirectUrl(): string
    {
        return route('integrations.oauth.callback', ['provider' => 'slack']);
    }

    public static function interactivityUrl(): string
    {
        return route('slack.interactivity');
    }

    public static function commandsUrl(): string
    {
        return route('slack.commands');
    }

    public static function authorizeUrl(string $state): string
    {
        return 'https://slack.com/oauth/v2/authorize?'.http_build_query([
            'client_id' => self::clientId(),
            'scope' => implode(',', [...self::SCOPES, ...self::EMAIL_SCOPES]),
            'redirect_uri' => self::redirectUrl(),
            'state' => $state,
        ]);
    }

    /**
     * The app manifest for this install, to paste into api.slack.com/apps.
     *
     * @return array<string, mixed>
     */
    public static function manifest(): array
    {
        return [
            'display_information' => [
                'name' => 'ServerOS',
                'description' => 'Alerts, deploys and /serveros for your machines.',
                'background_color' => '#02030e',
            ],
            'features' => [
                'bot_user' => ['display_name' => 'ServerOS', 'always_online' => false],
                'slash_commands' => [[
                    'command' => self::COMMAND,
                    'url' => self::commandsUrl(),
                    'description' => 'Machines, services and deploys',
                    'usage_hint' => 'status | machine <name> | deploy <app> | help',
                    'should_escape' => false,
                ]],
            ],
            'oauth_config' => [
                'redirect_urls' => [self::redirectUrl()],
                'scopes' => ['bot' => [...self::SCOPES, ...self::EMAIL_SCOPES]],
            ],
            'settings' => [
                'interactivity' => ['is_enabled' => true, 'request_url' => self::interactivityUrl()],
                'org_deploy_enabled' => false,
                'socket_mode_enabled' => false,
                'token_rotation_enabled' => false,
            ],
        ];
    }
}
