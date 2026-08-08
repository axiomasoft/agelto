<?php

declare(strict_types=1);

use App\Enums\Procurement\Estimate\EstimateValidationStatus;
use App\Enums\Procurement\Health\PlatformCheckVerdict;
use App\Models\Procurement\Estimate\Estimate;
use App\Models\Procurement\Estimate\Item;
use App\Models\Procurement\Platform\Platform;
use App\Services\Procurement\Health\Checks\EstimateFieldsCheck;
use App\Services\Procurement\Health\HealthBaselineReader;

test('проверка полей смет подтверждает заполненную валидную смету', function (): void {
    $platform = Platform::factory()->create(['code' => 'eis']);
    $estimate = Estimate::factory()->create(['platform_id' => $platform->id, 'validation_status' => EstimateValidationStatus::Valid->value]);
    Item::factory()->create(['estimate_id' => $estimate->id]);

    $result = app(EstimateFieldsCheck::class)->run($platform, app(HealthBaselineReader::class)->read(), now()->subDay(), now());

    expect($result->verdict)->toBe(PlatformCheckVerdict::Ok);
});

test('проверка полей смет считает пустые обязательные поля поломкой', function (): void {
    $platform = Platform::factory()->create(['code' => 'eis']);
    $estimate = Estimate::factory()->create(['platform_id' => $platform->id, 'validation_status' => EstimateValidationStatus::Invalid->value]);
    Item::factory()->create(['estimate_id' => $estimate->id, 'code' => null, 'unit' => null, 'quantity' => null]);

    $result = app(EstimateFieldsCheck::class)->run($platform, app(HealthBaselineReader::class)->read(), now()->subDay(), now());

    expect($result->verdict)->toBe(PlatformCheckVerdict::Broken);
});

test('проверка полей смет не называет отсутствие новых смет здоровьем', function (): void {
    $platform = Platform::factory()->create(['code' => 'eis']);

    $result = app(EstimateFieldsCheck::class)->run($platform, app(HealthBaselineReader::class)->read(), now()->subDay(), now());

    expect($result->verdict)->toBe(PlatformCheckVerdict::NotEnoughData);
});
