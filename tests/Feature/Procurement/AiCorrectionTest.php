<?php

declare(strict_types=1);

use App\Enums\Procurement\AnalysisStep;
use App\Enums\Procurement\CorrectionReason;
use App\Enums\Procurement\ReviewStatus;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Ai\Correction;
use App\Models\Procurement\Document\DocumentVersion;
use App\Models\Procurement\Estimate\Estimate;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/**
 * P9.1 — схема обратной связи оператора (D15, аудит F ось 6): ревью-колонки на `prc_ai_analyses`
 * + структурированные коррекции в `prc_ai_corrections`, partial unique допускает историю
 * перепрогонов и теневые анализы вне активного множества.
 */
test('боевой анализ по умолчанию unreviewed и не имеет истории перепрогонов', function () {
    $analysis = Analysis::factory()->create();

    expect($analysis->review_status)->toBe(ReviewStatus::Unreviewed)
        ->and($analysis->reviewed_by)->toBeNull()
        ->and($analysis->parent_analysis_id)->toBeNull()
        ->and($analysis->superseded_at)->toBeNull()
        ->and($analysis->is_shadow)->toBeFalse();
});

test('второй активный анализ на ту же версию документа и шаг запрещён partial unique индексом', function () {
    $documentVersion = DocumentVersion::factory()->create();

    Analysis::factory()->create([
        'document_version_id' => $documentVersion->id,
        'step' => AnalysisStep::Screening,
    ]);

    Analysis::factory()->create([
        'document_version_id' => $documentVersion->id,
        'step' => AnalysisStep::Screening,
    ]);
})->throws(QueryException::class);

test('superseded анализ не конфликтует с новым активным анализом той же версии и шага', function () {
    $documentVersion = DocumentVersion::factory()->create();

    $parent = Analysis::factory()->create([
        'document_version_id' => $documentVersion->id,
        'step' => AnalysisStep::Extraction,
        'superseded_at' => Carbon::now(),
    ]);

    $child = Analysis::factory()->create([
        'document_version_id' => $documentVersion->id,
        'step' => AnalysisStep::Extraction,
        'parent_analysis_id' => $parent->id,
    ]);

    expect($child->parentAnalysis->is($parent))->toBeTrue()
        ->and(Analysis::query()->active()->where('document_version_id', $documentVersion->id)->count())->toBe(1);
});

test('теневой анализ не конфликтует с активным боевым анализом той же версии и шага', function () {
    $documentVersion = DocumentVersion::factory()->create();

    Analysis::factory()->create([
        'document_version_id' => $documentVersion->id,
        'step' => AnalysisStep::Screening,
    ]);

    $shadow = Analysis::factory()->create([
        'document_version_id' => $documentVersion->id,
        'step' => AnalysisStep::Screening,
        'is_shadow' => true,
    ]);

    expect($shadow->is_shadow)->toBeTrue()
        ->and(Analysis::query()->active()->where('document_version_id', $documentVersion->id)->count())->toBe(1);
});

test('scope active выбирает только не заменённые и не теневые анализы', function () {
    $documentVersion = DocumentVersion::factory()->create();

    $active = Analysis::factory()->create(['document_version_id' => $documentVersion->id]);
    Analysis::factory()->create([
        'document_version_id' => DocumentVersion::factory(),
        'superseded_at' => Carbon::now(),
    ]);
    Analysis::factory()->create([
        'document_version_id' => DocumentVersion::factory(),
        'is_shadow' => true,
    ]);

    $result = Analysis::query()->active()->get();

    expect($result)->toHaveCount(1)
        ->and($result->first()->is($active))->toBeTrue();
});

test('коррекция хранит JSONPath поля, enum причину и переформулировку hint для промта', function () {
    $analysis = Analysis::factory()->create();
    $estimate = Estimate::factory()->create();
    $operator = User::factory()->create();

    $correction = Correction::factory()->create([
        'analysis_id' => $analysis->id,
        'estimate_id' => $estimate->id,
        'field_path' => '$.rows[3].total_cost',
        'reason' => CorrectionReason::WrongValue,
        'content' => 'Итог по строке 3 не совпадает со сметой',
        'hint' => 'Проверяй итог строки против графы 9',
        'is_reusable' => true,
        'created_by' => $operator->id,
    ]);

    expect($correction->uuid)->not->toBeNull()
        ->and($correction->analysis->is($analysis))->toBeTrue()
        ->and($correction->estimate->is($estimate))->toBeTrue()
        ->and($correction->createdBy->is($operator))->toBeTrue()
        ->and($correction->reason)->toBe(CorrectionReason::WrongValue)
        ->and($correction->hint)->toBe('Проверяй итог строки против графы 9')
        ->and($correction->is_reusable)->toBeTrue()
        ->and($correction->applied_at)->toBeNull();
});

test('коррекция без field_path адресует весь документ, а не конкретное поле', function () {
    $correction = Correction::factory()->create([
        'field_path' => null,
        'reason' => CorrectionReason::SplitError,
    ]);

    expect($correction->field_path)->toBeNull()
        ->and($correction->reason)->toBe(CorrectionReason::SplitError);
});

test('удаление анализа удаляет его коррекции', function () {
    $analysis = Analysis::factory()->create();
    $correction = Correction::factory()->create(['analysis_id' => $analysis->id]);

    $analysis->delete();

    expect(Correction::query()->whereKey($correction->id)->exists())->toBeFalse();
});

test('удаление сметы обнуляет привязку коррекции к смете, но саму коррекцию не удаляет', function () {
    $estimate = Estimate::factory()->create();
    $correction = Correction::factory()->create(['estimate_id' => $estimate->id]);

    $estimate->delete();
    $correction->refresh();

    expect($correction->estimate_id)->toBeNull();
});
