<?php

declare(strict_types=1);

use App\Enums\Procurement\AwardStatus;
use App\Enums\Procurement\BidStatus;
use App\Enums\Procurement\ProtocolType;
use App\Services\Procurement\Eis\Card\SupplierResultsParser;

function supplierResultsFixtureHtml(): string
{
    return file_get_contents(base_path('tests/Fixtures/Crawler/Card/supplier-results.html'));
}

/**
 * Диагноз P4.14: `div.supplier-results`/`dl`/`table.bids-table` (селекторы парсера, не тронуты —
 * Implementation Rules item'а п. 2/5) дают 0 совпадений на РЕАЛЬНОЙ вкладке supplier-results
 * (снятая карточка — закупка, признанная несостоявшейся, поля другие: «Наименование протокола
 * определения поставщика…», «Заказчик(и)…», «Основание признания торгов несостоявшимися»).
 * Фактуры для success-пути (протокол+заявки+победитель) нет ни на одной снятой карточке —
 * [UNVERIFIED] в Pending Work item'а, правка без фактуры запрещена.
 */
test('parse возвращает пустой результат на реальной вкладке supplier-results — закупка несостоявшаяся, старые селекторы не совпадают', function () {
    $data = app(SupplierResultsParser::class)->parse(supplierResultsFixtureHtml());

    expect($data->protocol)->toBeNull()
        ->and($data->bids)->toBe([])
        ->and($data->award)->toBeNull();
});

test('parse разбирает протокол, заявки и победителя (синтетическая раскладка, парсер не правился)', function () {
    // Синтетический HTML под ТЕКУЩИЙ (нетронутый) селектор парсера — проверяет внутреннюю логику
    // разбора протокола/заявок/победителя, а не соответствие реальной вкладке (её у парсера
    // сейчас нет для success-пути, см. тест выше и Pending Work item'а P4.14).
    $html = <<<'HTML'
    <html><body>
    <div class="supplier-results">
      <dl>
        <dt>Протокол</dt>
        <dd>Протокол подведения итогов №ИЗК1 от 15.07.2026</dd>
        <dt>Победитель</dt>
        <dd>ООО «Стройремонт», ИНН 7701234567, ОГРН 1027700123456</dd>
      </dl>
      <table class="bids-table">
        <tbody>
          <tr><td>1</td><td>ООО «Стройремонт»</td><td>1 450 000,00</td></tr>
          <tr><td>2</td><td>ООО «РемСтрой»</td><td>1 480 000,00</td></tr>
          <tr><td>3</td><td>ООО «Прогресс»</td><td>1 490 000,00</td></tr>
        </tbody>
      </table>
    </div>
    </body></html>
    HTML;

    $data = app(SupplierResultsParser::class)->parse($html);

    expect($data->protocol)->not->toBeNull()
        ->and($data->protocol->type)->toBe(ProtocolType::Summary->value)
        ->and($data->protocol->sourceNumber)->toBe('ИЗК1')
        ->and($data->protocol->signedAt->toDateString())->toBe('2026-07-15');

    expect($data->bids)->toHaveCount(3);

    expect($data->bids[0]->organization?->fullName)->toBe('ООО «Стройремонт»')
        ->and($data->bids[0]->organization?->inn)->toBe('7701234567')
        ->and($data->bids[0]->organization?->ogrn)->toBe('1027700123456')
        ->and($data->bids[0]->price)->toBe(1450000.0)
        ->and($data->bids[0]->status)->toBe(BidStatus::Winner);

    // Участники без ИНН/ОГРН в источнике не резолвятся в OrganizationData вовсе (не только
    // без ИНН) — иначе OrganizationResolver рискует склеить разных участников в одну
    // org_organizations (см. BidData/SupplierResultsProcessor, ревью P3.7).
    expect($data->bids[1]->organization)->toBeNull()
        ->and($data->bids[1]->price)->toBe(1480000.0)
        ->and($data->bids[1]->status)->toBe(BidStatus::Admitted);

    expect($data->bids[2]->organization)->toBeNull()
        ->and($data->bids[2]->price)->toBe(1490000.0)
        ->and($data->bids[2]->status)->toBe(BidStatus::Admitted);

    expect($data->award)->not->toBeNull()
        ->and($data->award->winner->inn)->toBe('7701234567')
        ->and($data->award->finalPrice)->toBe(1450000.0)
        ->and($data->award->status)->toBe(AwardStatus::Awarded);
});

test('parse возвращает пустой результат при отсутствии блока supplier-results', function () {
    $data = app(SupplierResultsParser::class)->parse('<html><body>пусто</body></html>');

    expect($data->protocol)->toBeNull()
        ->and($data->bids)->toBe([])
        ->and($data->award)->toBeNull();
});

test('parse возвращает пустой результат для пустой строки', function () {
    $data = app(SupplierResultsParser::class)->parse('');

    expect($data->protocol)->toBeNull()
        ->and($data->bids)->toBe([])
        ->and($data->award)->toBeNull();
});
