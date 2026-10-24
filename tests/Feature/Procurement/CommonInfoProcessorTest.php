<?php

declare(strict_types=1);

use App\Dto\Procurement\Details\CommonInfoData;
use App\Dto\Procurement\Details\CommonInfoSnapshot;
use App\Dto\Procurement\Details\FinancingRowData;
use App\Dto\Procurement\Details\OrganizationData;
use App\Dto\Procurement\Details\PartyData;
use App\Dto\Procurement\Details\PurchaseItemRowData;
use App\Enums\Organization\ProcurementPartyRole;
use App\Enums\Procurement\DetailSnapshotStatus;
use App\Models\Platforms\Eis\EisDetails;
use App\Models\Procurement\Financing;
use App\Models\Procurement\Procurement;
use App\Models\Procurement\ProcurementParty;
use App\Models\Procurement\PurchaseItem;
use App\Models\Reference\Okpd2;
use App\Services\Platforms\Eis\Card\CommonInfoParser;
use App\Services\Platforms\Eis\Card\PartiesParser;
use App\Services\Procurement\Detail\CommonInfoProcessor;
use Illuminate\Database\QueryException;

function commonInfoFixtureData(): CommonInfoSnapshot
{
    $html = file_get_contents(base_path('tests/Fixtures/Platforms/Eis/Card/common-info.html'));
    $partiesParser = app(PartiesParser::class);
    $parties = $partiesParser->parse($html);

    return app(CommonInfoParser::class)->parse($html)->withParties(
        parties: $parties,
        partiesComplete: $partiesParser->authorizesReconciliation(html: $html, parties: $parties),
    );
}

test('неполная январская проекция сторон сохраняет существующие организации и роли', function () {
    $procurement = Procurement::factory()->create();
    $html = file_get_contents(base_path('tests/Fixtures/Platforms/Eis/Card/parties-january-1.html'));
    $parser = app(PartiesParser::class);
    $parties = $parser->parse($html);
    $processor = app(CommonInfoProcessor::class);
    $processor->process($procurement, new CommonInfoSnapshot(
        status: DetailSnapshotStatus::Incomplete,
        commonInfo: new CommonInfoData,
        parties: $parties,
        partiesComplete: $parser->authorizesReconciliation($html, $parties),
    ));
    $customerId = $procurement->refresh()->customer_org_id;
    $roleIds = ProcurementParty::query()->where('procurement_id', $procurement->id)->orderBy('id')->pluck('id')->all();
    $broken = str_replace('class="row lots-block"', 'class="lost-block"', $html);
    $brokenParties = $parser->parse($broken);
    $processor->process($procurement, new CommonInfoSnapshot(
        status: DetailSnapshotStatus::Incomplete,
        commonInfo: new CommonInfoData,
        parties: $brokenParties,
        partiesComplete: $parser->authorizesReconciliation($broken, $brokenParties),
    ));

    expect($customerId)->not->toBeNull()
        ->and($procurement->refresh()->customer_org_id)->toBe($customerId)
        ->and(ProcurementParty::query()->where('procurement_id', $procurement->id)->orderBy('id')->pluck('id')->all())->toBe($roleIds);
});

/**
 * @param  list<PurchaseItemRowData>  $items
 * @param  list<PartyData>  $parties
 * @param  list<FinancingRowData>  $financingRows
 */
function completeCommonInfoSnapshot(array $items = [], array $parties = [], array $financingRows = [], int $ikzYear = 25): CommonInfoSnapshot
{
    return new CommonInfoSnapshot(
        status: DetailSnapshotStatus::Complete,
        commonInfo: new CommonInfoData(ikzYear: $ikzYear, financingRows: $financingRows, items: $items),
        parties: $parties,
        partiesComplete: true,
    );
}

function snapshotItem(int $positionNumber, string $name): PurchaseItemRowData
{
    return new PurchaseItemRowData(
        positionNumber: $positionNumber,
        name: $name,
        characteristics: null,
        classifierScheme: 'okpd2',
        classifierCodeOkpd2: '18.11.10.000',
        classifierCodeKtru: null,
        unit: 'шт',
        quantity: '1',
        unitPrice: '1',
        totalCost: '1',
    );
}

function snapshotParty(ProcurementPartyRole $role, string $inn): PartyData
{
    return new PartyData(
        role: $role,
        organization: new OrganizationData(fullName: "Организация {$inn}", inn: $inn),
        contactPersonName: null,
        contactPersonPosition: null,
    );
}

test('process создаёт eis_details/financing/items из разобранных данных вкладки (реальная вкладка common-info, P4.14)', function () {
    $procurement = Procurement::factory()->create();

    app(CommonInfoProcessor::class)->process(procurement: $procurement, snapshot: commonInfoFixtureData());

    expect(EisDetails::query()->where('procurement_id', $procurement->id)->count())->toBe(1)
        ->and(Financing::query()->where('procurement_id', $procurement->id)->count())->toBe(3)
        ->and(PurchaseItem::query()->where('procurement_id', $procurement->id)->count())->toBe(1);

    $details = EisDetails::query()->where('procurement_id', $procurement->id)->firstOrFail();

    expect($details->ikz_year)->toBe(25)
        ->and($details->etp_name)->toBe('АО «Сбербанк-АСТ»')
        ->and($details->etp_url)->toBe('http://www.sberbank-ast.ru')
        ->and($details->contact_person['name'])->toBe('Иванов И. И.')
        ->and($details->contact_person['email'])->toBe('responsible-official@example.invalid');
});

test('повторный process идемпотентен — строки не задваиваются', function () {
    $procurement = Procurement::factory()->create();
    $data = commonInfoFixtureData();

    app(CommonInfoProcessor::class)->process(procurement: $procurement, snapshot: $data);
    app(CommonInfoProcessor::class)->process(procurement: $procurement, snapshot: $data);

    expect(EisDetails::query()->where('procurement_id', $procurement->id)->count())->toBe(1)
        ->and(Financing::query()->where('procurement_id', $procurement->id)->count())->toBe(3)
        ->and(PurchaseItem::query()->where('procurement_id', $procurement->id)->count())->toBe(1);
});

test('process не создаёт prc_eis_details при полностью пустом разборе вкладки (ноль-как-успех запрещён, Д-8)', function () {
    $procurement = Procurement::factory()->create();

    app(CommonInfoProcessor::class)->process(
        procurement: $procurement,
        snapshot: new CommonInfoSnapshot(
            status: DetailSnapshotStatus::Complete,
            commonInfo: new CommonInfoData,
        ),
    );

    expect(EisDetails::query()->where('procurement_id', $procurement->id)->exists())->toBeFalse()
        ->and(Financing::query()->where('procurement_id', $procurement->id)->exists())->toBeFalse()
        ->and(PurchaseItem::query()->where('procurement_id', $procurement->id)->exists())->toBeFalse();
});

test('process резолвит okpd2_ref_id, когда код есть в справочнике ref_okpd2', function () {
    $procurement = Procurement::factory()->create();
    $okpd2 = Okpd2::factory()->create(['code' => '18.11.10.000']);

    app(CommonInfoProcessor::class)->process(procurement: $procurement, snapshot: commonInfoFixtureData());

    $item = PurchaseItem::query()->where('procurement_id', $procurement->id)->firstOrFail();

    expect($item->classifier_code)->toBe('18.11.10.000')
        ->and($item->okpd2_ref_id)->toBe($okpd2->id);
});

test('process сохраняет сырой classifier_code без резолва, если кода нет в справочниках', function () {
    $procurement = Procurement::factory()->create();

    app(CommonInfoProcessor::class)->process(procurement: $procurement, snapshot: commonInfoFixtureData());

    $item = PurchaseItem::query()->where('procurement_id', $procurement->id)->firstOrFail();

    expect($item->classifier_code)->toBe('18.11.10.000')
        ->and($item->okpd2_ref_id)->toBeNull()
        ->and($item->ktru_ref_id)->toBeNull();
});

test('реальная complete common-info сохраняется, а недоступные parties не снимают заказчика', function () {
    $procurement = Procurement::factory()->create();
    $processor = app(CommonInfoProcessor::class);

    $processor->process(procurement: $procurement, snapshot: completeCommonInfoSnapshot(
        items: [snapshotItem(positionNumber: 9, name: 'Предыдущая позиция')],
        parties: [snapshotParty(role: ProcurementPartyRole::Customer, inn: '5001234567')],
    ));
    $customerOrganizationId = $procurement->refresh()->customer_org_id;

    $processor->process(procurement: $procurement, snapshot: commonInfoFixtureData());

    expect(EisDetails::query()->where('procurement_id', $procurement->id)->sole()->ikz_year)->toBe(25)
        ->and(Financing::query()->where('procurement_id', $procurement->id)->count())->toBe(3)
        ->and(PurchaseItem::query()->where('procurement_id', $procurement->id)->pluck('position_number')->all())->toBe([1])
        ->and(ProcurementParty::query()->where('procurement_id', $procurement->id)->count())->toBe(1)
        ->and($procurement->refresh()->customer_org_id)->toBe($customerOrganizationId);
});

test('complete snapshot shrink-ит items и parties до актуального набора', function () {
    $procurement = Procurement::factory()->create();
    $processor = app(CommonInfoProcessor::class);

    $processor->process(procurement: $procurement, snapshot: completeCommonInfoSnapshot(
        items: [snapshotItem(positionNumber: 1, name: 'Первая'), snapshotItem(positionNumber: 2, name: 'Вторая')],
        parties: [
            snapshotParty(role: ProcurementPartyRole::Customer, inn: '5001234567'),
            snapshotParty(role: ProcurementPartyRole::Placer, inn: '5007654321'),
        ],
    ));
    $processor->process(procurement: $procurement, snapshot: completeCommonInfoSnapshot(
        items: [snapshotItem(positionNumber: 1, name: 'Первая')],
        parties: [snapshotParty(role: ProcurementPartyRole::Customer, inn: '5001234567')],
    ));

    expect(PurchaseItem::query()->where('procurement_id', $procurement->id)->pluck('position_number')->all())->toBe([1])
        ->and(ProcurementParty::query()->where('procurement_id', $procurement->id)->count())->toBe(1);
});

test('complete empty snapshot удаляет items и parties и очищает customer', function () {
    $procurement = Procurement::factory()->create();
    $processor = app(CommonInfoProcessor::class);

    $processor->process(procurement: $procurement, snapshot: completeCommonInfoSnapshot(
        items: [snapshotItem(positionNumber: 1, name: 'Первая')],
        parties: [snapshotParty(role: ProcurementPartyRole::Customer, inn: '5001234567')],
    ));
    $processor->process(procurement: $procurement, snapshot: completeCommonInfoSnapshot());

    expect(PurchaseItem::query()->where('procurement_id', $procurement->id)->exists())->toBeFalse()
        ->and(ProcurementParty::query()->where('procurement_id', $procurement->id)->exists())->toBeFalse()
        ->and($procurement->refresh()->customer_org_id)->toBeNull();
});

test('renumber заменяет позицию по typed position number без stale semantic identity', function () {
    $procurement = Procurement::factory()->create();
    $processor = app(CommonInfoProcessor::class);

    $processor->process(procurement: $procurement, snapshot: completeCommonInfoSnapshot(
        items: [snapshotItem(positionNumber: 1, name: 'Исходная'), snapshotItem(positionNumber: 2, name: 'Исчезающая')],
    ));
    $processor->process(procurement: $procurement, snapshot: completeCommonInfoSnapshot(
        items: [snapshotItem(positionNumber: 1, name: 'Новая семантика позиции')],
    ));

    expect(PurchaseItem::query()->where('procurement_id', $procurement->id)->count())->toBe(1)
        ->and(PurchaseItem::query()->where('procurement_id', $procurement->id)->sole()->name)->toBe('Новая семантика позиции');
});

test('incomplete snapshot не меняет current sets', function () {
    $procurement = Procurement::factory()->create();
    $processor = app(CommonInfoProcessor::class);
    $complete = completeCommonInfoSnapshot(
        items: [snapshotItem(positionNumber: 1, name: 'Первая')],
        parties: [snapshotParty(role: ProcurementPartyRole::Customer, inn: '5001234567')],
    );

    $processor->process(procurement: $procurement, snapshot: $complete);
    $processor->process(
        procurement: $procurement,
        snapshot: new CommonInfoSnapshot(status: DetailSnapshotStatus::Incomplete, commonInfo: new CommonInfoData),
    );

    expect(PurchaseItem::query()->where('procurement_id', $procurement->id)->count())->toBe(1)
        ->and(ProcurementParty::query()->where('procurement_id', $procurement->id)->count())->toBe(1);
});

test('complete parties reconcile независимо от incomplete common-info', function () {
    $procurement = Procurement::factory()->create();
    $processor = app(CommonInfoProcessor::class);

    $processor->process(procurement: $procurement, snapshot: completeCommonInfoSnapshot(
        items: [snapshotItem(positionNumber: 1, name: 'Сохранённая позиция')],
        parties: [
            snapshotParty(role: ProcurementPartyRole::Customer, inn: '5001234567'),
            snapshotParty(role: ProcurementPartyRole::Placer, inn: '5007654321'),
        ],
    ));
    $customerOrganizationId = $procurement->refresh()->customer_org_id;

    $processor->process(
        procurement: $procurement,
        snapshot: (new CommonInfoSnapshot(
            status: DetailSnapshotStatus::Incomplete,
            commonInfo: new CommonInfoData,
        ))->withParties(
            parties: [snapshotParty(role: ProcurementPartyRole::Customer, inn: '5001234567')],
            partiesComplete: true,
        ),
    );

    expect(EisDetails::query()->where('procurement_id', $procurement->id)->sole()->ikz_year)->toBe(25)
        ->and(PurchaseItem::query()->where('procurement_id', $procurement->id)->sole()->name)->toBe('Сохранённая позиция')
        ->and(ProcurementParty::query()->where('procurement_id', $procurement->id)->count())->toBe(1)
        ->and($procurement->refresh()->customer_org_id)->toBe($customerOrganizationId);
});

test('ошибка после item sync откатывает common, financing, items и parties вместе', function () {
    $procurement = Procurement::factory()->create();
    $processor = app(CommonInfoProcessor::class);
    $initial = completeCommonInfoSnapshot(
        items: [snapshotItem(positionNumber: 1, name: 'Исходная')],
        parties: [
            snapshotParty(role: ProcurementPartyRole::Customer, inn: '5001234567'),
            snapshotParty(role: ProcurementPartyRole::Placer, inn: '5007654321'),
        ],
        financingRows: [new FinancingRowData(fiscalYear: 2025, amount: '1')],
    );

    $processor->process(procurement: $procurement, snapshot: $initial);
    expect(fn () => $processor->process(
        procurement: $procurement,
        snapshot: completeCommonInfoSnapshot(
            items: [snapshotItem(positionNumber: 1, name: 'Новая')],
            parties: [new PartyData(
                role: ProcurementPartyRole::Customer,
                organization: new OrganizationData(fullName: str_repeat('x', 256), inn: '5009999999'),
                contactPersonName: null,
                contactPersonPosition: null,
            )],
            financingRows: [new FinancingRowData(fiscalYear: 2025, amount: '2')],
            ikzYear: 26,
        ),
    ))->toThrow(QueryException::class);

    expect(PurchaseItem::query()->where('procurement_id', $procurement->id)->sole()->name)->toBe('Исходная')
        ->and(ProcurementParty::query()->where('procurement_id', $procurement->id)->count())->toBe(2)
        ->and(EisDetails::query()->where('procurement_id', $procurement->id)->sole()->ikz_year)->toBe(25)
        ->and(Financing::query()->where('procurement_id', $procurement->id)->sole()->amount)->toBe('1.00');
});
