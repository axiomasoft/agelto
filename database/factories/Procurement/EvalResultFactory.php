<?php

declare(strict_types=1);

namespace Database\Factories\Procurement;

use App\Models\Procurement\DatasetExample;
use App\Models\Procurement\EvalResult;
use App\Models\Procurement\EvalRun;
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
