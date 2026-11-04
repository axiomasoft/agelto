<?php

declare(strict_types=1);

use App\Enums\Procurement\Ai\AnalysisStep;
use App\Enums\Procurement\Ai\CorrectionReason;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Ai\Correction;
use App\Models\Procurement\Ai\DatasetExample;
use App\Models\Procurement\Document\DocumentVersion;
use App\Models\Procurement\Estimate\Estimate;
use App\Models\Procurement\Estimate\Item;
use App\Models\User;
use App\Services\Procurement\Analyzers\Estimate\GoldExampleRecorder;

// Единственный потребитель `review_status=approved` (→ ) — снимает immutable gold-
// снапшот из НОРМАЛИЗОВАННЫХ смет анализа.

test('approve обычного анализа без коррекций создаёт gold версии 1 с source=llm', function (): void {
    $version = DocumentVersion::factory()->create();
    $reviewer = User::factory()->create();

    $analysis = Analysis::factory()->create([
        'document_version_id' => $version->id,
        'step' => AnalysisStep::Extraction,
        'reviewed_by' => $reviewer->id,
        'reviewed_at' => now(),
    ]);

    $estimate = Estimate::factory()->create(['analysis_id' => $analysis->id, 'document_version_id' => $version->id, 'ordinal' => 1]);
    Item::factory()->create(['estimate_id' => $estimate->id, 'row_index' => 1, 'name' => 'Позиция 1']);

    app(GoldExampleRecorder::class)->record($analysis);

    $example = DatasetExample::query()->where('document_version_id', $version->id)->sole();

    expect($example->task)->toBe('extraction')
        ->and($example->source)->toBe('llm')
        ->and($example->version)->toBe(1)
        ->and($example->verified_by)->toBe($reviewer->id)
        ->and($example->verified_at)->not->toBeNull()
        ->and($example->gold['tier'])->toBe(1)
        ->and($example->gold['estimates'][0]['rows'][0]['name'])->toBe('Позиция 1');
});

test('approve без персистированных смет — no-op (нечего фиксировать)', function (): void {
    $version = DocumentVersion::factory()->create();
    $analysis = Analysis::factory()->create(['document_version_id' => $version->id, 'step' => AnalysisStep::Extraction]);

    app(GoldExampleRecorder::class)->record($analysis);

    expect(DatasetExample::query()->where('document_version_id', $version->id)->exists())->toBeFalse();
});

test('шаг screening не производит estimates-контракт — recorder no-op', function (): void {
    $version = DocumentVersion::factory()->create();
    $analysis = Analysis::factory()->create(['document_version_id' => $version->id, 'step' => AnalysisStep::Screening]);

    app(GoldExampleRecorder::class)->record($analysis);

    expect(DatasetExample::query()->where('document_version_id', $version->id)->exists())->toBeFalse();
});

test('approve после re-run с применённой коррекцией предшественника даёт версию 2 source=corrected, версия 1 не мутируется', function (): void {
    $version = DocumentVersion::factory()->create();
    $reviewer = User::factory()->create();

    $parent = Analysis::factory()->create([
        'document_version_id' => $version->id,
        'step' => AnalysisStep::Extraction,
        'reviewed_by' => $reviewer->id,
        'reviewed_at' => now(),
    ]);
    $parentEstimate = Estimate::factory()->create(['analysis_id' => $parent->id, 'document_version_id' => $version->id, 'ordinal' => 1]);
    Item::factory()->create(['estimate_id' => $parentEstimate->id, 'row_index' => 1, 'name' => 'Позиция 1 (сырая)']);

    app(GoldExampleRecorder::class)->record($parent);

    $goldV1 = DatasetExample::query()->where('document_version_id', $version->id)->sole();
    expect($goldV1->version)->toBe(1)->and($goldV1->source)->toBe('llm');

    Correction::factory()->create([
        'analysis_id' => $parent->id,
        'reason' => CorrectionReason::WrongValue,
        'applied_at' => now(),
    ]);

    // Партиция «активный анализ на (версия, шаг)» — `RerunAnalysisAction` метит старый
    // `superseded_at` ДО создания нового (canon ); зеркалим порядок, иначе partial unique
    // `prc_ai_analyses_active_version_step_unique` не даст создать вторую активную запись.
    $parent->update(['superseded_at' => now()]);

    $rerun = Analysis::factory()->create([
        'document_version_id' => $version->id,
        'step' => AnalysisStep::Extraction,
        'parent_analysis_id' => $parent->id,
        'reviewed_by' => $reviewer->id,
        'reviewed_at' => now(),
    ]);
    $rerunEstimate = Estimate::factory()->create(['analysis_id' => $rerun->id, 'document_version_id' => $version->id, 'ordinal' => 1]);
    Item::factory()->create(['estimate_id' => $rerunEstimate->id, 'row_index' => 1, 'name' => 'Позиция 1 (исправлена)']);

    app(GoldExampleRecorder::class)->record($rerun);

    $examples = DatasetExample::query()->where('document_version_id', $version->id)->orderBy('version')->get();

    expect($examples)->toHaveCount(2)
        ->and($examples[0]->version)->toBe(1)
        ->and($examples[0]->gold['estimates'][0]['rows'][0]['name'])->toBe('Позиция 1 (сырая)')
        ->and($examples[1]->version)->toBe(2)
        ->and($examples[1]->source)->toBe('corrected')
        ->and($examples[1]->gold['estimates'][0]['rows'][0]['name'])->toBe('Позиция 1 (исправлена)');

    // Immutable bundle — версия 1 не тронута повторным approve.
    expect($goldV1->refresh()->gold['estimates'][0]['rows'][0]['name'])->toBe('Позиция 1 (сырая)');
});
