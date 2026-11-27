<?php

namespace App\Http\Controllers;

use App\Models\WorkflowRun;
use App\Support\Integrations\N8n\N8nStep;
use App\Support\Workflows\WorkflowEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Where an n8n workflow POSTs when it's done, for a "Run an n8n workflow"
 * step that waits for a callback. The URL is made for one step of one run
 * (an HMAC of both and when the step started), so nothing about it is
 * stored and it stops working once the step has finished.
 */
class N8nCallbackController extends Controller
{
    public function __invoke(Request $request, int $run, string $node, string $token, WorkflowEngine $engine): JsonResponse
    {
        $found = WorkflowRun::query()->find($run);
        $step = $found?->steps[$node] ?? null;
        if ($found === null || ! is_array($step) || ! hash_equals(N8nStep::callbackToken($found, $node, (int) ($step['startedAt'] ?? 0)), $token)) {
            return response()->json(['error' => 'Unknown callback.'], 404);
        }
        if (strlen($request->getContent()) > N8nStep::MAX_RESPONSE_BYTES * 4) {
            return response()->json(['error' => 'That’s more than ServerOS keeps. Send a smaller JSON object.'], 413);
        }

        $body = $request->isJson() ? $request->json()->all() : ($request->getContent() === '' ? $request->except([]) : $request->getContent());
        if (! $engine->finishStep($found->id, $node, 'callback', N8nStep::callbackResult($body))) {
            return response()->json(['error' => 'This step isn’t waiting any more.'], 409);
        }

        return response()->json(['ok' => true]);
    }
}
