<?php

namespace App\Support\Integrations\Slack;

use Illuminate\Http\Request;

/**
 * Slack signs every request it sends (docs.slack.dev, "Verifying requests
 * from Slack"): v0=HMAC-SHA256 of "v0:{timestamp}:{raw body}" with the
 * app's signing secret. A request older than five minutes is turned away,
 * so a copied one can't be sent again later.
 */
final class SlackSignature
{
    public const int WINDOW = 300;

    public static function valid(Request $request, string $secret): bool
    {
        $timestamp = (string) $request->header('X-Slack-Request-Timestamp', '');
        $signature = (string) $request->header('X-Slack-Signature', '');
        if ($secret === '' || preg_match('/^\d{1,12}$/', $timestamp) !== 1 || ! str_starts_with($signature, 'v0=')) {
            return false;
        }
        if (abs(now()->getTimestamp() - (int) $timestamp) > self::WINDOW) {
            return false;
        }

        return hash_equals(self::sign($secret, $timestamp, $request->getContent()), $signature);
    }

    public static function sign(string $secret, string $timestamp, string $body): string
    {
        return 'v0='.hash_hmac('sha256', "v0:{$timestamp}:{$body}", $secret);
    }
}
