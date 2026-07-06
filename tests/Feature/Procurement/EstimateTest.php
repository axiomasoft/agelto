<?php

declare(strict_types=1);

use App\Enums\Procurement\AnalysisStep;
use App\Models\Procurement\Analysis;
use App\Models\Procurement\DocumentVersion;
use App\Models\Procurement\Estimate;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * §11 «Повторный парсинг сметы» — unique (document_version_id, step) не даёт дублей
 * анализа, новая смета по analysis_id unique.
 *
 * Дублирующий insert оборачиваем в свой `DB::transaction()` (savepoint поверх
 * транзакции RefreshDatabase) — иначе Postgres держит внешнюю транзакцию теста
 * в состоянии aborted и следующие запросы падают с "current transaction is aborted".
 */
test('unique (document_version_id, step) не даёт дублей повторного анализа той же версии документа', function () {
    $documentVersion = DocumentVersion::factory()->create();

    Analysis::factory()->create([
        'document_version_id' => $documentVersion->id,
        'step' => AnalysisStep::Screening,
    ]);

    expect(fn () => DB::transaction(fn () => Analysis::factory()->create([
        'document_version_id' => $documentVersion->id,
        'step' => AnalysisStep::Screening,
    ])))->toThrow(QueryException::class);

    // другой шаг для той же версии документа — не конфликт.
    $extraction = Analysis::factory()->create([
        'document_version_id' => $documentVersion->id,
        'step' => AnalysisStep::Extraction,
    ]);

    expect($extraction->document_version_id)->toBe($documentVersion->id);
});

test('смета уникальна по analysis_id — повторный парсинг той же сессии анализа не плодит дублей', function () {
    $analysis = Analysis::factory()->create();

    Estimate::factory()->create(['analysis_id' => $analysis->id]);

    expect(fn () => DB::transaction(fn () => Estimate::factory()->create(['analysis_id' => $analysis->id])))
        ->toThrow(QueryException::class);

    expect(Estimate::query()->where('analysis_id', $analysis->id)->count())->toBe(1);
});
