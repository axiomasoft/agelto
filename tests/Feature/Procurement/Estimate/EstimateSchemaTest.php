<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

/**
 * Схема `prc_estimate*` по утверждённому списку P4.2+P4.3 (`plans/2026.07.26-EST-TRAIN/
 * research/06-schema-decision.md` §3.2, §8, §11) — 30 новых колонок, 3 переименования, 3 смены
 * типа, 2 удаления, новая таблица `prc_estimate_work_types` (13 колонок). Проверяет наличие
 * колонок, ключевые `nullable`-инварианты, индексы/уникальности и каскады FK; не проверяет
 * контракты ответа LLM/DTO (P4.5, Scope Excluded этого item'а).
 */
function columnsOf(string $table): array
{
    return collect(Schema::getColumns($table))->keyBy('name')->all();
}

function indexesOf(string $table): array
{
    return collect(Schema::getIndexes($table))->keyBy('name')->all();
}

function foreignKeysOf(string $table): array
{
    return collect(Schema::getForeignKeys($table))->keyBy('name')->all();
}

test('prc_estimates несёт утверждённый список колонок P4.2+P4.3, title удалена', function () {
    $columns = columnsOf('prc_estimates');

    expect($columns)->not->toHaveKey('title');

    $new = [
        'calc_method', 'labor_hours_workers', 'labor_hours_machinists',
        'document_number', 'document_date', 'period_start', 'period_end',
        'verified_at', 'verified_by',
        'source_sheet_name', 'source_row_start', 'source_row_end', 'source_page_start', 'source_page_end',
    ];

    foreach ($new as $column) {
        expect($columns)->toHaveKey($column)
            ->and($columns[$column]['nullable'])->toBeTrue("{$column} обязана быть nullable");
    }

    expect($columns['procurement_id']['nullable'])->toBeTrue('D98: procurement_id становится nullable')
        ->and($columns['labor_hours_workers']['type'])->toBe('numeric(18,6)')
        ->and($columns['document_date']['type_name'])->toBe('date')
        ->and($columns['verified_at']['type_name'])->toBe('timestamptz');
});

test('prc_estimates: unique(analysis_id, ordinal) сохранён, verified_by → users set null', function () {
    $indexes = indexesOf('prc_estimates');
    $foreignKeys = foreignKeysOf('prc_estimates');

    expect($indexes)->toHaveKey('prc_estimates_analysis_id_ordinal_unique')
        ->and($indexes['prc_estimates_analysis_id_ordinal_unique']['unique'])->toBeTrue()
        ->and($foreignKeys['prc_estimates_verified_by_foreign']['foreign_table'])->toBe('users')
        ->and($foreignKeys['prc_estimates_verified_by_foreign']['on_delete'])->toBe('set null')
        ->and($foreignKeys['prc_estimates_procurement_id_foreign']['foreign_table'])->toBe('prc_procurements');
});

test('prc_estimate_sections теряет section_number (мёртвый носитель, D100)', function () {
    $columns = columnsOf('prc_estimate_sections');

    expect($columns)->not->toHaveKey('section_number')
        ->and($columns)->toHaveKey('title');

    $foreignKeys = foreignKeysOf('prc_estimate_sections');
    expect($foreignKeys['prc_estimate_sections_estimate_id_foreign']['on_delete'])->toBe('cascade')
        ->and($foreignKeys['prc_estimate_sections_parent_id_foreign']['on_delete'])->toBe('set null');
});

test('prc_estimate_items: unit_price/total_price переименованы в *_curr, восемь новых колонок', function () {
    $columns = columnsOf('prc_estimate_items');

    expect($columns)->not->toHaveKey('unit_price')
        ->and($columns)->not->toHaveKey('total_price')
        ->and($columns)->toHaveKey('unit_price_curr')
        ->and($columns)->toHaveKey('total_price_curr');

    $new = [
        'unit_price_base', 'total_price_base', 'index_code', 'index_value',
        'unit_base', 'unit_multiplier', 'source_position_number', 'work_type_id',
    ];

    foreach ($new as $column) {
        expect($columns)->toHaveKey($column)
            ->and($columns[$column]['nullable'])->toBeTrue("{$column} обязана быть nullable");
    }

    expect($columns['unit_price_curr']['type'])->toBe('numeric(18,2)')
        ->and($columns['index_value']['type'])->toBe('numeric(18,6)')
        ->and($columns['unit_multiplier']['type'])->toBe('numeric(18,6)');
});

test('prc_estimate_items: unique(estimate_id, row_index) сохранён (SR-01 второй носитель — source_position_number)', function () {
    $indexes = indexesOf('prc_estimate_items');

    expect($indexes)->toHaveKey('prc_estimate_items_estimate_id_row_index_unique')
        ->and($indexes['prc_estimate_items_estimate_id_row_index_unique']['unique'])->toBeTrue();
});

test('prc_estimate_items.work_type_id — nullable FK на prc_estimate_work_types, set null (D97)', function () {
    $foreignKeys = foreignKeysOf('prc_estimate_items');

    expect($foreignKeys)->toHaveKey('prc_estimate_items_work_type_id_foreign')
        ->and($foreignKeys['prc_estimate_items_work_type_id_foreign']['foreign_table'])->toBe('prc_estimate_work_types')
        ->and($foreignKeys['prc_estimate_items_work_type_id_foreign']['on_delete'])->toBe('set null')
        ->and($foreignKeys['prc_estimate_items_estimate_id_foreign']['on_delete'])->toBe('cascade');
});

test('prc_estimate_resources: price переименован в price_curr, пять новых колонок', function () {
    $columns = columnsOf('prc_estimate_resources');

    expect($columns)->not->toHaveKey('price')
        ->and($columns)->toHaveKey('price_curr');

    $new = ['price_base', 'index_code', 'index_value', 'unit_base', 'unit_multiplier'];

    foreach ($new as $column) {
        expect($columns)->toHaveKey($column)
            ->and($columns[$column]['nullable'])->toBeTrue("{$column} обязана быть nullable");
    }

    $foreignKeys = foreignKeysOf('prc_estimate_resources');
    expect($foreignKeys['prc_estimate_resources_item_id_foreign']['on_delete'])->toBe('cascade');
});

test('prc_estimate_totals: amount стал nullable, kind сохранён, chapter_number/rate новые (D101)', function () {
    $columns = columnsOf('prc_estimate_totals');

    expect($columns['amount']['nullable'])->toBeTrue('D101: amount становится nullable')
        ->and($columns)->toHaveKey('kind')
        ->and($columns)->toHaveKey('chapter_number')
        ->and($columns)->toHaveKey('rate')
        ->and($columns['chapter_number']['nullable'])->toBeTrue()
        ->and($columns['rate']['nullable'])->toBeTrue()
        ->and($columns['rate']['type'])->toBe('numeric(18,6)');
});

test('prc_estimate_work_types — новая таблица с 13 колонками, per-estimate область и self-FK cascade (D34/D90)', function () {
    expect(Schema::hasTable('prc_estimate_work_types'))->toBeTrue();

    $columns = columnsOf('prc_estimate_work_types');

    expect($columns)->toHaveKeys([
        'id', 'estimate_id', 'parent_id', 'position', 'title',
        'overhead_code', 'overhead_rate', 'overhead_rate_curr',
        'profit_code', 'profit_rate', 'profit_rate_curr',
        'created_at', 'updated_at',
    ])->and($columns)->toHaveCount(13);

    $foreignKeys = foreignKeysOf('prc_estimate_work_types');

    expect($foreignKeys['prc_estimate_work_types_estimate_id_foreign']['foreign_table'])->toBe('prc_estimates')
        ->and($foreignKeys['prc_estimate_work_types_estimate_id_foreign']['on_delete'])->toBe('cascade')
        ->and($foreignKeys['prc_estimate_work_types_parent_id_foreign']['foreign_table'])->toBe('prc_estimate_work_types')
        ->and($foreignKeys['prc_estimate_work_types_parent_id_foreign']['on_delete'])->toBe('cascade');
});
