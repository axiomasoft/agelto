<?php

declare(strict_types=1);

use App\Enums\Procurement\AnalysisStatus;
use App\Enums\Procurement\AnalysisStep;
use App\Enums\Procurement\EstimateValidationStatus;
use App\Enums\Procurement\ProcurementEventType;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Models\Procurement\Estimate\Estimate;
use App\Models\Procurement\Estimate\Item;
use App\Models\Procurement\Estimate\Section;
use App\Models\Procurement\Estimate\Total;
use App\Models\Procurement\ProcurementEvent;
use App\Services\Procurement\Extraction\EstimatePersister;

// Feature-тесты персиста P6.1-формата (analysis.result.estimates[]) в prc_estimate_* через
// EstimateStitcher/EstimateValidator (P6.2) — те же builder-соглашения, что ExtractionOrchestratorTest
// (без JSON-фикстур, прецедент P6.1/P6.2 Known Deviations).

/**
 * @param  list<array<string, mixed>>  $rows
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
): array {
    return [
        'row_index' => $index,
        'row_type' => $rowType,
        'name' => $name,
        'section_title' => $sectionTitle,
        'code' => $code,
        'unit' => $quantity !== null ? 'м3' : null,
        'quantity' => $quantity,
        'unit_price' => $unitPrice,
        'total_price' => $totalPrice,
        'resources' => [],
        'modifiers' => [],
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
