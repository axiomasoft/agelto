<?php

declare(strict_types=1);

namespace App\Models\Procurement\Ai;

use Database\Factories\Procurement\Ai\EvalRunFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Прогон офлайн-оценки качества (precision/recall/F1, row_coverage) по датасету.
 */
#[UseFactory(EvalRunFactory::class)]
final class EvalRun extends Model
{
    use HasFactory;

    protected $table = 'prc_ai_eval_runs';

    protected $fillable = [
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
    ];

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
