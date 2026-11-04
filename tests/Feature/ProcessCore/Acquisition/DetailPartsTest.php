<?php

declare(strict_types=1);

use App\Dto\Platforms\Eis\EisDetailExtension;
use App\Dto\Procurement\Acquisition\DetailFetched;
use App\Dto\Procurement\Acquisition\DetailProgress;
use App\Dto\Procurement\Acquisition\DetailResult;
use App\Dto\Procurement\Acquisition\FetchDeferred;
use App\Dto\Procurement\Network\TransportSucceeded;
use App\Dto\Procurement\Process\RetryAt;
use App\Dto\Procurement\Process\StagePartial;
use App\Dto\Procurement\Process\StageSucceeded;
use App\Enums\Organization\ProcurementPartyRole;
use App\Enums\Procurement\Network\NetworkDeferralReason;
use App\Enums\Procurement\Platform\Completeness;
use App\Enums\Procurement\Platform\SnapshotConsistency;
use App\Enums\Procurement\Process\CheckpointStatus;
use App\Exceptions\Procurement\Process\DependencyUnavailable;
use App\Models\Organization\Organization;
use App\Models\Procurement\Document\Document;
use App\Repositories\Organization\ProcurementPartyStoreRepository;
use App\Repositories\Procurement\Crawler\DetailSnapshotRepository;
use App\Repositories\Procurement\Process\WorkStoreRepository;
use App\Services\Procurement\Acquisition\DetailArtifacts;
use App\Services\Procurement\Acquisition\DetailHandler;
use App\Services\Procurement\Acquisition\DetailValueCodec;
use App\Services\Procurement\Platform\FetchOutcomeBridge;
use App\Services\Procurement\Process\ExecutionContextFactory;
use App\Services\Procurement\Process\WorkFinalizer;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery\ExpectationInterface;
use Tests\Support\Platforms\Contract\ContractRuntime;
use Tests\Support\Platforms\Contract\RecordingOutbound;
use Tests\Support\Platforms\Eis\EisContractManifest;
use Tests\Support\ProcessCore\DetailFixtures as F;
use Tests\Support\ProcessCore\DetailFixtureSource;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\LedgerFixtures;

pest()->group('listing-coverage', 'document-storage-and-references');

function detailFixtureHandler(DetailFixtureSource $source): DetailHandler
{
    return new DetailHandler(app(DetailSnapshotRepository::class), $source, app(DetailArtifacts::class), new FetchOutcomeBridge);
}

test('partial part retains independent checkpoints and resumes without publishing a mixed head', function (): void {
    F::bind();
    $f = F::work();
    $r = $f['request'];
    $document = Document::factory()->create(['procurement_id' => $f['p']->id]);
    $customer = Organization::factory()->create();
    $parties = app(ProcurementPartyStoreRepository::class);
    $parties->upsertParty($f['p']->id, $customer->id, ProcurementPartyRole::Customer, null);
    $parties->setCustomerOrg($f['p'], $customer->id);
    $first = new DetailFetched(F::snapshot($r, 'attributes'));
    $source = new DetailFixtureSource([$first, new DetailFetched(F::snapshot($r, 'documents', Completeness::Partial))]);
    $lease = FinalizeFixtures::running($f['work']);
    $out = detailFixtureHandler($source)->execute($f['input'], app(ExecutionContextFactory::class)->open($lease));
    expect($out)->toBeInstanceOf(StagePartial::class);
    app(WorkFinalizer::class)->finish($lease, $out);
    expect(DB::table('prc_process_work')->value('state'))->toBe('ready')
        ->and(DB::table('prc_detail_snapshots')->where('part_key', 'like', '%:attributes')->count())->toBe(1)
        ->and(DB::table('prc_revisions')->count())->toBe(1);
    expect(DB::table('prc_documents')->where('id', $document->id)->exists())->toBeTrue();
    $second = new DetailFixtureSource([new DetailFetched(F::snapshot($r, 'documents')), new DetailFetched(F::snapshot($r, 'results')), $first]);
    $lease = FinalizeFixtures::running($f['work']);
    $out = detailFixtureHandler($second)->execute($f['input'], app(ExecutionContextFactory::class)->open($lease));
    expect($out)->toBeInstanceOf(StageSucceeded::class)->and($second->calls)->toBe(['documents', 'results', 'attributes']);
    app(WorkFinalizer::class)->finish($lease, $out);
    expect(DB::table('prc_process_work')->value('state'))->toBe('succeeded')
        ->and(DB::table('prc_process_work')->value('item_failures'))->toBe(0)
        ->and(DB::table('prc_operation_intents')->value('state'))->toBe('terminal')
        ->and(DB::table('prc_operation_intents')->value('next_due_at'))->not->toBeNull();
    expect(DB::table('prc_procurement_parties')->where('organization_id', $customer->id)->count())->toBe(1)
        ->and($f['p']->refresh()->customer_org_id)->toBe($customer->id)
        ->and(DB::table('prc_documents')->where('id', $document->id)->exists())->toBeTrue();
});

test('EIS parts roundtrip immutable artifacts with optional tabs and fresh control', function (): void {
    F::bind();
    $f = F::work(value: '00042');
    $common = file_get_contents(base_path('tests/Fixtures/Platforms/Eis/Card/contract-common.html'));
    $documents = file_get_contents(base_path('tests/Fixtures/Platforms/Eis/Card/contract-documents.html'));
    if ($common === false || $documents === false) {
        throw new LogicException('Missing fixtures');
    }
    $outbound = new RecordingOutbound(array_map(
        static fn (string $body) => new TransportSucceeded(ContractRuntime::response($body, 'text/html')),
        [$common, $documents, $common]));
    $source = EisContractManifest::detail($outbound);
    $handler = new DetailHandler(app(DetailSnapshotRepository::class), $source, app(DetailArtifacts::class), new FetchOutcomeBridge);
    $lease = FinalizeFixtures::running($f['work']);
    $out = $handler->execute($f['input'], app(ExecutionContextFactory::class)->open($lease));
    expect($out)->toBeInstanceOf(StageSucceeded::class)->and($outbound->plans)->toHaveCount(3);
    if (! $out instanceof StageSucceeded || ! $out->result instanceof DetailResult) {
        throw new LogicException('Missing detail result');
    }
    expect($out->result->snapshot->documents)->toBe([])->and($out->result->snapshot->extension)->toBeInstanceOf(EisDetailExtension::class);
    $codec = app(DetailValueCodec::class);
    expect($codec->encode($codec->decode($codec->encode($out->result))))->toBe($codec->encode($out->result));
    app(WorkFinalizer::class)->finish($lease, $out);
    expect(DB::table('prc_process_work')->value('state'))->toBe('succeeded')->and(DB::table('prc_detail_snapshots')->where('part_key', 'like', '%:published')->count())->toBe(1);
});

test('lease takeover rejects old checkpoint and final publication', function (): void {
    F::bind();
    $f = F::work();
    $lease = FinalizeFixtures::running($f['work']);
    FinalizeFixtures::expire($f['work']);
    FinalizeFixtures::revoke($f['work']);
    $saved = app(WorkStoreRepository::class)->checkpoint($lease, new DetailProgress, 0);
    expect($saved->status)->toBe(CheckpointStatus::LostOwnership);
    $out = new StageSucceeded(new DetailResult(F::snapshot($f['request'], 'attributes'), new DetailProgress, SnapshotConsistency::BoundedRevalidated, new DateTimeImmutable));
    expect(app(WorkFinalizer::class)->finish($lease, $out)->status->value)->toBe('lost_ownership')
        ->and(DB::table('prc_detail_snapshots')->count())->toBe(1)->and(DB::table('prc_revisions')->count())->toBe(1);
});

test('changed control discards old parts and revisionless tab mutation remains bounded evidence', function (): void {
    F::bind();
    $f = F::work();
    $r = $f['request'];
    $source = new DetailFixtureSource([new DetailFetched(F::snapshot($r, 'attributes')), new DetailFetched(F::snapshot($r, 'documents')),
        new DetailFetched(F::snapshot($r, 'results')), new DetailFetched(F::snapshot($r, 'attributes', body: 'B'))]);
    $lease = FinalizeFixtures::running($f['work']);
    $out = detailFixtureHandler($source)->execute($f['input'], app(ExecutionContextFactory::class)->open($lease));
    expect($out)->toBeInstanceOf(StagePartial::class);
    if (! $out instanceof StagePartial || ! $out->checkpoint instanceof DetailProgress) {
        throw new LogicException('Missing partial');
    }
    expect($out->checkpoint->parts)->toBe([])->and($out->checkpoint->pass)->toBe(2);
    app(WorkFinalizer::class)->finish($lease, $out);
    $source = new DetailFixtureSource([new DetailFetched(F::snapshot($r, 'attributes')), new DetailFetched(F::snapshot($r, 'documents', body: 'late-tab')),
        new DetailFetched(F::snapshot($r, 'results')), new DetailFetched(F::snapshot($r, 'attributes'))]);
    $lease = FinalizeFixtures::running($f['work']);
    $out = detailFixtureHandler($source)->execute($f['input'], app(ExecutionContextFactory::class)->open($lease));
    if (! $out instanceof StageSucceeded || ! $out->result instanceof DetailResult) {
        throw new LogicException('Missing result');
    }
    expect($out->result->consistency)->toBe(SnapshotConsistency::BoundedRevalidated);
    app(WorkFinalizer::class)->finish($lease, $out);
});

test('boundary codec accepts JSONB key order and rejects extra fields and scalar coercion', function (): void {
    $codec = new DetailValueCodec;
    $payload = json_decode($codec->encode(new DetailProgress), true, flags: JSON_THROW_ON_ERROR);
    if (! is_array($payload) || ! isset($payload['value']) || ! is_array($payload['value'])) {
        throw new LogicException('Invalid fixture payload');
    }
    $payload['value'] = array_reverse($payload['value'], true);
    $payload = array_reverse($payload, true);
    expect($codec->decode(json_encode($payload, JSON_THROW_ON_ERROR)))->toBeInstanceOf(DetailProgress::class);
    $payload['value']['pass'] = '1';
    expect(fn () => $codec->decode(json_encode($payload, JSON_THROW_ON_ERROR)))->toThrow(InvalidArgumentException::class);
    $payload['value']['pass'] = 1;
    $payload['value']['extra'] = true;
    expect(fn () => $codec->decode(json_encode($payload, JSON_THROW_ON_ERROR)))->toThrow(InvalidArgumentException::class);
});

test('unstable control stops after three passes and retains explicit evidence without publication', function (): void {
    F::bind();
    $f = F::work();
    for ($pass = 1; $pass <= 3; $pass++) {
        $source = new DetailFixtureSource([
            new DetailFetched(F::snapshot($f['request'], 'attributes')),
            new DetailFetched(F::snapshot($f['request'], 'documents')),
            new DetailFetched(F::snapshot($f['request'], 'results')),
            new DetailFetched(F::snapshot($f['request'], 'attributes', body: 'changed')),
        ]);
        $lease = FinalizeFixtures::running($f['work']);
        $out = detailFixtureHandler($source)->execute($f['input'], app(ExecutionContextFactory::class)->open($lease));
        app(WorkFinalizer::class)->finish($lease, $out);
    }
    expect(DB::table('prc_process_work')->value('state'))->toBe('blocked')
        ->and(DB::table('prc_process_work')->value('item_failures'))->toBe(0)
        ->and(DB::table('prc_detail_snapshots')->where('part_key', 'like', '%:unstable_source')->count())->toBe(1)
        ->and(DB::table('prc_detail_snapshots')->where('part_key', 'like', '%:published')->count())->toBe(0)
        ->and(DB::table('prc_revisions')->count())->toBe(1);
});

test('transport deferral retains a committed part without item fault or cursor advancement', function (): void {
    F::bind();
    $f = F::work();
    $source = new DetailFixtureSource([new DetailFetched(F::snapshot($f['request'], 'attributes')),
        new FetchDeferred(NetworkDeferralReason::NoRoute, new RetryAt(new DateTimeImmutable('+1 minute')))]);
    $lease = FinalizeFixtures::running($f['work']);
    $out = detailFixtureHandler($source)->execute($f['input'], app(ExecutionContextFactory::class)->open($lease));
    app(WorkFinalizer::class)->finish($lease, $out);
    expect(DB::table('prc_process_work')->value('state'))->toBe('deferred')
        ->and(DB::table('prc_process_work')->value('reason_code'))->toBe('no_route')
        ->and(DB::table('prc_process_work')->value('item_failures'))->toBe(0)
        ->and(DB::table('prc_process_work')->value('checkpoint_seq'))->toBe(1)
        ->and(DB::table('prc_detail_snapshots')->where('part_key', 'like', '%:attributes')->count())->toBe(1)
        ->and(DB::table('prc_detail_snapshots')->where('part_key', 'like', '%:published')->count())->toBe(0);
});

test('mandatory journal failure rolls back detail publication and intent due together', function (): void {
    F::bind();
    $f = F::work();
    $lease = FinalizeFixtures::running($f['work']);
    DB::unprepared("CREATE FUNCTION pg_temp.p216_reject_journal() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'fixture journal failure'; END $$");
    DB::unprepared('CREATE TRIGGER p216_reject_journal BEFORE INSERT ON prc_process_transitions FOR EACH ROW EXECUTE FUNCTION pg_temp.p216_reject_journal()');
    $out = new StageSucceeded(new DetailResult(F::snapshot($f['request'], 'attributes'), new DetailProgress, SnapshotConsistency::BoundedRevalidated, new DateTimeImmutable));
    expect(fn () => app(WorkFinalizer::class)->finish($lease, $out))->toThrow(QueryException::class);
    expect(DB::table('prc_process_work')->value('state'))->toBe('running')
        ->and(DB::table('prc_operation_intents')->value('state'))->toBe('active')
        ->and(DB::table('prc_operation_intents')->value('next_due_at'))->toBeNull()
        ->and(DB::table('prc_detail_snapshots')->count())->toBe(1);
});

test('detail observation schema refuses an unknown completeness value', function (): void {
    F::bind();
    $f = F::work();
    expect(LedgerFixtures::sqlState(fn () => DB::table('prc_detail_snapshots')->update(['completeness' => 'success-ish'])))->toBe('23514');
});

test('artifact persistence failure is a storage dependency rather than a handler defect', function (): void {
    F::bind();
    $f = F::work();
    $disk = Mockery::mock(FilesystemAdapter::class);
    $expectation = $disk->shouldReceive('put');
    if (! $expectation instanceof ExpectationInterface) {
        throw new LogicException('Expected a storage mock expectation');
    }
    $expectation->andReturn(false);
    Storage::shouldReceive('disk')->with('local')->once()->andReturn($disk);
    expect(fn () => app(DetailArtifacts::class)->put(F::snapshot($f['request'], 'attributes')))
        ->toThrow(DependencyUnavailable::class, 'storage');
});
