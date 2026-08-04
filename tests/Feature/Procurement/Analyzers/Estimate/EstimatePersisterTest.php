<?php

declare(strict_types=1);

use App\Enums\Procurement\Ai\AnalysisStatus;
use App\Enums\Procurement\Ai\AnalysisStep;
use App\Enums\Procurement\Estimate\EstimateValidationStatus;
use App\Enums\Procurement\ProcurementEventType;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Models\Procurement\Estimate\Estimate;
use App\Models\Procurement\Estimate\Item;
use App\Models\Procurement\Estimate\Resource;
use App\Models\Procurement\Estimate\Section;
use App\Models\Procurement\Estimate\Total;
use App\Models\Procurement\ProcurementEvent;
use App\Services\Procurement\Analyzers\Estimate\EstimatePersister;

// Feature-тесты персиста P6.1-формата (analysis.result.estimates[]) в prc_estimate_* через
// EstimateStitcher/EstimateValidator (P6.2) — те же builder-соглашения, что ExtractionOrchestratorTest
// (без JSON-фикстур, прецедент P6.1/P6.2 Known Deviations).

/**
 * @param  list<array<string, mixed>>  $resources
 * @return array<string, mixed>
 */
function estimateRowData(
    int $index,
    string $rowType,
    string $name,
    ?string $sectionTitle = null,
    ?string $code = null,
    ?string $totalPrice = null,
    ?string $quantity = null,
    ?string $unitPrice = null,
    ?string $unitPriceBase = null,
    ?string $totalPriceBase = null,
    ?string $indexCode = null,
    ?string $indexValue = null,
    ?string $sourcePositionNumber = null,
    array $resources = [],
): array {
    return [
        'row_index' => $index,
        'row_type' => $rowType,
        'name' => $name,
        'section_title' => $sectionTitle,
        'code' => $code,
        'unit' => $quantity !== null ? 'м3' : null,
        'quantity' => $quantity,
        'unit_price_base' => $unitPriceBase,
        'unit_price_curr' => $unitPrice,
        'total_price_base' => $totalPriceBase,
        'total_price_curr' => $totalPrice,
        'index_code' => $indexCode,
        'index_value' => $indexValue,
        'source_position_number' => $sourcePositionNumber,
        'resources' => $resources,
    ];
}

/**
 * @return array<string, mixed>
 */
function estimateResourceData(
    string $name,
    string $resourceType = 'material',
    ?string $priceBase = null,
    ?string $priceCurr = null,
    ?string $indexCode = null,
    ?string $indexValue = null,
): array {
    return [
        'resource_type' => $resourceType,
        'name' => $name,
        'code' => null,
        'unit' => null,
        'quantity' => null,
        'price_base' => $priceBase,
        'price_curr' => $priceCurr,
        'index_code' => $indexCode,
        'index_value' => $indexValue,
    ];
}

/**
 * @param  list<array<string, mixed>>  $rows
 */
function estimateResultData(int $ordinal, array $rows, ?string $grandTotal = '2000.00'): array
{
    return [
        'ordinal' => $ordinal,
        'estimate_number' => "СМ-{$ordinal}",
        'passport' => ['type' => null, 'reference' => null, 'target' => null, 'address' => null, 'generator' => null],
        'currency' => 'RUB',
        'base_year' => 2001,
        'price_level' => 'ФЕР-2001',
        'rows_expected' => 2,
        'rows' => $rows,
        'incomplete_items' => [],
        'totals' => $grandTotal === null ? null : ['footer' => [
            ['label' => 'Итого', 'value' => $grandTotal, 'kind' => null, 'children' => []],
        ]],
        'rows_done' => true,
        'totals_done' => true,
    ];
}

function seedPersisterAnalysis(array $estimatesData): Analysis
{
    $document = Document::factory()->create();
    $version = DocumentVersion::factory()->create(['document_id' => $document->id]);

    return Analysis::factory()->create([
        'document_version_id' => $version->id,
        'step' => AnalysisStep::Extraction,
        'status' => AnalysisStatus::Succeeded,
        'result' => ['estimates' => $estimatesData, 'meta' => ['usage' => []]],
        'finished_at' => now(),
    ]);
}

test('persist нормализует смету с разделом/позициями/итогами и пишет событие estimate_extracted', function (): void {
    $rows = [
        estimateRowData(1, 'section_header', 'Раздел 1'),
        estimateRowData(2, 'item', 'Позиция 1', sectionTitle: 'Раздел 1', code: 'ФЕР01-01-001-01', totalPrice: '1000.00', quantity: '10.000000', unitPrice: '100.00'),
        estimateRowData(3, 'item', 'Позиция 2', sectionTitle: 'Раздел 1', code: 'ФЕР01-01-002-01', totalPrice: '1000.00', quantity: '5.000000', unitPrice: '200.00'),
        estimateRowData(4, 'subtotal', 'Итого по разделу 1', sectionTitle: 'Раздел 1', totalPrice: '2000.00'),
    ];

    $analysis = seedPersisterAnalysis([estimateResultData(1, $rows)]);

    app(EstimatePersister::class)->persist($analysis);

    $estimate = Estimate::query()->where('analysis_id', $analysis->id)->where('ordinal', 1)->firstOrFail();

    expect($estimate->estimate_number)->toBe('СМ-1')
        ->and($estimate->rows_expected)->toBe(2)
        ->and($estimate->rows_extracted)->toBe(2)
        ->and($estimate->validation_status)->toBe(EstimateValidationStatus::Valid->value)
        ->and((string) $estimate->total_amount)->toBe('2000.00');

    $section = Section::query()->where('estimate_id', $estimate->id)->firstOrFail();
    expect($section->title)->toBe('Раздел 1')->and($section->parent_id)->toBeNull();

    $items = Item::query()->where('estimate_id', $estimate->id)->orderBy('row_index')->get();
    expect($items)->toHaveCount(2)
        ->and($items[0]->row_index)->toBe(2)
        ->and($items[0]->section_id)->toBe($section->id)
        ->and($items[1]->row_index)->toBe(3);

    $total = Total::query()->where('estimate_id', $estimate->id)->firstOrFail();
    expect($total->label)->toBe('Итого')->and((string) $total->amount)->toBe('2000.00');

    $event = ProcurementEvent::query()->where('event_type', ProcurementEventType::EstimateExtracted)->firstOrFail();
    expect($event->new_value)->toBe(EstimateValidationStatus::Valid->value);
});

test('повторный persist того же анализа не плодит дублей (upsert по analysis_id+ordinal, row_index)', function (): void {
    $rows = [
        estimateRowData(1, 'section_header', 'Раздел 1'),
        estimateRowData(2, 'item', 'Позиция 1', sectionTitle: 'Раздел 1', code: 'ФЕР01-01-001-01', totalPrice: '1000.00'),
        estimateRowData(3, 'item', 'Позиция 2', sectionTitle: 'Раздел 1', code: 'ФЕР01-01-002-01', totalPrice: '1000.00'),
    ];

    $analysis = seedPersisterAnalysis([estimateResultData(1, $rows, grandTotal: null)]);
    $persister = app(EstimatePersister::class);

    $persister->persist($analysis);
    $persister->persist($analysis);

    expect(Estimate::query()->where('analysis_id', $analysis->id)->count())->toBe(1)
        ->and(Section::query()->count())->toBe(1)
        ->and(Item::query()->count())->toBe(2);
});

test('несколько смет одного анализа персистятся отдельными записями с возрастающим ordinal', function (): void {
    $rowsA = [estimateRowData(1, 'item', 'Позиция A', totalPrice: '500.00')];
    $rowsB = [estimateRowData(1, 'item', 'Позиция B', totalPrice: '700.00')];

    $analysis = seedPersisterAnalysis([
        estimateResultData(1, $rowsA, grandTotal: null),
        estimateResultData(2, $rowsB, grandTotal: null),
    ]);

    app(EstimatePersister::class)->persist($analysis);

    $estimates = Estimate::query()->where('analysis_id', $analysis->id)->orderBy('ordinal')->get();

    expect($estimates)->toHaveCount(2)
        ->and($estimates[0]->ordinal)->toBe(1)
        ->and($estimates[1]->ordinal)->toBe(2);
});

test('persist доводит запись до полноты схемы: все поля класса A непусты в БД (P5.2)', function (): void {
    $rows = [
        estimateRowData(1, 'section_header', 'Раздел 1'),
        estimateRowData(
            2,
            'item',
            'Позиция 1',
            sectionTitle: 'Раздел 1',
            code: 'ФЕР01-01-001-01',
            totalPrice: '1200.00',
            quantity: '10.000000',
            unitPrice: '120.00',
            unitPriceBase: '100.00',
            totalPriceBase: '1000.00',
            indexCode: 'ИПЦ-2024',
            indexValue: '1.2',
            sourcePositionNumber: '9.1',
            resources: [estimateResourceData('Материал А', priceBase: '50.00', priceCurr: '60.00', indexCode: 'ИПЦ-2024', indexValue: '1.2')],
        ),
    ];

    $estimateData = estimateResultData(1, $rows, grandTotal: null);
    $estimateData['calc_method'] = 'base_index';
    $estimateData['labor_hours_workers'] = '12.500000';
    $estimateData['labor_hours_machinists'] = '3.250000';
    $estimateData['document_number'] = 'КС-2 №7';
    $estimateData['document_date'] = '2024-05-01';
    $estimateData['period_start'] = '2024-04-01';
    $estimateData['period_end'] = '2024-04-30';
    $estimateData['totals'] = ['footer' => [
        ['label' => 'Глава 15. НДС', 'value' => '210.00', 'kind' => null, 'chapter_number' => 15, 'rate' => '5', 'children' => []],
    ]];

    $analysis = seedPersisterAnalysis([$estimateData]);

    app(EstimatePersister::class)->persist($analysis);

    $estimate = Estimate::query()->where('analysis_id', $analysis->id)->where('ordinal', 1)->firstOrFail();

    expect($estimate->calc_method->value)->toBe('base_index')
        ->and((string) $estimate->labor_hours_workers)->toBe('12.500000')
        ->and((string) $estimate->labor_hours_machinists)->toBe('3.250000')
        ->and($estimate->document_number)->toBe('КС-2 №7')
        ->and($estimate->document_date->toDateString())->toBe('2024-05-01')
        ->and($estimate->period_start->toDateString())->toBe('2024-04-01')
        ->and($estimate->period_end->toDateString())->toBe('2024-04-30');

    $item = Item::query()->where('estimate_id', $estimate->id)->where('row_index', 2)->firstOrFail();

    expect((string) $item->unit_price_base)->toBe('100.00')
        ->and((string) $item->total_price_base)->toBe('1000.00')
        ->and($item->index_code)->toBe('ИПЦ-2024')
        ->and((string) $item->index_value)->toBe('1.200000')
        ->and($item->source_position_number)->toBe('9.1');

    $resource = Resource::query()->where('item_id', $item->id)->firstOrFail();

    expect((string) $resource->price_base)->toBe('50.00')
        ->and($resource->index_code)->toBe('ИПЦ-2024')
        ->and((string) $resource->index_value)->toBe('1.200000');

    $total = Total::query()->where('estimate_id', $estimate->id)->firstOrFail();

    expect($total->chapter_number)->toBe(15)
        ->and((string) $total->rate)->toBe('5.000000');
});
