<?php

namespace App\Support\Integrations\Chat;

use App\Support\Integrations\Discord\DiscordChat;
use App\Support\Integrations\Slack\SlackChat;
use App\Support\Integrations\Teams\TeamsChat;
use App\Support\Integrations\Telegram\TelegramChat;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Every chat alerts go to besides email and the plain webhook. MonitorAlerts
 * calls deliver() once per alert (and once per finished deploy, as a
 * ChatAlert::DEPLOY_NOTICE). Another chat registers its own target with
 * app(ChatTargets::class)->register(...), from its service provider.
 */
#[Singleton]
final class ChatTargets
{
    /** @var array<string, class-string<ChatTarget>|ChatTarget> */
    private array $targets = [
        'telegram' => TelegramChat::class,
        'discord' => DiscordChat::class,
        'slack' => SlackChat::class,
        'teams' => TeamsChat::class,
    ];

    /** @param  class-string<ChatTarget>|ChatTarget  $target */
    public function register(string $key, string|ChatTarget $target): void
    {
        $this->targets[$key] = $target;
    }

    public function deliver(ChatAlert $alert): void
    {
        foreach ($this->targets as $key => $target) {
            try {
                ($target instanceof ChatTarget ? $target : app($target))->deliver($alert);
            } catch (Throwable $e) {
                report($e);
                Log::warning('Chat alert failed', ['workspace' => $alert->workspace->id, 'chat' => $key, 'event' => $alert->event]);
            }
        }
    }
}
