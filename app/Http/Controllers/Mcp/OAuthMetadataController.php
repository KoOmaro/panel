<?php

namespace App\Http\Controllers\Mcp;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\Support\Mcp\OAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OAuthMetadataController extends Controller
{
    public function resource(Request $request, Workspace $workspace): JsonResponse
    {
        return response()->json(OAuth::resourceMetadata($workspace, $request->routeIs('mcp.legacy.*')))->header('Cache-Control', 'public, max-age=300');
    }

    public function server(): JsonResponse
    {
        return response()->json(OAuth::serverMetadata())->header('Cache-Control', 'public, max-age=300');
    }
}
