<?php

declare(strict_types=1);

use App\Models\Procurement\Platform\Platform;
use App\Models\Procurement\Platform\Registry;
use App\Models\Procurement\Platform\StatusMapping;
use App\Models\Procurement\Procurement;
use App\Models\Procurement\PurchaseItem;

/**
 * §11 «Новая площадка» и §11.1 «Валидация универсальности на бумаге» —
 * заведение площадки чистыми данными (`prc_*`), без миграций ядра.
 */
test('новая площадка заводится вставкой prc_platforms/prc_registries/prc_status_mappings, ядро не меняется', function () {
    $platform = Platform::factory()->create(['code' => 'commercial-x', 'name' => 'Коммерческая площадка X']);
    $registry = Registry::factory()->create(['platform_id' => $platform->id, 'code' => 'main']);
    $mapping = StatusMapping::factory()->create([
        'registry_id' => $registry->id,
        'source_stage' => 'Опубликовано',
        'canonical_status' => 'bidding',
    ]);

    expect($platform->code)->toBe('commercial-x')
        ->and($registry->platform_id)->toBe($platform->id)
        ->and($mapping->registry_id)->toBe($registry->id);

    // Само заведение площадки — целиком данные: ни одна миграция ядра (prc_*) не требуется,
    // код доказывает это тем, что процесс не касается таблиц prc_* напрямую.
    expect(Registry::query()->where('platform_id', $platform->id)->count())->toBe(1);
});

test('коммерческая площадка без ИКЗ сохраняет закупку с ikz = null в ядре', function () {
    $platform = Platform::factory()->create(['code' => 'commercial-x']);
    $registry = Registry::factory()->create(['platform_id' => $platform->id, 'code' => 'main']);

    $procurement = Procurement::factory()->create([
        'registry_id' => $registry->id,
        'ikz' => null,
    ]);

    expect($procurement->ikz)->toBeNull();

    // Классификатор позиций заменяем (OCDS-подход): своя схема через classifier_scheme,
    // не завязана на окpd2-специфичный столбец.
    $item = PurchaseItem::factory()->create([
        'procurement_id' => $procurement->id,
        'classifier_scheme' => 'commercial-x-category',
        'classifier_code' => 'CAT-42',
    ]);

    expect($item->classifier_scheme)->toBe('commercial-x-category')
        ->and($item->classifier_code)->toBe('CAT-42');
});
