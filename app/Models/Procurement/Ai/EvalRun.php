<?php

declare(strict_types=1);

namespace App\Models\Procurement\Ai;

use Database\Factories\Procurement\Ai\EvalRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Прогон офлайн-оценки качества (precision/recall/F1, row_coverage) по датасету.
 *
 * @property string $document_type
 * @property string $task
 * @property string $dataset_version
 * @property string $provider
 * @property string $model
 * @property Collection<string, mixed>|null $prompt_version_set
 * @property Collection<string, mixed>|null $metrics
 * @property string $ci_status
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 */
#[UseFactory(EvalRunFactory::class)]
#[Fillable([
    'document_type',
    'task',
    'dataset_version',
    'provider',
    'model',
    'prompt_version_set',
    'metrics',
    'ci_status',
    'started_at',
    'finished_at',
])]
#[Table(name: 'prc_ai_eval_runs')]
final class EvalRun extends Model
{
    /** @use HasFactory<EvalRunFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'prompt_version_set' => AsCollection::class,
            'metrics' => AsCollection::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
