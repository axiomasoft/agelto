<?php

declare(strict_types=1);

use App\Models\Procurement\Ai\DatasetExample;
use App\Models\Procurement\Ai\EvalRun;
use App\Models\Procurement\Estimate\Estimate;

/**
 * §11 «Обучение локальной LLM», «Смена версии промптов», «Прерванная многошаговая экстракция».
 * Реального вызова LLM здесь нет (вне scope ) — проверяем хранение данных,
 * на которых строится обучение/кэш/резюм, на уровне схемы.
 */
test('обучение локальной LLM опирается на ai_dataset_examples (gold) + est_estimates.raw_result (сырьё) + ai_eval_runs (метрики)', function () {
    $datasetExample = DatasetExample::factory()->create([
        'task' => 'extraction',
        'gold' => ['rows' => [['name' => 'Устройство ввода', 'total_cost' => '1200.00']]],
    ]);

    $estimate = Estimate::factory()->create([
        'raw_result' => ['rows' => [['name' => 'Устройство ввода', 'total_cost' => '1200.00']]],
    ]);

    $evalRun = EvalRun::factory()->create([
        'metrics' => ['precision' => 0.92, 'recall' => 0.88, 'f1' => 0.90, 'row_coverage' => 0.95],
    ]);

    expect($datasetExample->gold['rows'][0]['name'])->toBe('Устройство ввода')
        ->and($estimate->raw_result['rows'][0]['name'])->toBe('Устройство ввода')
        ->and($evalRun->metrics['f1'])->toBe(0.90);
});
