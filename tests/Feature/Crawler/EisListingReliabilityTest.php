<?php

declare(strict_types=1);

use App\Dto\Procurement\Crawler\ListingCursor;
use App\Dto\Procurement\Crawler\WindowFilter;
use App\Enums\Procurement\Crawler\ArchiveWindowScanResult;
use App\Enums\Procurement\Crawler\CrawlerMode;
use App\Enums\Procurement\Crawler\RunStatus;
use App\Enums\Procurement\Crawler\RunStopKind;
use App\Enums\Procurement\Proxy\CrawlerProxyMode;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\Fetch;
use App\Models\Procurement\Crawler\Run;
use App\Models\Procurement\Platform\Okpd2Node;
use App\Models\Procurement\Platform\Registry;
use App\Models\Procurement\Procurement;
use App\Services\Procurement\ArchiveWindowScanner;
use App\Services\Procurement\CrawlerRunManager;
use App\Services\Procurement\Eis\CsvSourceMethod;
use App\Services\Procurement\ListingRunCheckpoint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\Support\Doubles\Crawler\EisCsvFixture;

beforeEach(function () {
    Sleep::fake();
    Storage::fake('s3');
    config([
        'crawler.stages.listing.min_pause_ms' => 0,
        'crawler.stages.listing.max_pause_ms' => 0,
        'crawler.stages.listing.retry_delay_ms' => 3000,
        'crawler.budget.requests_per_minute_base' => 1000,
        'crawler.run.checkpoint_seconds' => 240,
    ]);
});

/** @return array{Crawler, Run, WindowFilter} */
function eisReliabilityRun(): array
{
    $registry = Registry::factory()->eis()->create(['code' => 'fz44']);
    Okpd2Node::factory()->create(['platform_id' => $registry->platform_id, 'code' => '43', 'external_id' => '8873921']);
    $crawler = Crawler::factory()->for($registry)->create([
        'mode' => CrawlerMode::Archive,
        'proxy_mode' => CrawlerProxyMode::Direct,
        'filter' => ['okpd2Codes' => ['43'], 'publishedFrom' => '2025-01-01', 'publishedTo' => '2025-01-31'],
    ]);
    $run = Run::factory()->for($crawler)->create(['cursor' => ['blockFrom' => 1, 'day' => '2025-01-20']]);
    $day = Carbon::parse('2025-01-20');

    return [$crawler, $run, new WindowFilter(dateField: 'publish', dateFrom: $day, dateTo: $day)];
}

test('невалидный ответ сохраняет raw evidence и не дренирует день', function (string $body, int $status) {
    [$crawler, $run, $filter] = eisReliabilityRun();
    Http::fake(['*' => fn () => Http::response($body, $status)]);

    expect(app(ArchiveWindowScanner::class)->scan($crawler, $run, $filter))->toBe(ArchiveWindowScanResult::Stopped)
        ->and($run->fresh()->status)->toBe(RunStatus::Paused);

    $fetch = Fetch::query()->where('crawler_run_id', $run->id)->sole();
    expect($run->fresh()->cursor->toArray())->toEqual(['blockFrom' => 1, 'day' => '2025-01-20'])
        ->and($fetch->rows_errors)->toBe(1)
        ->and($fetch->http_status)->toBe($status)
        ->and(Storage::disk('s3')->get($fetch->artifact_ref))->toBe($body)
        ->and(Procurement::query()->count())->toBe(0);
})->with([
    'html 200' => ['<html>Access denied</html>', 200],
    'http 403' => ['Forbidden', 403],
    'http 503 exhausted' => ['Unavailable', 503],
    'repeated empty' => ['', 200],
    'invalid header' => ['invalid;header', 200],
]);

test('валидный header-only CSV дренирует пустой день', function () {
    [$crawler, $run, $filter] = eisReliabilityRun();
    $header = mb_convert_encoding('Реестровый номер закупки;Дата размещения;Дата обновления', 'Windows-1251', 'UTF-8');
    Http::fake(['*' => fn () => Http::response($header, 200)]);

    expect(app(ArchiveWindowScanner::class)->scan($crawler, $run, $filter))->toBe(ArchiveWindowScanResult::Drained)
        ->and(Fetch::query()->where('crawler_run_id', $run->id)->count())->toBe(2)
        ->and(Fetch::query()->where('crawler_run_id', $run->id)->sum('rows_errors'))->toBe(0);
});

test('ошибка обязательного поля приостанавливает обход и сохраняет курсор дня', function (string $column, string $value) {
    [$crawler, $run, $filter] = eisReliabilityRun();
    $stream = fopen('php://temp', 'w+');
    $row = ['Реестровый номер закупки' => '0173200001234000001', 'Дата размещения' => '20.01.2025', 'Дата обновления' => '21.01.2025'];
    $row[$column] = $value;
    fputcsv($stream, array_keys($row), ';', '"', '');
    fputcsv($stream, array_values($row), ';', '"', '');
    rewind($stream);
    $body = mb_convert_encoding(stream_get_contents($stream), 'Windows-1251', 'UTF-8');
    fclose($stream);
    Http::fake(['*' => fn () => Http::response($body, 200)]);

    expect(app(ArchiveWindowScanner::class)->scan($crawler, $run, $filter))->toBe(ArchiveWindowScanResult::Stopped)
        ->and($run->fresh()->status)->toBe(RunStatus::Paused)
        ->and($run->fresh()->cursor->toArray())->toEqual(['blockFrom' => 1, 'day' => '2025-01-20'])
        ->and(Procurement::query()->count())->toBe(0);
})->with([
    ['Реестровый номер закупки', '№'],
    ['Дата размещения', '31.02.2025'],
    ['Дата обновления', ''],
]);

test('пауза и отмена между HTTP попытками не запускают следующий запрос', function (RunStopKind $kind, RunStatus $status) {
    [$crawler, $run, $filter] = eisReliabilityRun();
    Http::fake(function () use ($run, $kind) {
        $run->update(['stop_requested_at' => now(), 'stop_kind' => $kind]);

        return Http::response('Unavailable', 503);
    });

    expect(app(ArchiveWindowScanner::class)->scan($crawler, $run, $filter))->toBe(ArchiveWindowScanResult::Stopped)
        ->and($run->fresh()->status)->toBe($status)
        ->and($run->fresh()->cursor['day'])->toBe('2025-01-20');
    Http::assertSentCount(1);
})->with([[RunStopKind::Pause, RunStatus::Paused], [RunStopKind::Cancel, RunStatus::Cancelled]]);

test('пауза прерывает длинное retry ожидание за один секундный slice', function () {
    [$crawler, $run, $filter] = eisReliabilityRun();
    Http::fake(['*' => fn () => Http::response('Unavailable', 503)]);
    Sleep::whenFakingSleep(function () use ($run) {
        $run->update(['stop_requested_at' => now(), 'stop_kind' => RunStopKind::Pause]);
    });

    expect(app(ArchiveWindowScanner::class)->scan($crawler, $run, $filter))->toBe(ArchiveWindowScanResult::Stopped)
        ->and($run->fresh()->status)->toBe(RunStatus::Paused);
    Http::assertSentCount(1);
    Sleep::assertSleptTimes(1);
    Sleep::assertSlept(fn ($duration) => $duration->totalMilliseconds === 1000.0);
});

test('пауза прерывает budget ожидание до запроса', function () {
    [$crawler, $run, $filter] = eisReliabilityRun();
    config(['crawler.budget.requests_per_minute_base' => 1]);
    RateLimiter::hit('crawl:budget:zakupki.gov.ru', 60);
    Http::fake();
    Sleep::whenFakingSleep(function () use ($run) {
        $run->update(['stop_requested_at' => now(), 'stop_kind' => RunStopKind::Pause]);
    });

    expect(app(ArchiveWindowScanner::class)->scan($crawler, $run, $filter))->toBe(ArchiveWindowScanResult::Stopped);
    Http::assertNothingSent();
    Sleep::assertSleptTimes(1);
});

test('listing deadline возвращает checkpoint без изменения курсора и HTTP запроса', function () {
    [$crawler, $run, $filter] = eisReliabilityRun();
    config(['crawler.run.checkpoint_seconds' => 4]);
    Http::fake();

    expect(app(ArchiveWindowScanner::class)->scan($crawler, $run, $filter))->toBe(ArchiveWindowScanResult::Checkpointed)
        ->and($run->fresh()->status)->toBe(RunStatus::Running)
        ->and($run->fresh()->cursor['day'])->toBe('2025-01-20');
    Http::assertNothingSent();
});

test('потеря execution lease между попытками запрещает следующий запрос', function () {
    [$crawler, $run, $filter] = eisReliabilityRun();
    $manager = app(CrawlerRunManager::class);
    expect($manager->acquireExecutionLease($run))->toBeTrue();
    Http::fake(function () use ($run) {
        Run::query()->whereKey($run->id)->update(['execution_lease_expires_at' => DB::raw("CURRENT_TIMESTAMP - INTERVAL '1 second'")]);
        $newOwner = $run->fresh();
        expect((new CrawlerRunManager)->acquireExecutionLease($newOwner))->toBeTrue();

        return Http::response('Unavailable', 503);
    });

    expect(fn () => app(ArchiveWindowScanner::class)->scan($crawler, $run, $filter))->toThrow(RuntimeException::class, 'lease')
        ->and($run->fresh()->cursor['day'])->toBe('2025-01-20')
        ->and(Fetch::query()->where('crawler_run_id', $run->id)->count())->toBe(0);
    Http::assertSentCount(1);
});

test('журнал попыток сохраняет стабильную идентичность и enum без transport secrets', function () {
    [$crawler, $run] = eisReliabilityRun();
    Log::spy();
    $number = 0;
    Http::fake(function () use (&$number) {
        if (++$number === 1) {
            throw new ConnectionException('timed out https://private:secret@proxy.example');
        }

        return Http::response(EisCsvFixture::body(), 200);
    });
    $events = [];
    app(CsvSourceMethod::class)->collectListingForRun($crawler, new ListingCursor(blockFrom: 1), app(ListingRunCheckpoint::class)->context($run, microtime(true) + 240));
    Log::shouldHaveReceived('info')->withArgs(function ($message, $context) use (&$events) {
        if ($message !== 'Crawler outbound attempt') {
            return false;
        }
        $events[] = $context;

        return true;
    })->twice();

    expect($events[0]['request_hash'])->toBe($events[1]['request_hash'])
        ->and($events[0]['reason'])->toBe('network_timeout')
        ->and($events[0]['retry'])->toBeTrue()
        ->and($events[1]['attempt'])->toBe(2)
        ->and($events[1]['retry'])->toBeFalse()
        ->and($events[1]['run_id'])->toBe($run->id)
        ->and(json_encode($events))->not->toContain('private', 'secret', 'proxy.example', 'timed out');
});

test('исчерпание сети ограничено пятью попытками и не раскрывает исходный transport error', function () {
    [$crawler, $run, $filter] = eisReliabilityRun();
    $requests = 0;
    Http::fake(function () use (&$requests) {
        $requests++;
        throw new ConnectionException('timed out https://private:secret@proxy.example');
    });

    try {
        app(CsvSourceMethod::class)->collectListingForRun(
            $crawler, new ListingCursor(blockFrom: 1),
            app(ListingRunCheckpoint::class)->context($run, microtime(true) + 240),
        );
        $this->fail('Expected exhausted transport exception.');
    } catch (ConnectionException $exception) {
        expect($exception->getMessage())->toBe('Outbound transport failed: network_timeout.')
            ->and($exception->getPrevious())->toBeNull();
    }

    expect($requests)->toBe(5)
        ->and($run->fresh()->cursor['day'])->toBe('2025-01-20')
        ->and(Fetch::query()->where('crawler_run_id', $run->id)->count())->toBe(0);
});

test('HTML content type отклоняет даже тело с формально правильным CSV заголовком', function () {
    [$crawler, $run, $filter] = eisReliabilityRun();
    $body = mb_convert_encoding('Реестровый номер закупки;Дата размещения;Дата обновления', 'Windows-1251', 'UTF-8');
    Http::fake(['*' => fn () => Http::response($body, 200, ['Content-Type' => 'text/html; charset=windows-1251'])]);

    expect(app(ArchiveWindowScanner::class)->scan($crawler, $run, $filter))->toBe(ArchiveWindowScanResult::Stopped)
        ->and($run->fresh()->status)->toBe(RunStatus::Paused)
        ->and($run->fresh()->cursor['day'])->toBe('2025-01-20');
});
