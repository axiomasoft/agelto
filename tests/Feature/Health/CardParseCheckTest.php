<?php

declare(strict_types=1);

use App\Enums\Procurement\Health\PlatformCheckVerdict;
use App\Models\Procurement\Platform\Platform;
use App\Models\Procurement\Platform\Registry;
use App\Models\Procurement\Procurement;
use App\Services\Procurement\Health\Checks\CardParseCheck;
use App\Services\Procurement\Health\HealthBaselineReader;

function measuredCardOutcome(): array
{
    return [
        'common-info' => ['status' => 'measured', 'anchors_missing_critical' => 0, 'key_fields_expected' => 0, 'key_fields_filled' => 0],
        'documents' => ['status' => 'measured', 'anchors_missing_critical' => 0, 'key_fields_expected' => 0, 'key_fields_filled' => 0],
        'event-journal' => ['status' => 'measured', 'anchors_missing_critical' => 0, 'key_fields_expected' => 0, 'key_fields_filled' => 0],
        'supplier-results' => ['status' => 'measured', 'anchors_missing_critical' => 0, 'key_fields_expected' => 0, 'key_fields_filled' => 0],
    ];
}

test('проверка разбора карточек подтверждает здоровый штатный исход', function (): void {
    $platform = Platform::factory()->create(['code' => 'eis']);
    $registry = Registry::factory()->create(['platform_id' => $platform->id]);
    Procurement::factory()->create(['registry_id' => $registry->id, 'detail_fetched_at' => now(), 'detail_parse_outcome' => measuredCardOutcome()]);

    $result = app(CardParseCheck::class)->run($platform, app(HealthBaselineReader::class)->read(), now()->subDay(), now());

    expect($result->verdict)->toBe(PlatformCheckVerdict::Ok);
});

test('проверка разбора карточек считает пропавший якорь поломкой', function (): void {
    $platform = Platform::factory()->create(['code' => 'eis']);
    $registry = Registry::factory()->create(['platform_id' => $platform->id]);
    $outcome = measuredCardOutcome();
    $outcome['documents']['anchors_missing_critical'] = 1;
    Procurement::factory()->create(['registry_id' => $registry->id, 'detail_fetched_at' => now(), 'detail_parse_outcome' => $outcome]);

    $result = app(CardParseCheck::class)->run($platform, app(HealthBaselineReader::class)->read(), now()->subDay(), now());

    expect($result->verdict)->toBe(PlatformCheckVerdict::Broken);
});

test('проверка разбора карточек не называет пустоту здоровьем', function (): void {
    $platform = Platform::factory()->create(['code' => 'eis']);

    $result = app(CardParseCheck::class)->run($platform, app(HealthBaselineReader::class)->read(), now()->subDay(), now());

    expect($result->verdict)->toBe(PlatformCheckVerdict::NotEnoughData);
});

test('production baseline считает пустые поля при сохранных якорях поломкой', function (): void {
    $platform = Platform::factory()->create(['code' => 'eis']);
    $registry = Registry::factory()->create(['platform_id' => $platform->id]);
    $baseline = app(HealthBaselineReader::class)->read();
    $outcome = [];

    foreach ($baseline->cardTabs as $tab => $description) {
        if (($description['status'] ?? null) === 'known_broken') {
            continue;
        }

        $keyFields = $description['key_fields'] ?? [];
        expect($keyFields)->not->toBeEmpty();
        $outcome[$tab] = [
            'status' => 'measured',
            'anchors_missing_critical' => 0,
            'key_fields_expected' => count($keyFields),
            'key_fields_filled' => 0,
        ];
    }

    Procurement::factory()->create([
        'registry_id' => $registry->id,
        'detail_fetched_at' => now(),
        'detail_parse_outcome' => $outcome,
    ]);

    $result = app(CardParseCheck::class)->run($platform, $baseline, now()->subDay(), now());

    expect($result->verdict)->toBe(PlatformCheckVerdict::Broken);
});
