<?php

declare(strict_types=1);

use App\Models\Organization\Organization;
use App\Models\Procurement\Bid;
use App\Models\Procurement\Procurement;
use App\Models\Procurement\Protocol;
use Illuminate\Database\QueryException;

test('два резолвленных участника одного протокола с разными organization_id уживаются', function () {
    $procurement = Procurement::factory()->create();
    $protocol = Protocol::factory()->create(['procurement_id' => $procurement->id]);
    $orgOne = Organization::factory()->create();
    $orgTwo = Organization::factory()->create();

    Bid::factory()->create([
        'procurement_id' => $procurement->id,
        'protocol_id' => $protocol->id,
        'organization_id' => $orgOne->id,
    ]);
    Bid::factory()->create([
        'procurement_id' => $procurement->id,
        'protocol_id' => $protocol->id,
        'organization_id' => $orgTwo->id,
    ]);

    expect(Bid::query()->where('procurement_id', $procurement->id)->count())->toBe(2);
});

test('вставка второй заявки с тем же procurement_id, protocol_id, organization_id кидает исключение', function () {
    $procurement = Procurement::factory()->create();
    $protocol = Protocol::factory()->create(['procurement_id' => $procurement->id]);
    $organization = Organization::factory()->create();

    Bid::factory()->create([
        'procurement_id' => $procurement->id,
        'protocol_id' => $protocol->id,
        'organization_id' => $organization->id,
    ]);

    expect(fn () => Bid::factory()->create([
        'procurement_id' => $procurement->id,
        'protocol_id' => $protocol->id,
        'organization_id' => $organization->id,
    ]))->toThrow(QueryException::class);
});

test('два анонимных участника с разными participant_ordinal уживаются, с одинаковым падают', function () {
    $procurement = Procurement::factory()->create();
    $protocol = Protocol::factory()->create(['procurement_id' => $procurement->id]);

    Bid::factory()->create([
        'procurement_id' => $procurement->id,
        'protocol_id' => $protocol->id,
        'organization_id' => null,
        'participant_ordinal' => 1,
    ]);
    Bid::factory()->create([
        'procurement_id' => $procurement->id,
        'protocol_id' => $protocol->id,
        'organization_id' => null,
        'participant_ordinal' => 2,
    ]);

    expect(Bid::query()->where('procurement_id', $procurement->id)->count())->toBe(2);

    expect(fn () => Bid::factory()->create([
        'procurement_id' => $procurement->id,
        'protocol_id' => $protocol->id,
        'organization_id' => null,
        'participant_ordinal' => 1,
    ]))->toThrow(QueryException::class);
});

test('заявка без протокола ловится дублем через NULLS NOT DISTINCT', function () {
    $procurement = Procurement::factory()->create();

    Bid::factory()->create([
        'procurement_id' => $procurement->id,
        'protocol_id' => null,
        'organization_id' => null,
        'participant_ordinal' => 1,
    ]);

    expect(fn () => Bid::factory()->create([
        'procurement_id' => $procurement->id,
        'protocol_id' => null,
        'organization_id' => null,
        'participant_ordinal' => 1,
    ]))->toThrow(QueryException::class);
});
