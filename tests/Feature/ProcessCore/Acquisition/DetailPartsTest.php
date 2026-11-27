<?php

declare(strict_types=1);

use App\Dto\Platforms\Eis\EisCardRoutes;
use App\Dto\Platforms\Eis\EisDetailExtension;
use App\Dto\Platforms\Eis\EisPolicy;
use App\Dto\Procurement\Acquisition\Detail\DetailFetched;
use App\Dto\Procurement\Acquisition\Detail\DetailProgress;
use App\Dto\Procurement\Acquisition\Detail\DetailResult;
use App\Dto\Procurement\Acquisition\Detail\ProcurementSnapshot;
use App\Dto\Procurement\Acquisition\Fetch\FetchDeferred;
use App\Dto\Procurement\Details\AwardData;
use App\Dto\Procurement\Details\SupplierResultsData;
use App\Dto\Procurement\Network\Outcome\TransportSucceeded;
use App\Dto\Procurement\Process\Inputs\DetailInput;
use App\Dto\Procurement\Process\Outcomes\StagePartial;
use App\Dto\Procurement\Process\Outcomes\StageSucceeded;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Dto\Procurement\Process\Primitives\RetryAt;
use App\Dto\Procurement\Process\Primitives\WorkId;
use App\Enums\Organization\ProcurementPartyRole;
use App\Enums\Procurement\AwardStatus;
use App\Enums\Procurement\Network\NetworkDeferralReason;
use App\Enums\Procurement\Platform\Completeness;
use App\Enums\Procurement\Platform\SnapshotConsistency;
use App\Enums\Procurement\Process\Outcomes\CheckpointStatus;
use App\Exceptions\Procurement\Process\DependencyUnavailable;
use App\Models\Organization\Organization;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Procurement;
use App\Repositories\Organization\ProcurementPartyStoreRepository;
use App\Repositories\Procurement\Crawler\DetailSnapshotRepository;
use App\Repositories\Procurement\Process\Work\WorkStoreRepository;
use App\Services\Platforms\Eis\Card\EisDetailParser;
use App\Services\Platforms\Eis\Card\SupplierResultsParser;
use App\Services\Platforms\Eis\EisDetailSource;
use App\Services\Procurement\Acquisition\Detail\DetailArtifacts;
use App\Services\Procurement\Acquisition\Detail\DetailHandler;
use App\Services\Procurement\Acquisition\Detail\DetailValueCodec;
use App\Services\Procurement\Platform\FetchOutcomeBridge;
use App\Services\Procurement\Process\Execution\ExecutionContextFactory;
use App\Services\Procurement\Process\Execution\WorkFinalizer;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery\ExpectationInterface;
use Tests\Support\Platforms\Contract\ContractRuntime;
use Tests\Support\Platforms\Contract\RecordingOutbound;
use Tests\Support\Platforms\Eis\EisContractManifest;
use Tests\Support\ProcessCore\ContractFixtures;
use Tests\Support\ProcessCore\DetailFixtures as F;
use Tests\Support\ProcessCore\DetailFixtureSource;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\LedgerFixtures;
use Tests\Support\ProcessCore\ListingFixtures;

pest()->group('listing-coverage', 'document-storage-and-references');

function detailFixtureHandler(DetailFixtureSource $source): DetailHandler
{
    return new DetailHandler(app(DetailSnapshotRepository::class), $source, app(DetailArtifacts::class), new FetchOutcomeBridge);
}

function eisDetailFixtureHandler(RecordingOutbound $outbound, bool $supplierResults = false): DetailHandler
{
    $routes = new EisCardRoutes(procedures: ['ea20' => [
        'common-info' => 'https://zakupki.gov.ru/common-info?regNumber={regNumber}',
        'documents' => 'https://zakupki.gov.ru/documents?regNumber={regNumber}',
        'event-journal' => null,
        'supplier-results' => $supplierResults ? 'https://zakupki.gov.ru/supplier-results?regNumber={regNumber}' : null,
    ]], procedureTypes: ['Электронный аукцион' => 'ea20']);
    $source = new EisDetailSource(policy: new EisPolicy(config()), routes: $routes, parser: app(EisDetailParser::class),
        outbound: $outbound, targetId: ContractFixtures::id());

    return new DetailHandler(store: app(DetailSnapshotRepository::class), source: $source,
        artifacts: app(DetailArtifacts::class), bridge: new FetchOutcomeBridge);
}

test('подтверждённая НМЦК из карточки публикуется в закупку с пустой ценой в CSV', function (): void {
    F::bind();
    ListingFixtures::bind();
    $run = ListingFixtures::run(from: '2026-10-31');
    $csv = <<<'CSV'
        Реестровый номер закупки;Наименование закупки;Этап закупки;Дата размещения;Дата обновления;Начальная (максимальная) цена контракта;Способ определения поставщика (подрядчика, исполнителя), подрядной организации (размещения закупки)
        00042;Работы;Подача заявок;31.10.2026;31.10.2026;;Электронный аукцион
        CSV;
    $page = EisContractManifest::listing()->parse(request: EisContractManifest::request(), response: ContractRuntime::response($csv, 'text/csv'));
    expect($page->summaries->items)->toHaveCount(1);
    ListingFixtures::finish(work: $run['work'], input: $run['input'], page: $page);
    $procurement = Procurement::query()->sole();
    expect($procurement->initial_price)->toBeNull();

    $common = file_get_contents(base_path(EisContractManifest::CARD.'contract-common.html'));
    $documents = file_get_contents(base_path(EisContractManifest::CARD.'contract-documents.html'));
    assert(is_string($common) && is_string($documents));
    $price = '<section><span class="section__title">Начальная (максимальная) цена контракта</span><span class="section__info">1 234 567,89 ₽</span></section>';
    $common = str_replace('</body>', $price.'</body>', $common);
    $outbound = new RecordingOutbound(array_map(
        static fn (string $body) => new TransportSucceeded(ContractRuntime::response($body, 'text/html')),
        [$common, $documents, $common],
    ));
    $workId = DB::table('prc_process_work')->where('stage', 'detail')->where('procurement_id', $procurement->id)->value('id');
    if (! is_string($workId)) {
        throw new LogicException('Ожидалась задача detail для закупки из листинга');
    }
    $work = new WorkId(new ProcessUuid($workId));
    $lease = FinalizeFixtures::running($work);
    $input = F::input($work);
    if (! $input instanceof DetailInput) {
        throw new LogicException('Ожидался вход задачи detail');
    }
    $outcome = eisDetailFixtureHandler($outbound)->execute(input: $input, execution: app(ExecutionContextFactory::class)->open($lease));
    expect($outcome)->toBeInstanceOf(StageSucceeded::class);
    assert($outcome instanceof StageSucceeded && $outcome->result instanceof DetailResult);
    $commonPart = app(DetailArtifacts::class)->read($outcome->result->progress->parts['common-info']);
    expect($commonPart->initialPrice)->toBe('1234567.89')
        ->and(app(WorkFinalizer::class)->finish(lease: $lease, outcome: $outcome)->status->value)->toBe('applied')
        ->and($procurement->refresh()->initial_price)->toBe('1234567.89');
    $priceInRevision = DB::table('prc_revisions')->where('procurement_id', $procurement->id)
        ->orderByDesc('id')->selectRaw("snapshot->>'initial_price' AS price")->value('price');
    expect($priceInRevision)->toBe('1234567.89');
});

test('при повторе результатов двух лотов анонимные победители одного ранга не записываются как один', function (): void {
    F::bind();
    $fixture = F::work(value: '00042');
    $common = file_get_contents(base_path(EisContractManifest::CARD.'contract-common.html'));
    $documents = file_get_contents(base_path(EisContractManifest::CARD.'contract-documents.html'));
    assert(is_string($common) && is_string($documents));
    $results = <<<'HTML'
        <a class="cardMainInfo__purchaseLink">№ 00042</a>
        <section class="blockInfo__section">
            <span class="section__title">Наименование протокола определения поставщика (подрядчика, исполнителя)</span>
            <span class="section__info">Протокол подведения итогов от 05.11.2026 №1</span>
        </section>
        <div id="supplier-def-result-participant-table-1"><table class="tableBlock"><tbody>
            <tr><td></td><td>1 — победитель</td><td>100,10</td></tr>
        </tbody></table></div>
        <div id="supplier-def-result-participant-table-2"><table class="tableBlock"><tbody>
            <tr><td></td><td>1 — победитель</td><td>200,20</td></tr>
        </tbody></table></div>
        HTML;
    $response = static fn (string $body) => new TransportSucceeded(ContractRuntime::response($body, 'text/html'));
    $execution = app(ExecutionContextFactory::class);
    $finalizer = app(WorkFinalizer::class);
    foreach ([[$common, $documents, $results, $common], [$results]] as $attempt => $bodies) {
        $outbound = new RecordingOutbound(array_map($response, $bodies));
        $handler = eisDetailFixtureHandler(outbound: $outbound, supplierResults: true);
        $lease = FinalizeFixtures::running($fixture['work']);
        $outcome = $handler->execute(input: $fixture['input'], execution: $execution->open($lease));
        expect($outcome)->toBeInstanceOf(StagePartial::class);
        assert($outcome instanceof StagePartial);
        expect($outcome->missing->values)->toBe(['supplier-results'])
            ->and($finalizer->finish(lease: $lease, outcome: $outcome)->status->value)->toBe('applied')
            ->and(DB::table('prc_detail_snapshots')->where('part_key', 'like', '%:published')->exists())->toBeFalse();
        foreach (['prc_protocols', 'prc_bids', 'prc_awards'] as $table) {
            expect(DB::table($table)->where('procurement_id', $fixture['p']->id)->exists())->toBeFalse();
        }
        expect($outbound->plans)->toHaveCount($attempt === 0 ? 3 : 1);
    }

    // Before the fix, a completed checkpoint could contain the bids and only the first award.
    $parsed = (new SupplierResultsParser)->parse(html: $results, strict: true);
    $legacy = F::snapshot(request: $fixture['request'], part: 'results');
    $snapshot = new ProcurementSnapshot(identity: $legacy->identity, title: $legacy->title, sourceStage: $legacy->sourceStage,
        parties: [], items: [], financing: [], documents: [], parts: $legacy->parts,
        results: new SupplierResultsData(protocol: $parsed->protocol, bids: $parsed->bids,
            award: new AwardData(winner: null, finalPrice: '100.10', status: AwardStatus::Awarded)));
    $result = new DetailResult(snapshot: $snapshot, progress: new DetailProgress,
        consistency: SnapshotConsistency::BoundedRevalidated, observedAt: new DateTimeImmutable);
    $lease = FinalizeFixtures::running($fixture['work']);
    expect($finalizer->complete(lease: $lease, result: $result)->status->value)->toBe('applied')
        ->and(DB::table('prc_process_work')->where('id', $fixture['work']->value->value)->value('reason_code'))->toBe('policy_review')
        ->and(DB::table('prc_detail_snapshots')->where('part_key', 'like', '%:published')->exists())->toBeFalse()
        ->and(DB::table('prc_bids')->where('procurement_id', $fixture['p']->id)->exists())->toBeFalse()
        ->and(DB::table('prc_awards')->where('procurement_id', $fixture['p']->id)->exists())->toBeFalse();
});

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
