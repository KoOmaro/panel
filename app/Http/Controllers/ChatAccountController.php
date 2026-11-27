<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Audit\AuditContext;
use App\Support\Audit\Audited;
use App\Support\Audit\NotAudited;
use App\Support\Integrations\Chat\ChatLinks;
use App\Support\Integrations\Discord\DiscordChat;
use App\Support\Integrations\Telegram\TelegramWebhooks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * A member links their own Telegram or Discord account, so the buttons on
 * alerts and the commands that change something run as them.
 */
class ChatAccountController extends Controller
{
    /** Chats whose accounts are listed and can be unlinked here. Slack people link by email or Slack's own link page, so there's no code for it. */
    public const array CHATS = ['telegram' => 'Telegram', 'discord' => 'Discord', 'slack' => 'Slack'];

    #[NotAudited('Makes a one-time code; linking is recorded when the code is used')]
    public function code(Request $request, Workspace $workspace, string $chat): JsonResponse
    {
        Gate::authorize('view', $workspace);
        $where = self::where($workspace, $chat);
        abort_if($where === null, 404);

        $made = ChatLinks::make($workspace, $request->user(), $chat);

        return response()->json([
            'code' => $made['code'],
            'expiresAt' => $made['expiresAt']->toIso8601String(),
            'command' => '/link '.$made['code'],
            'link' => $chat === 'telegram' && $where !== '' ? 'https://t.me/'.$where.'?start='.$made['code'] : null,
        ])->header('Cache-Control', 'no-store');
    }

    #[Audited]
    public function destroy(Request $request, Workspace $workspace, string $chat): RedirectResponse
    {
        Gate::authorize('view', $workspace);
        abort_unless(isset(self::CHATS[$chat]), 404);

        $identity = ChatLinks::of($workspace, $request->user(), $chat);
        if ($identity === null) {
            AuditContext::nothingChanged();

            return back();
        }
        $identity->delete();
        Activity::record($workspace, 'alerts.chat_unlink', $identity->external_name, null, $request->user(), ['channel' => $chat]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Unlinked your :chat account.', ['chat' => self::CHATS[$chat]])]);

        return back();
    }

    /**
     * The member's chat accounts for their profile: the chats this workspace
     * answers in, and the ones they linked.
     *
     * @return list<array{chat: string, name: string, ready: bool, where: string|null, linked: array{name: string|null, at: string|null}|null}>
     */
    public static function props(Workspace $workspace, User $user): array
    {
        $out = [];
        foreach (self::CHATS as $chat => $name) {
            $where = self::where($workspace, $chat);
            $identity = ChatLinks::of($workspace, $user, $chat);
            if ($where === null && $identity === null) {
                continue;
            }
            $out[] = [
                'chat' => $chat,
                'name' => $name,
                'ready' => $where !== null,
                'where' => $where === null || $where === '' ? null : ($chat === 'telegram' ? '@'.$where : $where),
                'linked' => $identity === null ? null : ['name' => $identity->external_name, 'at' => $identity->created_at?->toIso8601String()],
            ];
        }

        return $out;
    }

    /** Where the chat answers for this workspace (the bot's @name, the Discord server), or null when it doesn't. */
    private static function where(Workspace $workspace, string $chat): ?string
    {
        return match ($chat) {
            'telegram' => ($bot = TelegramWebhooks::answering($workspace)) === null ? null : (string) $bot->username,
            'discord' => ($account = DiscordChat::account($workspace->id)) === null ? null : $account->label,
            default => null,
        };
    }
}
