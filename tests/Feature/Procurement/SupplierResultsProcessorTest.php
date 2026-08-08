<?php

declare(strict_types=1);

use App\Dto\Procurement\Details\AwardData;
use App\Dto\Procurement\Details\OrganizationData;
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

function supplierResultsSuccessDataForProcessorTest(): SupplierResultsData
{
    $html = file_get_contents(base_path('tests/Fixtures/Crawler/Card/supplier-results-success.html'));

    return app(SupplierResultsParser::class)->parse($html);
}

function identifiedAwardDataForProcessorTest(Organization $winner, float $price = 9000000.0): SupplierResultsData
{
    return new SupplierResultsData(
        protocol: null,
        bids: [],
        award: new AwardData(
            winner: new OrganizationData(
                fullName: $winner->full_name,
                inn: $winner->inn,
                kpp: $winner->kpp,
            ),
            finalPrice: $price,
            status: AwardStatus::Awarded,
            awardedAt: now(),
        ),
    );
}

test('process сохраняет протокол, анонимную заявку и award из живой success-фикстуры', function () {
    $procurement = Procurement::factory()->create();
    $organizationsBefore = Organization::query()->count();

    app(SupplierResultsProcessor::class)->process($procurement, supplierResultsSuccessDataForProcessorTest());

    $award = Award::query()->where('procurement_id', $procurement->id)->firstOrFail();
    $bid = Bid::query()->where('procurement_id', $procurement->id)->firstOrFail();

    expect(Protocol::query()->where('procurement_id', $procurement->id)->count())->toBe(1)
        ->and(Bid::query()->where('procurement_id', $procurement->id)->count())->toBe(1)
        ->and($bid->bid_number)->toBe('2')
        ->and($bid->participant_ordinal)->toBe(1)
        ->and($award->winner_org_id)->toBeNull()
        ->and((float) $award->final_price)->toBe(10000000.0)
        ->and($award->status)->toBe(AwardStatus::Awarded)
        ->and(Organization::query()->count())->toBe($organizationsBefore)
        ->and(ProcurementParty::query()
            ->where('procurement_id', $procurement->id)
            ->where('role', ProcurementPartyRole::Winner)
            ->exists())->toBeFalse()
        ->and(ProcurementEvent::query()
            ->where('procurement_id', $procurement->id)
            ->where('event_type', ProcurementEventType::AwardMade)
            ->exists())->toBeTrue()
        ->and(ProcurementEvent::query()
            ->where('procurement_id', $procurement->id)
            ->where('event_type', ProcurementEventType::ProtocolPublished)
            ->exists())->toBeTrue();
});

test('повторный process живой success-фикстуры не задваивает данные и события', function () {
    $procurement = Procurement::factory()->create();
    $data = supplierResultsSuccessDataForProcessorTest();

    app(SupplierResultsProcessor::class)->process($procurement, $data);
    app(SupplierResultsProcessor::class)->process($procurement, $data);

    expect(Protocol::query()->where('procurement_id', $procurement->id)->count())->toBe(1)
        ->and(Bid::query()->where('procurement_id', $procurement->id)->count())->toBe(1)
        ->and(Award::query()->where('procurement_id', $procurement->id)->count())->toBe(1)
        ->and(ProcurementEvent::query()
            ->where('procurement_id', $procurement->id)
            ->where('event_type', ProcurementEventType::AwardMade)
            ->count())->toBe(1)
        ->and(ProcurementEvent::query()
            ->where('procurement_id', $procurement->id)
            ->where('event_type', ProcurementEventType::ProtocolPublished)
            ->count())->toBe(1);
});

test('идентифицируемый победитель монотонно обогащает ранее анонимный award', function () {
    $procurement = Procurement::factory()->create();
    $winner = Organization::factory()->create();
    $processor = app(SupplierResultsProcessor::class);

    $processor->process($procurement, supplierResultsSuccessDataForProcessorTest());
    $processor->process($procurement, identifiedAwardDataForProcessorTest($winner));

    $award = Award::query()->where('procurement_id', $procurement->id)->firstOrFail();

    expect($award->winner_org_id)->toBe($winner->id)
        ->and(ProcurementParty::query()
            ->where('procurement_id', $procurement->id)
            ->where('organization_id', $winner->id)
            ->where('role', ProcurementPartyRole::Winner)
            ->exists())->toBeTrue();
});

test('анонимный повторный разбор не стирает уже известного победителя', function () {
    $procurement = Procurement::factory()->create();
    $winner = Organization::factory()->create();
    $processor = app(SupplierResultsProcessor::class);

    $processor->process($procurement, identifiedAwardDataForProcessorTest($winner));
    $processor->process($procurement, supplierResultsSuccessDataForProcessorTest());

    $award = Award::query()->where('procurement_id', $procurement->id)->firstOrFail();

    expect($award->winner_org_id)->toBe($winner->id)
        ->and(ProcurementParty::query()
            ->where('procurement_id', $procurement->id)
            ->where('organization_id', $winner->id)
            ->where('role', ProcurementPartyRole::Winner)
            ->exists())->toBeTrue();
});

test('process не создаёт ничего для пустой вкладки', function () {
    $procurement = Procurement::factory()->create();
    $data = app(SupplierResultsParser::class)->parse('');

    app(SupplierResultsProcessor::class)->process($procurement, $data);

    expect(Protocol::query()->where('procurement_id', $procurement->id)->count())->toBe(0)
        ->and(Bid::query()->where('procurement_id', $procurement->id)->count())->toBe(0)
        ->and(Award::query()->where('procurement_id', $procurement->id)->count())->toBe(0);
});
