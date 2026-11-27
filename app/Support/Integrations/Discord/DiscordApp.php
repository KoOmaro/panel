<?php

namespace App\Support\Integrations\Discord;

/**
 * The Discord application whoever runs this panel made for it
 * (services.discord: application id, public key, bot token and client
 * secret, see docs/integrations.md). Without it, Discord alerts go through
 * the alert webhook only: no buttons and no commands.
 */
final class DiscordApp
{
    public const string API = 'https://discord.com/api/v10';

    // View Channels, Send Messages, Embed Links and Read Message History: enough to post alerts and edit them.
    public const int PERMISSIONS = (1 << 10) | (1 << 11) | (1 << 14) | (1 << 16);

    public static function configured(): bool
    {
        return self::applicationId() !== '' && self::publicKey() !== '' && self::botToken() !== '' && self::clientSecret() !== '';
    }

    public static function applicationId(): string
    {
        return trim((string) config('services.discord.application_id'));
    }

    public static function publicKey(): string
    {
        return trim((string) config('services.discord.public_key'));
    }

    public static function botToken(): string
    {
        return trim((string) config('services.discord.bot_token'));
    }

    public static function clientSecret(): string
    {
        return trim((string) config('services.discord.client_secret'));
    }

    public static function redirectUri(): string
    {
        return rtrim((string) config('app.url'), '/').route('discord.callback', [], false);
    }

    public static function interactionsUrl(): string
    {
        return rtrim((string) config('app.url'), '/').route('discord.interactions', [], false);
    }

    /** The OAuth2 page that adds the bot (and its commands) to a server the person manages. */
    public static function installUrl(string $state): string
    {
        return 'https://discord.com/oauth2/authorize?'.http_build_query([
            'client_id' => self::applicationId(),
            'scope' => 'bot applications.commands',
            'permissions' => (string) self::PERMISSIONS,
            'integration_type' => 0,
            'response_type' => 'code',
            'redirect_uri' => self::redirectUri(),
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }
}
