<?php

declare(strict_types=1);

use App\Dto\Platforms\Eis\EisListingCursor;
use App\Dto\Platforms\Eis\EisListingFilter;
use App\Dto\Platforms\Eis\EisPolicy;
use App\Dto\Procurement\Acquisition\ListingRequest;
use App\Dto\Procurement\Acquisition\ProcurementSummary;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Enums\Platforms\Eis\EisProcedureType;
use App\Services\Platforms\Eis\Csv\EisSummaryParser;
use App\Services\Platforms\Eis\EisListingSource;
use Tests\Support\Platforms\Contract\RecordingOutbound;

/**
 * Базовая строка CSV-экспорта ЕИС со всеми колонками, которые читает `EisSummaryParser`.
 * Тесты подменяют отдельные ключи под сценарий — форма ассоциатива соответствует реальным
 * заголовкам колонок (см. `EisSummaryParser` константы `*_COLUMN`).
 *
 * @return array<string, string>
 */
function eisCsvRow(array $overrides = []): array
{
    return array_merge([
        'Реестровый номер закупки' => '0173200001234000001',
        'Способ определения поставщика (подрядчика, исполнителя), подрядной организации (размещения закупки)' => 'Электронный аукцион',
        'Наименование закупки' => 'Поставка оргтехники',
        'Этап закупки' => 'Подача заявок',
        'Идентификационный код закупки (ИКЗ)' => '243773601234512345100100001',
        'Начальная (максимальная) цена контракта' => '1 250 000,50',
        'Валюта' => 'RUB',
        'Классификация по ОКПД2' => '26.20.11.110, 26.20.15.000',
        'Дата размещения' => '01.03.2026',
        'Дата обновления' => '02.03.2026 10:15:00',
        'Начало подачи заявок' => '01.03.2026',
        'Окончание подачи заявок' => '15.03.2026',
        'Наименование Заказчика' => 'ГБУ «Тестовое учреждение»',
    ], $overrides);
}

test('44-ФЗ способ закупки из закрытой карты маппится в procedure_type enum', function () {

    $snapshot = app(EisSummaryParser::class)->parse(row: eisCsvRow());

    expect($snapshot)->toBeInstanceOf(ProcurementSummary::class)
        ->and($snapshot->facts->procedureType)->toBe(EisProcedureType::ElectronicAuction->value)
        ->and($snapshot->facts->procedureTypeRaw)->toBe('Электронный аукцион');
});

test('unknown procedure labels retain raw text while an unregistered registry fails closed', function () {

    $snapshot = app(EisSummaryParser::class)->parse(
        row: eisCsvRow(['Способ определения поставщика (подрядчика, исполнителя), подрядной организации (размещения закупки)' => 'Закупка у единственного поставщика по 223-ФЗ (внутренний регламент)'])
    );

    expect($snapshot->facts->procedureType)->toBeNull()
        ->and($snapshot->facts->procedureTypeRaw)->toBe('Закупка у единственного поставщика по 223-ФЗ (внутренний регламент)');
    $filter = new EisListingFilter(['123']);
    $cursor = new EisListingCursor('2026-03-01', 'publish', 0, 10000, $filter->fingerprint());
    $source = new EisListingSource(new EisPolicy(config()), new RecordingOutbound,
        new ProcessUuid('00000000-0000-4000-8000-000000000001'));
    expect(fn () => $source->plan(new ListingRequest('fz223', $filter, $cursor->envelope())))
        ->toThrow(InvalidArgumentException::class);
});

test('44-ФЗ способ, которого нет в закрытой карте, тоже уходит в procedure_type_raw без исключения', function () {

    $snapshot = app(EisSummaryParser::class)->parse(
        row: eisCsvRow(['Способ определения поставщика (подрядчика, исполнителя), подрядной организации (размещения закупки)' => 'Новый неизвестный способ'])
    );

    expect($snapshot->facts->procedureType)->toBeNull()
        ->and($snapshot->facts->procedureTypeRaw)->toBe('Новый неизвестный способ');
});

test('НМЦК с разделителем тысяч и запятой нормализуется в numeric-строку с точкой', function () {

    $snapshot = app(EisSummaryParser::class)->parse(row: eisCsvRow());

    expect($snapshot->initialPrice)->toBe('1250000.50');
});

test('даты размещения/обновления/подачи заявок сохраняются в immutable summary facts', function () {

    $snapshot = app(EisSummaryParser::class)->parse(row: eisCsvRow());

    expect($snapshot->publishedAt)->toBeInstanceOf(DateTimeImmutable::class)
        ->and($snapshot->publishedAt->format('Y-m-d'))->toBe('2026-03-01')
        ->and($snapshot->updatedAt->format('Y-m-d H:i:s'))->toBe('2026-03-02 07:15:00')
        ->and($snapshot->facts->biddingStartsAt->format('Y-m-d'))->toBe('2026-03-01')
        ->and($snapshot->facts->biddingEndsAt->format('Y-m-d'))->toBe('2026-03-15');
});

test('дата парсится без подстановки текущего времени (модификатор `!`)', function (string $rawDate, string $expected) {

    $snapshot = app(EisSummaryParser::class)->parse(row: eisCsvRow(['Дата обновления' => $rawDate]));

    expect($snapshot->updatedAt->format('Y-m-d H:i:s'))->toBe($expected);
})->with([
    'd.m.Y H:i:s — время сохраняется целиком' => ['02.03.2026 10:15:42', '2026-03-02 07:15:42'],
    'd.m.Y H:i — секунды нулевые, часы и минуты сохранены' => ['02.03.2026 10:15', '2026-03-02 07:15:00'],
    // Сутки источника: начало суток по МСК в UTC (см. `EisSummaryParser::parseSourceUpdatedAt`).
    'd.m.Y — начало суток источника' => ['02.03.2026', '2026-03-01 21:00:00'],
]);

test('суточная точность даты обновления передаётся явно, а не выводится из полуночи', function () {

    $dayPrecision = app(EisSummaryParser::class)->parse(row: eisCsvRow(['Дата обновления' => '02.03.2026']));
    $timestampPrecision = app(EisSummaryParser::class)->parse(row: eisCsvRow(['Дата обновления' => '02.03.2026 00:00:00']));

    expect($dayPrecision->updatedAtDayPrecision)->toBeTrue()
        ->and($timestampPrecision->updatedAtDayPrecision)->toBeFalse();
});

test('пустой registry_number вызывает ошибку обязательного поля', function () {

    expect(fn () => app(EisSummaryParser::class)->parse(row: eisCsvRow(['Реестровый номер закупки' => ''])))->toThrow(UnexpectedValueException::class);
});

test('отсутствующий registry_number вызывает ошибку обязательного поля', function () {

    $row = eisCsvRow();
    unset($row['Реестровый номер закупки']);

    expect(fn () => app(EisSummaryParser::class)->parse(row: $row))->toThrow(UnexpectedValueException::class);
});

test('непарсящаяся дата размещения вызывает ошибку обязательного поля', function () {

    expect(fn () => app(EisSummaryParser::class)->parse(row: eisCsvRow(['Дата размещения' => 'не дата'])))->toThrow(UnexpectedValueException::class);
});

test('отсутствующая дата обновления вызывает ошибку обязательного поля', function () {

    $row = eisCsvRow();
    unset($row['Дата обновления']);

    expect(fn () => app(EisSummaryParser::class)->parse(row: $row))->toThrow(UnexpectedValueException::class);
});

test('префикс «№» перед реестровым номером снимается (живая проба — 500/500 строк блока несут его)', function () {

    $snapshot = app(EisSummaryParser::class)->parse(
        row: eisCsvRow(['Реестровый номер закупки' => '№0173200001234000001'])
    );

    expect($snapshot->identity->value->value)->toBe('0173200001234000001');
});

/**
 * Реальная фикстура `eis-block-win1251.csv` — тело живой пробы (4 строки блока архивного
 * профиля fz44, `findings/csv-headers.md`), парсится ТЕМ ЖЕ путём, что `CsvSourceMethod::
 * parseCsv()` (win1251→UTF-8, `str_getcsv` по `;`/`"`). Проверяет именно те величины, которые до
 * молча уходили в `null` при формально успешном прогоне: `initial_price`, `attributes.
 * customer_name`, `procedure_type_raw`. Колонка «Классификация по ОКПД2» в этом блоке (архив,
 * завершённые закупки) пуста у ВСЕХ 500 строк пробы — источник целиком не заполняет
 * классификацию на этом срезе данных (`findings/csv-headers.md`); маппер эту колонку больше
 * не читает — второй, независимый от , замер пустоты остаётся зафиксированным
 * здесь фактом, на котором стоит решение о сносе ветки.
 *
 * @return list<array<string, string>>
 */
function eisRealFixtureRows(): array
{
    $body = file_get_contents(base_path('tests/Fixtures/Platforms/Eis/Csv/eis-block-win1251.csv'));
    $utf8 = mb_convert_encoding($body, 'UTF-8', 'Windows-1251');
    $lines = preg_split('/\r\n|\r|\n/', trim($utf8));
    $header = str_getcsv($lines[0], ';', '"');

    return array_map(
        static fn (string $line): array => array_combine($header, str_getcsv($line, ';', '"')),
        array_slice($lines, offset: 1),
    );
}

test('реальная фикстура: initial_price/customer_name/procedure_type_raw непусты, registry_number без префикса «№»', function () {
    $rows = eisRealFixtureRows();

    foreach ($rows as $row) {
        $snapshot = app(EisSummaryParser::class)->parse(row: $row);

        expect($snapshot)->not->toBeNull()
            ->and($snapshot->identity->value->value)->not->toStartWith('№')
            ->and($snapshot->initialPrice)->not->toBeNull()
            ->and($snapshot->facts->procedureTypeRaw)->not->toBeNull()
            ->and($snapshot->facts->customerName)->not->toBeNull();
    }
});
