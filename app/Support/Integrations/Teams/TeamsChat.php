<?php

namespace App\Support\Integrations\Teams;

use App\Models\ChatPost;
use App\Models\Integration;
use App\Support\Integrations\Chat\ChatAlert;
use App\Support\Integrations\Chat\ChatFailed;
use App\Support\Integrations\Chat\ThreadedChat;
use App\Support\Integrations\IntegrationFailed;
use App\Support\Integrations\Servers\CloudServers;

/**
 * Cards in the Teams channels the workspace connected. A workflow webhook
 * gives no message id, so a card can't be changed: resolve() says so, and
 * the alert that says it's fixed is posted as a card of its own.
 */
final class TeamsChat extends ThreadedChat
{
    public const string CHAT = 'teams';

    public function chat(): string
    {
        return self::CHAT;
    }

    protected function destinations(ChatAlert $alert): array
    {
        $out = [];
        $accounts = Integration::query()->where('workspace_id', $alert->workspace->id)->where('provider', 'teams')
            ->where('status', Integration::CONNECTED)->orderBy('id')->get();
        foreach ($accounts as $integration) {
            $deploys = TeamsProvider::wants($integration, 'deploys');
            $wanted = match (true) {
                $alert->isDeployNotice() => $deploys,
                $alert->isDeploy() => ! $deploys && TeamsProvider::wants($integration, 'alerts'),
                default => TeamsProvider::wants($integration, 'alerts'),
            };
            if ($wanted) {
                $out[] = ['destination' => (string) $integration->id, 'integration' => $integration->id];
            }
        }

        return $out;
    }

    protected function post(ChatAlert $alert, ChatPost $post): string
    {
        $integration = Integration::query()->find($post->integration_id) ?? throw new ChatFailed('The Teams channel is gone.');
        try {
            (new TeamsClient($integration->credential('webhook_url')))->postCard(TeamsCards::alert($alert));
        } catch (IntegrationFailed $e) {
            if ($e->refusedCredentials() || $e->reason === IntegrationFailed::PERMISSION) {
                CloudServers::failed($integration, $e);
            }

            throw new ChatFailed($e->getMessage());
        }

        return '';
    }

    protected function resolve(ChatAlert $recovery, ChatPost $open): bool
    {
        return false;
    }
}
