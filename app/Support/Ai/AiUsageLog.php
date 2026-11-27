<?php

namespace App\Support\Ai;

use App\Models\Workspace;
use App\Models\WorkspaceAiUsage;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * Counts the tokens each of a workspace's own keys used, from the usage
 * the provider sends back with every answer, per day, model and feature.
 *
 * @phpstan-import-type Ai from AiProviders
 */
final class AiUsageLog
{
    public const int DAYS = 30;

    /** @return array{0: int, 1: int} the input and output tokens of one answer */
    public static function tokens(string $provider, Response $response): array
    {
        $usage = $response->json('usage');
        $usage = is_array($usage) ? $usage : [];
        $n = fn (string $key): int => is_numeric($usage[$key] ?? null) ? max(0, (int) $usage[$key]) : 0;

        return match ($provider) {
            'anthropic' => [$n('input_tokens') + $n('cache_creation_input_tokens') + $n('cache_read_input_tokens'), $n('output_tokens')],
            'openai' => [$n('input_tokens'), $n('output_tokens')],
            default => [$n('prompt_tokens'), $n('completion_tokens')],
        };
    }

    /** @param  Ai  $ai */
    public static function record(array $ai, Response $response): void
    {
        if (! $ai['own'] || ! isset($ai['workspace'])) {
            return;
        }
        [$input, $output] = self::tokens($ai['provider'], $response);
        $model = mb_substr((string) ($response->json('model') ?: $ai['model']), 0, 120);
        self::add($ai['workspace'], $ai['provider'], $model !== '' ? $model : $ai['model'], $ai['feature'] ?? AiFeatures::OTHER, $input, $output);
    }

    public static function add(int $workspace, string $provider, string $model, string $feature, int $input, int $output, int $requests = 1): void
    {
        try {
            $row = WorkspaceAiUsage::query()->createOrFirst([
                'workspace_id' => $workspace,
                'day' => now()->toDateString(),
                'provider' => mb_substr($provider, 0, 20),
                'model' => mb_substr($model, 0, 120),
                'feature' => mb_substr($feature, 0, 20),
            ], ['requests' => 0, 'input_tokens' => 0, 'output_tokens' => 0]);
            $row->incrementEach(['requests' => max(0, $requests), 'input_tokens' => max(0, $input), 'output_tokens' => max(0, $output)]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * The last 30 days of one provider's usage in a workspace.
     *
     * @return array{days: list<array{day: string, input: int, output: int, requests: int}>, models: list<array{model: string, requests: int, input: int, output: int, features: list<string>}>, total: array{requests: int, input: int, output: int}}
     */
    public static function summary(Workspace $workspace, string $provider): array
    {
        $from = now()->subDays(self::DAYS - 1)->toDateString();
        $rows = WorkspaceAiUsage::query()
            ->where('workspace_id', $workspace->id)
            ->where('provider', $provider)
            ->where('day', '>=', $from)
            ->get(['day', 'model', 'feature', 'requests', 'input_tokens', 'output_tokens']);

        $byDay = [];
        $byModel = [];
        foreach ($rows as $row) {
            $day = substr((string) $row->day, 0, 10);
            $byDay[$day]['input'] = ($byDay[$day]['input'] ?? 0) + $row->input_tokens;
            $byDay[$day]['output'] = ($byDay[$day]['output'] ?? 0) + $row->output_tokens;
            $byDay[$day]['requests'] = ($byDay[$day]['requests'] ?? 0) + $row->requests;
            $model = $byModel[$row->model] ?? ['model' => $row->model, 'requests' => 0, 'input' => 0, 'output' => 0, 'features' => []];
            $model['requests'] += $row->requests;
            $model['input'] += $row->input_tokens;
            $model['output'] += $row->output_tokens;
            $model['features'][$row->feature] = AiFeatures::label($row->feature);
            $byModel[$row->model] = $model;
        }

        $days = [];
        for ($i = self::DAYS - 1; $i >= 0; $i--) {
            $day = now()->subDays($i)->toDateString();
            $days[] = ['day' => $day, 'input' => $byDay[$day]['input'] ?? 0, 'output' => $byDay[$day]['output'] ?? 0, 'requests' => $byDay[$day]['requests'] ?? 0];
        }
        $order = array_flip([...array_keys(AiFeatures::ALL), AiFeatures::TEST, AiFeatures::OTHER]);
        $models = array_values(array_map(function (array $m) use ($order): array {
            uksort($m['features'], fn (string $a, string $b) => ($order[$a] ?? 99) <=> ($order[$b] ?? 99));

            return [...$m, 'features' => array_values($m['features'])];
        }, $byModel));
        usort($models, fn (array $a, array $b) => ($b['input'] + $b['output']) <=> ($a['input'] + $a['output']));

        return [
            'days' => $days,
            'models' => $models,
            'total' => [
                'requests' => array_sum(array_column($days, 'requests')),
                'input' => array_sum(array_column($days, 'input')),
                'output' => array_sum(array_column($days, 'output')),
            ],
        ];
    }
}
