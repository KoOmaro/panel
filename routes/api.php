<?php

use App\Api\ApiRoutes;
use App\Http\Controllers\Api\DaemonEnrolController;
use App\Http\Controllers\Api\DaemonGatewayController;
use App\Http\Controllers\Api\DaemonReleaseController;
use App\Http\Controllers\Api\DeployHookController;
use App\Http\Controllers\Api\GitSourceController;
use App\Http\Controllers\Api\SlackController;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\DiscordInteractionController;
use App\Http\Controllers\DockerHubWebhookController;
use App\Http\Controllers\GitHubWebhookController;
use App\Http\Controllers\GitLabWebhookController;
use App\Http\Controllers\MetricsController;
use App\Http\Controllers\N8nCallbackController;
use App\Http\Controllers\TelegramWebhookController;
use App\Http\Controllers\WorkflowController;
use App\Http\Controllers\WorkspaceDomainController;
use App\Http\Middleware\AuthenticateApiKey;
use App\Models\DockerHubApp;
use App\Support\Deploys\DeployHooks;
use App\Support\Extensions\Extensions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('daemon/enrol', DaemonEnrolController::class)->middleware('throttle:20,1');
Route::get('daemon/releases/latest', [DaemonReleaseController::class, 'latest'])->middleware('throttle:60,1');
Route::post('daemon/releases', [DaemonReleaseController::class, 'store'])->middleware('throttle:20,1');
Route::put('daemon/releases/{version}/{file}', [DaemonReleaseController::class, 'upload'])->middleware('throttle:20,1');
Route::post('gateway/hello', [DaemonGatewayController::class, 'hello'])->middleware('throttle:gateway-hello');
Route::post('gateway/machines/{uid}/ingest', [DaemonGatewayController::class, 'ingest'])->middleware('throttle:gateway-ingest');
Route::post('gateway/machines/{uid}/closed', [DaemonGatewayController::class, 'closed'])->middleware('throttle:gateway-closed');
Route::get('internal/tls-check', [WorkspaceDomainController::class, 'tlsCheck'])->name('domains.tls-check');
Route::post('github/webhook', GitHubWebhookController::class)->middleware('throttle:300,1')->name('github.webhook');
Route::post('gitlab/webhook/{hook}', GitLabWebhookController::class)->whereUuid('hook')->middleware('throttle:300,1')->name('gitlab.webhook');
Route::post('telegram/{hook}', TelegramWebhookController::class)->whereUuid('hook')->middleware('throttle:300,1')->name('telegram.webhook');
Route::post('discord/interactions', DiscordInteractionController::class)->middleware('throttle:600,1')->name('discord.interactions');
Route::post('slack/interactivity', [SlackController::class, 'interactivity'])->middleware('throttle:600,1')->name('slack.interactivity');
Route::post('slack/commands', [SlackController::class, 'commands'])->middleware('throttle:300,1')->name('slack.commands');
Route::post('docker-hub/webhook/{token}', DockerHubWebhookController::class)->where('token', DockerHubApp::TOKEN_PATTERN)->middleware('throttle:120,1')->name('docker-hub.webhook');
Route::post('hooks/workflows/{token}', [WorkflowController::class, 'hook'])->middleware('throttle:60,1')->where('token', 'wh_[A-Za-z0-9]{40}')->name('workflows.hook');
Route::post('hooks/deploy/{token}', DeployHookController::class)->middleware('throttle:60,1')->where('token', DeployHooks::TOKEN_PATTERN)->name('deploy-hooks.receive');
Route::post('n8n/callback/{run}/{node}/{token}', N8nCallbackController::class)->whereNumber('run')->where(['node' => '[A-Za-z0-9_-]{1,64}', 'token' => '[a-f0-9]{64}'])->middleware('throttle:60,1')->name('n8n.callback');
Route::get('metrics', MetricsController::class)->middleware('throttle:120,1')->name('metrics');

Route::match(['get', 'post'], 'deploy-sources/{token}.git/{path}', GitSourceController::class)
    ->where(['token' => '[A-Za-z0-9]{48}', 'path' => 'info/refs|git-upload-pack'])
    ->middleware('throttle:120,1')
    ->name('deploy-sources.git');

Route::prefix('v1')->group(function () {
    Route::get('openapi.json', [ApiController::class, 'openapi'])->name('api.v1.openapi');

    Route::middleware(AuthenticateApiKey::class)->group(function () {
        Route::get('/', [ApiController::class, 'index'])->name('api.v1.index');
        Route::get('tools', [ApiController::class, 'tools'])->name('api.v1.tools');
        Route::post('tools/{name}', [ApiController::class, 'tool'])->where('name', '[a-z_]{1,64}')->name('api.v1.tool');

        foreach (ApiRoutes::all() as $route) {
            Route::match([$route['method']], $route['path'], fn (Request $request) => app(ApiController::class)->route($request))
                ->defaults('tool', $route['tool']);
        }
    });
});

app(Extensions::class)->loadRoutes(Extensions::API);
