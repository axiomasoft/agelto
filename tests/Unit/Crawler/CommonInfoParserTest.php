<?php

declare(strict_types=1);

use App\Dto\Procurement\Details\CommonInfoData;
use App\Services\Procurement\Eis\Card\CommonInfoParser;

function commonInfoFixtureHtml(): string
{
    return file_get_contents(base_path('tests/Fixtures/Crawler/Card/common-info.html'));
}

test('parse разбирает ИКЗ на 8 разрядов по вычисленной раскладке', function () {
    $data = app(CommonInfoParser::class)->parse(commonInfoFixtureHtml());

    expect($data)->toBeInstanceOf(CommonInfoData::class)
        ->and($data->ikzYear)->toBe(26)
        ->and($data->ikzCustomerCode)->toBe('35000000000000000000')
        ->and($data->ikzPlanNumber)->toBe('0000')
        ->and($data->ikzPlangraphNumber)->toBe('012')
        ->and($data->ikzOkpdClass)->toBe('34')
        ->and($data->ikzOkpdSubclass)->toBe('5')
        ->and($data->ikzOkpdGroup)->toBe('6')
        ->and($data->ikzKvr)->toBe('789');
});

test('parse возвращает 2 строки финансирования без итоговой строки «Всего»', function () {
    $data = app(CommonInfoParser::class)->parse(commonInfoFixtureHtml());

    expect($data->financingRows)->toHaveCount(2);

    expect($data->financingRows[0]->fiscalYear)->toBe(2026)
        ->and($data->financingRows[0]->amount)->toBe('500000.00')
        ->and($data->financingRows[1]->fiscalYear)->toBe(2027)
        ->and($data->financingRows[1]->amount)->toBe('1000000.00');

    expect(collect($data->financingRows)->pluck('fiscalYear'))->not->toContain('Всего');
});

test('parse разбирает позицию объекта закупки с кодами ОКПД2 и КТРУ', function () {
    $data = app(CommonInfoParser::class)->parse(commonInfoFixtureHtml());

    expect($data->items)->toHaveCount(1);

    $item = $data->items[0];

    expect($item->positionNumber)->toBe(1)
        ->and($item->name)->toBe('Работы по капитальному ремонту кровли')
        ->and($item->classifierCodeOkpd2)->toBe('41.20.40')
        ->and($item->classifierCodeKtru)->toBe('41.20.40.000-00000023')
        ->and($item->unit)->toBe('усл. ед.')
        ->and($item->quantity)->toBe('1')
        ->and($item->unitPrice)->toBe('1500000.00')
        ->and($item->totalCost)->toBe('1500000.00');
});

test('parse проставляет детерминированные fallback-номера позициям с пустым/нечисловым «№»', function () {
    $html = commonInfoFixtureHtml();

    // Заменяем единственную строку таблицы объекта закупки на 3 строки: первая — с валидным «№»,
    // вторая — с пустой ячейкой «№», третья — с нечисловым «№» (брак вёрстки источника).
    $html = preg_replace(
        '#<tbody>\s*<tr>\s*<td>1</td>.*?</tr>\s*</tbody>(\s*</table>\s*</section>\s*</div>)#s',
        <<<'HTML'
        <tbody>
          <tr>
            <td>1</td>
            <td>41.20.40 / 41.20.40.000-00000023</td>
            <td>Позиция первая</td>
            <td>усл. ед.</td>
            <td>1</td>
            <td>1 500 000,00</td>
            <td>1 500 000,00</td>
          </tr>
          <tr>
            <td></td>
            <td>41.20.41 / 41.20.41.000-00000024</td>
            <td>Позиция вторая (без номера)</td>
            <td>усл. ед.</td>
            <td>2</td>
            <td>200 000,00</td>
            <td>400 000,00</td>
          </tr>
          <tr>
            <td>—</td>
            <td>41.20.42 / 41.20.42.000-00000025</td>
            <td>Позиция третья (нечисловой номер)</td>
            <td>усл. ед.</td>
            <td>3</td>
            <td>300 000,00</td>
            <td>900 000,00</td>
          </tr>
        </tbody>$1
        HTML,
        $html,
    );

    $data = app(CommonInfoParser::class)->parse($html);

    expect($data->items)->toHaveCount(3);

    // Первая строка — реальный «№» из вёрстки.
    expect($data->items[0]->positionNumber)->toBe(1)
        ->and($data->items[0]->name)->toBe('Позиция первая');

    // Вторая и третья — fallback $index + 1 по позиции строки в tbody (индексы 1 и 2 → 2 и 3),
    // без падения и без схлопывания в один и тот же номер.
    expect($data->items[1]->positionNumber)->toBe(2)
        ->and($data->items[1]->name)->toBe('Позиция вторая (без номера)')
        ->and($data->items[2]->positionNumber)->toBe(3)
        ->and($data->items[2]->name)->toBe('Позиция третья (нечисловой номер)');

    expect($data->items[1]->positionNumber)->not->toBe($data->items[2]->positionNumber);
});

test('parse возвращает classifierScheme unknown при отсутствии обоих кодов классификатора', function () {
    $html = commonInfoFixtureHtml();

    // Ячейка «Код ОКПД2/КТРУ» пуста — оба кода отсутствуют, это не «ktru», а именно unknown.
    $html = preg_replace(
        '#<td>41\.20\.40 / 41\.20\.40\.000-00000023</td>#',
        '<td></td>',
        $html,
    );

    $data = app(CommonInfoParser::class)->parse($html);

    expect($data->items)->toHaveCount(1);

    $item = $data->items[0];

    expect($item->classifierScheme)->toBe('unknown')
        ->and($item->classifierCodeOkpd2)->toBeNull()
        ->and($item->classifierCodeKtru)->toBeNull();
});

test('parse не падает и возвращает null-поля ИКЗ при отсутствии блока price-info-block', function () {
    $html = commonInfoFixtureHtml();

    // Вырезаем весь блок price-info-block (содержит ИКЗ) — вёрстка без него встречается.
    $html = preg_replace(
        '#<section class="price-info-block">.*?</section>#s',
        '',
        $html,
    );

    $data = app(CommonInfoParser::class)->parse($html);

    expect($data->ikzYear)->toBeNull()
        ->and($data->ikzCustomerCode)->toBeNull()
        ->and($data->ikzPlanNumber)->toBeNull()
        ->and($data->ikzPlangraphNumber)->toBeNull()
        ->and($data->ikzOkpdClass)->toBeNull()
        ->and($data->ikzOkpdSubclass)->toBeNull()
        ->and($data->ikzOkpdGroup)->toBeNull()
        ->and($data->ikzKvr)->toBeNull();
});
