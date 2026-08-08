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

test('проверка полей смет анализирует только последние min sample записей', function (): void {
    $platform = Platform::factory()->create(['code' => 'eis']);

    foreach (range(1, 20) as $minutesAgo) {
        $estimate = Estimate::factory()->create([
            'platform_id' => $platform->id,
            'validation_status' => EstimateValidationStatus::Valid->value,
            'updated_at' => now()->subMinutes($minutesAgo + 1),
        ]);
        Item::factory()->create(['estimate_id' => $estimate->id]);
    }

    $latestEstimate = Estimate::factory()->create([
        'platform_id' => $platform->id,
        'validation_status' => EstimateValidationStatus::Invalid->value,
        'updated_at' => now(),
    ]);
    Item::factory()->create([
        'estimate_id' => $latestEstimate->id,
        'code' => null,
        'unit' => null,
        'quantity' => null,
    ]);

    $result = app(EstimateFieldsCheck::class)->run(
        $platform,
        app(HealthBaselineReader::class)->read(),
        now()->subDay(),
        now(),
    );

    expect($result->verdict)->toBe(PlatformCheckVerdict::Broken)
        ->and($result->sampleSize)->toBe(1)
        ->and($result->measured['validation_bad'])->toBe(1);
});
