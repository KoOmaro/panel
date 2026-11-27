<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Tokens a workspace's own AI keys used in a day, per provider, model and feature.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $day a Y-m-d date
 * @property string $provider
 * @property string $model
 * @property string $feature
 * @property int $requests
 * @property int $input_tokens
 * @property int $output_tokens
 */
class WorkspaceAiUsage extends Model
{
    protected $table = 'workspace_ai_usage';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['requests' => 'integer', 'input_tokens' => 'integer', 'output_tokens' => 'integer'];
    }
}
