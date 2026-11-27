<?php

namespace App\Support\Metrics;

use App\Models\McpToken;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Tokens that can read a workspace's metrics and nothing else: their own
 * audience, so the API and MCP never take them, and the metrics endpoint
 * takes only them.
 */
final class MetricsTokens
{
    public const string AUDIENCE = 'metrics';

    public const string SCOPE = 'metrics:read';

    public const string PREFIX = 'som_';

    public const int MAX = 10;

    /** @return array{0: McpToken, 1: string} */
    public static function issue(Workspace $workspace, User $user, string $name): array
    {
        $secret = self::PREFIX.Str::random(40);
        $token = McpToken::query()->create([
            'audience' => self::AUDIENCE,
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'kind' => 'key',
            'name' => $name,
            'scopes' => [self::SCOPE],
            'token_hash' => McpToken::hash($secret),
            'token_prefix' => substr($secret, 0, 12),
        ]);

        return [$token, $secret];
    }

    public static function find(?string $secret): ?McpToken
    {
        if ($secret === null || ! str_starts_with($secret, self::PREFIX)) {
            return null;
        }
        $token = McpToken::query()->where('token_hash', McpToken::hash($secret))->where('audience', self::AUDIENCE)->active()->with('workspace')->first();

        return $token !== null && $token->allows(self::SCOPE) ? $token : null;
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, McpToken>
     */
    public static function active(Workspace $workspace, array $ids): Collection
    {
        return McpToken::query()->where('workspace_id', $workspace->id)->where('audience', self::AUDIENCE)->whereIn('id', $ids)->active()->latest('id')->get();
    }
}
