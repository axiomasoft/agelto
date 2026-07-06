<?php

declare(strict_types=1);

use App\Enums\Organization\ProcurementPartyRole;
use App\Models\Organization\Organization;
use App\Models\Procurement\Procurement;
use App\Models\Procurement\ProcurementParty;
use App\Services\Procurement\Detail\OrganizationPartiesProcessor;
use App\Services\Procurement\Eis\Card\PartiesParser;

function partiesFixtureDataForProcessorTest(): array
{
    $html = file_get_contents(base_path('tests/Fixtures/Crawler/Card/common-info.html'));

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
