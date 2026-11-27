<?php

namespace App\Http\Controllers;

use App\Support\Integrations\Discord\DiscordApp;
use App\Support\Integrations\Discord\DiscordInteractions;
use App\Support\Integrations\Discord\DiscordSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The panel's Discord app's Interactions Endpoint URL. Every request is
 * checked against the app's Ed25519 public key before it's read; Discord
 * sends some badly signed ones on purpose and expects a 401.
 */
class DiscordInteractionController extends Controller
{
    public function __invoke(Request $request, DiscordInteractions $interactions): JsonResponse
    {
        if (! DiscordApp::configured()) {
            return response()->json(['error' => 'Discord isn’t set up on this panel.'], 404);
        }
        $body = $request->getContent();
        if (! DiscordSignature::valid(DiscordApp::publicKey(), $request->header('X-Signature-Ed25519'), $request->header('X-Signature-Timestamp'), $body)) {
            return response()->json(['error' => 'Invalid request signature.'], 401);
        }

        $interaction = json_decode($body, true);
        if (! is_array($interaction)) {
            return response()->json(['error' => 'Not JSON.'], 400);
        }

        $answer = $interactions->handle($interaction);

        return $answer === null ? response()->json(['error' => 'Seen already.'], 409) : response()->json($answer);
    }
}
