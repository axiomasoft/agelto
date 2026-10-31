<?php

declare(strict_types=1);

use App\Dto\Procurement\Acquisition\ListingReceipt;
use App\Dto\Procurement\Acquisition\ListingResult;
use App\Enums\Procurement\Platform\Completeness;
use App\Enums\Procurement\Platform\TerminalProof;
use App\Enums\Procurement\Process\StageCode;
use App\Enums\Procurement\ProcurementEventType;
use App\Models\Procurement\Crawler\IgnoredItem;
use App\Models\Procurement\Procurement;
use App\Models\Procurement\ProcurementEvent;
use App\Models\Procurement\ProcurementRevision;
use App\Repositories\Procurement\Crawler\ListingStoreRepository;
use Illuminate\Support\Facades\DB;
use Tests\Support\Platforms\Eis\EisListingFactsFixtures as C;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\ListingFixtures as F;

beforeEach(function (): void {
    ExecutionFixtures::authority([StageCode::Listing]);
    F::bindLocal();
});

test('Process CSV publication creates canonical purchases revisions and discovered events together', function (): void {
    $run = F::run('2026-03-01');
    $page = C::parse(C::body([C::row('0173200001234000001'), C::row('0173200001234000002')]));
    F::finish($run['work'], $run['input'], $page);
    expect(Procurement::query()->count())->toBe(2)->and(ProcurementRevision::query()->count())->toBe(2)
        ->and(ProcurementEvent::query()->where('event_type', ProcurementEventType::Discovered)->count())->toBe(2)
        ->and(DB::table('prc_process_work')->where('stage', 'detail')->count())->toBe(2);
});

test('Process CSV preserves earliest source day precision independently from publication dates', function (): void {
    $page = C::parse(C::body([C::row('1', ['Дата обновления' => '01.03.2026 03:00:00']), C::row('2', ['Дата обновления' => '01.03.2026'])]));
    $items = $page->summaries->items;
    expect($items[1]->updatedAt->format(DATE_ATOM))->toBe('2026-02-28T21:00:00+00:00')
        ->and($items[1]->updatedAtDayPrecision)->toBeTrue()->and($items[0]->updatedAtDayPrecision)->toBeFalse()
        ->and($items[1]->publishedAt->format('Y-m-d'))->toBe('2026-03-01');
});

test('Process CSV replay counts parsed observations without second revisions or events', function (): void {
    $run = F::run('2026-03-01');
    $page = C::parse(C::body([C::row('1')]));
    F::finish($run['work'], $run['input'], $page);
    $next = F::next($run['crawl']);
    F::finish($next['work'], $next['input'], $page);
    expect($page->rowsTotal)->toBe(1)->and(Procurement::query()->count())->toBe(1)
        ->and(ProcurementRevision::query()->count())->toBe(1)
        ->and(ProcurementEvent::query()->where('event_type', ProcurementEventType::Discovered)->count())->toBe(1)
        ->and(DB::table('prc_process_work')->where('stage', 'detail')->count())->toBe(1);
});

test('Process CSV receipt retains a full content digest cardinality and terminal proof without legacy fetch rows', function (): void {
    $run = F::run('2026-03-01');
    $body = C::body([C::row('1')]);
    $page = C::parse($body);
    F::finish($run['work'], $run['input'], $page);
    $receipt = ListingReceipt::fromJson(DB::table('prc_crawl_blocks')->where('id', $run['input']->crawlBlockId->value)->value('terminal_proof'));
    expect($receipt->observations)->toHaveCount(1)->and($receipt->identities->hex)->toBe($page->summaries->digest(false)->hex)
        ->and($page->terminalProof)->toBe(TerminalProof::ShortPage)
        ->and($page->assessment->bodyHash->hex)->toBe(hash('sha256', $body));
});

test('Process CSV explicit registry denylist skips only requested identities without publishing detail obligations', function (): void {
    $run = F::run('2026-03-01');
    $registry = app(ListingStoreRepository::class)->task($run['input'])->registryId;
    IgnoredItem::factory()->create(['registry_id' => $registry, 'registry_number' => '1']);
    $page = C::parse(C::body([C::row('1'), C::row('2')]));
    F::finish($run['work'], $run['input'], $page);
    expect($page->rowsTotal)->toBe(2)->and(Procurement::query()->pluck('source_identity_value')->all())->toBe(['2'])
        ->and(DB::table('prc_process_work')->where('stage', 'detail')->count())->toBe(1);
});

test('Process CSV duplicate observations retain 510 membership entries but first identity content wins 500 publications', function (): void {
    $run = F::run('2026-03-01', blockSize: 1000);
    $rows = [];
    for ($i = 1; $i <= 500; $i++) {
        $rows[] = C::row(sprintf('%019d', $i));
    }
    for ($i = 1; $i <= 10; $i++) {
        $rows[] = C::row(sprintf('%019d', $i), ['Наименование закупки' => 'duplicate must not win']);
    }
    $page = C::parse(C::body($rows), blockSize: 1000);
    F::finish($run['work'], $run['input'], $page);
    expect($page->rowsTotal)->toBe(510)->and($page->summaries->items)->toHaveCount(510)
        ->and(Procurement::query()->count())->toBe(500)->and(ProcurementRevision::query()->count())->toBe(500)
        ->and(Procurement::query()->where('source_identity_value', sprintf('%019d', 1))->value('title'))->toBe('Поставка оргтехники');
});

test('Process CSV invalid row rejects the entire page without partial canonical writes', function (array $change): void {
    $run = F::run('2026-03-01');
    $page = C::parse(C::body([C::row('1', $change), C::row('2')]));
    expect($page->assessment->completeness)->toBe(Completeness::Partial)->and($page->terminalProof)->toBe(TerminalProof::None)
        ->and($page->summaries->items)->toBe([])->and(fn () => new ListingResult(
            app(ListingStoreRepository::class)->task($run['input']), $page))->toThrow(InvalidArgumentException::class)
        ->and(Procurement::query()->count())->toBe(0)->and(DB::table('prc_crawl_blocks')->whereNotNull('terminal_proof')->count())->toBe(0);
})->with(['missing number' => [['Реестровый номер закупки' => '']], 'invalid date' => [['Дата размещения' => 'не дата']],
    'invalid identifier' => [['Реестровый номер закупки' => '№']], 'all invalid date syntax' => [['Дата размещения' => '2026-03-01']]]);

test('Process CSV malformed record contributes cardinality but cannot prove page completeness', function (): void {
    $page = C::parse(C::body([C::row('1')])."malformed;row\n");
    expect($page->rowsTotal)->toBe(2)->and($page->assessment->completeness)->toBe(Completeness::Partial)
        ->and($page->summaries->items)->toBe([])->and($page->terminalProof)->toBe(TerminalProof::None);
});

test('Process CSV invalid responses remain evidence without source incident writes or canonical data', function (string $body, int $status, string $type): void {
    $page = C::parse($body, $status, $type);
    expect($page->assessment->completeness)->toBe(Completeness::Partial)->and($page->terminalProof)->toBe(TerminalProof::None)
        ->and($page->summaries->items)->toBe([])->and(Procurement::query()->count())->toBe(0)
        ->and(DB::table('prc_source_incidents')->count())->toBe(0);
})->with(['403' => ['<html>Denied</html>', 403, 'text/html'], 'html 200' => ['<!DOCTYPE html>', 200, 'text/csv'],
    'empty' => ['', 200, 'text/csv'], 'missing header' => ['wrong;columns', 200, 'text/csv']]);

test('Process CSV semantic empty requires the complete mandatory header', function (): void {
    $page = C::parse(C::body([]));
    expect($page->rowsTotal)->toBe(0)->and($page->assessment->completeness)->toBe(Completeness::CompleteEmpty)
        ->and($page->terminalProof)->toBe(TerminalProof::SemanticEmpty);
    $rows = [C::row('1')];
    unset($rows[0]['Дата обновления']);
    expect(C::parse(C::body($rows))->assessment->completeness)->toBe(Completeness::Partial);
});
