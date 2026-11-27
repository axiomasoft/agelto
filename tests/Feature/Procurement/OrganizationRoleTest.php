<?php

declare(strict_types=1);

use App\Enums\Organization\ProcurementPartyRole;
use App\Models\Organization\Organization;
use App\Models\Procurement\Procurement;
use App\Models\Procurement\ProcurementParty;
use Illuminate\Database\QueryException;

/**
 * §11 «Организация в нескольких ролях» — одна org_organizations (резолв по ИНН)
 * + N строк org_procurement_parties с разными role.
 */
test('одна организация резолвится по ИНН и участвует в закупке сразу в нескольких ролях', function () {
    $organization = Organization::factory()->create(['inn' => '7701234567']);
    $procurement = Procurement::factory()->create();

    $customerParty = ProcurementParty::factory()->create([
        'procurement_id' => $procurement->id,
        'organization_id' => $organization->id,
        'role' => ProcurementPartyRole::Customer,
    ]);

    $guarantorParty = ProcurementParty::factory()->create([
        'procurement_id' => $procurement->id,
        'organization_id' => $organization->id,
        'role' => ProcurementPartyRole::Guarantor,
    ]);

    expect(Organization::query()->where('inn', '7701234567')->count())->toBe(1)
        ->and(ProcurementParty::query()->where('procurement_id', $procurement->id)->where('organization_id', $organization->id)->count())->toBe(2)
        ->and($customerParty->role)->toBe(ProcurementPartyRole::Customer)
        ->and($guarantorParty->role)->toBe(ProcurementPartyRole::Guarantor);
});

test('одна и та же роль организации в закупке не дублируется (unique procurement_id+organization_id+role)', function () {
    $organization = Organization::factory()->create();
    $procurement = Procurement::factory()->create();

    ProcurementParty::factory()->create([
        'procurement_id' => $procurement->id,
        'organization_id' => $organization->id,
        'role' => ProcurementPartyRole::Participant,
    ]);

    expect(fn () => ProcurementParty::factory()->create([
        'procurement_id' => $procurement->id,
        'organization_id' => $organization->id,
        'role' => ProcurementPartyRole::Participant,
    ]))->toThrow(QueryException::class);
});
