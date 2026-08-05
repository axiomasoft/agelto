<?php

declare(strict_types=1);

use App\Dto\Procurement\Details\SupplierResultsData;
use App\Enums\Organization\ProcurementPartyRole;
use App\Enums\Procurement\AwardStatus;
use App\Enums\Procurement\ProcurementEventType;
use App\Models\Organization\Organization;
use App\Models\Procurement\Award;
use App\Models\Procurement\Bid;
use App\Models\Procurement\Procurement;
use App\Models\Procurement\ProcurementEvent;
use App\Models\Procurement\ProcurementParty;
use App\Models\Procurement\Protocol;
use App\Services\Procurement\Detail\SupplierResultsProcessor;
use App\Services\Procurement\Eis\Card\SupplierResultsParser;

/**
 * Синтетический HTML под протокол/заявки/победителя — НЕ общая фикстура парсера (P4.14
 * заменил `tests/Fixtures/Crawler/Card/supplier-results.html` на реальную вкладку НЕСОСТОЯВШЕЙСЯ
 * закупки без победителя; этот тест проверяет `SupplierResultsProcessor` — персистенцию по
 * ЛЮБОМУ валидному `SupplierResultsData`, а не соответствие текущей раскладке ЕИС).
 */
function supplierResultsDataForProcessorTest(): SupplierResultsData
{
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

    return app(SupplierResultsParser::class)->parse($html);
}

test('process создаёт протокол, заявки и award, победитель резолвлен в org_organizations с ролью winner', function () {
    $procurement = Procurement::factory()->create();

    app(SupplierResultsProcessor::class)->process($procurement, supplierResultsDataForProcessorTest());

    expect(Protocol::query()->where('procurement_id', $procurement->id)->count())->toBe(1)
        ->and(Bid::query()->where('procurement_id', $procurement->id)->count())->toBe(3)
        ->and(Award::query()->where('procurement_id', $procurement->id)->count())->toBe(1);

    // Победитель встречается и в блоке «Победитель», и построчно в bids-table — один org.
    expect(Organization::query()->where('inn', '7701234567')->count())->toBe(1);

    $winner = Organization::query()->where('inn', '7701234567')->firstOrFail();
    $award = Award::query()->where('procurement_id', $procurement->id)->firstOrFail();

    expect($award->winner_org_id)->toBe($winner->id)
        ->and((float) $award->final_price)->toBe(1450000.0)
        ->and($award->status)->toBe(AwardStatus::Awarded);

    expect(ProcurementParty::query()
        ->where('procurement_id', $procurement->id)
        ->where('organization_id', $winner->id)
        ->where('role', ProcurementPartyRole::Winner)
        ->exists())->toBeTrue();

    expect(ProcurementEvent::query()
        ->where('procurement_id', $procurement->id)
        ->where('event_type', ProcurementEventType::AwardMade)
        ->exists())->toBeTrue();

    expect(ProcurementEvent::query()
        ->where('procurement_id', $procurement->id)
        ->where('event_type', ProcurementEventType::ProtocolPublished)
        ->exists())->toBeTrue();
});

test('повторный process идемпотентен — протокол/заявки/award/событие не задваиваются', function () {
    $procurement = Procurement::factory()->create();
    $data = supplierResultsDataForProcessorTest();

    app(SupplierResultsProcessor::class)->process($procurement, $data);
    app(SupplierResultsProcessor::class)->process($procurement, $data);

    expect(Protocol::query()->where('procurement_id', $procurement->id)->count())->toBe(1)
        ->and(Bid::query()->where('procurement_id', $procurement->id)->count())->toBe(3)
        ->and(Award::query()->where('procurement_id', $procurement->id)->count())->toBe(1)
        ->and(Organization::query()->where('inn', '7701234567')->count())->toBe(1)
        ->and(ProcurementEvent::query()->where('procurement_id', $procurement->id)->where('event_type', ProcurementEventType::AwardMade)->count())->toBe(1)
        ->and(ProcurementEvent::query()->where('procurement_id', $procurement->id)->where('event_type', ProcurementEventType::ProtocolPublished)->count())->toBe(1);
});

test('process не схлопывает двух разных участников без ИНН в одну организацию/заявку', function () {
    $procurement = Procurement::factory()->create();

    app(SupplierResultsProcessor::class)->process($procurement, supplierResultsDataForProcessorTest());

    $bidsWithoutOrganization = Bid::query()
        ->where('procurement_id', $procurement->id)
        ->whereNull('organization_id')
        ->get();

    // Фикстура содержит двух участников без ИНН («ООО «РемСтрой»» и «ООО «Прогресс»»):
    // обе заявки сохранены как отдельные строки prc_bids с organization_id = null
    // (не резолвлены в org_organizations и не схлопнуты друг с другом по participant_ordinal).
    expect($bidsWithoutOrganization)->toHaveCount(2)
        ->and($bidsWithoutOrganization->pluck('participant_ordinal')->sort()->values()->all())->toBe([2, 3]);
});

test('process не создаёт ничего для незавершённой закупки (пустая вкладка)', function () {
    $procurement = Procurement::factory()->create();

    app(SupplierResultsProcessor::class)->process($procurement, app(SupplierResultsParser::class)->parse(''));

    expect(Protocol::query()->where('procurement_id', $procurement->id)->count())->toBe(0)
        ->and(Bid::query()->where('procurement_id', $procurement->id)->count())->toBe(0)
        ->and(Award::query()->where('procurement_id', $procurement->id)->count())->toBe(0);
});
