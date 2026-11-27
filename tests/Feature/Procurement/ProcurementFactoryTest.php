<?php

declare(strict_types=1);

use App\Models\Procurement\Platform\Platform;
use App\Models\Procurement\Platform\Registry;
use App\Models\Procurement\Procurement;

test('дефолтные закупки используют канонический EIS-реестр', function (): void {
    $procurements = Procurement::factory()->count(2)->create();
    $registry = Registry::query()->findOrFail($procurements->first()->getAttribute('registry_id'));

    expect($procurements->pluck('registry_id')->unique())->toHaveCount(1)
        ->and($registry->getAttribute('code'))->toBe('fz44')
        ->and(Platform::query()->findOrFail($registry->platform_id)->getAttribute('code'))->toBe('eis');
});

test('явный EIS fixture сохраняет разные реестры одной площадки', function (): void {
    $registries = collect(['test-fz44', 'test-fz223'])
        ->map(fn (string $code): Registry => Registry::factory()->eis()->create(['code' => $code]));

    expect($registries->pluck('platform_id')->unique())->toHaveCount(1)
        ->and($registries->pluck('code')->sort()->values()->all())->toBe(['test-fz223', 'test-fz44']);
});
