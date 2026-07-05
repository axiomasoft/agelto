<?php

declare(strict_types=1);

use App\Dto\Crawler\Details\CommonInfoData;
use App\Services\Crawler\Eis\Card\CommonInfoParser;

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
