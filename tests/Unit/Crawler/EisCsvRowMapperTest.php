<?php

declare(strict_types=1);

use App\Dto\Procurement\ProcurementSnapshotData;
use App\Enums\Procurement\EisProcedureType;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Platform\Registry;
use App\Services\Procurement\Eis\EisCsvRowMapper;

/**
 * `Crawler` без обращения к БД (Unit-тест, без `RefreshDatabase`) — реестр привязывается
 * как in-memory relation, `EisCsvRowMapper` читает только `$crawler->registry->code`.
 */
function eisCrawlerForRegistryCode(string $registryCode): Crawler
{
    $registry = Registry::factory()->make(['code' => $registryCode]);
    $crawler = Crawler::factory()->make(['registry_id' => 1]);
    $crawler->setRelation('registry', $registry);

    return $crawler;
}

/**
 * Базовая строка CSV-экспорта ЕИС со всеми колонками, которые читает `EisCsvRowMapper`.
 * Тесты подменяют отдельные ключи под сценарий — форма ассоциатива соответствует реальным
 * заголовкам колонок (см. `EisCsvRowMapper` константы `*_COLUMN`).
 *
 * @return array<string, string>
 */
function eisCsvRow(array $overrides = []): array
{
    return array_merge([
        'Реестровый номер закупки' => '0173200001234000001',
        'Способ определения поставщика' => 'Электронный аукцион',
        'Наименование закупки' => 'Поставка оргтехники',
        'Этап закупки' => 'Размещение извещения',
        'Идентификационный код закупки (ИКЗ)' => '243773601234512345100100001',
        'НМЦК' => '1 250 000,50',
        'Валюта' => 'RUB',
        'ОКПД2' => '26.20.11.110, 26.20.15.000',
        'Дата размещения' => '01.03.2026',
        'Дата обновления' => '02.03.2026 10:15:00',
        'Начало подачи заявок' => '01.03.2026',
        'Окончание подачи заявок' => '15.03.2026',
        'Наименование заказчика' => 'ГБУ «Тестовое учреждение»',
    ], $overrides);
}

test('44-ФЗ способ закупки из закрытой карты маппится в procedure_type enum', function () {
    $crawler = eisCrawlerForRegistryCode('fz44');

    $snapshot = app(EisCsvRowMapper::class)->map(row: eisCsvRow(), crawler: $crawler);

    expect($snapshot)->toBeInstanceOf(ProcurementSnapshotData::class)
        ->and($snapshot->procedureType)->toBe(EisProcedureType::ElectronicAuction->value)
        ->and($snapshot->procedureTypeRaw)->toBe('Электронный аукцион');
});

test('223-ФЗ способ закупки остаётся свободным текстом без procedure_type', function () {
    $crawler = eisCrawlerForRegistryCode('fz223');

    $snapshot = app(EisCsvRowMapper::class)->map(
        row: eisCsvRow(['Способ определения поставщика' => 'Закупка у единственного поставщика по 223-ФЗ (внутренний регламент)']),
        crawler: $crawler,
    );

    expect($snapshot->procedureType)->toBeNull()
        ->and($snapshot->procedureTypeRaw)->toBe('Закупка у единственного поставщика по 223-ФЗ (внутренний регламент)');
});

test('44-ФЗ способ, которого нет в закрытой карте, тоже уходит в procedure_type_raw без исключения', function () {
    $crawler = eisCrawlerForRegistryCode('fz44');

    $snapshot = app(EisCsvRowMapper::class)->map(
        row: eisCsvRow(['Способ определения поставщика' => 'Новый неизвестный способ']),
        crawler: $crawler,
    );

    expect($snapshot->procedureType)->toBeNull()
        ->and($snapshot->procedureTypeRaw)->toBe('Новый неизвестный способ');
});

test('НМЦК с разделителем тысяч и запятой нормализуется в numeric-строку с точкой', function () {
    $crawler = eisCrawlerForRegistryCode('fz44');

    $snapshot = app(EisCsvRowMapper::class)->map(row: eisCsvRow(), crawler: $crawler);

    expect($snapshot->initialPrice)->toBe('1250000.50');
});

test('даты размещения/обновления/подачи заявок парсятся в Carbon', function () {
    $crawler = eisCrawlerForRegistryCode('fz44');

    $snapshot = app(EisCsvRowMapper::class)->map(row: eisCsvRow(), crawler: $crawler);

    expect($snapshot->publishedAt)->toBeInstanceOf(Carbon\Carbon::class)
        ->and($snapshot->publishedAt->toDateString())->toBe('2026-03-01')
        ->and($snapshot->sourceUpdatedAt->format('Y-m-d H:i:s'))->toBe('2026-03-02 10:15:00')
        ->and($snapshot->biddingStartsAt->toDateString())->toBe('2026-03-01')
        ->and($snapshot->biddingEndsAt->toDateString())->toBe('2026-03-15');
});

test('ОКПД2 разбирается на первый код и полный список', function () {
    $crawler = eisCrawlerForRegistryCode('fz44');

    $snapshot = app(EisCsvRowMapper::class)->map(row: eisCsvRow(), crawler: $crawler);

    expect($snapshot->okpd2Code)->toBe('26.20.11.110')
        ->and($snapshot->okpd2Codes)->toBe(['26.20.11.110', '26.20.15.000']);
});

test('пустой registry_number приводит к skip (map возвращает null)', function () {
    $crawler = eisCrawlerForRegistryCode('fz44');

    $snapshot = app(EisCsvRowMapper::class)->map(row: eisCsvRow(['Реестровый номер закупки' => '']), crawler: $crawler);

    expect($snapshot)->toBeNull();
});

test('отсутствующий registry_number приводит к skip (map возвращает null)', function () {
    $crawler = eisCrawlerForRegistryCode('fz44');

    $row = eisCsvRow();
    unset($row['Реестровый номер закупки']);

    $snapshot = app(EisCsvRowMapper::class)->map(row: $row, crawler: $crawler);

    expect($snapshot)->toBeNull();
});

test('непарсящаяся дата размещения приводит к skip (map возвращает null)', function () {
    $crawler = eisCrawlerForRegistryCode('fz44');

    $snapshot = app(EisCsvRowMapper::class)->map(row: eisCsvRow(['Дата размещения' => 'не дата']), crawler: $crawler);

    expect($snapshot)->toBeNull();
});

test('отсутствующая дата обновления приводит к skip (map возвращает null)', function () {
    $crawler = eisCrawlerForRegistryCode('fz44');

    $row = eisCsvRow();
    unset($row['Дата обновления']);

    $snapshot = app(EisCsvRowMapper::class)->map(row: $row, crawler: $crawler);

    expect($snapshot)->toBeNull();
});
