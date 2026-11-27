<?php

namespace App\Support\Integrations\Slack;

use App\Models\Activity;
use App\Models\ChatIdentity;
use App\Models\Integration;
use App\Models\User;
use App\Support\Audit\AuditContext;
use App\Support\Integrations\Chat\ChatLinks;
use App\Support\Integrations\IntegrationFailed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Who a Slack user is in the workspace. Once linked, a Slack user acts as
 * that member. A Slack account whose email is a member's verified email is
 * linked the first time it presses or types something (when the app can
 * read emails); anyone else gets a one-time link to sign in and link it.
 */
final class SlackPeople
{
    public const int LINK_MINUTES = 30;

    public static function member(Integration $integration, string $slackUser, ?string $name = null): ?User
    {
        $workspace = $integration->workspace;
        $linked = ChatLinks::member($workspace, SlackChat::CHAT, $slackUser);
        if ($linked !== null || ! SlackProvider::emailLinking($integration)) {
            return $linked;
        }

        try {
            $user = (array) (SlackChat::client($integration)->read('users.info', ['user' => $slackUser])['user'] ?? []);
        } catch (IntegrationFailed) {
            return null;
        }
        $email = mb_strtolower(trim((string) ($user['profile']['email'] ?? '')));
        if ($email === '' || ($user['deleted'] ?? false) === true || ($user['is_bot'] ?? false) === true || ($user['team_id'] ?? null) !== ($integration->meta['team_id'] ?? null)) {
            return null;
        }
        $member = $workspace->members()->whereRaw('lower(users.email) = ?', [$email])->whereNotNull('users.email_verified_at')->first();
        if (! $member instanceof User) {
            return null;
        }
        self::link($integration, $member, $slackUser, $name ?? (is_string($user['name'] ?? null) ? $user['name'] : null), 'email');

        return $member;
    }

    public static function link(Integration $integration, User $user, string $slackUser, ?string $name, string $how): void
    {
        $workspace = $integration->workspace;
        ChatIdentity::query()->where('workspace_id', $workspace->id)->where('chat', SlackChat::CHAT)
            ->where(fn ($q) => $q->where('user_id', $user->id)->orWhere('external_id', $slackUser))
            ->delete();
        ChatIdentity::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'chat' => SlackChat::CHAT,
            'external_id' => $slackUser,
            'external_name' => $name === null ? null : mb_substr($name, 0, 120),
        ]);
        AuditContext::within(['via' => SlackChat::CHAT], fn () => Activity::record($workspace, 'alerts.chat_link', $name ?? $slackUser, null, $user, ['channel' => SlackChat::CHAT, 'by' => $how, 'account' => $integration->label]));
    }

    /** A page that links the Slack user to whoever opens it while signed in. Works once, for half an hour. */
    public static function linkUrl(Integration $integration, string $slackUser, ?string $name): string
    {
        $token = Str::random(40);
        Cache::put(self::key($token), ['integration' => $integration->id, 'user' => $slackUser, 'name' => $name], now()->addMinutes(self::LINK_MINUTES));

        return $integration->workspace->url('/integrations/'.$integration->id.'/slack/link/'.$token);
    }

    /** @return array{integration: int, user: string, name: string|null}|null */
    public static function pending(string $token): ?array
    {
        $held = preg_match('/^[A-Za-z0-9]{40}$/', $token) === 1 ? Cache::get(self::key($token)) : null;

        return is_array($held) && is_int($held['integration'] ?? null) && is_string($held['user'] ?? null)
            ? ['integration' => $held['integration'], 'user' => $held['user'], 'name' => is_string($held['name'] ?? null) ? $held['name'] : null]
            : null;
    }

    /** @return array{integration: int, user: string, name: string|null}|null the link, used up */
    public static function redeem(string $token): ?array
    {
        if (self::pending($token) === null) {
            return null;
        }
        $held = Cache::pull(self::key($token));

        return is_array($held) && is_int($held['integration'] ?? null) && is_string($held['user'] ?? null)
            ? ['integration' => $held['integration'], 'user' => $held['user'], 'name' => is_string($held['name'] ?? null) ? $held['name'] : null]
            : null;
    }

    private static function key(string $token): string
    {
        return 'slack-link:'.hash('sha256', $token);
    }
}
