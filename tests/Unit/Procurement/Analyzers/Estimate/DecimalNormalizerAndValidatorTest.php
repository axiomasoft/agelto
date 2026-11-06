<?php

declare(strict_types=1);

use App\Dto\Procurement\Analyzers\Estimate\EstimateRow;
use App\Enums\Procurement\Estimate\EstimateValidationStatus;
use App\Enums\Procurement\Estimate\RowType;
use App\Models\Procurement\Estimate\Estimate;
use App\Models\Procurement\Estimate\Item;
use App\Models\Procurement\Estimate\Section;
use App\Models\Procurement\Estimate\Total;
use App\Services\Procurement\Analyzers\Estimate\DecimalNormalizer;
use App\Services\Procurement\Analyzers\Estimate\EstimateStitcher;
use App\Services\Procurement\Analyzers\Estimate\EstimateToContractMapper;
use App\Services\Procurement\Analyzers\Estimate\EstimateValidator;
use App\Services\Procurement\Analyzers\Estimate\EvalMetrics;
use App\Services\Procurement\Gold\GsfxGoldMapper;
use App\Services\Procurement\Gold\WorksheetCellReader;

test('DecimalNormalizer нормализует десятичные строки, экспоненту и ведущую точку без переполнения памяти', function (): void {
    expect(DecimalNormalizer::normalize(null))->toBeNull()
        ->and(DecimalNormalizer::normalize('   '))->toBeNull()
        ->and(DecimalNormalizer::normalize('+1 234,56'))->toBe('1234.56')
        ->and(DecimalNormalizer::normalize("-1\u{00A0}000.5"))->toBe('-1000.5')
        ->and(DecimalNormalizer::normalize('1.5E-5'))->toBe('0.000015')
        ->and(DecimalNormalizer::normalize('-2.5e3'))->toBe('-2500')
        ->and(DecimalNormalizer::normalize('.5', allowLeadingDot: false))->toBeNull()
        ->and(DecimalNormalizer::normalize('.5', allowLeadingDot: true))->toBe('0.5')
        ->and(DecimalNormalizer::normalize('-.5', allowLeadingDot: true))->toBe('-0.5')
        ->and(DecimalNormalizer::normalize('100.', allowLeadingDot: true))->toBe('100')
        ->and(DecimalNormalizer::normalize('1e999999999', allowLeadingDot: true))->toBeNull()
        ->and(DecimalNormalizer::isWellFormedBcmath('-123.45'))->toBeTrue()
        ->and(DecimalNormalizer::isWellFormedBcmath('+123.45'))->toBeFalse()
        ->and(DecimalNormalizer::isWellFormedBcmath('1.5E-5'))->toBeFalse();
});

test('GsfxGoldMapper безопасно отклоняет сверхбольшую экспоненту без исчерпания памяти', function (): void {
    $method = new ReflectionMethod(GsfxGoldMapper::class, 'normalizeDecimalStatic');

    expect($method->invoke(null, '1e999999999'))->toBeNull()
        ->and($method->invoke(null, '1.25e2'))->toBe('125')
        ->and($method->invoke(null, '-.75'))->toBe('-0.75');
});

test('EvalMetrics корректно сравнивает числа в научной нотации и со знаком плюс без ValueError в bcmath', function (): void {
    $metrics = app(EvalMetrics::class);
    $method = new ReflectionMethod(EvalMetrics::class, 'numbersEqual');

    expect($method->invoke($metrics, '1.5E-5', '0.000015'))->toBeTrue()
        ->and($method->invoke($metrics, '+100,50', '100.50'))->toBeTrue()
        ->and($method->invoke($metrics, '1e999999999', '1'))->toBeFalse();
});

test('EstimateValidator не принимает строки SummaryRow за промежуточный итог раздела Subtotal', function (): void {
    $rows = [
        new EstimateRow(
            row_index: 1,
            row_type: RowType::SectionHeader,
            name: 'Раздел 1',
        ),
        new EstimateRow(
            row_index: 2,
            row_type: RowType::Item,
            name: 'Разработка грунта',
            code: 'ФЕР01-01-001-01',
            unit: '1000 м3',
            quantity: '1',
            total_price_curr: '100.00',
            source_position_number: '1',
        ),
        new EstimateRow(
            row_index: 3,
            row_type: RowType::SummaryRow,
            name: 'НДС 20%',
            total_price_curr: '20.00',
        ),
        new EstimateRow(
            row_index: 4,
            row_type: RowType::SummaryRow,
            name: 'ВСЕГО по смете',
            total_price_curr: '120.00',
        ),
    ];

    $report = app(EstimateValidator::class)->validate(rowsExpected: 1, rows: $rows, totals: null);

    expect($report->status)->toBe(EstimateValidationStatus::Valid)
        ->and($report->anomalousRowIndexes)->toBe([])
        ->and($report->notes)->toBe([]);
});

test('EstimateStitcher сохраняет раздел с именем 0 при восстановлении иерархии', function (): void {
    $report = app(EstimateStitcher::class)->stitch(
        rows: [
            new EstimateRow(row_index: 1, row_type: RowType::SectionHeader, name: '0'),
            new EstimateRow(row_index: 2, row_type: RowType::Item, name: 'Позиция в нулевом разделе', section_title: '0'),
        ],
        incompleteItems: [],
    );

    expect($report->sectionPaths[1])->toBe(['0'])
        ->and($report->sectionPaths[2])->toBe(['0'])
        ->and($report->sectionTitleMismatches)->toBe([]);
});

test('EstimateToContractMapper разрывает циклы parent_id в разделах и дереве итогов', function (): void {
    $estimate = new Estimate;
    $estimate->ordinal = 1;
    $estimate->currency = 'RUB';
    $estimate->rows_expected = 1;

    $sec1 = new Section;
    $sec1->id = 10;
    $sec1->parent_id = 20;
    $sec1->title = 'Раздел А';
    $sec1->position = 1;

    $sec2 = new Section;
    $sec2->id = 20;
    $sec2->parent_id = 10;
    $sec2->title = 'Раздел Б';
    $sec2->position = 2;

    $item = new Item;
    $item->id = 100;
    $item->section_id = 10;
    $item->row_index = 1;
    $item->name = 'Позиция 1';
    $item->setRelation('resources', collect());

    $tot1 = new Total;
    $tot1->id = 1;
    $tot1->parent_id = null;
    $tot1->position = 1;
    $tot1->label = 'Итого прямые затраты';
    $tot1->amount = '500.00';

    $tot2 = new Total;
    $tot2->id = 2;
    $tot2->parent_id = 2;
    $tot2->position = 2;
    $tot2->label = 'Самоцикл';
    $tot2->amount = '10.00';

    $estimate->setRelation('sections', collect([$sec1, $sec2]));
    $estimate->setRelation('items', collect([$item]));
    $estimate->setRelation('totals', collect([$tot1, $tot2]));

    $contract = app(EstimateToContractMapper::class)->toContract($estimate);

    expect($contract['rows'])->toHaveCount(3)
        ->and($contract['rows'][0]['name'])->toBe('Раздел Б')
        ->and($contract['rows'][1]['name'])->toBe('Раздел А')
        ->and($contract['rows'][2]['name'])->toBe('Позиция 1')
        ->and($contract['totals']['footer'])->toHaveCount(1)
        ->and($contract['totals']['footer'][0]['label'])->toBe('Итого прямые затраты');
});

test('WorksheetCellReader::stringify преобразует объекты Stringable и скаляры в нормализованную строку', function (): void {
    $richTextLike = new class implements Stringable
    {
        public function __toString(): string
        {
            return '  Текст ячейки  ';
        }
    };

    expect(WorksheetCellReader::stringify($richTextLike))->toBe('Текст ячейки')
        ->and(WorksheetCellReader::stringify(42))->toBe('42')
        ->and(WorksheetCellReader::stringify(true))->toBe('1')
        ->and(WorksheetCellReader::stringify(false))->toBe('0')
        ->and(WorksheetCellReader::stringify(null))->toBe('');
});
