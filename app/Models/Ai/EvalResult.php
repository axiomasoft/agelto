<?php

declare(strict_types=1);

namespace App\Models\Ai;

use Database\Factories\Ai\EvalResultFactory;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Результат по одной метрике на одном эталонном примере в рамках прогона оценки.
 *
 * @use HasFactory<EvalResultFactory>
 */
final class EvalResult extends Model
{
    /** @use HasFactory<EvalResultFactory> */
    use HasFactory;

    protected $table = 'ai_eval_results';

    protected $fillable = [
        'eval_run_id',
        'dataset_example_id',
        'metric',
        'value',
        'details',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:6',
            'details' => AsCollection::class,
        ];
    }

    public function evalRun(): BelongsTo
    {
        return $this->belongsTo(related: EvalRun::class);
    }

    public function datasetExample(): BelongsTo
    {
        return $this->belongsTo(related: DatasetExample::class);
    }
}
