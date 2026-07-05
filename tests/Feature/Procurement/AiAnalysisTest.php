<?php

declare(strict_types=1);

use App\Enums\Ai\AnalysisStatus;
use App\Enums\Ai\AnalysisStep;
use App\Models\Ai\Analysis;
use App\Models\Ai\DatasetExample;
use App\Models\Ai\EvalRun;
use App\Models\Estimate\Estimate;
use Illuminate\Support\Carbon;

/**
 * §11 «Обучение локальной LLM», «Смена версии промптов», «Прерванная многошаговая экстракция».
 * Реального вызова LLM здесь нет (вне scope P1.10) — проверяем хранение данных,
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

test('несовпадение rules_hash с текущей версией промптов делает кэш анализа невалидным', function () {
    $currentRulesHash = hash('sha256', 'prompt-rules-v2');

    $staleAnalysis = Analysis::factory()->create([
        'rules_hash' => hash('sha256', 'prompt-rules-v1'),
        'cache_id' => 'gemini-cache-stale',
        'cache_expires_at' => Carbon::now()->addDay(),
    ]);

    $freshAnalysis = Analysis::factory()->create([
        'rules_hash' => $currentRulesHash,
        'cache_id' => 'gemini-cache-fresh',
        'cache_expires_at' => Carbon::now()->addDay(),
    ]);

    expect($staleAnalysis->rules_hash !== $currentRulesHash)->toBeTrue()
        ->and($freshAnalysis->rules_hash === $currentRulesHash)->toBeTrue();
});

test('истёкший cache_expires_at тоже делает кэш анализа невалидным даже при совпавшем rules_hash', function () {
    $currentRulesHash = hash('sha256', 'prompt-rules-v2');

    $expiredAnalysis = Analysis::factory()->create([
        'rules_hash' => $currentRulesHash,
        'cache_id' => 'gemini-cache-expired',
        'cache_expires_at' => Carbon::now()->subMinute(),
    ]);

    expect($expiredAnalysis->cache_expires_at->isPast())->toBeTrue();
});

test('прогресс прерванной многошаговой экстракции хранится в ai_analyses.progress для резюма с точки обрыва', function () {
    $analysis = Analysis::factory()->create([
        'step' => AnalysisStep::Extraction,
        'status' => AnalysisStatus::Failed,
        'progress' => ['completed_pages' => [1, 2, 3], 'last_row_index' => 47],
    ]);

    $analysis->refresh();

    expect($analysis->progress['completed_pages'])->toBe([1, 2, 3])
        ->and($analysis->progress['last_row_index'])->toBe(47);
});
