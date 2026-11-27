<?php

namespace App\Http\Controllers;

use App\Models\DockerHubApp;
use App\Support\Integrations\DockerHub\DockerHubApps;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pushes from the webhooks people add to their Docker Hub repositories.
 * Docker Hub signs nothing, so each app's address carries its own secret.
 */
class DockerHubWebhookController extends Controller
{
    public function __invoke(Request $request, string $token, DockerHubApps $apps): JsonResponse
    {
        $row = DockerHubApp::forToken($token);
        if ($row === null) {
            return response()->json(['error' => 'Unknown webhook address.'], 404);
        }

        [$status, $body] = $apps->receive($row, (array) $request->json()->all());

        return response()->json($body, $status);
    }
}
