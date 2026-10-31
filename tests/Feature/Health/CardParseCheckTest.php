<?php

declare(strict_types=1);

use App\Enums\Procurement\Health\PlatformCheckVerdict;
use App\Enums\Procurement\Network\AdapterVerdict;
use App\Models\Procurement\Platform\Platform;
use App\Models\Procurement\Platform\Registry;
use App\Services\Procurement\Health\Checks\CardParseCheck;
use App\Services\Procurement\Health\HealthBaselineReader;
use Carbon\CarbonImmutable;
use Tests\Support\ProcessCore\AdapterObservationFixtures as F;

test('проверка разбора карточек подтверждает здоровый штатный исход', function (): void {
    $platform = Platform::factory()->create(['code' => 'eis']);
    $registry = Registry::factory()->create(['platform_id' => $platform->id]);
    F::detail($platform->code, AdapterVerdict::Accepted);

    $result = app(CardParseCheck::class)->run($platform, app(HealthBaselineReader::class)->read(), now()->subDay(), now());

    expect($result->verdict)->toBe(PlatformCheckVerdict::Ok);
});

test('проверка разбора карточек считает пропавший якорь поломкой', function (): void {
    $platform = Platform::factory()->create(['code' => 'eis']);
    $registry = Registry::factory()->create(['platform_id' => $platform->id]);
    // Missing critical anchors are already assessed by the registered adapter.
    F::detail($platform->code, AdapterVerdict::ContractFailure);

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

    foreach ($baseline->cardTabs as $tab => $description) {
        if (($description['status'] ?? null) === 'known_broken') {
            continue;
        }

        $keyFields = $description['key_fields'] ?? [];
        expect($keyFields)->not->toBeEmpty();
    }

    // Empty required fields yield a rejected validation fact, never a successful detail flag.
    F::detail($platform->code, AdapterVerdict::ContractFailure);

    $result = app(CardParseCheck::class)->run($platform, $baseline, now()->subDay(), now());

    expect($result->verdict)->toBe(PlatformCheckVerdict::Broken);
});

test('passive card health excludes stale validation and facts of another platform', function (): void {
    $platform = Platform::factory()->create(['code' => 'eis']);
    F::detail('eis', AdapterVerdict::Accepted, stale: true);
    F::detail('other_fixture', AdapterVerdict::Accepted);

    $result = app(CardParseCheck::class)->run($platform, app(HealthBaselineReader::class)->read(), now()->subDay(), now());
    expect($result->verdict)->toBe(PlatformCheckVerdict::NotEnoughData)->and($result->sampleSize)->toBe(0);
});

test('passive card health preserves microsecond window boundaries', function (): void {
    $platform = Platform::factory()->create(['code' => 'eis']);
    $at = new CarbonImmutable('2026-10-31T12:00:00.500000Z');
    F::detail('eis', AdapterVerdict::Accepted, occurredAt: $at);
    $check = app(CardParseCheck::class);
    $baseline = app(HealthBaselineReader::class)->read();

    expect($check->run($platform, $baseline, $at->subMicrosecond(), $at->addMicrosecond())->sampleSize)->toBe(1)
        ->and($check->run($platform, $baseline, $at->addMicrosecond(), $at->addSecond())->sampleSize)->toBe(0);
});
