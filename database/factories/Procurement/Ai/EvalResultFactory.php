<?php

declare(strict_types=1);

namespace Database\Factories\Procurement\Ai;

use App\Models\Procurement\Ai\DatasetExample;
use App\Models\Procurement\Ai\EvalResult;
use App\Models\Procurement\Ai\EvalRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EvalResult>
 */
final class EvalResultFactory extends Factory
{
    protected $model = EvalResult::class;

    public function definition(): array
    {
        return [
            'eval_run_id' => EvalRun::factory(),
            'dataset_example_id' => DatasetExample::factory(),
            'metric' => 'f1',
            'value' => fake()->randomFloat(nbMaxDecimals: 4, min: 0, max: 1),
            'details' => null,
        ];
    }
}
