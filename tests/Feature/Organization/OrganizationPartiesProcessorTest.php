<?php

declare(strict_types=1);

use App\Dto\Organization\OrganizationIdentifierData;
use App\Dto\Procurement\Details\OrganizationData;
use App\Dto\Procurement\Details\PartyData;
use App\Enums\Organization\ProcurementPartyRole;
use App\Models\Organization\Organization;
use App\Models\Procurement\Procurement;
use App\Models\Procurement\ProcurementParty;
use App\Services\Platforms\Eis\Card\PartiesParser;
use App\Services\Procurement\Detail\OrganizationPartiesProcessor;

/**
 * Совместимая табличная fixture проверяет persistence по валидным DTO.
 * Реальные январские варианты и повторы без ИНН проверяются ниже.
 *
 * @return list<PartyData>
 */
function partiesFixtureDataForProcessorTest(): array
{
    $html = <<<'HTML'
    <html><body>
    <section class="organizations-block">
      <table class="parties-table">
        <tbody>
          <tr>
            <td>Заказчик</td>
            <td>Администрация городского округа</td>
            <td>5001234567</td>
            <td>500101001</td>
            <td>1025001234567</td>
            <td>Иванов Иван Иванович</td>
            <td>Главный специалист</td>
          </tr>
          <tr>
            <td>Размещающая организация</td>
            <td>Администрация городского округа</td>
            <td>5001234567</td>
            <td>500101001</td>
            <td>1025001234567</td>
            <td></td>
            <td></td>
          </tr>
          <tr>
            <td>Уполномоченный орган</td>
            <td>Департамент государственного заказа Московской области</td>
            <td>5027654321</td>
            <td>502701001</td>
            <td>1035027654321</td>
            <td></td>
            <td></td>
          </tr>
        </tbody>
      </table>
    </section>
    </body></html>
    HTML;

    return app(PartiesParser::class)->parse($html);
}

test('process резолвит одну организацию в двух ролях в один org_organizations + 2 pivot', function () {
    $procurement = Procurement::factory()->create();

    app(OrganizationPartiesProcessor::class)->process($procurement, partiesFixtureDataForProcessorTest());

    // Фикстура: «Администрация городского округа» — Заказчик + Размещающая организация (тот же ИНН/КПП),
    // «Департамент...» — Уполномоченный орган. Итого 2 организации, 3 pivot-строки.
    expect(Organization::query()->count())->toBe(2)
        ->and(ProcurementParty::query()->where('procurement_id', $procurement->id)->count())->toBe(3);

    $administration = Organization::query()->where('inn', '5001234567')->firstOrFail();

    expect(ProcurementParty::query()
        ->where('procurement_id', $procurement->id)
        ->where('organization_id', $administration->id)
        ->count())->toBe(2);
});

test('process проставляет customer_org_id из роли customer', function () {
    $procurement = Procurement::factory()->create();

    app(OrganizationPartiesProcessor::class)->process($procurement, partiesFixtureDataForProcessorTest());

    $administration = Organization::query()->where('inn', '5001234567')->firstOrFail();

    expect($procurement->refresh()->customer_org_id)->toBe($administration->id);
});

test('process сохраняет контактное лицо в attributes pivot-строки заказчика', function () {
    $procurement = Procurement::factory()->create();

    app(OrganizationPartiesProcessor::class)->process($procurement, partiesFixtureDataForProcessorTest());

    $administration = Organization::query()->where('inn', '5001234567')->firstOrFail();

    $customerParty = ProcurementParty::query()
        ->where('procurement_id', $procurement->id)
        ->where('organization_id', $administration->id)
        ->where('role', ProcurementPartyRole::Customer)
        ->firstOrFail();

    expect($customerParty->attributes['name'])->toBe('Иванов Иван Иванович')
        ->and($customerParty->attributes['position'])->toBe('Главный специалист');
});

test('повторный process идемпотентен — организации и pivot не задваиваются', function () {
    $procurement = Procurement::factory()->create();
    $parties = partiesFixtureDataForProcessorTest();

    app(OrganizationPartiesProcessor::class)->process($procurement, $parties);
    app(OrganizationPartiesProcessor::class)->process($procurement, $parties);

    expect(Organization::query()->count())->toBe(2)
        ->and(ProcurementParty::query()->where('procurement_id', $procurement->id)->count())->toBe(3);
});

test('process удаляет отсутствующие роли и очищает customer при complete empty set', function () {
    $procurement = Procurement::factory()->create();
    $processor = app(OrganizationPartiesProcessor::class);
    $parties = partiesFixtureDataForProcessorTest();

    $processor->process(procurement: $procurement, parties: $parties);
    $processor->process(procurement: $procurement, parties: [$parties[0]]);

    expect(ProcurementParty::query()->where('procurement_id', $procurement->id)->count())->toBe(1);

    $processor->process(procurement: $procurement, parties: []);

    expect(ProcurementParty::query()->where('procurement_id', $procurement->id)->exists())->toBeFalse()
        ->and($procurement->refresh()->customer_org_id)->toBeNull();
});

test('повтор реального common-info без ИНН идемпотентен и сохраняет роли других вкладок', function () {
    $procurement = Procurement::factory()->create();
    $winner = Organization::factory()->create();
    foreach ([ProcurementPartyRole::Winner, ProcurementPartyRole::Participant, ProcurementPartyRole::Guarantor] as $role) {
        ProcurementParty::factory()->create([
            'procurement_id' => $procurement->id, 'organization_id' => $winner->id, 'role' => $role,
        ]);
    }
    $html = file_get_contents(base_path('tests/Fixtures/Platforms/Eis/Card/parties-january-1.html'));
    $parties = app(PartiesParser::class)->parse($html);
    $processor = app(OrganizationPartiesProcessor::class);
    $processor->process($procurement, $parties);
    $customerId = $procurement->refresh()->customer_org_id;
    $processor->process($procurement, $parties);

    expect(Organization::query()->count())->toBe(3)
        ->and(ProcurementParty::query()->where('procurement_id', $procurement->id)->count())->toBe(6)
        ->and($procurement->refresh()->customer_org_id)->toBe($customerId)
        ->and(Organization::query()->findOrFail($customerId)->identifiers()->where('platform_code', 'eis')->where('type', 'customer_code')->sole()->value)->toBe('30253020551025301001');

    $processor->process($procurement, []);
    expect(ProcurementParty::query()->where('procurement_id', $procurement->id)->count())->toBe(3)
        ->and($procurement->refresh()->customer_org_id)->toBeNull();
});

test('несколько заказчиков остаются в ролях без произвольного выбора customer_org_id', function () {
    $procurement = Procurement::factory()->create();
    $parties = [
        new PartyData(role: ProcurementPartyRole::Customer, organization: new OrganizationData(fullName: 'Первый', identifiers: [new OrganizationIdentifierData(platformCode: 'market-a', type: 'customer', value: '30253020551025301001')])),
        new PartyData(role: ProcurementPartyRole::Customer, organization: new OrganizationData(fullName: 'Второй', identifiers: [new OrganizationIdentifierData(platformCode: 'market-a', type: 'customer', value: '35509007093550901001')])),
    ];
    $processor = app(OrganizationPartiesProcessor::class);
    $processor->process($procurement, [$parties[0]]);
    expect($procurement->refresh()->customer_org_id)->not->toBeNull();

    $processor->process($procurement, $parties);
    $processor->process($procurement, array_reverse($parties));

    expect(Organization::query()->count())->toBe(2)
        ->and(ProcurementParty::query()->where('procurement_id', $procurement->id)->count())->toBe(2)
        ->and($procurement->refresh()->customer_org_id)->toBeNull();
});
