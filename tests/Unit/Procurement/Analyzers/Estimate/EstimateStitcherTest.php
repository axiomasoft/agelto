<?php

declare(strict_types=1);

use App\Dto\Procurement\Analyzers\Estimate\EstimateRow;
use App\Dto\Procurement\Analyzers\Estimate\IncompleteRow;
use App\Enums\Procurement\IncompleteReason;
use App\Enums\Procurement\RowType;
use App\Services\Procurement\Analyzers\Estimate\EstimateStitcher;

// Юнит-тесты чистой функции (Scope Included P6.2) — без LLM/БД, входы строятся напрямую из
// P2.2-контрактов (тот же стиль builder-функций, что ExtractionOrchestratorTest, прецедент
// Known Deviations P6.1: JSON-фикстур tests/fixtures/extraction/*.json в кодовой базе нет ни у
// одного закрытого LLM-item'а).

function row(
    int $index,
    ?string $name = null,
    RowType $type = RowType::Item,
    ?string $sectionTitle = null,
    ?string $code = null,
    ?string $totalPrice = null,
): EstimateRow {
    $name ??= "Позиция {$index}";

    return new EstimateRow(
        row_index: $index,
        row_type: $type,
        name: $name,
        section_title: $sectionTitle,
        code: $code,
        total_price_curr: $totalPrice,
    );
}

function incomplete(
    IncompleteReason $reason,
    string $rawText = 'обрывок',
    ?string $lastKnownCode = null,
    ?int $page = null,
): IncompleteRow {
    return new IncompleteRow(
        reason: $reason,
        raw_text: $rawText,
        last_known_code: $lastKnownCode,
        page_number: $page,
    );
}

test('stitch отбрасывает точный дубль row_index, оставляя первое вхождение', function () {
    $report = app(EstimateStitcher::class)->stitch(
        rows: [row(1, name: 'первая версия'), row(2), row(1, name: 'повторный ответ модели')],
        incompleteItems: [],
    );

    expect($report->rows)->toHaveCount(2)
        ->and($report->rows[0]->name)->toBe('первая версия')
        ->and($report->droppedDuplicateRowIndexes)->toBe([1]);
});

test('stitch отбрасывает дубль по составному ключу на соседних позициях (overlap двух окон)', function () {
    $report = app(EstimateStitcher::class)->stitch(
        rows: [
            row(1, name: 'Кирпич', code: 'ФЕР01', totalPrice: '100.00'),
            row(2, name: 'Кирпич', code: 'ФЕР01', totalPrice: '100.00'),
            row(3, name: 'Цемент', code: 'ФЕР02', totalPrice: '50.00'),
        ],
        incompleteItems: [],
    );

    expect($report->rows)->toHaveCount(2)
        ->and(array_column($report->rows, 'row_index'))->toBe([1, 3])
        ->and($report->droppedDuplicateComposites)->toBe([2]);
});

test('stitch восстанавливает иерархию раздел→подраздел из row_type', function () {
    $report = app(EstimateStitcher::class)->stitch(
        rows: [
            row(1, name: 'Раздел 1', type: RowType::SectionHeader),
            row(2, name: 'Позиция 1.1', sectionTitle: 'Раздел 1'),
            row(3, name: 'Подраздел 1.1', type: RowType::SubsectionHeader),
            row(4, name: 'Позиция 1.1.1', sectionTitle: 'Подраздел 1.1'),
            row(5, name: 'Раздел 2', type: RowType::SectionHeader),
            row(6, name: 'Позиция 2.1', sectionTitle: 'Раздел 2'),
        ],
        incompleteItems: [],
    );

    expect($report->sectionPaths[1])->toBe(['Раздел 1'])
        ->and($report->sectionPaths[2])->toBe(['Раздел 1'])
        ->and($report->sectionPaths[3])->toBe(['Раздел 1', 'Подраздел 1.1'])
        ->and($report->sectionPaths[4])->toBe(['Раздел 1', 'Подраздел 1.1'])
        ->and($report->sectionPaths[5])->toBe(['Раздел 2'])
        ->and($report->sectionPaths[6])->toBe(['Раздел 2'])
        ->and($report->sectionTitleMismatches)->toBe([]);
});

test('stitch различает два раздела с одинаковым именем по позиции в стеке, не по имени', function () {
    $report = app(EstimateStitcher::class)->stitch(
        rows: [
            row(1, name: 'Земляные работы', type: RowType::SectionHeader),
            row(2, name: 'Позиция А', sectionTitle: 'Земляные работы'),
            row(3, name: 'Земляные работы', type: RowType::SectionHeader),
            row(4, name: 'Позиция Б', sectionTitle: 'Земляные работы'),
        ],
        incompleteItems: [],
    );

    expect($report->sectionPaths[2])->toBe(['Земляные работы'])
        ->and($report->sectionPaths[4])->toBe(['Земляные работы'])
        ->and($report->sectionTitleMismatches)->toBe([]);
});

test('stitch помечает расхождение заявленного section_title с восстановленной иерархией', function () {
    $report = app(EstimateStitcher::class)->stitch(
        rows: [
            row(1, name: 'Раздел 1', type: RowType::SectionHeader),
            row(2, name: 'Позиция вне раздела', sectionTitle: 'Другой раздел'),
        ],
        incompleteItems: [],
    );

    expect($report->sectionTitleMismatches)->toBe([2]);
});

test('stitch склеивает разорванную строку по совпадению last_known_code', function () {
    $report = app(EstimateStitcher::class)->stitch(
        rows: [row(1)],
        incompleteItems: [
            incomplete(IncompleteReason::SplitByPageEnd, rawText: 'ФЕР05 Устройство фундамента', lastKnownCode: 'ФЕР05'),
            incomplete(IncompleteReason::OrphanNumbersAtPageStart, rawText: '12.5 м3 45000.00', lastKnownCode: 'ФЕР05'),
        ],
    );

    expect($report->mergedIncompleteItems)->toHaveCount(1)
        ->and($report->mergedIncompleteItems[0]['code'])->toBe('ФЕР05')
        ->and($report->mergedIncompleteItems[0]['name'])->toBe('ФЕР05 Устройство фундамента 12.5 м3 45000.00')
        ->and($report->unmatchedIncompleteItems)->toBe([]);
});

test('stitch склеивает разорванную строку по смежным page_number в оконном пути', function () {
    $report = app(EstimateStitcher::class)->stitch(
        rows: [],
        incompleteItems: [
            incomplete(IncompleteReason::SplitByPageEnd, rawText: 'обрыв на стр.3', page: 3),
            incomplete(IncompleteReason::OrphanNumbersAtPageStart, rawText: 'продолжение стр.4', page: 4),
        ],
    );

    expect($report->mergedIncompleteItems)->toHaveCount(1)
        ->and($report->unmatchedIncompleteItems)->toBe([]);
});

test('stitch оставляет несопоставленные incomplete_items аномалией, не склеивая наугад', function () {
    $report = app(EstimateStitcher::class)->stitch(
        rows: [],
        incompleteItems: [
            incomplete(IncompleteReason::SplitByPageEnd, rawText: 'обрыв А', lastKnownCode: 'ФЕР01', page: 3),
            incomplete(IncompleteReason::OrphanNumbersAtPageStart, rawText: 'продолжение Б', lastKnownCode: 'ФЕР02', page: 10),
        ],
    );

    expect($report->mergedIncompleteItems)->toBe([])
        ->and($report->unmatchedIncompleteItems)->toHaveCount(2)
        ->and($report->hasAnomalies())->toBeTrue();
});

test('stitch сохраняет порядок по row_index даже если чанки пришли не по порядку', function () {
    $report = app(EstimateStitcher::class)->stitch(
        rows: [row(3), row(1), row(2)],
        incompleteItems: [],
    );

    expect(array_column($report->rows, 'row_index'))->toBe([1, 2, 3]);
});
