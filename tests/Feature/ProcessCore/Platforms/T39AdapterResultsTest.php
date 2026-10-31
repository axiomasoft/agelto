<?php

declare(strict_types=1);

use App\Dto\Platforms\Eis\EisCardRoutes;
use App\Dto\Platforms\Eis\EisDetailExtension;
use App\Dto\Platforms\Eis\EisListingCursor;
use App\Dto\Platforms\Eis\EisListingFilter;
use App\Dto\Platforms\Eis\EisPolicy;
use App\Dto\Procurement\Acquisition\DetailFetched;
use App\Dto\Procurement\Acquisition\DetailRequest;
use App\Dto\Procurement\Acquisition\FetchBlocked;
use App\Dto\Procurement\Acquisition\FetchDeferred;
use App\Dto\Procurement\Acquisition\FetchItemRejected;
use App\Dto\Procurement\Acquisition\ListingPage;
use App\Dto\Procurement\Acquisition\ListingPassEvidence;
use App\Dto\Procurement\Acquisition\ListingRequest;
use App\Dto\Procurement\Acquisition\ProcurementSummaries;
use App\Dto\Procurement\Network\DecodedTextBody;
use App\Dto\Procurement\Network\ResponseEnvelope;
use App\Dto\Procurement\Network\TransportBlocked;
use App\Dto\Procurement\Network\TransportDeferred;
use App\Dto\Procurement\Platform\CursorEnvelope;
use App\Dto\Procurement\Platform\ExpectedResource;
use App\Dto\Procurement\Platform\SourceIdentifierValue;
use App\Dto\Procurement\Platform\SourceIdentity;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\Duration;
use App\Dto\Procurement\Process\ExecutionContext;
use App\Dto\Procurement\Process\ExecutionLease;
use App\Dto\Procurement\Process\RetryAt;
use App\Dto\Procurement\Process\WorkId;
use App\Enums\Procurement\Network\NetworkBlockedReason;
use App\Enums\Procurement\Network\NetworkDeferralReason;
use App\Enums\Procurement\Platform\AssessmentReason;
use App\Enums\Procurement\Platform\Completeness;
use App\Enums\Procurement\Platform\FailureScope;
use App\Enums\Procurement\Platform\ListingStability;
use App\Enums\Procurement\Platform\TerminalProof;
use App\Enums\Procurement\Process\StageCode;
use App\Exceptions\Procurement\Crawler\OutboundRequestDeadlineExceeded;
use App\Exceptions\Procurement\Platform\InvalidPlatformDefinition;
use App\Services\Platforms\Eis\Card\EisDetailParser;
use App\Services\Platforms\Eis\Csv\EisCsvParser;
use App\Services\Platforms\Eis\Diagnostics\ListingFilterCheck;
use App\Services\Platforms\Eis\EisDefinition;
use App\Services\Platforms\Eis\EisDetailSource;
use App\Services\Platforms\Eis\EisListingPartitioner;
use App\Services\Platforms\Eis\EisListingSource;
use App\Services\Procurement\Platform\Contracts\DetailSource;
use App\Services\Procurement\Platform\Contracts\ListingSource;
use App\Services\Procurement\Platform\ListingEvidence;
use App\Services\Procurement\Platform\PlatformBootValidator;
use App\Services\Procurement\Process\Contracts\LeaseKeeper;
use App\Services\Procurement\Process\Contracts\ProcessClock;
use Illuminate\Config\Repository;
use Tests\Support\Platforms\Contract\RecordingOutbound;
use Tests\Support\Platforms\JsonProcurement\JsonListingFilter;
use Tests\Support\Platforms\JsonProcurement\JsonProcurementDefinition;
use Tests\Support\Platforms\JsonProcurement\JsonProcurementSource;
use Tests\Support\ProcessCore\ContractFixtures;

pest()->group('t02', 't27', 't39', 'p22');

function p210Response(string $body, string $type = 'text/csv', int $status = 200): ResponseEnvelope
{
    return new ResponseEnvelope($status, ['content-type' => [$type]], new DecodedTextBody($body, 'UTF-8'), strlen($body), strlen($body));
}
function p210Csv(string $name = 'neutral-valid'): string
{
    return file_get_contents(base_path('tests/Fixtures/Platforms/Eis/Csv/'.$name.'.csv'));
}
function p210Request(int $firstRow = 1, int $blockSize = 500): ListingRequest
{
    $filter = new EisListingFilter(['123']);

    return new ListingRequest('fz44', $filter, (new EisListingCursor('2026-10-31', 'publish', 0, 200000000, $filter->fingerprint(), $firstRow, blockSize: $blockSize))->envelope());
}
function p210Eis(?RecordingOutbound $outbound = null, int $size = 500): EisListingSource
{
    $config = new Repository(config()->all());
    $config->set('platforms.eis.block_size', $size);

    return new EisListingSource(new EisPolicy($config), $outbound ?? new RecordingOutbound, ContractFixtures::id());
}
function p210Json(?RecordingOutbound $outbound = null): JsonProcurementSource
{
    return new JsonProcurementSource((new JsonProcurementDefinition)->definition(), $outbound ?? new RecordingOutbound, ContractFixtures::id());
}
function p210JsonRequest(string $token = 'opaque:initial'): ListingRequest
{
    return new ListingRequest('fz44', new JsonListingFilter('roads'), new CursorEnvelope('json.listing', 1, $token));
}
function p210Context(): ExecutionContext
{
    $clock = Mockery::mock(ProcessClock::class);
    $clock->shouldReceive('monotonicNs')->andReturn(1000000000);
    $lease = new ExecutionLease(new WorkId(ContractFixtures::id()), ContractFixtures::id(2), 1, ContractFixtures::id(3),
        new DateTimeImmutable('+360 seconds'), 1, ContractFixtures::hash(), 0, null, StageCode::Listing, 'fixture/1');

    return new ExecutionContext($lease, Mockery::mock(LeaseKeeper::class), $clock, Duration::seconds(240),
        Duration::seconds(360), Duration::seconds(15), Duration::seconds(15), Duration::seconds(30));
}
function p210DetailSource(): EisDetailSource
{
    $routes = new EisCardRoutes(['ea20' => [
        'common-info' => 'https://zakupki.gov.ru/epz/order/notice/ea20/view/common-info.html?regNumber={regNumber}',
        'documents' => 'https://zakupki.gov.ru/epz/order/notice/ea20/view/documents.html?regNumber={regNumber}',
        'event-journal' => null, 'supplier-results' => null,
    ]]);

    return new EisDetailSource(new EisPolicy(config()), $routes, app(EisDetailParser::class), new RecordingOutbound, ContractFixtures::id());
}
function p210DetailRequest(): DetailRequest
{
    $definition = (new EisDefinition(config()))->definition();

    return new DetailRequest('fz44', new SourceIdentity($definition->code, $definition->registries->get('fz44')->primaryIdentityType,
        new SourceIdentifierValue('00042')), new CursorEnvelope('eis.card', 1, 'ea20'));
}

test('T02 обе definitions рекламируют реальные typed listing/detail без runtime activation', function (): void {
    foreach ([(new EisDefinition(config()))->definition(), (new JsonProcurementDefinition)->definition()] as $definition) {
        (new PlatformBootValidator)->validate($definition, $definition->code->value, config());
        expect(is_a($definition->listing->implementation, ListingSource::class, true))->toBeTrue()
            ->and(is_a($definition->detail->implementation, DetailSource::class, true))->toBeTrue();
    }
});

test('T27 CSV first1 inclusive три блока покрывают 1200 строк без пропуска', function (): void {
    $source = p210Eis();
    $request = p210Request();
    $identities = [];
    foreach ([500, 500, 200] as $index => $count) {
        $plan = $source->plan($request);
        parse_str(parse_url($plan->url->reveal(), PHP_URL_QUERY), $query);
        expect($query['from'])->toBe((string) (1 + 500 * $index))
            ->and($query['to'])->toBe((string) (500 + 500 * $index))
            ->and($query['sortDirection'])->toBe('true')
            ->and($query['registryNumberCsv'])->toBe('on')
            ->and($query)->not->toHaveKey('updateDateFrom')
            ->and($plan->profile->totalSeconds)->toBe(90);
        $body = p210Csv('neutral-empty');
        for ($i = 1; $i <= $count; $i++) {
            $body .= sprintf("%05d;Работы;Подача заявок;31.10.2026;31.10.2026 15:00;1250000,50;RUB\n", $index * 500 + $i);
        }
        $page = $source->parse($request, p210Response($body));
        expect($page->rowsTotal)->toBe($count)->and($page->assessment->usable())->toBeTrue();
        foreach ($page->summaries->items as $summary) {
            $identities[] = $summary->identity->value->value;
        }
        if ($index < 2) {
            expect($page->terminalProof)->toBe(TerminalProof::None);
            $request = new ListingRequest('fz44', $request->filter, $page->nextCursor);
        } else {
            expect($page->terminalProof)->toBe(TerminalProof::ShortPage)->and($page->nextCursor)->toBeNull();
        }
    }
    expect(count(array_unique($identities)))->toBe(1200);
});

test('T39 semantic empty отличается от blank truncated malformed и overflow у обеих площадок', function (): void {
    $eis = p210Eis();
    $empty = $eis->parse(p210Request(), p210Response(p210Csv('neutral-empty')));
    expect($empty->assessment->completeness)->toBe(Completeness::CompleteEmpty)->and($empty->terminalProof)->toBe(TerminalProof::SemanticEmpty);
    foreach (['', '<html>login</html>', p210Csv('neutral-empty').'"broken', p210Csv('neutral-empty')."one;two\n"] as $body) {
        $page = $eis->parse(p210Request(), p210Response($body));
        expect($page->assessment->usable())->toBeFalse()->and($page->terminalProof)->toBe(TerminalProof::None)->and($page->nextCursor)->toBeNull();
    }
    $row = "00042;Работы;Подача заявок;31.10.2026;31.10.2026;1;RUB\n";
    expect($eis->parse(p210Request(), p210Response(p210Csv('neutral-empty').str_repeat($row, 1001)))->assessment->reason)->toBe(AssessmentReason::Overflow);
    $json = p210Json();
    $jsonEmpty = file_get_contents(base_path('tests/Fixtures/ProcessCore/JsonPlatform/listing-empty.json'));
    expect($json->parseListing(p210JsonRequest(), p210Response($jsonEmpty, 'application/json'))->terminalProof)->toBe(TerminalProof::SemanticEmpty);
    foreach (['', '{"notices":', '{"notices":[],"paging":{"count":0,"complete":false,"next":"x"}}', '{"notices":[],"paging":{"count":0,"complete":false,"next":""}}', str_replace('"count":0', '"count":1', $jsonEmpty), str_replace('"paging"', '"csvHeaders"', $jsonEmpty)] as $body) {
        $page = $json->parseListing(p210JsonRequest(), p210Response($body, 'application/json'));
        expect($page->assessment->usable())->toBeFalse()->and($page->nextCursor)->toBeNull()->and($page->terminalProof)->toBe(TerminalProof::None);
    }
    $valid = json_decode(file_get_contents(base_path('tests/Fixtures/ProcessCore/JsonPlatform/listing.json')), true);
    $valid['notices'] = array_fill(0, 2000, $valid['notices'][0]);
    $valid['paging']['count'] = 2000;
    expect($json->parseListing(p210JsonRequest(), p210Response(json_encode($valid), 'application/json'))->assessment->reason)->toBe(AssessmentReason::Overflow);
});

test('T27 decimal overlap сохраняет midpoint и bounded split оставляет explicit incomplete', function (): void {
    $filter = p210Request()->filter;
    $cursor = new EisListingCursor('2026-10-31', 'publish', 100000000, 150000100, $filter->fingerprint());
    $result = (new EisListingPartitioner)->split($cursor->envelope(), 1);
    [$left, $right] = array_map(EisListingCursor::decode(...), $result->children);
    expect($left->highCents)->toBe(125000050)->and($right->lowCents)->toBe(125000050)
        ->and(EisListingCursor::decimal($left->highCents))->toBe('1250000.50')
        ->and($left->firstRow)->toBe(1);
    foreach ([
        (new EisListingPartitioner)->split($cursor->envelope(), 9999),
        (new EisListingPartitioner)->split((new EisListingCursor('2026-10-31', 'publish', 0, 100000, $filter->fingerprint()))->envelope(), 1),
        (new EisListingPartitioner)->split((new EisListingCursor('2026-10-31', 'publish', 0, 100000000, $filter->fingerprint(), depth: 20))->envelope(), 1),
    ] as $limited) {
        expect($limited->children)->toBe([])->and($limited->incompleteReason)->toBe(AssessmentReason::Overflow);
    }
});

test('T27 повтор cap boundary и неприменённый date/price filter не дают terminal proof', function (): void {
    $source = p210Eis(size: 1);
    $request = p210Request(blockSize: 1);
    $page = $source->parse($request, p210Response(p210Csv()));
    $repeat = new ListingRequest('fz44', $request->filter, $page->nextCursor, [$page->summaries->digest(false)]);
    expect($source->parse($repeat, p210Response(p210Csv()))->assessment->reason)->toBe(AssessmentReason::RepeatedPage);
    $atLimit = $source->parse(p210Request(12, 1), p210Response(p210Csv()));
    expect($atLimit->assessment->reason)->toBe(AssessmentReason::Overflow)->and($atLimit->nextCursor)->toBeNull()->and($atLimit->terminalProof)->toBe(TerminalProof::None);
    foreach ([str_replace('31.10.2026', '30.10.2026', p210Csv()), str_replace('1250000,50', '2500000,50', p210Csv())] as $body) {
        expect(p210Eis()->parse(p210Request(), p210Response($body))->assessment->reason)->toBe(AssessmentReason::FilterMismatch);
    }
    // 5000 is not a terminal cap: the tenth full block still has a successor.
    $row = "00042;Работы;Подача заявок;31.10.2026;31.10.2026;1;RUB\n";
    $at5000 = p210Eis()->parse(p210Request(4501), p210Response(p210Csv('neutral-empty').str_repeat($row, 500)));
    expect($at5000->terminalProof)->toBe(TerminalProof::None)->and(EisListingCursor::decode($at5000->nextCursor)->firstRow)->toBe(5001);
});

test('T27 full-content stability замечает mutation при тех же IDs и сохраняет cardinality', function (): void {
    $source = p210Eis();
    $first = $source->parse(p210Request(), p210Response(p210Csv()));
    $changed = $source->parse(p210Request(), p210Response(str_replace('дорожные', 'мостовые', p210Csv())));
    expect($first->summaries->digest(false)->hex)->toBe($changed->summaries->digest(false)->hex)
        ->and($first->summaries->digest()->hex)->not->toBe($changed->summaries->digest()->hex);
    $partition = ContentHash::of('immutable partition');
    $one = new ListingPassEvidence($partition, $first->summaries->digest(), true, 1);
    $two = new ListingPassEvidence($partition, $first->summaries->digest(), true, 2);
    $evidence = new ListingEvidence;
    expect($evidence->stability($two, $one, true))->toBe(ListingStability::Stable)
        ->and($evidence->stability($two, $one, false))->toBe(ListingStability::ObservedOpen)
        ->and($evidence->stability(new ListingPassEvidence($partition, $changed->summaries->digest(), true, 3), $two, true))->toBe(ListingStability::Incomplete)
        ->and($evidence->stability(new ListingPassEvidence($partition, $first->summaries->digest(), false, 2), $one, true))->toBe(ListingStability::Incomplete);
    $duplicate = new ProcurementSummaries([...$first->summaries->items, ...$first->summaries->items]);
    expect($duplicate->digest(false)->hex)->not->toBe($first->summaries->digest(false)->hex);
});

test('T39 JSON opaque cursor identity reordered fields и один detail сохраняют canonical contract', function (): void {
    $source = p210Json();
    $raw = file_get_contents(base_path('tests/Fixtures/ProcessCore/JsonPlatform/listing.json'));
    $page = $source->parseListing(p210JsonRequest(), p210Response($raw, 'application/json'));
    expect($page->summaries->items[0]->identity->value->value)->toBe(' 00:Ab-/42 ')
        ->and($page->nextCursor->serializedPayload)->toBe('opaque:+/Next== ');
    $reordered = json_decode($raw, true);
    $reordered['notices'][0] = array_reverse($reordered['notices'][0], true);
    expect($source->parseListing(p210JsonRequest(), p210Response(json_encode($reordered), 'application/json'))->summaries->digest()->hex)->toBe($page->summaries->digest()->hex);
    expect($source->parseListing(p210JsonRequest('opaque:+/Next== '), p210Response($raw, 'application/json'))->assessment->reason)->toBe(AssessmentReason::RepeatedPage);
    $request = new DetailRequest('fz44', $page->summaries->items[0]->identity);
    $detail = file_get_contents(base_path('tests/Fixtures/ProcessCore/JsonPlatform/detail.json'));
    $result = $source->parseDetail($request, p210Response($detail, 'application/json'));
    expect($result)->toBeInstanceOf(DetailFetched::class)->and($result->snapshot->complete())->toBeTrue()
        ->and($result->snapshot->extension)->toBeNull()
        ->and($result->snapshot->parties[0]->organization->identifiers[0]->value)->toBe('org:Ab-1')
        ->and($result->snapshot->documents[0]->expiresAt->getTimestamp())->toBe(1793491200);
    expect($source->parseDetail($request, p210Response(str_replace('00:Ab-/42', '00:ab-/42', $detail), 'application/json')))->toBeInstanceOf(FetchItemRejected::class);
    expect($source->parseDetail($request, p210Response('{"notice":{}}', 'application/json')))->toBeInstanceOf(FetchItemRejected::class);
});

test('T39 транспортные deferred blocked остаются typed без пустого success и второго request', function (): void {
    foreach ([
        new TransportDeferred(NetworkDeferralReason::NoRoute, new RetryAt(new DateTimeImmutable('+1 hour'))),
        new TransportDeferred(NetworkDeferralReason::AttemptsExhausted, new RetryAt(new DateTimeImmutable('+1 hour'))),
        new TransportBlocked(NetworkBlockedReason::ConfigurationHold),
    ] as $outcome) {
        foreach (['eis', 'json'] as $platform) {
            $outbound = new RecordingOutbound([$outcome]);
            $result = $platform === 'eis' ? p210Eis($outbound)->fetch(p210Request(), p210Context())
                : p210Json($outbound)->fetchListing(p210JsonRequest(), p210Context());
            expect($result)->toBeInstanceOf($outcome instanceof TransportDeferred ? FetchDeferred::class : FetchBlocked::class)
                ->and($result->reason)->toBe($outcome->reason)->and($outbound->plans)->toHaveCount(1);
            if ($result instanceof FetchDeferred) {
                expect($result->retryAt)->toBe($outcome->retryAt);
            }
        }
    }
});

test('T39 ЕИС detail выносит extension и missing required сохраняет partial вместо empty', function (): void {
    $header = '<a class="cardMainInfo__purchaseLink">№ 00042</a><div class="cardMainInfo__purchaseObject">Работы</div><div class="cardMainInfo__state">Подача заявок</div>';
    $common = file_get_contents(base_path('tests/Fixtures/Platforms/Eis/Card/common-info.html'));
    $common .= '<section class="organizations-block"><table class="parties-table"><tbody></tbody></table></section>';
    $source = p210DetailSource();
    $result = $source->parse(p210DetailRequest(), [
        'common-info' => p210Response($header.$common, 'text/html'),
        'documents' => p210Response($header.'<div class="blockFilesTabDocs"></div>', 'text/html'),
    ]);
    expect($result)->toBeInstanceOf(DetailFetched::class)->and($result->snapshot->extension)->toBeInstanceOf(EisDetailExtension::class)
        ->and($result->snapshot->extension->ikzYear)->not->toBeNull()
        ->and($result->snapshot->items)->not->toBeEmpty()
        ->and($result->snapshot->complete())->toBeTrue();
    $parts = array_column(array_map(get_object_vars(...), $result->snapshot->parts), 'completeness', 'name');
    expect($parts['documents'])->toBe(Completeness::CompleteEmpty)->and($parts['results'])->toBe(Completeness::NotApplicable);
    $partial = $source->parse(p210DetailRequest(), ['common-info' => p210Response($header.$common, 'text/html')]);
    expect($partial->snapshot->complete())->toBeFalse()->and($partial->snapshot->documents)->toBe([]);
    $wrong = $source->parse(p210DetailRequest(), ['common-info' => p210Response(str_replace('00042', '000420', $header).$common, 'text/html')]);
    expect($wrong)->toBeInstanceOf(FetchItemRejected::class)->and($wrong->assessment->reason)->toBe(AssessmentReason::IdentityMismatch);
});

test('T39 pure EIS filter diagnostic не принимает ignored filter и empty sample за healthy', function (): void {
    $page = p210Eis()->parse(p210Request(), p210Response(p210Csv()));
    expect(ListingFilterCheck::assessPages($page, $page, 0.8)->reason)->toBe(AssessmentReason::FilterMismatch);
    $empty = p210Eis()->parse(p210Request(), p210Response(p210Csv('neutral-empty')));
    expect(ListingFilterCheck::assessPages($page, $empty, 0.8)->usable())->toBeFalse();
    expect(fn () => ListingFilterCheck::assessPages($page, $page, NAN))->toThrow(InvalidArgumentException::class);
});

test('T39 cursor чужой codec filter schema и scalar coercion отвергаются', function (): void {
    $request = p210Request();
    foreach ([new CursorEnvelope('json.listing', 1, 'token'), new CursorEnvelope('eis.listing', 2, '{}'),
        new CursorEnvelope('eis.listing', 1, str_replace('"firstRow":1', '"firstRow":"1"', $request->cursor->serializedPayload)),
    ] as $cursor) {
        expect(fn () => p210Eis()->plan(new ListingRequest('fz44', $request->filter, $cursor)))->toThrow(InvalidArgumentException::class);
    }
    expect(fn () => p210Eis()->plan(new ListingRequest('fz44', new EisListingFilter(['456']), $request->cursor)))->toThrow(InvalidArgumentException::class);
    expect(fn () => p210Eis(size: 250)->plan($request))->toThrow(InvalidArgumentException::class);
});

test('T39 records до mapping cooperative parser tick и policy bounds сохраняются', function (): void {
    $body = p210Csv('neutral-empty')."one;two\n";
    $invalid = p210Eis()->parse(p210Request(), p210Response($body));
    expect($invalid->rowsTotal)->toBe(1)->and($invalid->nextCursor)->toBeNull();
    $ticks = 0;
    $large = p210Csv('neutral-empty').str_repeat("00042;Работы;Подача заявок;31.10.2026;31.10.2026;1;RUB\n", 500);
    $batch = (new EisCsvParser)->parseBatch($large, tick: function () use (&$ticks): void {
        $ticks++;
    });
    expect($ticks)->toBeGreaterThan(0)->and($batch['records'])->toBe(500);
    expect(fn () => (new EisCsvParser)->parseBatch($large, tick: function (): void {
        throw new LogicException('fixture programming defect');
    }))->toThrow(LogicException::class, 'fixture programming defect');
    expect(fn () => (new EisCsvParser)->parseBatch($large, microtime(true) - 1))
        ->toThrow(OutboundRequestDeadlineExceeded::class);
    foreach (['max_blocks' => 0, 'max_depth' => 21, 'max_partitions' => 10001, 'parser_seconds' => '5'] as $key => $value) {
        $config = new Repository(config()->all());
        $config->set('platforms.eis.listing_bounds.'.$key, $value);
        expect(fn () => new EisPolicy($config))->toThrow(InvalidPlatformDefinition::class, 'platforms.eis.listing_bounds');
    }
});

test('T39 ResponseValidator оценивает semantic contract а stage filter mutation обнаруживается', function (): void {
    $filter = new EisListingFilter(['123'], ['pc']);
    $request = new ListingRequest('fz44', $filter, (new EisListingCursor('2026-10-31', 'publish', 0, 200000000, $filter->fingerprint()))->envelope());
    $assessment = p210Eis()->assess(p210Response(p210Csv()), new ExpectedResource($request));
    expect($assessment->reason)->toBe(AssessmentReason::FilterMismatch)
        ->and($assessment->failureScope)->toBe(FailureScope::Adapter);
    $json = file_get_contents(base_path('tests/Fixtures/ProcessCore/JsonPlatform/listing-empty.json'));
    expect(p210Json()->assess(p210Response($json, 'application/json'), new ExpectedResource(p210JsonRequest()))->completeness)->toBe(Completeness::CompleteEmpty);
});

test('T39 neutral page не разрешает valid outcome без continuation либо terminal proof', function (): void {
    $page = p210Eis()->parse(p210Request(), p210Response(p210Csv()));
    expect(fn () => new ListingPage($page->summaries, 1, $page->assessment, TerminalProof::None, null))
        ->toThrow(InvalidArgumentException::class);
    $empty = p210Eis()->parse(p210Request(), p210Response(p210Csv('neutral-empty')));
    expect(fn () => new ListingPage($page->summaries, 1, $empty->assessment, TerminalProof::ShortPage, null))
        ->toThrow(InvalidArgumentException::class);
});
