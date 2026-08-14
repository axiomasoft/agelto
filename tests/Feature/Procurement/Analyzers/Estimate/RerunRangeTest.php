<?php

declare(strict_types=1);

use App\Enums\Llm\FinishReason;
use App\Enums\Procurement\Ai\AnalysisStatus;
use App\Enums\Procurement\Ai\AnalysisStep;
use App\Enums\Procurement\Analyzers\Estimate\EstimateLlmPurpose;
use App\Models\Llm\LlmRoute;
use App\Models\Llm\Provider;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Models\Procurement\Estimate\Estimate;
use App\Models\Procurement\Estimate\Item;
use App\Models\Procurement\Estimate\Section;
use App\Services\Llm\Dto\LlmStructuredResponse;
use App\Services\Llm\Dto\LlmUsage;
use App\Services\Llm\Providers\PrismGeminiProvider;
use App\Services\Procurement\Analyzers\Estimate\RerunRangeAction;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Doubles\FakeLlmProvider;

// Точечный re-run (Scope Included P6.3, ТЗ 03 §3.5 п.5) — заменяет ТОЛЬКО запрошенный диапазон
// row_index, остальные позиции сметы не трогает. Builder-функции локальны файлу (тот же приём,
// что ExtractionOrchestratorTest/EstimatePersisterTest — без общих global-хелперов между файлами).

/**
 * @param  array<string, mixed>  $data
 */
function rerunStructured(array $data): LlmStructuredResponse
{
    return new LlmStructuredResponse(
        data: $data,
        usage: new LlmUsage(promptTokens: 300, completionTokens: 40, cachedTokens: 0, totalTokens: 340),
        finishReason: FinishReason::Stop,
        raw: json_encode($data, JSON_THROW_ON_ERROR),
    );
}

function rerunRowsResponse(array $rows): LlmStructuredResponse
{
    return rerunStructured(['rows' => $rows, 'incomplete_items' => []]);
}

function rerunRow(
    int $index,
    string $name,
    ?string $totalPrice = null,
    ?string $unitPriceBase = null,
    ?string $totalPriceBase = null,
    ?string $indexCode = null,
    ?string $indexValue = null,
    ?string $sourcePositionNumber = null,
): array {
    return [
        'row_index' => $index,
        'row_type' => 'item',
        'name' => $name,
        'section_title' => null,
        'code' => null,
        'unit' => null,
        'quantity' => null,
        'unit_price_base' => $unitPriceBase,
        'unit_price_curr' => null,
        'total_price_base' => $totalPriceBase,
        'total_price_curr' => $totalPrice,
        'index_code' => $indexCode,
        'index_value' => $indexValue,
        'source_position_number' => $sourcePositionNumber,
        'resources' => [],
    ];
}

function seedRerunRoute(FakeLlmProvider $fake): void
{
    app()->instance(PrismGeminiProvider::class, $fake);

    $provider = Provider::factory()->create(['driver' => 'gemini']);

    LlmRoute::factory()->create([
        'document_type' => 'estimate',
        'purpose' => EstimateLlmPurpose::ExtractRows->value,
        'provider_id' => $provider->id,
        'model' => 'gemini-2.5-flash',
        'priority' => 0,
    ]);
}

test('re-run заменяет только позиции запрошенного диапазона, остальные строки не трогает', function (): void {
    Storage::fake('s3');

    $fake = FakeLlmProvider::succeeding(rerunRowsResponse([
        rerunRow(5, 'Обновлённая позиция 5', totalPrice: '1500.00'),
        rerunRow(6, 'Обновлённая позиция 6', totalPrice: '900.00'),
    ]));
    seedRerunRoute($fake);

    $document = Document::factory()->create();
    $version = DocumentVersion::factory()->create(['document_id' => $document->id]);
    Storage::disk('s3')->put($version->storage_path, 'test PDF');
    $analysis = Analysis::factory()->create([
        'document_version_id' => $version->id,
        'step' => AnalysisStep::Extraction,
        'status' => AnalysisStatus::Succeeded,
        'progress' => null,
    ]);

    $estimate = Estimate::factory()->create([
        'analysis_id' => $analysis->id,
        'document_version_id' => $version->id,
        'procurement_id' => $document->procurement_id,
        'ordinal' => 1,
        'rows_expected' => 3,
        'rows_extracted' => 3,
        'validation_status' => 'suspicious',
    ]);

    $section = Section::factory()->create(['estimate_id' => $estimate->id]);

    Item::factory()->create(['estimate_id' => $estimate->id, 'section_id' => null, 'row_index' => 1, 'name' => 'Позиция 1 (не трогать)']);
    $original5 = Item::factory()->create(['estimate_id' => $estimate->id, 'section_id' => $section->id, 'row_index' => 5, 'name' => 'Старая позиция 5']);

    app(RerunRangeAction::class)->run($estimate, rowStart: 5, rowLength: 2);

    $untouched = Item::query()->where('estimate_id', $estimate->id)->where('row_index', 1)->firstOrFail();
    expect($untouched->name)->toBe('Позиция 1 (не трогать)');

    $updated5 = Item::query()->where('estimate_id', $estimate->id)->where('row_index', 5)->firstOrFail();
    expect($updated5->id)->toBe($original5->id)
        ->and($updated5->name)->toBe('Обновлённая позиция 5')
        ->and((string) $updated5->total_price_curr)->toBe('1500.00')
        ->and($updated5->section_id)->toBe($section->id); // прежняя секция сохранена (Known Deviations P6.3)

    $created6 = Item::query()->where('estimate_id', $estimate->id)->where('row_index', 6)->firstOrFail();
    expect($created6->name)->toBe('Обновлённая позиция 6')
        // Новый row_index без предшествующей Item-записи — секция БЛИЖАЙШЕЙ предыдущей позиции
        // (row_index 5, та же секция $section), не null (review-фикс P6.3 MED).
        ->and($created6->section_id)->toBe($section->id);

    expect($estimate->refresh()->rows_extracted)->toBe(3); // 1 + 5 + 6, дубля нет

    // Files API хэндл не переиспользован из progress (пуст) — файл загружен для точечного re-run.
    expect($fake->uploadedFiles())->toHaveCount(1);
});

test('re-run доводит запись позиции до полноты схемы тем же набором полей, что полный persist (P5.2)', function (): void {
    Storage::fake('s3');

    $fake = FakeLlmProvider::succeeding(rerunRowsResponse([
        rerunRow(5, 'Обновлённая позиция 5', totalPrice: '900.00', unitPriceBase: '80.00', totalPriceBase: '800.00', indexCode: 'ИПЦ-2024', indexValue: '1.1', sourcePositionNumber: '3.2'),
    ]));
    seedRerunRoute($fake);

    $document = Document::factory()->create();
    $version = DocumentVersion::factory()->create(['document_id' => $document->id]);
    Storage::disk('s3')->put($version->storage_path, 'test PDF');
    $analysis = Analysis::factory()->create([
        'document_version_id' => $version->id,
        'step' => AnalysisStep::Extraction,
        'status' => AnalysisStatus::Succeeded,
        'progress' => null,
    ]);

    $estimate = Estimate::factory()->create([
        'analysis_id' => $analysis->id,
        'document_version_id' => $version->id,
        'procurement_id' => $document->procurement_id,
        'ordinal' => 1,
        'rows_expected' => 1,
        'rows_extracted' => 1,
        'validation_status' => 'suspicious',
    ]);

    Item::factory()->create([
        'estimate_id' => $estimate->id,
        'section_id' => null,
        'row_index' => 5,
        'name' => 'Старая позиция 5',
        'unit_price_base' => '999.00',
        'index_code' => 'СТАРЫЙ',
        'source_position_number' => 'СТАРЫЙ',
    ]);

    app(RerunRangeAction::class)->run($estimate, rowStart: 5, rowLength: 1);

    $updated = Item::query()->where('estimate_id', $estimate->id)->where('row_index', 5)->firstOrFail();

    expect((string) $updated->unit_price_base)->toBe('80.00')
        ->and((string) $updated->total_price_base)->toBe('800.00')
        ->and($updated->index_code)->toBe('ИПЦ-2024')
        ->and((string) $updated->index_value)->toBe('1.100000')
        ->and($updated->source_position_number)->toBe('3.2');
});

test('re-run не обнуляет ранее записанные unit_price_base/index_code/source_position_number, если сам их не пересчитал (P5.2 Implementation Rules п.5)', function (): void {
    Storage::fake('s3');

    $fake = FakeLlmProvider::succeeding(rerunRowsResponse([
        // Тот же ряд — но ЭТОТ прогон вернул null там, где раньше уже было записано значение
        // (модель «не пересчитала» поле в этот раз).
        rerunRow(5, 'Обновлённая позиция 5', totalPrice: '900.00'),
    ]));
    seedRerunRoute($fake);

    $document = Document::factory()->create();
    $version = DocumentVersion::factory()->create(['document_id' => $document->id]);
    Storage::disk('s3')->put($version->storage_path, 'test PDF');
    $analysis = Analysis::factory()->create([
        'document_version_id' => $version->id,
        'step' => AnalysisStep::Extraction,
        'status' => AnalysisStatus::Succeeded,
        'progress' => null,
    ]);

    $estimate = Estimate::factory()->create([
        'analysis_id' => $analysis->id,
        'document_version_id' => $version->id,
        'procurement_id' => $document->procurement_id,
        'ordinal' => 1,
        'rows_expected' => 1,
        'rows_extracted' => 1,
        'validation_status' => 'suspicious',
    ]);

    Item::factory()->create([
        'estimate_id' => $estimate->id,
        'section_id' => null,
        'row_index' => 5,
        'name' => 'Старая позиция 5',
        'unit_price_base' => '100.00',
        'total_price_base' => '1000.00',
        'index_code' => 'ИПЦ-2023',
        'index_value' => '1.050000',
        'source_position_number' => '9.1',
    ]);

    app(RerunRangeAction::class)->run($estimate, rowStart: 5, rowLength: 1);

    $updated = Item::query()->where('estimate_id', $estimate->id)->where('row_index', 5)->firstOrFail();

    expect($updated->name)->toBe('Обновлённая позиция 5') // поле, которое re-run пересчитал — обновлено
        ->and((string) $updated->total_price_curr)->toBe('900.00')
        ->and((string) $updated->unit_price_base)->toBe('100.00') // не пересчитано этим прогоном — сохранено
        ->and((string) $updated->total_price_base)->toBe('1000.00')
        ->and($updated->index_code)->toBe('ИПЦ-2023')
        ->and((string) $updated->index_value)->toBe('1.050000')
        ->and($updated->source_position_number)->toBe('9.1');
});
