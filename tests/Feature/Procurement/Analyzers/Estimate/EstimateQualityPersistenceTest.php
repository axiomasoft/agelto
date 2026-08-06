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
use App\Services\Llm\Dto\LlmStructuredResponse;
use App\Services\Llm\Dto\LlmUsage;
use App\Services\Llm\Providers\PrismGeminiProvider;
use App\Services\Procurement\Analyzers\Estimate\EstimatePersister;
use App\Services\Procurement\Analyzers\Estimate\RerunRangeAction;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Doubles\FakeLlmProvider;

// Носитель неопределённости позиции (Scope Included P8.4, research/12-uncertainty-model.md §3.2/§3.3)
// — построчная пометка МОДЕЛИ (P8.3) и построчная карта причин ВАЛИДАТОРА (EstimateValidator)
// сливаются в один jsonb-ключ `quality` под провенансом верхнего уровня `model`/`validator`.
// Builder-функции локальны файлу (тот же приём, что EstimatePersisterTest/RerunRangeTest — без
// общих global-хелперов между файлами).

/**
 * @param  list<array<string, mixed>>  $resources
 * @return array<string, mixed>
 */
function qualityRowData(
    int $index,
    string $rowType,
    string $name,
    ?string $totalPrice = null,
    ?string $quantity = null,
    ?string $unitPrice = null,
    ?array $quality = null,
    array $resources = [],
): array {
    return [
        'row_index' => $index,
        'row_type' => $rowType,
        'name' => $name,
        'section_title' => null,
        'code' => null,
        'unit' => $quantity !== null ? 'м3' : null,
        'quantity' => $quantity,
        'unit_price_base' => null,
        'unit_price_curr' => $unitPrice,
        'total_price_base' => null,
        'total_price_curr' => $totalPrice,
        'index_code' => null,
        'index_value' => null,
        'source_position_number' => null,
        'work_type_name' => null,
        'resources' => $resources,
        'quality' => $quality,
    ];
}

/**
 * @param  list<array<string, mixed>>  $rows
 */
function qualityResultData(int $rowsExpected, array $rows): array
{
    return [
        'ordinal' => 1,
        'estimate_number' => 'СМ-1',
        'passport' => ['type' => null, 'reference' => null, 'target' => null, 'address' => null, 'generator' => null],
        'currency' => 'RUB',
        'rows_expected' => $rowsExpected,
        'rows' => $rows,
        'incomplete_items' => [],
        'totals' => null,
        'work_types' => [],
        'rows_done' => true,
        'totals_done' => true,
    ];
}

function seedQualityAnalysis(array $rows, int $rowsExpected): Analysis
{
    $document = Document::factory()->create();
    $version = DocumentVersion::factory()->create(['document_id' => $document->id]);

    return Analysis::factory()->create([
        'document_version_id' => $version->id,
        'step' => AnalysisStep::Extraction,
        'status' => AnalysisStatus::Succeeded,
        'result' => ['estimates' => [qualityResultData($rowsExpected, $rows)], 'meta' => ['usage' => []]],
        'finished_at' => now(),
    ]);
}

test('пометка модели (unreadable) доезжает до БД под ключом quality.model', function (): void {
    $rows = [
        qualityRowData(1, 'item', 'Позиция 1', quality: ['reason' => 'unreadable', 'fields' => ['quantity', 'total_price_curr']]),
    ];

    $analysis = seedQualityAnalysis($rows, rowsExpected: 1);

    app(EstimatePersister::class)->persist($analysis);

    $item = Item::query()->where('row_index', 1)->firstOrFail();

    // toEqual (не toBe) — jsonb Postgres не гарантирует порядок ключей объекта при round-trip.
    expect($item->quality?->toArray())->toEqual([
        'model' => ['reason' => 'unreadable', 'fields' => ['quantity', 'total_price_curr']],
    ]);
});

test('аномалия валидатора доезжает до БД и адресует те row_index, что в отчёте, кроме row_type != item', function (): void {
    $rows = [
        qualityRowData(1, 'item', 'Позиция 1', totalPrice: '100.00'),
        qualityRowData(2, 'item', 'Позиция 2', totalPrice: '50.00'),
        qualityRowData(3, 'subtotal', 'Итого по разделу', totalPrice: '999.00'),
    ];

    $analysis = seedQualityAnalysis($rows, rowsExpected: 2);

    app(EstimatePersister::class)->persist($analysis);

    $estimate = Estimate::query()->where('analysis_id', $analysis->id)->firstOrFail();
    $expectedNote = 'арифметика раздела: сумма позиций 150.00 ≠ subtotal 999.00 (row_index=3)';

    $item1 = Item::query()->where('estimate_id', $estimate->id)->where('row_index', 1)->firstOrFail();
    $item2 = Item::query()->where('estimate_id', $estimate->id)->where('row_index', 2)->firstOrFail();

    // toEqual (не toBe) — jsonb Postgres не гарантирует порядок ключей объекта при round-trip.
    expect($item1->quality?->toArray())->toEqual(['validator' => ['reasons' => ['arithmetic_mismatch'], 'notes' => [$expectedNote]]])
        ->and($item2->quality?->toArray())->toEqual(['validator' => ['reasons' => ['arithmetic_mismatch'], 'notes' => [$expectedNote]]])
        // Subtotal (row_type != item) не материализуется в prc_estimate_items (D10, Г-2) — его
        // пометка сознательно теряется на записи, тест её не ожидает.
        ->and(Item::query()->where('estimate_id', $estimate->id)->where('row_index', 3)->exists())->toBeFalse()
        ->and($estimate->validation_notes?->toArray())->toBe([$expectedNote]);
});

test('чистая смета — ни одна позиция не помечена (И-А §6.1)', function (): void {
    $rows = [
        qualityRowData(1, 'item', 'Позиция 1', totalPrice: '1000.00', quantity: '10.000000', unitPrice: '100.00'),
        qualityRowData(2, 'item', 'Позиция 2', totalPrice: '1000.00', quantity: '5.000000', unitPrice: '200.00'),
        qualityRowData(3, 'subtotal', 'Итого', totalPrice: '2000.00'),
    ];

    $analysis = seedQualityAnalysis($rows, rowsExpected: 2);

    app(EstimatePersister::class)->persist($analysis);

    $estimate = Estimate::query()->where('analysis_id', $analysis->id)->firstOrFail();
    $items = Item::query()->where('estimate_id', $estimate->id)->get();

    expect($items)->toHaveCount(2)
        ->and($items->every(fn (Item $item): bool => $item->quality === null))->toBeTrue()
        ->and($estimate->validation_notes)->toBeNull();
});

test('фильтр «только помеченные» отбирает ожидаемое множество позиций', function (): void {
    $rows = [
        qualityRowData(1, 'item', 'Позиция 1', totalPrice: '100.00'),
        qualityRowData(2, 'item', 'Позиция 2', totalPrice: '100.00', quality: ['reason' => 'unreadable', 'fields' => ['total_price_curr']]),
        qualityRowData(3, 'item', 'Позиция 3', totalPrice: '100.00'),
    ];

    $analysis = seedQualityAnalysis($rows, rowsExpected: 3);

    app(EstimatePersister::class)->persist($analysis);

    $estimate = Estimate::query()->where('analysis_id', $analysis->id)->firstOrFail();

    $marked = Item::query()->where('estimate_id', $estimate->id)->whereNotNull('quality')->pluck('row_index')->all();

    expect($marked)->toBe([2]);
});

/**
 * @param  array<string, mixed>  $data
 */
function qualityStructured(array $data): LlmStructuredResponse
{
    return new LlmStructuredResponse(
        data: $data,
        usage: new LlmUsage(promptTokens: 300, completionTokens: 40, cachedTokens: 0, totalTokens: 340),
        finishReason: FinishReason::Stop,
        raw: json_encode($data, JSON_THROW_ON_ERROR),
    );
}

function seedQualityRerunRoute(FakeLlmProvider $fake): void
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

test('re-run диапазона убирает ключ validator и замещает ключ model (research/12 §7)', function (): void {
    Storage::fake('s3');

    $fake = FakeLlmProvider::succeeding(qualityStructured([
        'rows' => [
            [
                'row_index' => 5,
                'row_type' => 'item',
                'name' => 'Обновлённая позиция 5',
                'section_title' => null,
                'code' => null,
                'unit' => null,
                'quantity' => null,
                'unit_price_base' => null,
                'unit_price_curr' => null,
                'total_price_base' => null,
                'total_price_curr' => '900.00',
                'index_code' => null,
                'index_value' => null,
                'source_position_number' => null,
                'resources' => [],
                // Новый ответ пометки не несёт — ключ quality обязан стать null целиком, а не
                // сохранить старую пометку прошлого прогона (Ф-10, research/12 §7).
            ],
        ],
        'incomplete_items' => [],
    ]));
    seedQualityRerunRoute($fake);

    $document = Document::factory()->create();
    $version = DocumentVersion::factory()->create(['document_id' => $document->id]);
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
        'quality' => [
            'model' => ['reason' => 'unreadable', 'fields' => ['total_price_curr']],
            'validator' => ['reasons' => ['arithmetic_mismatch'], 'notes' => ['арифметика раздела: сумма позиций 100.00 ≠ subtotal 999.00 (row_index=6)']],
        ],
    ]);

    app(RerunRangeAction::class)->run($estimate, rowStart: 5, rowLength: 1);

    $updated = Item::query()->where('estimate_id', $estimate->id)->where('row_index', 5)->firstOrFail();

    expect($updated->quality)->toBeNull();
});
