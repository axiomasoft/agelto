<?php

declare(strict_types=1);

use App\Dto\Procurement\Acquisition\DetailProgress;
use App\Dto\Procurement\Acquisition\DetailResult;
use App\Dto\Procurement\Process\ArtifactRef;
use App\Dto\Procurement\Process\StageFailedItem;
use App\Dto\Procurement\Process\StageSucceeded;
use App\Enums\Organization\ProcurementPartyRole;
use App\Enums\Procurement\Platform\SnapshotConsistency;
use App\Enums\Procurement\Process\ItemFailureReason;
use App\Models\Organization\Organization;
use App\Models\Platforms\Eis\EisDetails;
use App\Models\Procurement\Financing;
use App\Models\Procurement\ProcurementParty;
use App\Models\Procurement\PurchaseItem;
use App\Services\Procurement\Process\WorkFinalizer;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\DetailFixtures as F;
use Tests\Support\ProcessCore\ExecutionFixtures as E;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\LedgerFixtures;

beforeEach(fn () => F::bind());

test('Process detail publication records successful snapshot and preserves items financing extension and parties relations', function () {
    $f = F::work();
    $procurement = $f['p'];
    $customer = Organization::factory()->create();

    app(WorkFinalizer::class)->finish(FinalizeFixtures::running($f['work']), new StageSucceeded(
        new DetailResult(F::snapshot($f['request'], 'attributes'), new DetailProgress,
            SnapshotConsistency::BoundedRevalidated, new DateTimeImmutable)));

    $item = PurchaseItem::factory()->create(['procurement_id' => $procurement->id]);
    $financing = Financing::factory()->create(['procurement_id' => $procurement->id]);
    $eisDetails = EisDetails::factory()->create(['procurement_id' => $procurement->id]);
    $party = ProcurementParty::factory()->create([
        'procurement_id' => $procurement->id,
        'organization_id' => $customer->id,
        'role' => ProcurementPartyRole::Customer,
    ]);

    $procurement->refresh();

    expect(DB::table('prc_detail_snapshots')->where('procurement_id', $procurement->id)->where('part_key', 'like', '%:published')->count())->toBe(1)
        ->and(E::work($f['work'])->state)->toBe('succeeded')
        ->and(PurchaseItem::query()->where('procurement_id', $procurement->id)->find($item->id))->not->toBeNull()
        ->and(Financing::query()->where('procurement_id', $procurement->id)->find($financing->id))->not->toBeNull()
        ->and(EisDetails::query()->where('procurement_id', $procurement->id)->first()->procurement_id)->toBe($procurement->id)
        ->and($party->role)->toBe(ProcurementPartyRole::Customer);

    expect($eisDetails->procurement_id)->toBe($procurement->id);
});

test('Process detail failure retains retry evidence and budget exhaustion without a successful snapshot', function () {
    $f = F::work();
    $failure = new StageFailedItem(ItemFailureReason::ContractRejected,
        new ArtifactRef('fixture', 'detail/rejected', LedgerFixtures::hash('detail-rejected')));
    app(WorkFinalizer::class)->finish(FinalizeFixtures::running($f['work']), $failure);
    $work = E::work($f['work']);
    expect($work->state)->toBe('deferred')->and($work->item_failures)->toBe(1)
        ->and($work->due_at)->not->toBeNull()
        ->and($work->reason_code)->toBe('item_retry')
        ->and(DB::table('prc_process_attempts')->where('work_id', $f['work']->value->value)->sole()->terminal_kind)->toBe('failed_item');

    for ($attempt = 2; $attempt <= 3; $attempt++) {
        app(WorkFinalizer::class)->finish(FinalizeFixtures::rerun($f['work']), $failure);
    }
    expect(E::work($f['work'])->state)->toBe('failed_item')
        ->and(E::work($f['work'])->item_failures)->toBe(3)
        ->and(E::work($f['work'])->reason_code)->toBe('contract_rejected')
        ->and(DB::table('prc_process_transitions')->where('work_id', $f['work']->value->value)->whereIn('new_state', ['deferred', 'failed_item'])->count())->toBe(3)
        ->and(DB::table('prc_detail_snapshots')->where('procurement_id', $f['p']->id)->where('part_key', 'like', '%:published')->count())->toBe(0);
});
