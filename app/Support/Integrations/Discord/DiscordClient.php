<?php

namespace App\Support\Integrations\Discord;

use App\Support\Integrations\IntegrationFailed;
use App\Support\Integrations\ProviderClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Discord's HTTP API (https://docs.discord.com/developers/reference) as the
 * panel's bot. One bot serves every workspace; a server (guild) belongs to
 * the one workspace that added it.
 */
final class DiscordClient extends ProviderClient
{
    /** The slash commands each server gets, registered on that server when it's connected. */
    public const array COMMANDS = [
        ['name' => 'status', 'type' => 1, 'description' => 'Machines, services and monitors that are down'],
        ['name' => 'machine', 'type' => 1, 'description' => 'How one machine is doing', 'options' => [
            ['type' => 3, 'name' => 'name', 'description' => 'The machine’s name', 'required' => true, 'max_length' => 120],
        ]],
        ['name' => 'checknow', 'type' => 1, 'description' => 'Check a monitor now', 'options' => [
            ['type' => 3, 'name' => 'monitor', 'description' => 'The monitor’s name', 'required' => true, 'max_length' => 120],
        ]],
        ['name' => 'mute', 'type' => 1, 'description' => 'Mute a monitor’s alerts for a while', 'options' => [
            ['type' => 3, 'name' => 'monitor', 'description' => 'The monitor’s name', 'required' => true, 'max_length' => 120],
            ['type' => 4, 'name' => 'minutes', 'description' => 'How long', 'required' => false, 'choices' => [
                ['name' => '30 minutes', 'value' => 30], ['name' => '1 hour', 'value' => 60], ['name' => '2 hours', 'value' => 120],
                ['name' => '4 hours', 'value' => 240], ['name' => '8 hours', 'value' => 480], ['name' => '24 hours', 'value' => 1440],
            ]],
        ]],
        ['name' => 'link', 'type' => 1, 'description' => 'Link your Discord account to your ServerOS account', 'options' => [
            ['type' => 3, 'name' => 'code', 'description' => 'The code from your profile in ServerOS', 'required' => true, 'max_length' => 20],
        ]],
        ['name' => 'help', 'type' => 1, 'description' => 'What ServerOS can do here'],
    ];

    protected function baseUrl(): string
    {
        return DiscordApp::API;
    }

    protected function providerName(): string
    {
        return 'Discord';
    }

    protected function headers(): array
    {
        return [
            'Authorization' => 'Bot '.DiscordApp::botToken(),
            'User-Agent' => 'DiscordBot ('.rtrim((string) config('app.url'), '/').', 1.0)',
        ];
    }

    protected function providerMessage(Response $response): ?string
    {
        $message = $response->json('message');

        return is_string($message) ? $message : null;
    }

    protected function failure(Response $response): ?IntegrationFailed
    {
        return match ($response->json('code')) {
            50001, 50013 => new IntegrationFailed($this->t('The bot isn’t allowed to post there. In Discord, give it View Channel, Send Messages and Embed Links in that channel.'), IntegrationFailed::PERMISSION, $response->status()),
            10003 => new IntegrationFailed($this->t('Discord can’t find that channel. It may have been deleted.'), IntegrationFailed::NOT_FOUND, $response->status()),
            10004 => new IntegrationFailed($this->t('The bot isn’t in that server any more. Connect Discord again to add it back.'), IntegrationFailed::CREDENTIALS, $response->status()),
            10008 => new IntegrationFailed($this->t('Discord can’t find that message. It may have been deleted.'), IntegrationFailed::NOT_FOUND, $response->status()),
            default => $response->status() === 401
                ? new IntegrationFailed($this->t('Discord turned the panel’s bot token down. Whoever runs ServerOS needs to check DISCORD_BOT_TOKEN.'), IntegrationFailed::CREDENTIALS, 401)
                : null,
        };
    }

    /** @return array<string, mixed> */
    public function guild(string $id): array
    {
        return $this->get("guilds/{$id}");
    }

    /** @return list<array{id: string, name: string}> the server's text and announcement channels, in Discord's order */
    public function channels(string $guild): array
    {
        $channels = array_values(array_filter($this->get("guilds/{$guild}/channels"), fn ($c) => is_array($c) && in_array($c['type'] ?? null, [0, 5], true)));
        usort($channels, fn (array $a, array $b) => ((int) ($a['position'] ?? 0)) <=> ((int) ($b['position'] ?? 0)));

        return array_map(fn (array $c) => ['id' => (string) $c['id'], 'name' => (string) ($c['name'] ?? '')], $channels);
    }

    public function registerCommands(string $guild): int
    {
        return count($this->put('applications/'.DiscordApp::applicationId()."/guilds/{$guild}/commands", self::COMMANDS));
    }

    public function removeCommands(string $guild): void
    {
        $this->put('applications/'.DiscordApp::applicationId()."/guilds/{$guild}/commands", []);
    }

    public function leave(string $guild): void
    {
        $this->delete("users/@me/guilds/{$guild}");
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    public function postMessage(string $channel, array $message): array
    {
        return $this->post("channels/{$channel}/messages", $message);
    }

    /** @param  array<string, mixed>  $message */
    public function editMessage(string $channel, string $id, array $message): void
    {
        $this->patch("channels/{$channel}/messages/{$id}", $message);
    }

    /** @param  array<string, mixed>  $message */
    public function editReply(string $interactionToken, array $message): void
    {
        $this->patch('webhooks/'.DiscordApp::applicationId()."/{$interactionToken}/messages/@original", $message);
    }

    /**
     * Swaps the code from the bot invite for the server it was added to.
     * Discord's redirect names a guild_id too, but only the token answer
     * proves it. The access token isn't needed, so it's revoked at once.
     *
     * @return array<string, mixed>|null the guild, or null when Discord didn't confirm one
     */
    public function exchange(string $code): ?array
    {
        $form = fn () => Http::asForm()->withBasicAuth(DiscordApp::applicationId(), DiscordApp::clientSecret())->timeout(self::TIMEOUT)->connectTimeout(self::CONNECT_TIMEOUT);
        try {
            $response = $form()->post(DiscordApp::API.'/oauth2/token', [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => DiscordApp::redirectUri(),
            ]);
        } catch (ConnectionException) {
            throw new IntegrationFailed($this->t(':provider couldn’t be reached. Try again in a few minutes.'), IntegrationFailed::UNAVAILABLE);
        }
        if (! $response->successful()) {
            return null;
        }
        $token = $response->json('access_token');
        if (is_string($token) && $token !== '') {
            rescue(fn () => $form()->post(DiscordApp::API.'/oauth2/token/revoke', ['token' => $token, 'token_type_hint' => 'access_token']), null, false);
        }
        $guild = $response->json('guild');

        return is_array($guild) && is_string($guild['id'] ?? null) ? $guild : null;
    }
}
