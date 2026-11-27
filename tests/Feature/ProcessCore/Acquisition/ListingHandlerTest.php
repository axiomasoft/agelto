<?php

declare(strict_types=1);

use App\Dto\Platforms\Eis\EisListingCursor;
use App\Dto\Platforms\Eis\EisListingFilter;
use App\Dto\Platforms\Eis\EisPolicy;
use App\Dto\Procurement\Acquisition\Listing\ListingRequest;
use App\Dto\Procurement\Acquisition\Listing\ListingResult;
use App\Dto\Procurement\Network\Http\DecodedTextBody;
use App\Dto\Procurement\Network\Http\ResponseEnvelope;
use App\Dto\Procurement\Network\Outcome\TransportSucceeded;
use App\Dto\Procurement\Process\Outcomes\StageBlocked;
use App\Dto\Procurement\Process\Outcomes\StageSucceeded;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Dto\Procurement\Process\Primitives\VersionedPayload;
use App\Enums\Procurement\Platform\TerminalProof;
use App\Repositories\Procurement\Crawler\ListingStoreRepository;
use App\Services\Platforms\Eis\EisListingPartitioner;
use App\Services\Platforms\Eis\EisListingSource;
use App\Services\Procurement\Acquisition\Listing\ListingHandler;
use App\Services\Procurement\Acquisition\Listing\ListingStageCodec;
use App\Services\Procurement\Platform\FetchOutcomeBridge;
use App\Services\Procurement\Process\Execution\ExecutionContextFactory;
use App\Services\Procurement\Process\Execution\WorkFinalizer;
use Illuminate\Config\Repository;
use Illuminate\Support\Facades\DB;
use Tests\Support\Platforms\Contract\RecordingOutbound;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\LedgerFixtures;
use Tests\Support\ProcessCore\ListingFixtures as F;

pest()->group('listing-coverage', 'listing-partition-coverage');

function listingFixtureBody(int $from, int $count, string $day = '01.01.2026'): string
{
    $body = "Реестровый номер закупки;Наименование закупки;Этап закупки;Дата размещения;Дата обновления;Начальная (максимальная) цена контракта;Валюта;Способ определения поставщика (подрядчика, исполнителя), подрядной организации (размещения закупки)\n";
    for ($i = $from; $i < $from + $count; $i++) {
        $body .= "№ {$i};Road;Подача заявок;{$day};{$day};1250000,50;RUB;Электронный аукцион\n";
    }

    return $body;
}

function listingFixtureResponse(string $body): ResponseEnvelope
{
    return new ResponseEnvelope(200, ['content-type' => ['text/csv']], new DecodedTextBody($body, 'UTF-8'), strlen($body), strlen($body));
}

test('real CSV parser reads 1200 records from row one and does not treat 5000 as terminal', function (): void {
    $source = new EisListingSource(new EisPolicy(new Repository(config()->all())), new RecordingOutbound, LedgerFixtures::uuid());
    $filter = new EisListingFilter(['123']);
    $cursor = new EisListingCursor('2026-01-01', 'publish', 0, 250000000, $filter->fingerprint());
    $seen = [];
    $total = 0;
    foreach ([500, 500, 200] as $n) {
        $request = new ListingRequest('fz44', $filter, $cursor->envelope(), $seen);
        parse_str((string) parse_url($source->plan($request)->url->reveal(), PHP_URL_QUERY), $query);
        expect($query['from'])->toBe((string) $cursor->firstRow)->and($query['to'])->toBe((string) ($cursor->firstRow + 499))
            ->and($query)->not->toHaveKey('updateDateFrom');
        $page = $source->parse($request, listingFixtureResponse(listingFixtureBody($cursor->firstRow, $n)));
        $total += $page->rowsTotal;
        expect($page->summaries->items)->toHaveCount($n);
        $seen[] = $page->summaries->digest(false);
        if ($page->nextCursor !== null) {
            $cursor = EisListingCursor::decode($page->nextCursor);
        }
    }
    expect($total)->toBe(1200)->and($page->terminalProof)->toBe(TerminalProof::ShortPage);
    $cursor = new EisListingCursor('2026-01-01', 'publish', 0, 250000000, $filter->fingerprint(), 4501);
    $page = $source->parse(new ListingRequest('fz44', $filter, $cursor->envelope()), listingFixtureResponse(listingFixtureBody(4501, 500)));
    expect($page->terminalProof)->toBe(TerminalProof::None)->and($page->nextCursor)->not->toBeNull();
});

test('actual handler maps invalid CSV to blocked without cursor summary or successor writes', function (): void {
    F::bind();
    $run = F::run();
    $outbound = new RecordingOutbound([new TransportSucceeded(listingFixtureResponse(''))]);
    $planner = new EisListingPartitioner;
    $handler = new ListingHandler(app(ListingStoreRepository::class), new EisListingSource(new EisPolicy(new Repository(config()->all())), $outbound, LedgerFixtures::uuid()),
        $planner, $planner, new FetchOutcomeBridge);
    $lease = FinalizeFixtures::running($run['work']);
    $outcome = $handler->execute($run['input'], app(ExecutionContextFactory::class)->open($lease));
    expect($outcome)->toBeInstanceOf(StageBlocked::class)->and($outbound->plans)->toHaveCount(1);
    app(WorkFinalizer::class)->finish($lease, $outcome);
    expect(DB::table('prc_procurements')->count())->toBe(0)->and(DB::table('prc_process_work')->value('state'))->toBe('blocked')
        ->and(DB::table('prc_crawl_blocks')->value('terminal_proof'))->toBeNull();
});

test('valid handler result roundtrips a closed codec and publishes full source-date precision', function (): void {
    F::bind();
    $run = F::run();
    $outbound = new RecordingOutbound([new TransportSucceeded(listingFixtureResponse(listingFixtureBody(42, 1)))]);
    $planner = new EisListingPartitioner;
    $handler = new ListingHandler(app(ListingStoreRepository::class), new EisListingSource(new EisPolicy(new Repository(config()->all())), $outbound, LedgerFixtures::uuid()),
        $planner, $planner, new FetchOutcomeBridge);
    $lease = FinalizeFixtures::running($run['work']);
    $outcome = $handler->execute($run['input'], app(ExecutionContextFactory::class)->open($lease));
    expect($outcome)->toBeInstanceOf(StageSucceeded::class);
    if (! $outcome instanceof StageSucceeded || ! $outcome->result instanceof ListingResult) {
        throw new LogicException('Missing listing result');
    }
    $codec = new ListingStageCodec;
    $payload = $codec->encodeResult($outcome->result);
    expect($codec->encodeResult($codec->decodeResult($payload))->json)->toBe($payload->json);
    $invalid = json_decode($payload->json ?? '', true, flags: JSON_THROW_ON_ERROR);
    if (! is_array($invalid)) {
        throw new LogicException('Invalid fixture payload');
    }
    $invalid['surprise'] = 1;
    expect(fn () => $codec->decodeResult(new VersionedPayload(json_encode($invalid, JSON_THROW_ON_ERROR), $payload->schemaVersion)))->toThrow(InvalidArgumentException::class);
    app(WorkFinalizer::class)->finish($lease, $outcome);
    expect(DB::table('prc_procurements')->value('source_identity_value'))->toBe('42')
        ->and(DB::table('prc_procurements')->value('source_updated_has_day_precision'))->toBeTrue()
        ->and(DB::table('prc_process_work')->where('stage', 'detail')->count())->toBe(1);
    $intent = DB::table('prc_process_work')->where('stage', 'detail')->value('intent_id');
    if (! is_string($intent)) {
        throw new LogicException('Detail work lost its durable intent');
    }
    $reference = app(ListingStoreRepository::class)->detailReference(new ProcessUuid($intent));
    if ($reference === null) {
        throw new LogicException('Missing accepted detail reference');
    }
    expect($reference->intentId->value)->toBe($intent)
        ->and($reference->locator?->serializedPayload)->toBe('Электронный аукцион')
        ->and($reference->fingerprint->hex)->toHaveLength(64);
});
