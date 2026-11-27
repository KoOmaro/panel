<?php

namespace App\Support\Integrations\Discord;

use App\Models\ChatPost;
use App\Models\Integration;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Integrations\Chat\ChatAction;
use App\Support\Integrations\Chat\ChatCommands;
use App\Support\Integrations\Chat\ChatLinks;
use App\Support\Integrations\IntegrationFailed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Interactions Discord sends to the panel (already checked against the
 * app's public key): the slash commands and the buttons on alerts. The
 * answer is the HTTP response, within Discord's three seconds; a check's
 * result follows by editing that answer.
 *
 * @see https://docs.discord.com/developers/interactions/receiving-and-responding
 */
final class DiscordInteractions
{
    public const string CHAT = 'discord';

    private const int PING = 1;

    private const int COMMAND = 2;

    private const int COMPONENT = 3;

    private const int PONG = 1;

    private const int REPLY = 4;

    private const int EPHEMERAL = 64;

    public function __construct(private readonly ChatCommands $commands) {}

    /**
     * @param  array<string, mixed>  $interaction
     * @return array<string, mixed>|null the response, or null for one seen already
     */
    public function handle(array $interaction): ?array
    {
        $type = $interaction['type'] ?? null;
        if ($type === self::PING) {
            return ['type' => self::PONG];
        }
        if (! in_array($type, [self::COMMAND, self::COMPONENT], true)) {
            return self::reply('ServerOS doesn’t know that one.');
        }
        $id = (string) ($interaction['id'] ?? '');
        if ($id === '' || ! Cache::add('discord-interaction:'.$id, true, now()->addMinutes(15))) {
            return null;
        }

        $guild = is_string($interaction['guild_id'] ?? null) ? $interaction['guild_id'] : null;
        $integration = $guild === null ? null : Integration::query()->where('provider', 'discord')
            ->where('fingerprint', Integration::fingerprintOf('discord', ['guild_id' => $guild]))->with('workspace')->first();
        if ($integration === null) {
            return self::reply('This server isn’t connected to ServerOS. Connect it from Integrations in ServerOS.');
        }
        $workspace = $integration->workspace;
        $person = (array) ($interaction['member']['user'] ?? $interaction['user'] ?? []);
        $personId = (string) ($person['id'] ?? '');
        $user = $personId === '' ? null : ChatLinks::member($workspace, self::CHAT, $personId);
        $sees = $user !== null || (is_string($interaction['channel_id'] ?? null) && $interaction['channel_id'] === $integration->setting('alerts'));
        $token = (string) ($interaction['token'] ?? '');

        if ($type === self::COMPONENT) {
            return $this->button($workspace, (array) ($interaction['data'] ?? []), $user, $token);
        }

        $data = (array) ($interaction['data'] ?? []);
        $options = [];
        foreach ((array) ($data['options'] ?? []) as $option) {
            if (is_array($option) && is_string($option['name'] ?? null)) {
                $options[$option['name']] = $option['value'] ?? null;
            }
        }
        $text = fn (string $key): string => is_scalar($options[$key] ?? null) ? trim((string) $options[$key]) : '';

        return match ((string) ($data['name'] ?? '')) {
            'help' => self::reply(ChatCommands::help()),
            'link' => self::reply($this->link($workspace, $text('code'), $personId, $person)),
            'status' => self::reply($sees ? $this->commands->status($workspace) : self::stranger()),
            'machine' => self::reply($sees ? $this->commands->machine($workspace, $text('name')) : self::stranger()),
            'checknow' => $sees ? $this->checkNow($workspace, $user, $text('monitor'), $token) : self::reply(self::stranger()),
            'mute' => self::reply($sees ? $this->mute($workspace, $user, $text('monitor'), (int) ($options['minutes'] ?? 60)) : self::stranger()),
            default => self::reply('ServerOS doesn’t know that command.'),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function button(Workspace $workspace, array $data, ?User $user, string $token): array
    {
        if (preg_match('/^serveros:(check|restart|mute):(\d{1,18})$/', (string) ($data['custom_id'] ?? ''), $m) !== 1) {
            return self::reply('That button doesn’t work any more. Open ServerOS instead.');
        }
        $post = ChatPost::query()->whereKey((int) $m[2])->where('workspace_id', $workspace->id)->where('chat', self::CHAT)->first();
        if ($post === null) {
            return self::reply('That button doesn’t work any more. Open ServerOS instead.');
        }
        if ($user === null || ! ChatCommands::allowed($workspace, $user)) {
            return self::reply(ChatCommands::refusal($workspace, $user));
        }

        $name = $user->name;
        $reply = $this->commands->press(ChatAction::from($m[1]), $post, $user, self::CHAT,
            fn (string $result) => $this->followUp($token, "{$name}: {$result}"));

        return self::reply("{$name}: {$reply}", public: true);
    }

    /** @return array<string, mixed> */
    private function checkNow(Workspace $workspace, ?User $user, string $reference, string $token): array
    {
        $monitor = $this->commands->findMonitor($workspace, $reference);
        if (is_string($monitor)) {
            return self::reply($monitor);
        }

        return self::reply($this->commands->checkNow($workspace, $user, $monitor, fn (string $result) => $this->followUp($token, $result)));
    }

    private function mute(Workspace $workspace, ?User $user, string $reference, int $minutes): string
    {
        $monitor = $this->commands->findMonitor($workspace, $reference);

        return is_string($monitor) ? $monitor : $this->commands->mute($workspace, $user, $monitor, $minutes, self::CHAT);
    }

    /** @param  array<string, mixed>  $person */
    private function link(Workspace $workspace, string $code, string $personId, array $person): string
    {
        $name = is_string($person['global_name'] ?? null) ? $person['global_name'] : (is_string($person['username'] ?? null) ? $person['username'] : null);
        $user = $personId === '' || $code === '' ? null : ChatLinks::redeem(self::CHAT, $workspace, $code, $personId, $name);

        return $user === null
            ? 'That code didn’t work. Make a new one in your ServerOS profile, under Chat accounts. Codes work once, for '.ChatLinks::MINUTES.' minutes.'
            : "Linked to {$user->name} in {$workspace->name}. The buttons on alerts now work for you, as far as your role allows.";
    }

    private function followUp(string $token, string $text): void
    {
        if ($token === '') {
            return;
        }
        try {
            (new DiscordClient)->editReply($token, ['content' => mb_substr(DiscordChat::escape($text), 0, 2000), 'allowed_mentions' => ['parse' => []]]);
        } catch (IntegrationFailed $e) {
            Log::warning('Discord reply failed', ['error' => $e->getMessage()]);
        }
    }

    private static function stranger(): string
    {
        return 'Link your account first with /link and the code from your ServerOS profile, or ask in the alerts channel.';
    }

    /** @return array<string, mixed> */
    private static function reply(string $text, bool $public = false): array
    {
        return ['type' => self::REPLY, 'data' => array_filter([
            'content' => mb_substr(DiscordChat::escape($text), 0, 2000),
            'flags' => $public ? null : self::EPHEMERAL,
            'allowed_mentions' => ['parse' => []],
        ], fn ($v) => $v !== null)];
    }
}
