<?php

declare(strict_types=1);

use App\Dto\Organization\OrganizationIdentifierData;
use App\Dto\Procurement\Details\OrganizationData;

test('пустой или слишком длинный внешний идентификатор отвергается до записи', function (string $platformCode, string $type, string $value) {
    expect(fn () => new OrganizationIdentifierData(platformCode: $platformCode, type: $type, value: $value))
        ->toThrow(InvalidArgumentException::class);
})->with([
    ['', 'organization', '001'],
    ['market-a', ' ', '001'],
    ['market-a', 'organization', ''],
    [str_repeat('a', 65), 'organization', '001'],
    ['market-a', str_repeat('a', 65), '001'],
    ['market-a', 'organization', str_repeat('a', 256)],
]);

test('один snapshot не принимает разные значения одного типа идентификатора площадки', function () {
    expect(fn () => new OrganizationData(fullName: 'Заказчик', identifiers: [
        new OrganizationIdentifierData(platformCode: 'market-a', type: 'organization', value: '001'),
        new OrganizationIdentifierData(platformCode: 'market-a', type: 'organization', value: '002'),
    ]))->toThrow(InvalidArgumentException::class);
});
