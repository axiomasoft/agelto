<?php

declare(strict_types=1);

use App\Enums\Procurement\Estimate\ResourceType;
use App\Models\Procurement\Estimate\Estimate;
use App\Models\Procurement\Estimate\Item;
use App\Models\Procurement\Estimate\Resource;
use App\Models\Procurement\Estimate\Section;
use App\Models\Procurement\Estimate\Total;
use App\Services\Procurement\Analyzers\Estimate\Core\EstimateToContractMapper;

// Обратный маппер (ревью K п.23) — реконструирует DTO-контракт из НОРМАЛИЗОВАННЫХ
// `prc_estimate_*`. `row_index` заголовков разделов НЕ персистируется нигде —
// реконструкция переприсваивает его последовательно заново; тест фиксирует это как канон, а не
// как побочный эффект (докблок маппера).

test('маппер восстанавливает паспорт/итоги/иерархию строк из нормализованных таблиц', function (): void {
    $estimate = Estimate::factory()->create([
        'ordinal' => 1,
        'estimate_number' => '17-СМ-0042',
        'currency' => 'RUB',
        'base_year' => 2001,
        'price_level' => 'ФЕР-2001',
        'passport' => ['type' => 'local_estimate', 'reference' => null, 'target' => 'construction', 'address' => null, 'generator' => 'Гранд-Смета'],
        'rows_expected' => 2,
    ]);

    $sectionA = Section::factory()->create(['estimate_id' => $estimate->id, 'parent_id' => null, 'position' => 1, 'title' => 'Раздел 1']);
    $subsectionA1 = Section::factory()->create(['estimate_id' => $estimate->id, 'parent_id' => $sectionA->id, 'position' => 1, 'title' => 'Подраздел 1.1']);
    $sectionB = Section::factory()->create(['estimate_id' => $estimate->id, 'parent_id' => null, 'position' => 2, 'title' => 'Раздел 2']);

    $itemA = Item::factory()->create([
        'estimate_id' => $estimate->id,
        'section_id' => $subsectionA1->id,
        'row_index' => 3,
        'code' => 'ФЕР01-01-001-01',
        'name' => 'Позиция A',
        'unit' => 'м3',
        'quantity' => '10.5',
        'unit_price_curr' => '100.00',
        'total_price_curr' => '1050.00',
        'attributes' => null,
    ]);
    Resource::factory()->create([
        'item_id' => $itemA->id,
        'resource_type' => ResourceType::Material,
        'name' => 'Цемент',
        'code' => 'РЕС-0001',
        'unit' => 'т',
        'quantity' => '2.5',
        'price_curr' => '3000.00',
    ]);

    Item::factory()->create([
        'estimate_id' => $estimate->id,
        'section_id' => $sectionB->id,
        'row_index' => 7,
        'code' => 'ФЕР02-02-002-02',
        'name' => 'Позиция B',
        'unit' => 'шт',
        'quantity' => '4',
        'unit_price_curr' => '250.00',
        'total_price_curr' => '1000.00',
        'attributes' => null,
    ]);

    $totalRoot = Total::factory()->create(['estimate_id' => $estimate->id, 'parent_id' => null, 'position' => 1, 'label' => 'Итого', 'kind' => 'total', 'amount' => '2050.00']);
    Total::factory()->create(['estimate_id' => $estimate->id, 'parent_id' => $totalRoot->id, 'position' => 1, 'label' => 'в т.ч. НДС', 'kind' => 'vat', 'amount' => '341.67']);

    $contract = (new EstimateToContractMapper)->toContract($estimate->fresh());

    expect($contract['estimate_number'])->toBe('17-СМ-0042')
        ->and($contract['currency'])->toBe('RUB')
        ->and($contract['base_year'])->toBe(2001)
        ->and($contract['price_level'])->toBe('ФЕР-2001')
        ->and($contract['rows_expected'])->toBe(2)
        ->and($contract['ordinal'])->toBe(1)
        ->and($contract['passport']['type'])->toBe('local_estimate')
        ->and($contract['passport']['generator'])->toBe('Гранд-Смета');

    $rows = $contract['rows'];

    // Раздел 1 → Подраздел 1.1 → Позиция A → Раздел 2 (общего префикса с прошлым breadcrumb
    // нет — эмитируется новый заголовок верхнего уровня) → Позиция B.
    expect($rows)->toHaveCount(5);

    // Заголовки перед позицией A: раздел (depth 0) → подраздел (depth 1), row_index переприсвоен
    // последовательно (0,1) — оригинальные row_index у самих Item (3,7) не персистированы у секций.
    expect($rows[0]['row_type'])->toBe('section_header')
        ->and($rows[0]['name'])->toBe('Раздел 1')
        ->and($rows[0]['row_index'])->toBe(0)
        ->and($rows[1]['row_type'])->toBe('subsection_header')
        ->and($rows[1]['name'])->toBe('Подраздел 1.1')
        ->and($rows[1]['row_index'])->toBe(1);

    expect($rows[2]['row_type'])->toBe('item')
        ->and($rows[2]['name'])->toBe('Позиция A')
        ->and($rows[2]['section_title'])->toBe('Подраздел 1.1')
        ->and($rows[2]['quantity'])->toBe('10.500000')
        ->and($rows[2]['resources'])->toHaveCount(1)
        ->and($rows[2]['resources'][0]['resource_type'])->toBe('material')
        ->and($rows[2]['resources'][0]['name'])->toBe('Цемент');

    expect($rows[3]['row_type'])->toBe('section_header')
        ->and($rows[3]['name'])->toBe('Раздел 2');

    expect($rows[4]['row_type'])->toBe('item')
        ->and($rows[4]['name'])->toBe('Позиция B')
        ->and($rows[4]['section_title'])->toBe('Раздел 2');

    $footer = $contract['totals']['footer'];

    expect($footer)->toHaveCount(1)
        ->and($footer[0]['label'])->toBe('Итого')
        ->and($footer[0]['value'])->toBe('2050.00')
        ->and($footer[0]['children'])->toHaveCount(1)
        ->and($footer[0]['children'][0]['label'])->toBe('в т.ч. НДС');
});
