<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\HandleSlackRequest;
use App\Support\Integrations\Slack\SlackApp;
use App\Support\Integrations\Slack\SlackRequests;
use App\Support\Integrations\Slack\SlackSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Where the ServerOS Slack app's button presses and /serveros commands
 * arrive. Each is checked against the app's signing secret and its time,
 * answered at once (Slack waits three seconds), and done after the answer.
 */
class SlackController extends Controller
{
    public function interactivity(Request $request): Response
    {
        $this->verify($request);
        $payload = json_decode((string) $request->input('payload'), true);
        if (is_array($payload) && ($payload['type'] ?? null) === 'block_actions') {
            HandleSlackRequest::dispatchAfterResponse(HandleSlackRequest::INTERACTION, $payload);
        }

        return response('', 200);
    }

    public function commands(Request $request): Response|JsonResponse
    {
        $this->verify($request);
        $fields = array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $request->only(['team_id', 'channel_id', 'user_id', 'user_name', 'command', 'text', 'response_url']));
        if (in_array(mb_strtolower(trim($fields['text'] ?? '')), ['', 'help'], true)) {
            return response()->json(SlackRequests::help());
        }
        HandleSlackRequest::dispatchAfterResponse(HandleSlackRequest::COMMAND, $fields);

        return response('', 200);
    }

    private function verify(Request $request): void
    {
        abort_unless(SlackApp::configured(), 404);
        abort_unless(SlackSignature::valid($request, SlackApp::signingSecret()), 401, 'The request isn’t signed by Slack, or it’s too old.');
    }
}
