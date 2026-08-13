<?php

declare(strict_types=1);

namespace App\Models\Procurement\Ai;

use Database\Factories\Procurement\Ai\EvalResultFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * Результат по одной метрике на одном эталонном примере в рамках прогона оценки.
 *
 * @property string $value
 * @property Collection<string, mixed>|null $details
 * @property-read DatasetExample|null $datasetExample
 */
#[UseFactory(EvalResultFactory::class)]
final class EvalResult extends Model
{
    /** @use HasFactory<EvalResultFactory> */
    use HasFactory;

    protected $table = 'prc_ai_eval_results';

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

    /** @return BelongsTo<EvalRun, $this> */
    public function evalRun(): BelongsTo
    {
        return $this->belongsTo(related: EvalRun::class);
    }

    /** @return BelongsTo<DatasetExample, $this> */
    public function datasetExample(): BelongsTo
    {
        return $this->belongsTo(related: DatasetExample::class);
    }
}
