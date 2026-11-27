<?php

namespace App\Support\Integrations\Chat;

use App\Models\ChatPost;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * A chat target that keeps each post as a ChatPost: the buttons carry the
 * post's id, and the alert that says a problem is fixed edits the post that
 * opened it (falling back to a new message when the edit doesn't work).
 */
abstract class ThreadedChat implements ChatTarget
{
    /** The name kept on each ChatPost, like "telegram". */
    abstract public function chat(): string;

    /**
     * Where this alert goes for its workspace, and the integration it goes
     * through (null for one that isn't an integration). Empty when the
     * workspace hasn't set this chat up or doesn't want this alert here.
     *
     * @return list<array{destination: string, integration: int|null}>
     */
    abstract protected function destinations(ChatAlert $alert): array;

    /**
     * Posts the alert as $post (saved already, so the buttons can carry its
     * id) and returns the chat's id for the message.
     *
     * @throws ChatFailed
     */
    abstract protected function post(ChatAlert $alert, ChatPost $post): string;

    /** Edits the open post to say it's fixed. False when it couldn't. */
    abstract protected function resolve(ChatAlert $recovery, ChatPost $open): bool;

    public function deliver(ChatAlert $alert): void
    {
        foreach ($this->destinations($alert) as $to) {
            $this->deliverTo($alert, $to['destination'], $to['integration']);
        }
    }

    private function deliverTo(ChatAlert $alert, string $destination, ?int $integration): void
    {
        $thread = $alert->thread();
        $open = fn (): Builder => ChatPost::query()
            ->where('workspace_id', $alert->workspace->id)
            ->where('chat', $this->chat())
            ->where('destination', $destination)
            ->where('thread', $thread)
            ->where('state', ChatPost::OPEN);

        if ($alert->resolves()) {
            $edited = false;
            foreach ($open()->whereNotNull('message_id')->latest('id')->limit(5)->get() as $post) {
                $done = $this->resolve($alert, $post);
                $post->forceFill(['state' => $done ? ChatPost::RESOLVED : ChatPost::SENT, 'resolved_at' => $done ? now() : null])->save();
                $edited = $edited || $done;
            }
            if ($edited) {
                return;
            }
        }
        if ($alert->editOnly) {
            return;
        }
        if ($alert->opens()) {
            $open()->update(['state' => ChatPost::SENT]);
        }

        $post = ChatPost::query()->create([
            'workspace_id' => $alert->workspace->id,
            'integration_id' => $integration,
            'chat' => $this->chat(),
            'destination' => $destination,
            'thread' => $thread,
            'event' => mb_substr($alert->event, 0, 60),
            'subject' => mb_substr($alert->subject, 0, 200),
            'state' => $alert->opens() ? ChatPost::OPEN : ChatPost::SENT,
            'context' => $alert->context() === [] ? null : $alert->context(),
        ]);

        try {
            $post->forceFill(['message_id' => mb_substr($this->post($alert, $post), 0, 100)])->save();
        } catch (ChatFailed $e) {
            $post->forceFill(['state' => ChatPost::FAILED, 'error' => mb_substr($e->getMessage(), 0, 300)])->save();
            Log::warning(ucfirst($this->chat()).' alert failed', ['workspace' => $alert->workspace->id, 'event' => $alert->event, 'error' => $e->getMessage()]);
        }
    }
}
