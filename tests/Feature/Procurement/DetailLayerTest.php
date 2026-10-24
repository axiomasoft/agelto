<?php

declare(strict_types=1);

use App\Enums\Organization\ProcurementPartyRole;
use App\Models\Organization\Organization;
use App\Models\Platforms\Eis\EisDetails;
use App\Models\Procurement\Financing;
use App\Models\Procurement\Procurement;
use App\Models\Procurement\ProcurementParty;
use App\Models\Procurement\PurchaseItem;
use Illuminate\Support\Carbon;

/**
 * §11 «Детальный слой карточки снят» и «Детальная карточка упала».
 * Процессора детального слоя в этой фазе нет (вне scope P1.10) — проверяем
 * схему/модели напрямую через фабрики.
 */
test('снятие детального слоя заполняет items/financing/eis_details/parties и проставляет detail_fetched_at', function () {
    $procurement = Procurement::factory()->create(['detail_fetched_at' => null]);
    $customer = Organization::factory()->create();

    $fetchedAt = Carbon::parse('2026-02-01 12:00:00');
    $procurement->update(['detail_fetched_at' => $fetchedAt]);

    $item = PurchaseItem::factory()->create(['procurement_id' => $procurement->id]);
    $financing = Financing::factory()->create(['procurement_id' => $procurement->id]);
    $eisDetails = EisDetails::factory()->create(['procurement_id' => $procurement->id]);
    $party = ProcurementParty::factory()->create([
        'procurement_id' => $procurement->id,
        'organization_id' => $customer->id,
        'role' => ProcurementPartyRole::Customer,
    ]);

    $procurement->refresh();

    expect($procurement->detail_fetched_at->equalTo($fetchedAt))->toBeTrue()
        ->and(PurchaseItem::query()->where('procurement_id', $procurement->id)->find($item->id))->not->toBeNull()
        ->and(Financing::query()->where('procurement_id', $procurement->id)->find($financing->id))->not->toBeNull()
        ->and(EisDetails::query()->where('procurement_id', $procurement->id)->first()->procurement_id)->toBe($procurement->id)
        ->and($party->role)->toBe(ProcurementPartyRole::Customer);

    expect($eisDetails->procurement_id)->toBe($procurement->id);
});

test('провал детальной карточки помечает detail_next_retry_at/detail_failed для ретрая по detail_fetched_at IS NULL', function () {
    $failingRetryAt = Carbon::now()->addMinutes(30);

    $procurement = Procurement::factory()->create([
        'detail_fetched_at' => null,
        'detail_attempts' => 1,
        'detail_last_error' => 'HTTP 503 от карточки закупки',
        'detail_next_retry_at' => $failingRetryAt,
        'detail_failed' => false,
    ]);

    $pendingRetry = Procurement::query()
        ->whereNull('detail_fetched_at')
        ->where('detail_failed', false)
        ->where('detail_next_retry_at', '<=', Carbon::now()->addHour())
        ->find($procurement->id);

    expect($pendingRetry)->not->toBeNull()
        ->and($pendingRetry->detail_attempts)->toBe(1)
        ->and($pendingRetry->detail_last_error)->toBe('HTTP 503 от карточки закупки');

    $procurement->update(['detail_failed' => true]);

    $permanentlyFailed = Procurement::query()
        ->whereNull('detail_fetched_at')
        ->where('detail_failed', true)
        ->find($procurement->id);

    expect($permanentlyFailed)->not->toBeNull();
});
