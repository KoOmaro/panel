<?php

namespace App\Support\Integrations\Chat;

use App\Models\Activity;
use App\Models\ChatIdentity;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * One-time codes that tie a chat account (a Telegram or Discord user) to the
 * workspace member who asked for the code in the panel. A code works once,
 * for ten minutes, and only in the workspace it was made for.
 */
final class ChatLinks
{
    public const int MINUTES = 10;

    public const array CHANNELS = ['telegram', 'discord'];

    private const string ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    private const int LENGTH = 8;

    /** @return array{code: string, expiresAt: CarbonImmutable} */
    public static function make(Workspace $workspace, User $user, string $channel): array
    {
        $mine = self::userKey($workspace, $user, $channel);
        $previous = Cache::get($mine);
        if (is_string($previous)) {
            Cache::forget(self::codeKey($channel, $previous));
        }

        do {
            $code = '';
            for ($i = 0; $i < self::LENGTH; $i++) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
        } while (! Cache::add(self::codeKey($channel, $code), ['workspace' => $workspace->id, 'user' => $user->id], now()->addMinutes(self::MINUTES)));
        Cache::put($mine, $code, now()->addMinutes(self::MINUTES));

        return ['code' => $code, 'expiresAt' => CarbonImmutable::now()->addMinutes(self::MINUTES)];
    }

    /**
     * Links the chat account to whoever made the code, when the code is right
     * and for this workspace. Turns away a chat account that keeps guessing.
     */
    public static function redeem(string $channel, Workspace $workspace, string $code, string $externalId, ?string $externalName): ?User
    {
        $limiter = "chat-link:{$channel}:{$externalId}";
        if (RateLimiter::tooManyAttempts($limiter, 10)) {
            return null;
        }
        RateLimiter::hit($limiter, 600);

        $code = strtoupper(trim($code));
        if (preg_match('/^['.self::ALPHABET.']{'.self::LENGTH.'}$/', $code) !== 1) {
            return null;
        }
        $held = Cache::get(self::codeKey($channel, $code));
        if (! is_array($held) || ($held['workspace'] ?? null) !== $workspace->id) {
            return null;
        }
        Cache::forget(self::codeKey($channel, $code));

        $user = User::query()->find($held['user'] ?? null);
        if (! $user instanceof User || ! $workspace->hasMember($user)) {
            return null;
        }
        Cache::forget(self::userKey($workspace, $user, $channel));

        ChatIdentity::query()->where('workspace_id', $workspace->id)->where('chat', $channel)
            ->where(fn ($q) => $q->where('user_id', $user->id)->orWhere('external_id', $externalId))
            ->delete();
        ChatIdentity::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'chat' => $channel,
            'external_id' => $externalId,
            'external_name' => $externalName === null ? null : mb_substr($externalName, 0, 120),
        ]);
        RateLimiter::clear($limiter);
        Activity::record($workspace, 'alerts.chat_link', $externalName, null, $user, ['channel' => $channel]);

        return $user;
    }

    /** The member a chat account is linked to, while they're still in the workspace. */
    public static function member(Workspace $workspace, string $channel, string $externalId): ?User
    {
        $identity = ChatIdentity::query()->where('workspace_id', $workspace->id)->where('chat', $channel)
            ->where('external_id', $externalId)->with('user')->first();
        $user = $identity?->user;

        return $user instanceof User && $workspace->hasMember($user) ? $user : null;
    }

    public static function of(Workspace $workspace, User $user, string $channel): ?ChatIdentity
    {
        return ChatIdentity::query()->where('workspace_id', $workspace->id)->where('chat', $channel)->where('user_id', $user->id)->first();
    }

    private static function codeKey(string $channel, string $code): string
    {
        return 'chat-link-code:'.$channel.':'.hash('sha256', $code);
    }

    private static function userKey(Workspace $workspace, User $user, string $channel): string
    {
        return "chat-link-user:{$workspace->id}:{$user->id}:{$channel}";
    }
}
