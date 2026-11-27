<?php

namespace App\Support\Integrations\Slack;

use App\Models\ChatPost;
use App\Models\DaemonJob;
use App\Models\Integration;
use App\Models\SlackMessage;
use App\Support\Integrations\Chat\ChatAlert;
use App\Support\Integrations\Chat\ChatFailed;
use App\Support\Integrations\Chat\ThreadedChat;
use App\Support\Integrations\IntegrationFailed;
use App\Support\Integrations\Servers\CloudServers;
use Illuminate\Support\Facades\Log;

/**
 * Alerts and deploy notices in the Slack channels each connected Slack
 * account picked: an alert goes to its alerts channel with buttons, a
 * finished deploy to its deploys channel with Roll back, and the alert that
 * says a monitor or machine is fixed redraws the message it opened.
 */
final class SlackChat extends ThreadedChat
{
    public const string CHAT = 'slack';

    public function chat(): string
    {
        return self::CHAT;
    }

    protected function destinations(ChatAlert $alert): array
    {
        $out = [];
        $accounts = Integration::query()->where('workspace_id', $alert->workspace->id)->where('provider', 'slack')
            ->where('status', Integration::CONNECTED)->orderBy('id')->get();
        foreach ($accounts as $integration) {
            $deploys = SlackProvider::channel($integration, 'deploys');
            // A deploy channel already says when a deploy failed and when the next one worked.
            if (! $alert->isDeployNotice() && $alert->isDeploy() && $deploys !== null) {
                continue;
            }
            $channel = $alert->isDeployNotice() ? $deploys : SlackProvider::channel($integration, 'alerts');
            if ($channel !== null) {
                $out[] = ['destination' => $channel['id'], 'integration' => $integration->id];
            }
        }

        return $out;
    }

    protected function post(ChatAlert $alert, ChatPost $post): string
    {
        $integration = Integration::query()->find($post->integration_id) ?? throw new ChatFailed('The Slack account is gone.');
        $deploy = $alert->isDeployNotice();
        $message = new SlackMessage([
            'chat_post_id' => $post->id,
            'integration_id' => $integration->id,
            'channel' => $post->destination,
            'ts' => '',
            'kind' => $deploy ? SlackMessage::DEPLOY : SlackMessage::ALERT,
            'payload' => $deploy ? SlackBlocks::deployPayload($alert) : SlackBlocks::alertPayload($alert),
        ]);
        $message->setRelation('post', $post);

        try {
            $sent = self::client($integration)->call('chat.postMessage', [
                'channel' => $post->destination,
                ...SlackBlocks::draw($message),
                'unfurl_links' => false,
                'unfurl_media' => false,
            ]);
        } catch (IntegrationFailed $e) {
            self::failed($integration, $e);

            throw new ChatFailed($e->getMessage());
        }
        $message->ts = (string) ($sent['ts'] ?? '');
        $message->save();

        return $message->ts;
    }

    protected function resolve(ChatAlert $recovery, ChatPost $open): bool
    {
        $message = SlackMessage::query()->where('chat_post_id', $open->id)->with('integration')->first();
        if (! $message instanceof SlackMessage || $message->ts === '') {
            return false;
        }
        $payload = $message->payload;
        $payload['resolved'] = ['subject' => $recovery->subject, 'lines' => $recovery->lines, 'at' => now()->toIso8601String()];
        $message->payload = $payload;

        if (! $this->redraw($message)) {
            return false;
        }
        $message->save();

        return true;
    }

    /** Draws the message again as it is now. False when Slack wouldn't take it. */
    public function redraw(SlackMessage $message): bool
    {
        try {
            self::client($message->integration)->call('chat.update', ['channel' => $message->channel, 'ts' => $message->ts, ...SlackBlocks::draw($message)]);
        } catch (IntegrationFailed $e) {
            self::failed($message->integration, $e);
            Log::warning('Slack message update failed', ['integration' => $message->integration_id, 'error' => $e->getMessage()]);

            return false;
        }

        return true;
    }

    /** A job someone started from a message finished: the message says how it went. */
    public function jobFinished(DaemonJob $job): void
    {
        $messages = SlackMessage::query()->where('job_id', $job->id)->with('integration')->get();
        foreach ($messages as $message) {
            $ok = $job->state === 'succeeded';
            $error = is_array($job->error) ? trim((string) ($job->error['message'] ?? '')) : '';
            $what = match ($job->kind) {
                'rollback' => $ok ? 'Rolled back to '.substr((string) ($job->command['job']['release'] ?? ''), 0, 7).'.' : 'The roll back didn’t work'.($error !== '' ? ": {$error}" : '.'),
                'deploy' => $ok ? 'Deployed it again.' : 'The deploy didn’t work'.($error !== '' ? ": {$error}" : '.'),
                default => $ok ? 'Restarted '.($job->service ?? 'it').'.' : 'The restart didn’t work'.($error !== '' ? ": {$error}" : '.'),
            };
            $message->note($what);
            if ($ok && in_array($job->kind, ['rollback', 'deploy'], true)) {
                $message->payload = [...$message->payload, 'rolledBack' => true];
            }
            $message->job_id = null;
            $message->save();
            $this->redraw($message);
        }
    }

    public static function client(Integration $integration): SlackClient
    {
        return new SlackClient($integration->credential('bot_token'));
    }

    private static function failed(Integration $integration, IntegrationFailed $e): void
    {
        if ($e->refusedCredentials() || $e->reason === IntegrationFailed::PERMISSION) {
            CloudServers::failed($integration, $e);
        }
    }
}
