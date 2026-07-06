<?php

declare(strict_types=1);

use App\Enums\Organization\ProcurementPartyRole;
use App\Enums\Procurement\AwardStatus;
use App\Enums\Procurement\BidStatus;
use App\Models\Organization\Organization;
use App\Models\Procurement\Award;
use App\Models\Procurement\Bid;
use App\Models\Procurement\Procurement;
use App\Models\Procurement\ProcurementParty;
use App\Models\Procurement\Protocol;

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
