<?php

namespace App\Http\Controllers;

use App\Models\TelegramBot;
use App\Support\Integrations\Telegram\TelegramUpdates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Updates from a workspace's Telegram bot. Each bot has its own address and
 * a secret that Telegram sends back as X-Telegram-Bot-Api-Secret-Token on
 * every update; anything without it is turned away before it's read.
 */
class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request, string $hook, TelegramUpdates $updates): JsonResponse
    {
        $bot = TelegramBot::query()->where('hook', $hook)->with('workspace')->first();
        $secret = $request->header('X-Telegram-Bot-Api-Secret-Token');
        if ($bot === null || ! is_string($secret) || $secret === '' || ! hash_equals($bot->secret, $secret)) {
            return response()->json(['error' => 'Bad secret.'], 401);
        }

        $update = $request->json()->all();
        $id = $update['update_id'] ?? null;
        if (! is_int($id)) {
            return response()->json(['ignored' => true]);
        }
        $fresh = TelegramBot::query()->whereKey($bot->id)
            ->where(fn ($q) => $q->whereNull('last_update_id')->orWhere('last_update_id', '<', $id))
            ->update(['last_update_id' => $id]);
        if ($fresh === 0) {
            return response()->json(['duplicate' => true]);
        }

        $updates->handle($bot, $update);

        return response()->json(['ok' => true]);
    }
}
