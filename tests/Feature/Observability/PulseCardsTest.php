<?php

declare(strict_types=1);

use App\Enums\Llm\CoreLlmPurpose;
use App\Enums\Procurement\AnalysisStatus;
use App\Enums\Procurement\AnalysisStep;
use App\Enums\Procurement\DocumentStatus;
use App\Models\Llm\LlmCall;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Document\Document;
use App\Pulse\Cards\LlmSpendCard;
use App\Pulse\Cards\PipelineStagesCard;

test('PipelineStagesCard считает succeeded/failed по стадиям prepare/screening/extraction за 24ч', function () {
    // prepare: 1 успех (покинул downloaded) + 1 провал (failed_permanently)
    Document::factory()->create(['status' => DocumentStatus::Prepared, 'failed_permanently' => false]);
    Document::factory()->create(['status' => DocumentStatus::Downloaded, 'failed_permanently' => true]);
    // документ вне окна (обновлён «вчера») — не считается
    Document::factory()->create(['status' => DocumentStatus::Prepared, 'updated_at' => now()->subDays(2)]);

    Analysis::factory()->count(2)->create(['step' => AnalysisStep::Screening, 'status' => AnalysisStatus::Succeeded]);
    Analysis::factory()->create(['step' => AnalysisStep::Screening, 'status' => AnalysisStatus::Failed]);
    Analysis::factory()->create(['step' => AnalysisStep::Extraction, 'status' => AnalysisStatus::Succeeded]);

    $card = new PipelineStagesCard;
    $view = $card->render();
    $stages = $view->getData()['stages']->keyBy('stage');

    expect($stages['prepare']['succeeded'])->toBe(1)
        ->and($stages['prepare']['failed'])->toBe(1)
        ->and($stages['screening']['succeeded'])->toBe(2)
        ->and($stages['screening']['failed'])->toBe(1)
        ->and($stages['screening']['failure_rate'])->toBe(33.3)
        ->and($stages['extraction']['succeeded'])->toBe(1)
        ->and($stages['extraction']['failed'])->toBe(0);
});

test('LlmSpendCard группирует токены/стоимость llm_calls по purpose за 24ч', function () {
    LlmCall::factory()->create([
        'purpose' => CoreLlmPurpose::Screening->value,
        'prompt_tokens' => 1000,
        'cached_tokens' => 0,
        'completion_tokens' => 100,
        'cost_micros' => 500,
        'called_at' => now(),
    ]);
    LlmCall::factory()->create([
        'purpose' => CoreLlmPurpose::Screening->value,
        'prompt_tokens' => 500,
        'cached_tokens' => 0,
        'completion_tokens' => 50,
        'cost_micros' => 250,
        'called_at' => now(),
    ]);
    // вне окна — не учитывается
    LlmCall::factory()->create([
        'purpose' => CoreLlmPurpose::Screening->value,
        'cost_micros' => 999_999,
        'called_at' => now()->subDays(3),
    ]);

    $card = new LlmSpendCard;
    $view = $card->render();
    $data = $view->getData();
    $rows = $data['rows']->keyBy('purpose');

    expect($rows[CoreLlmPurpose::Screening->value]->calls)->toBe(2)
        ->and($rows[CoreLlmPurpose::Screening->value]->promptTokens)->toBe(1500)
        ->and($rows[CoreLlmPurpose::Screening->value]->costMicros)->toBe(750)
        ->and($data['totalCostMicros'])->toBe(750);
});

test('LlmSpendCard подписывает цифру источником — «наш трекинг» с лагом и горизонтом', function () {
    $card = new LlmSpendCard;
    $signature = $card->render()->getData()['signature'];

    expect($signature)->toContain('наш трекинг (llm_calls)')
        ->and($signature)->toContain('лаг 0')
        ->and($signature)->toContain('горизонт 12 мес.');
});
