<?php

declare(strict_types=1);

use App\Filament\Platforms\Eis\EisCrawlerPlatformForm;
use App\Models\Platforms\Eis\EisDetails;
use App\Models\Procurement\Procurement;
use Database\Factories\Platforms\Eis\EisDetailsFactory;

test('перенесённая extension factory сохраняет модель таблицу и связь с закупкой', function (): void {
    $procurement = Procurement::factory()->create();
    $factory = EisDetails::factory();
    $details = $factory->create(['procurement_id' => $procurement->id, 'etp_name' => 'Fixture ETP']);

    expect($factory)->toBeInstanceOf(EisDetailsFactory::class)
        ->and($details)->toBeInstanceOf(EisDetails::class)
        ->and($details->getTable())->toBe('prc_eis_details')
        ->and($details->getKeyName())->toBe('procurement_id')
        ->and(EisDetails::query()->findOrFail($procurement->id)->etp_name)->toBe('Fixture ETP');
});

test('перенесённая platform form резолвится контейнером вне Filament resource discovery', function (): void {
    expect(app(EisCrawlerPlatformForm::class))->toBeInstanceOf(EisCrawlerPlatformForm::class);
});
