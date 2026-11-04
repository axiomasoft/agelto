<?php

declare(strict_types=1);

use App\Dto\Procurement\Acquisition\DetailProgress;
use App\Dto\Procurement\Acquisition\DetailResult;
use App\Dto\Procurement\Acquisition\ProcurementSnapshot;
use App\Dto\Procurement\Process\StageSucceeded;
use App\Enums\Procurement\Platform\SnapshotConsistency;
use App\Enums\Procurement\ProcurementStatus;
use App\Models\Procurement\Platform\Registry;
use App\Models\Procurement\Platform\StatusMapping;
use App\Models\Procurement\Procurement;
use App\Services\Procurement\Acquisition\StatusRemapper;
use App\Services\Procurement\Acquisition\UnmappedStatusReporter;
use App\Services\Procurement\Process\WorkFinalizer;
use App\Services\Procurement\StatusMappingService;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\DetailFixtures as F;
use Tests\Support\ProcessCore\FinalizeFixtures;

pest()->group('unknown-status-mapping');

test('fifty unknown statuses share one scoped incident and consume zero item faults', function (): void {
    F::bind();
    $registry = Registry::factory()->eis()->create();
    for ($i = 0; $i < 50; $i++) {
        $f = F::work($registry, 'unknown-'.$i, 'unmapped-fixture');
        $s = F::snapshot($f['request'], 'attributes');
        $s = new ProcurementSnapshot($s->identity, $s->title, 'unmapped-fixture', [], [], [], [], $s->parts);
        app(WorkFinalizer::class)->finish(FinalizeFixtures::running($f['work']), new StageSucceeded(new DetailResult($s, new DetailProgress, SnapshotConsistency::BoundedRevalidated, new DateTimeImmutable)));
    }
    expect(DB::table('prc_procurements')->where('status', 'unknown')->count())->toBe(50)
        ->and(DB::table('prc_process_incidents')->where('kind', 'unmapped_status')->count())->toBe(1)
        ->and(DB::table('prc_process_work')->sum('item_failures'))->toBe(0)
        ->and(DB::table('prc_process_work')->where('state', 'succeeded')->count())->toBe(50);
    $other = Registry::factory()->create(['platform_id' => $registry->platform_id, 'code' => 'second']);
    $p = Procurement::factory()->create(['registry_id' => $other->id, 'platform_id' => $other->platform_id, 'source_stage' => 'unmapped-fixture', 'status' => 'unknown']);
    DB::transaction(fn () => app(UnmappedStatusReporter::class)->record($p));
    expect(DB::table('prc_process_incidents')->where('kind', 'unmapped_status')->count())->toBe(2);
});

test('exact registry precedes default and remap publishes one coherent revision and event', function (): void {
    F::bind();
    $registry = Registry::factory()->eis()->create();
    StatusMapping::query()->create(['platform_id' => $registry->platform_id, 'registry_id' => null, 'source_stage' => 'mapped-fixture', 'canonical_status' => 'bidding']);
    expect(app(StatusMappingService::class)->resolve($registry->id, 'mapped-fixture')->status)->toBe(ProcurementStatus::Bidding);
    StatusMapping::query()->create(['platform_id' => null, 'registry_id' => $registry->id, 'source_stage' => 'mapped-fixture', 'canonical_status' => 'evaluation']);
    expect(app(StatusMappingService::class)->resolve($registry->id, 'mapped-fixture')->status)->toBe(ProcurementStatus::Evaluation);
    $f = F::work($registry, 'remap', 'raw-remap');
    $p = $f['p'];
    StatusMapping::query()->where('registry_id', $registry->id)->where('source_stage', 'raw-remap')->update(['canonical_status' => 'evaluation']);
    expect(app(StatusRemapper::class)->remap($p->id, $p->head_generation))->toBeTrue();
    expect($p->refresh()->status)->toBe(ProcurementStatus::Evaluation)->and($p->revision_count)->toBe(2)
        ->and(DB::table('prc_events')->where('procurement_id', $p->id)->where('event_type', 'status_changed')->count())->toBe(2);
    expect(app(StatusRemapper::class)->remap($p->id, 1))->toBeFalse()
        ->and(app(StatusRemapper::class)->remap($p->id, $p->head_generation))->toBeTrue()
        ->and($p->refresh()->revision_count)->toBe(2);
});
