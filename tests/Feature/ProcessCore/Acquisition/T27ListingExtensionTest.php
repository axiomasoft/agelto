<?php

declare(strict_types=1);

use App\Dto\Platforms\Eis\EisDetailExtension;
use App\Dto\Procurement\Acquisition\DetailProgress;
use App\Dto\Procurement\Acquisition\DetailResult;
use App\Dto\Procurement\Acquisition\ProcurementSnapshot;
use App\Dto\Procurement\Acquisition\ProcurementSummary;
use App\Dto\Procurement\Acquisition\StatusResult;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\StageSucceeded;
use App\Enums\Procurement\Platform\Completeness;
use App\Enums\Procurement\Platform\SnapshotConsistency;
use App\Enums\Procurement\Process\StageCode;
use App\Enums\Procurement\ProcurementStatus;
use App\Models\Procurement\Platform\Registry;
use App\Models\Procurement\Platform\StatusMapping;
use App\Repositories\Procurement\Crawler\RefreshRepository;
use App\Repositories\Procurement\ProcurementReadRepository;
use App\Services\Procurement\Acquisition\StatusRemapper;
use App\Services\Procurement\Process\WorkFinalizer;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\DetailFixtures;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\ListingFixtures;

pest()->group('t07', 't27', 't42', 'p23');

test('T27 listing сохраняет typed extension, историю и действующие detail/status work после публикации карточки', function (): void {
    ExecutionFixtures::authority([StageCode::Listing, StageCode::Detail, StageCode::StatusRefresh]);
    ListingFixtures::bindLocal();
    DetailFixtures::bindLocal();
    $registry = Registry::factory()->eis()->create();
    $f = DetailFixtures::work($registry);
    $p = $f['p'];
    $extension = new EisDetailExtension(etpName: 'Current ETP', contactPersonName: 'Current contact', hasCommonInfo: true);
    $part = DetailFixtures::snapshot($f['request'], 'attributes');
    $snapshot = new ProcurementSnapshot($part->identity, $part->title, $part->sourceStage, [], [], [], [], $part->parts, $extension);
    $detail = new StageSucceeded(new DetailResult($snapshot, new DetailProgress, SnapshotConsistency::BoundedRevalidated, new DateTimeImmutable));
    $finalizer = app(WorkFinalizer::class);
    $finalizer->finish(FinalizeFixtures::running($f['work']), $detail);
    $p->refresh();
    $read = app(ProcurementReadRepository::class);
    $before = $read->findLatestRevision($p->id) ?? throw new LogicException('Missing detail revision');
    $head = $p->head_generation;
    $revisions = $p->revision_count;
    $events = DB::table('prc_events')->where('procurement_id', $p->id)->count();
    expect($before->snapshot['extension'])->toEqual(get_object_vars($extension));

    // Both operations were allocated and claimed against the detailed head BEFORE listing.
    DB::update('UPDATE prc_operation_intents SET next_due_at=clock_timestamp() WHERE id=?', [$f['input']->intentId->value]);
    $refresh = app(RefreshRepository::class);
    $detailWork = $refresh->scheduleDetail($p->id) ?? throw new LogicException('Missing detail cycle');
    $detailLease = FinalizeFixtures::running($detailWork);
    $statusWork = $refresh->scheduleStatus($p->id) ?? throw new LogicException('Missing status cycle');
    $statusLease = FinalizeFixtures::running($statusWork);
    $detailDue = DB::table('prc_operation_intents')->where('id', $f['input']->intentId->value)->value('next_due_at');

    $listing = ListingFixtures::run(registry: $registry);
    $summary = new ProcurementSummary($snapshot->identity, $p->title, $p->source_stage,
        DateTimeImmutable::createFromInterface($p->published_at),
        DateTimeImmutable::createFromInterface($p->source_updated_at ?? $p->published_at), ContentHash::of('unchanged listing'),
        updatedAtDayPrecision: $p->source_updated_has_day_precision, initialPrice: $p->initial_price, currency: $p->currency);
    ListingFixtures::finish($listing['work'], $listing['input'], ListingFixtures::page([$summary]));
    $after = $read->findLatestRevision($p->id) ?? throw new LogicException('Missing listing revision');
    expect($after->id)->toBe($before->id)->and($after->snapshot)->toBe($before->snapshot)
        ->and($after->snapshot_hash)->toBe($before->snapshot_hash)
        ->and($p->refresh()->head_generation)->toBe($head)->and($p->revision_count)->toBe($revisions)
        ->and(DB::table('prc_events')->where('procurement_id', $p->id)->count())->toBe($events)
        ->and(DB::table('prc_eis_details')->where('procurement_id', $p->id)->value('etp_name'))->toBe('Current ETP');

    // A no-extension status observation must use the same preserved canonical extension.
    $finalizer->finish($statusLease, new StageSucceeded(new StatusResult($part, new DateTimeImmutable)));
    expect(DB::table('prc_procurements')->where('id', $p->id)->whereRaw('next_status_due_at > clock_timestamp()')->exists())->toBeTrue()
        ->and(DB::table('prc_operation_intents')->where('id', $f['input']->intentId->value)->value('next_due_at'))->toBe($detailDue);
    $finalizer->finish($detailLease, $detail);
    foreach ([$detailWork, $statusWork] as $work) {
        expect(DB::table('prc_process_work')->where('id', $work->value->value)->value('state'))->toBe('succeeded');
    }
    expect($read->findLatestRevision($p->id)?->id)->toBe($before->id)
        ->and($p->refresh()->head_generation)->toBe($head)->and($p->revision_count)->toBe($revisions)
        ->and(DB::table('prc_events')->where('procurement_id', $p->id)->count())->toBe($events);

    // A genuine mapping change creates exactly one revision, retaining the extension.
    StatusMapping::query()->updateOrCreate(['registry_id' => $registry->id, 'source_stage' => $p->source_stage],
        ['platform_id' => null, 'canonical_status' => 'evaluation']);
    expect(app(StatusRemapper::class)->remap($p->id, $head))->toBeTrue()
        ->and($p->refresh()->status)->toBe(ProcurementStatus::Evaluation)
        ->and($p->head_generation)->toBe($head + 1)->and($p->revision_count)->toBe($revisions + 1)
        ->and($read->findLatestRevision($p->id)?->snapshot['extension'])->toBe($before->snapshot['extension'])
        ->and($before->fresh()?->snapshot)->toBe($before->snapshot)
        ->and(DB::table('prc_eis_details')->where('procurement_id', $p->id)->value('etp_name'))->toBe('Current ETP');
});

test('T27 explicit CompleteEmpty очищает extension, а следующий listing сохраняет именно пустой typed snapshot', function (): void {
    ExecutionFixtures::authority([StageCode::Listing, StageCode::Detail, StageCode::StatusRefresh]);
    ListingFixtures::bindLocal();
    DetailFixtures::bindLocal();
    $registry = Registry::factory()->eis()->create();
    $listing = ListingFixtures::run(registry: $registry);
    $f = DetailFixtures::work($registry, 'empty-extension');
    $p = $f['p'];
    $make = function (EisDetailExtension $extension, Completeness $completeness) use ($f): StageSucceeded {
        $part = DetailFixtures::snapshot($f['request'], 'attributes', $completeness);
        $snapshot = new ProcurementSnapshot($part->identity, $part->title, $part->sourceStage, [], [], [], [], $part->parts, $extension);

        return new StageSucceeded(new DetailResult($snapshot, new DetailProgress, SnapshotConsistency::BoundedRevalidated, new DateTimeImmutable));
    };
    $finalizer = app(WorkFinalizer::class);
    $finalizer->finish(FinalizeFixtures::running($f['work']), $make(new EisDetailExtension(etpName: 'Old ETP', hasCommonInfo: true), Completeness::Complete));
    expect(DB::table('prc_eis_details')->where('procurement_id', $p->id)->value('etp_name'))->toBe('Old ETP');
    DB::update('UPDATE prc_operation_intents SET next_due_at=clock_timestamp() WHERE id=?', [$f['input']->intentId->value]);
    $work = app(RefreshRepository::class)->scheduleDetail($p->id) ?? throw new LogicException('Missing empty detail cycle');
    $empty = new EisDetailExtension(hasCommonInfo: true);
    $finalizer->finish(FinalizeFixtures::running($work), $make($empty, Completeness::CompleteEmpty));
    $p->refresh();
    $head = $p->head_generation;
    $read = app(ProcurementReadRepository::class);
    $before = $read->findLatestRevision($p->id) ?? throw new LogicException('Missing empty revision');
    expect($head)->toBe(3)->and($before->snapshot['extension'])->toEqual(get_object_vars($empty))
        ->and(DB::table('prc_eis_details')->where('procurement_id', $p->id)->value('etp_name'))->toBeNull();
    $summary = new ProcurementSummary($f['request']->identity, $p->title, $p->source_stage,
        DateTimeImmutable::createFromInterface($p->published_at),
        DateTimeImmutable::createFromInterface($p->source_updated_at ?? $p->published_at), ContentHash::of('empty extension listing'),
        updatedAtDayPrecision: $p->source_updated_has_day_precision, initialPrice: $p->initial_price, currency: $p->currency);
    ListingFixtures::finish($listing['work'], $listing['input'], ListingFixtures::page([$summary]));
    expect($read->findLatestRevision($p->id)?->snapshot)->toBe($before->snapshot)
        ->and($read->findLatestRevision($p->id)?->id)->toBe($before->id)
        ->and($p->refresh()->head_generation)->toBe($head)->and($p->revision_count)->toBe(3)
        ->and(DB::table('prc_eis_details')->where('procurement_id', $p->id)->value('etp_name'))->toBeNull();
});
