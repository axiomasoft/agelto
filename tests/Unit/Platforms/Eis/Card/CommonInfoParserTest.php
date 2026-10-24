<?php

declare(strict_types=1);

use App\Dto\Procurement\Details\CommonInfoData;
use App\Dto\Procurement\Details\CommonInfoSnapshot;
use App\Enums\Procurement\DetailSnapshotStatus;
use App\Services\Platforms\Eis\Card\CommonInfoParser;

function commonInfoFixtureHtml(): string
{
    return file_get_contents(base_path('tests/Fixtures/Platforms/Eis/Card/common-info.html'));
}

test('parse разбирает ИКЗ на 8 разрядов по вычисленной раскладке (реальная вкладка common-info, P4.14)', function () {
    $data = app(CommonInfoParser::class)->parse(commonInfoFixtureHtml())->commonInfo;

    expect($data)->toBeInstanceOf(CommonInfoData::class)
        ->and($data->ikzYear)->toBe(25)
        ->and($data->ikzCustomerCode)->toBe('24100013650410101001')
        ->and($data->ikzPlanNumber)->toBe('0002')
        ->and($data->ikzPlangraphNumber)->toBe('001')
        ->and($data->ikzOkpdClass)->toBe('18')
        ->and($data->ikzOkpdSubclass)->toBe('1')
        ->and($data->ikzOkpdGroup)->toBe('1')
        ->and($data->ikzKvr)->toBe('244');
});

test('parse разбирает площадку и контактное лицо из раздельных section (не общий dd со ссылкой)', function () {
    $data = app(CommonInfoParser::class)->parse(commonInfoFixtureHtml())->commonInfo;

    expect($data->etpName)->toBe('АО «Сбербанк-АСТ»')
        ->and($data->etpUrl)->toBe('http://www.sberbank-ast.ru')
        ->and($data->contactPersonName)->toBe('Иванов И. И.')
        ->and($data->contactPersonEmail)->toBe('responsible-official@example.invalid');
});

test('parse транспонирует таблицу «год — колонка» в строки финансирования, без «Всего» и «Последующих годов»', function () {
    $data = app(CommonInfoParser::class)->parse(commonInfoFixtureHtml())->commonInfo;

    expect($data->financingRows)->toHaveCount(3);

    expect($data->financingRows[0]->fiscalYear)->toBe(2025)
        ->and($data->financingRows[0]->amount)->toBe('597960.00')
        ->and($data->financingRows[1]->fiscalYear)->toBe(2026)
        ->and($data->financingRows[1]->amount)->toBe('0.00')
        ->and($data->financingRows[2]->fiscalYear)->toBe(2027)
        ->and($data->financingRows[2]->amount)->toBe('0.00');

    expect(collect($data->financingRows)->pluck('fiscalYear'))->not->toContain('Всего');
});

test('parse разбирает позицию объекта закупки из #positionKTRU с кодом ОКПД2', function () {
    $data = app(CommonInfoParser::class)->parse(commonInfoFixtureHtml())->commonInfo;

    expect($data->items)->toHaveCount(1);

    $item = $data->items[0];

    // Первая ячейка строки на реальной вкладке ВСЕГДА пуста (служебная, не «№») — позиция
    // всегда идёт через fallback $index + 1, это штатный путь для текущей вёрстки.
    expect($item->positionNumber)->toBe(1)
        ->and($item->name)->toContain('оказание услуг по информационному сопровождению')
        ->and($item->classifierScheme)->toBe('okpd2')
        ->and($item->classifierCodeOkpd2)->toBe('18.11.10.000')
        ->and($item->classifierCodeKtru)->toBeNull()
        ->and($item->unit)->toBe('Квадратный сантиметр')
        ->and($item->quantity)->toBe('10872.00')
        ->and($item->unitPrice)->toBe('55.00')
        ->and($item->totalCost)->toBe('597960.00');
});

test('parse распознаёт КТРУ по дефис-суффиксу, ОКПД2 — префиксом до дефиса', function () {
    // На снятой карточке единственная позиция несёт голый код ОКПД2 (без дефиса) — дефис-формат
    // КТРУ (`##.##.##.###-#####`, database/factories/Reference/KtruFactory.php) в артефактах
    // P4.13 не встретился ни разу; точечная порча кода в реальной фикстуре — единственный способ
    // проверить ветку без выдумывания отдельной вкладки.
    $html = str_replace('18.11.10.000', '18.11.10.000-00000001', commonInfoFixtureHtml());

    $data = app(CommonInfoParser::class)->parse($html)->commonInfo;

    expect($data->items)->toHaveCount(1);

    $item = $data->items[0];

    expect($item->classifierCodeOkpd2)->toBe('18.11.10.000')
        ->and($item->classifierCodeKtru)->toBe('18.11.10.000-00000001');
    // classifierScheme() отдаёт приоритет ОКПД2-ветке всегда, когда он не null (до этого item'а
    // не тронуто — вне Scope P4.14, «приведение к вёрстке», а не правка приоритета схемы).
});

test('parse проставляет детерминированные fallback-номера позициям вне зависимости от содержимого первой ячейки', function () {
    $html = commonInfoFixtureHtml();

    $html = preg_replace(
        '#<tbody class="tableBlock__body">.*?</tbody>#s',
        <<<'HTML'
        <tbody class="tableBlock__body">
          <tr class="tableBlock__row">
            <td class="tableBlock__col">1</td>
            <td class="tableBlock__col">18.11.10.000</td>
            <td class="tableBlock__col">Позиция первая</td>
            <td class="tableBlock__col">усл. ед.</td>
            <td class="tableBlock__col">1</td>
            <td class="tableBlock__col tableBlock__col_right">1 500 000,00</td>
            <td class="tableBlock__col tableBlock__col_right">1 500 000,00</td>
          </tr>
          <tr class="tableBlock__row">
            <td class="tableBlock__col"></td>
            <td class="tableBlock__col">18.11.20.000</td>
            <td class="tableBlock__col">Позиция вторая (пустая служебная ячейка)</td>
            <td class="tableBlock__col">усл. ед.</td>
            <td class="tableBlock__col">2</td>
            <td class="tableBlock__col tableBlock__col_right">200 000,00</td>
            <td class="tableBlock__col tableBlock__col_right">400 000,00</td>
          </tr>
          <tr class="tableBlock__row">
            <td class="tableBlock__col">—</td>
            <td class="tableBlock__col">18.11.30.000</td>
            <td class="tableBlock__col">Позиция третья (нечисловая служебная ячейка)</td>
            <td class="tableBlock__col">усл. ед.</td>
            <td class="tableBlock__col">3</td>
            <td class="tableBlock__col tableBlock__col_right">300 000,00</td>
            <td class="tableBlock__col tableBlock__col_right">900 000,00</td>
          </tr>
        </tbody>
        HTML,
        $html,
    );

    $data = app(CommonInfoParser::class)->parse($html)->commonInfo;

    expect($data->items)->toHaveCount(3);

    // Первая строка — цифра «1» в первой ячейке (на реальной вкладке этого не бывает, но код
    // поддерживает и такой случай).
    expect($data->items[0]->positionNumber)->toBe(1)
        ->and($data->items[0]->name)->toBe('Позиция первая');

    // Вторая и третья — fallback $index + 1 по позиции строки в tbody (индексы 1 и 2 → 2 и 3),
    // без падения и без схлопывания в один и тот же номер.
    expect($data->items[1]->positionNumber)->toBe(2)
        ->and($data->items[1]->name)->toBe('Позиция вторая (пустая служебная ячейка)')
        ->and($data->items[2]->positionNumber)->toBe(3)
        ->and($data->items[2]->name)->toBe('Позиция третья (нечисловая служебная ячейка)');

    expect($data->items[1]->positionNumber)->not->toBe($data->items[2]->positionNumber);
});

test('parse возвращает classifierScheme unknown при пустой ячейке кода классификатора', function () {
    // Единственное вхождение кода на реальной вкладке — вычищаем его, оставляя пустую ячейку.
    $html = str_replace('18.11.10.000', '', commonInfoFixtureHtml());

    $data = app(CommonInfoParser::class)->parse($html)->commonInfo;

    expect($data->items)->toHaveCount(1);

    $item = $data->items[0];

    expect($item->classifierScheme)->toBe('unknown')
        ->and($item->classifierCodeOkpd2)->toBeNull()
        ->and($item->classifierCodeKtru)->toBeNull();
});

test('parse не падает и возвращает null-поля ИКЗ при отсутствии соответствующего section', function () {
    $html = commonInfoFixtureHtml();

    // Вырезаем ИКЗ-section целиком — вёрстка без него встречается (блок опционален).
    $html = preg_replace(
        '#<section class="blockInfo__section section">\s*<span class="section__title">Идентификационный код закупки \(ИКЗ\)</span>.*?</section>#s',
        '',
        $html,
    );

    $data = app(CommonInfoParser::class)->parse($html)->commonInfo;

    expect($data->ikzYear)->toBeNull()
        ->and($data->ikzCustomerCode)->toBeNull()
        ->and($data->ikzPlanNumber)->toBeNull()
        ->and($data->ikzPlangraphNumber)->toBeNull()
        ->and($data->ikzOkpdClass)->toBeNull()
        ->and($data->ikzOkpdSubclass)->toBeNull()
        ->and($data->ikzOkpdGroup)->toBeNull()
        ->and($data->ikzKvr)->toBeNull();
});

test('parse помечает snapshot неполным, если нет контейнера позиций', function () {
    $snapshot = app(CommonInfoParser::class)->parse('<html><body><section class="blockInfo__section"></section></body></html>');

    expect($snapshot->status)->toBe(DetailSnapshotStatus::Incomplete)
        ->and($snapshot->authorizesCommonInfoReconciliation())->toBeFalse();
});

test('parse помечает snapshot неполным без подтверждённого financing container', function () {
    $snapshot = app(CommonInfoParser::class)->parse(
        '<section class="blockInfo__section"></section><div id="positionKTRU"><table class="tableBlock"><tbody></tbody></table></div>',
    );

    expect($snapshot->status)->toBe(DetailSnapshotStatus::Incomplete);
});

test('добавление недоступных parties сохраняет независимый status common-info', function () {
    $complete = app(CommonInfoParser::class)->parse(commonInfoFixtureHtml())
        ->withParties(parties: [], partiesComplete: false);
    $snapshot = app(CommonInfoParser::class)->parse('<html><body><section class="blockInfo__section"></section></body></html>');
    $failed = new CommonInfoSnapshot(
        status: DetailSnapshotStatus::Failed,
        commonInfo: $snapshot->commonInfo,
    );
    $failed = $failed->withParties(parties: [], partiesComplete: false);

    expect($complete->status)->toBe(DetailSnapshotStatus::Complete)
        ->and($complete->authorizesCommonInfoReconciliation())->toBeTrue()
        ->and($complete->authorizesPartiesReconciliation())->toBeFalse()
        ->and($failed->status)->toBe(DetailSnapshotStatus::Failed)
        ->and($failed->authorizesCommonInfoReconciliation())->toBeFalse()
        ->and($failed->authorizesPartiesReconciliation())->toBeFalse();
});

function commonInfoWithExpandedCustomerRows(): string
{
    // Structure observed on live January cards: malformed hidden rows are repaired by the DOM parser.
    $expanded = <<<'HTML'
    <tr class="truInfo_1 tableBlock__row" style="display: none">
    <tr class="truInfo_1 tableBlock__row" style="display: none">
      <td class="tableBlock__col pt-0"></td>
      <tr class="truInfo_1" style="display: none">
        <td colspan="4">Тестовый заказчик</td><td>1</td><td colspan="2"></td>
      </tr>
      <td class="tableBlock__col pt-0"></td>
    </tr>
    HTML;

    return preg_replace_callback(
        '#(<div[^>]+id="positionKTRU".*?<tbody[^>]*>)(.*?)(</tbody>)#s',
        static fn (array $match): string => $match[1].$match[2].$expanded.$match[3],
        commonInfoFixtureHtml(),
        limit: 1,
    );
}

test('служебные раскрываемые строки заказчика не делают полную карточку неполной', function () {
    $snapshot = app(CommonInfoParser::class)->parse(commonInfoWithExpandedCustomerRows());

    expect($snapshot->status)->toBe(DetailSnapshotStatus::Complete)
        ->and($snapshot->commonInfo->items)->toHaveCount(1)
        ->and($snapshot->commonInfo->items[0]->positionNumber)->toBe(1)
        ->and($snapshot->commonInfo->financingRows)->toHaveCount(3);
});

test('служебные строки не сдвигают номера следующих позиций без явного номера', function () {
    preg_match('#<tbody class="tableBlock__body">\s*(<tr.*?</tr>)#s', commonInfoFixtureHtml(), $match);
    $second = str_replace('18.11.10.000', '43.21.10.290', $match[1]);
    $html = preg_replace_callback(
        '#(<div[^>]+id="positionKTRU".*?<tbody[^>]*>)(.*?)(</tbody>)#s',
        static fn (array $match): string => $match[1].$match[2].$second.$match[3],
        commonInfoWithExpandedCustomerRows(),
        limit: 1,
    );
    $html = str_replace('Итого (1 записей)', 'Итого (2 записей)', $html);

    $snapshot = app(CommonInfoParser::class)->parse($html);

    expect($snapshot->status)->toBe(DetailSnapshotStatus::Complete)
        ->and(array_column($snapshot->commonInfo->items, 'positionNumber'))->toBe([1, 2]);
});

test('заявленный источником итог не позволяет принять пропущенную позицию за полный snapshot', function () {
    $html = str_replace('Итого (1 записей)', 'Итого (2 записей)', commonInfoFixtureHtml());

    expect(app(CommonInfoParser::class)->parse($html)->status)->toBe(DetailSnapshotStatus::Incomplete);
});

test('повреждённая строка позиции не становится допустимой служебной строкой', function () {
    $html = preg_replace_callback(
        '#(<div[^>]+id="positionKTRU".*?<tbody[^>]*>)(.*?)(</tbody>)#s',
        static fn (array $match): string => $match[1].preg_replace('#<td[^>]*>.*?</td>#s', '', $match[2], limit: 1).$match[3],
        commonInfoFixtureHtml(),
        limit: 1,
    );

    expect(app(CommonInfoParser::class)->parse($html)->status)->toBe(DetailSnapshotStatus::Incomplete);
});
