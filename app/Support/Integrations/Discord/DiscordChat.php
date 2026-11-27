<?php

namespace App\Support\Integrations\Discord;

use App\Models\ChatIdentity;
use App\Models\ChatPost;
use App\Models\Integration;
use App\Support\Integrations\Chat\ChatAction;
use App\Support\Integrations\Chat\ChatAlert;
use App\Support\Integrations\Chat\ChatFailed;
use App\Support\Integrations\Chat\ThreadedChat;
use App\Support\Integrations\IntegrationFailed;

/**
 * Alerts in the Discord channel the workspace picked, as an embed with
 * buttons (Check now, Restart, Mute 1 hour, Open), and every finished deploy
 * in its deploys channel. The alert that says it's fixed edits the message.
 */
final class DiscordChat extends ThreadedChat
{
    public const int RED = 0xE5484D;

    public const int GREEN = 0x30A46C;

    public function chat(): string
    {
        return 'discord';
    }

    public static function account(int $workspaceId): ?Integration
    {
        if (! DiscordApp::configured()) {
            return null;
        }

        return Integration::query()->where('workspace_id', $workspaceId)->where('provider', 'discord')
            ->where('status', Integration::CONNECTED)->first();
    }

    protected function destinations(ChatAlert $alert): array
    {
        $integration = self::account($alert->workspace->id);
        if ($integration === null) {
            return [];
        }
        $alerts = $integration->setting('alerts');
        $deploys = $integration->setting('deploys');
        $channel = match (true) {
            $alert->isDeployNotice() => $deploys,
            // With a deploys channel, its notices say how each deploy went; the alert would only repeat it.
            $alert->isDeploy() && is_string($deploys) => null,
            default => $alerts,
        };

        return is_string($channel) && $channel !== '' ? [['destination' => $channel, 'integration' => $integration->id]] : [];
    }

    protected function post(ChatAlert $alert, ChatPost $post): string
    {
        try {
            $sent = (new DiscordClient)->postMessage($post->destination, self::message($alert, $post, $alert->actions()));
        } catch (IntegrationFailed $e) {
            throw new ChatFailed($e->getMessage());
        }

        return (string) ($sent['id'] ?? '');
    }

    protected function resolve(ChatAlert $recovery, ChatPost $open): bool
    {
        try {
            (new DiscordClient)->editMessage($open->destination, (string) $open->message_id, self::message($recovery, $open, [], "Earlier: {$open->subject}."));
        } catch (IntegrationFailed) {
            return false;
        }

        return true;
    }

    /**
     * @param  list<ChatAction>  $actions
     * @return array<string, mixed>
     */
    public static function message(ChatAlert $alert, ChatPost $post, array $actions, ?string $footer = null): array
    {
        $buttons = array_map(fn (ChatAction $action) => [
            'type' => 2,
            'style' => $action === ChatAction::CheckNow ? 1 : 2,
            'label' => mb_substr($alert->label($action), 0, 80),
            'custom_id' => "serveros:{$action->value}:{$post->id}",
        ], $actions);
        if (preg_match('#^https?://#i', $alert->url) === 1) {
            $buttons[] = ['type' => 2, 'style' => 5, 'label' => mb_substr($alert->actionLabel, 0, 80), 'url' => $alert->url];
        }
        $description = implode("\n", array_filter([...array_map(self::escape(...), $alert->lines), $footer === null ? null : self::escape($footer)]));

        return [
            'embeds' => [array_filter([
                'title' => mb_substr(self::escape($alert->subject), 0, 256),
                'description' => $description === '' ? null : mb_substr($description, 0, 4000),
                'url' => preg_match('#^https?://#i', $alert->url) === 1 ? $alert->url : null,
                'color' => $alert->bad ? self::RED : self::GREEN,
                'timestamp' => now()->toIso8601String(),
            ], fn ($v) => $v !== null)],
            'components' => $buttons === [] ? [] : [['type' => 1, 'components' => array_slice($buttons, 0, 5)]],
            'allowed_mentions' => ['parse' => []],
        ];
    }

    public static function escape(string $text): string
    {
        return (string) preg_replace('/([\\\\*_~`|>])/', '\\\\$1', $text);
    }

    /**
     * What the account page shows: the server's channels to pick from, what's
     * picked, who linked their account, and the last alerts posted.
     *
     * @return array<string, mixed>
     */
    public static function accountProps(Integration $integration): array
    {
        $error = null;
        $channels = [];
        try {
            $channels = (new DiscordClient)->channels($integration->credential('guild_id'));
        } catch (IntegrationFailed $e) {
            $error = $e->getMessage();
        }

        return [
            'channels' => $channels,
            'error' => $error,
            'alerts' => $integration->setting('alerts'),
            'deploys' => $integration->setting('deploys'),
            'commands' => (int) ($integration->meta['commands'] ?? 0) > 0,
            'members' => ChatIdentity::query()->where('workspace_id', $integration->workspace_id)->where('chat', 'discord')
                ->with('user:id,name,email')->orderBy('created_at')->get()
                ->map(fn (ChatIdentity $identity) => [
                    'id' => $identity->id,
                    'name' => $identity->user->name,
                    'discord' => $identity->external_name,
                    'linkedAt' => $identity->created_at?->toIso8601String(),
                ])->all(),
            'posts' => ChatPost::query()->where('integration_id', $integration->id)->latest('id')->limit(8)->get()
                ->map(fn (ChatPost $post) => [
                    'id' => $post->id,
                    'subject' => $post->subject,
                    'state' => $post->state,
                    'error' => $post->error,
                    'at' => $post->created_at?->toIso8601String(),
                    'resolvedAt' => $post->resolved_at?->toIso8601String(),
                ])->all(),
        ];
    }
}
