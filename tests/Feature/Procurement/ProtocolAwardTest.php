<?php

declare(strict_types=1);

use App\Dto\Procurement\Details\AwardData;
use App\Dto\Procurement\Details\OrganizationData;
use App\Dto\Procurement\Details\SupplierResultsData;
use App\Enums\Organization\ProcurementPartyRole;
use App\Enums\Procurement\AwardStatus;
use App\Enums\Procurement\BidStatus;
use App\Models\Organization\Organization;
use App\Models\Procurement\Award;
use App\Models\Procurement\Bid;
use App\Models\Procurement\Procurement;
use App\Models\Procurement\ProcurementParty;
use App\Models\Procurement\Protocol;
use App\Services\Procurement\Detail\SupplierResultsProcessor;

/**
 * §11 «Размещён протокол / определён победитель» — prc_protocols + prc_bids + prc_awards,
 * победитель получает роль winner в org_procurement_parties.
 */
test('размещение протокола и определение победителя фиксируются протоколом, заявками, итогом и ролью winner', function () {
    $procurement = Procurement::factory()->create();
    $winnerOrganization = Organization::factory()->create();
    $rejectedOrganization = Organization::factory()->create();

    $protocol = Protocol::factory()->create(['procurement_id' => $procurement->id]);

    $winningBid = Bid::factory()->create([
        'procurement_id' => $procurement->id,
        'protocol_id' => $protocol->id,
        'organization_id' => $winnerOrganization->id,
        'status' => BidStatus::Winner,
        'final_rank' => 1,
    ]);

    $rejectedBid = Bid::factory()->create([
        'procurement_id' => $procurement->id,
        'protocol_id' => $protocol->id,
        'organization_id' => $rejectedOrganization->id,
        'status' => BidStatus::Rejected,
        'reject_reason' => 'Несоответствие требованиям документации',
    ]);

    $award = Award::factory()->create([
        'procurement_id' => $procurement->id,
        'winner_org_id' => $winnerOrganization->id,
        'status' => AwardStatus::Awarded,
    ]);

    $winnerParty = ProcurementParty::factory()->create([
        'procurement_id' => $procurement->id,
        'organization_id' => $winnerOrganization->id,
        'role' => ProcurementPartyRole::Winner,
    ]);

    expect(Protocol::query()->where('procurement_id', $procurement->id)->count())->toBe(1)
        ->and(Bid::query()->where('procurement_id', $procurement->id)->count())->toBe(2)
        ->and($winningBid->status)->toBe(BidStatus::Winner)
        ->and($rejectedBid->status)->toBe(BidStatus::Rejected)
        ->and($award->winner_org_id)->toBe($winnerOrganization->id)
        ->and($award->status)->toBe(AwardStatus::Awarded)
        ->and($winnerParty->role)->toBe(ProcurementPartyRole::Winner)
        ->and($winnerParty->organization_id)->toBe($winnerOrganization->id);
});

test('пересмотр итогов снимает роль winner со старой организации и оставляет её у новой', function () {
    $procurement = Procurement::factory()->create();
    $oldWinner = Organization::factory()->create();
    $newWinner = Organization::factory()->create();

    $processor = app(SupplierResultsProcessor::class);

    $processor->process($procurement, new SupplierResultsData(
        protocol: null,
        bids: [],
        award: new AwardData(
            winner: new OrganizationData(fullName: $oldWinner->full_name, inn: $oldWinner->inn, kpp: $oldWinner->kpp),
            finalPrice: '100.00',
            status: AwardStatus::Awarded,
            awardedAt: now(),
        ),
    ));

    $processor->process($procurement, new SupplierResultsData(
        protocol: null,
        bids: [],
        award: new AwardData(
            winner: new OrganizationData(fullName: $newWinner->full_name, inn: $newWinner->inn, kpp: $newWinner->kpp),
            finalPrice: '90.00',
            status: AwardStatus::Awarded,
            awardedAt: now(),
        ),
    ));

    $winnerRoles = ProcurementParty::query()
        ->where('procurement_id', $procurement->id)
        ->where('role', ProcurementPartyRole::Winner)
        ->get();

    expect($winnerRoles)->toHaveCount(1)
        ->and($winnerRoles->first()->organization_id)->toBe($newWinner->id);
});

test('снятие роли winner не задевает другие роли той же организации', function () {
    $procurement = Procurement::factory()->create();
    $oldWinner = Organization::factory()->create();
    $newWinner = Organization::factory()->create();

    ProcurementParty::factory()->create([
        'procurement_id' => $procurement->id,
        'organization_id' => $oldWinner->id,
        'role' => ProcurementPartyRole::Participant,
    ]);

    $processor = app(SupplierResultsProcessor::class);

    $processor->process($procurement, new SupplierResultsData(
        protocol: null,
        bids: [],
        award: new AwardData(
            winner: new OrganizationData(fullName: $oldWinner->full_name, inn: $oldWinner->inn, kpp: $oldWinner->kpp),
            finalPrice: '100.00',
            status: AwardStatus::Awarded,
            awardedAt: now(),
        ),
    ));

    $processor->process($procurement, new SupplierResultsData(
        protocol: null,
        bids: [],
        award: new AwardData(
            winner: new OrganizationData(fullName: $newWinner->full_name, inn: $newWinner->inn, kpp: $newWinner->kpp),
            finalPrice: '90.00',
            status: AwardStatus::Awarded,
            awardedAt: now(),
        ),
    ));

    $oldWinnerRoles = ProcurementParty::query()
        ->where('procurement_id', $procurement->id)
        ->where('organization_id', $oldWinner->id)
        ->pluck('role');

    expect($oldWinnerRoles)->toHaveCount(1)
        ->and($oldWinnerRoles->first())->toBe(ProcurementPartyRole::Participant);
});
