<?php

declare(strict_types=1);

use App\Dto\Procurement\Crawler\ListingCursor;
use App\Dto\Procurement\Crawler\WindowFilter;
use App\Enums\Procurement\Crawler\ArchiveWindowScanResult;
use App\Enums\Procurement\Crawler\CrawlerMode;
use App\Enums\Procurement\Crawler\RunStatus;
use App\Enums\Procurement\Crawler\RunStopKind;
use App\Exceptions\Procurement\Network\OutboundUnavailable;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\Fetch;
use App\Models\Procurement\Crawler\RawArtifact;
use App\Models\Procurement\Crawler\Run;
use App\Models\Procurement\Platform\Okpd2Node;
use App\Models\Procurement\Procurement;
use App\Services\Platforms\Eis\Csv\CsvSourceMethod;
use App\Services\Procurement\ArchiveWindowScanner;
use App\Services\Procurement\CrawlerRunManager;
use App\Services\Procurement\ListingRunCheckpoint;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ProcessCore\Http\CallerFixtures;
use Tests\Support\ProcessCore\Http\PermitFixtures;
use Tests\Support\ProcessCore\Http\WireFixture;

beforeEach(function (): void {
    PermitFixtures::clean();
    Storage::fake('s3');
    $this->wire = new WireFixture;
    $this->registry = CallerFixtures::platform($this->wire);
    // Prior full acceptance is fixture setup for the ordinary retry/stop scenarios below.
    DB::table('prc_adapter_health')->update(['state' => 'healthy']);
    DB::table('prc_network_budgets')->update(['emission_interval_us' => 1000]);
    config(['crawler.run.checkpoint_seconds' => 240]);
});
afterEach(function (): void {
    $this->wire->stop();
    PermitFixtures::clean();
});

function eisReliabilityResponse(string $body, int $status = 200, ?string $contentType = null): void
{
    test()->wire->respond('/csv', $body, $status, ['Content-Type' => $contentType ?? 'text/csv; charset=windows-1251']);
}

function eisReliabilityStopAfterResponse(Run $run, RunStopKind $kind): void
{
    $done = false;
    DB::listen(function (QueryExecuted $query) use ($run, $kind, &$done): void {
        if (! $done && str_contains(strtolower($query->sql), 'insert into prc_observations') && str_contains($query->sql, 'network_transport')) {
            $done = true;
            $run->update(['stop_requested_at' => now(), 'stop_kind' => $kind]);
        }
    });
}

function eisReliabilityProfile(array $patch): void
{
    $target = DB::table('prc_source_targets')->where('capability', 'listing')->sole();
    $policy = json_decode($target->endpoint_policy, true, flags: JSON_THROW_ON_ERROR);
    $policy['profiles'][0] = array_replace($policy['profiles'][0], $patch);
    DB::table('prc_source_targets')->where('id', $target->id)->update(['endpoint_policy' => json_encode($policy, JSON_THROW_ON_ERROR)]);
}

/** @return array{Crawler, Run, WindowFilter} */
function eisReliabilityRun(): array
{
    $registry = test()->registry;
    Okpd2Node::factory()->create(['platform_id' => $registry->platform_id, 'code' => '43', 'external_id' => '8873921']);
    $crawler = Crawler::factory()->for($registry)->create([
        'mode' => CrawlerMode::Archive,
        'route_profile_id' => null,
        'filter' => ['okpd2Codes' => ['43'], 'publishedFrom' => '2025-01-01', 'publishedTo' => '2025-01-31'],
    ]);
    $run = Run::factory()->for($crawler)->create(['cursor' => ['blockFrom' => 1, 'day' => '2025-01-20']]);
    $day = Carbon::parse('2025-01-20');

    return [$crawler, $run, new WindowFilter(dateField: 'publish', dateFrom: $day, dateTo: $day)];
}

test('невалидный ответ сохраняет raw evidence и не дренирует день', function (string $body, int $status) {
    [$crawler, $run, $filter] = eisReliabilityRun();
    eisReliabilityResponse($body, $status);

    expect(app(ArchiveWindowScanner::class)->scan($crawler, $run, $filter))->toBe(ArchiveWindowScanResult::Stopped)
        ->and($run->fresh()->status)->toBe(RunStatus::Paused);

    $artifact = RawArtifact::query()->where('path', 'like', "raw/csv/{$crawler->id}/{$run->id}/%")->sole();
    expect($run->fresh()->cursor->toArray())->toEqual(['blockFrom' => 1, 'day' => '2025-01-20'])
        ->and(DB::table('prc_request_attempts')->where('status', $status)->count())->toBeGreaterThan(0)
        ->and(Storage::disk('s3')->get($artifact->path))->toBe($body)
        ->and(Fetch::query()->where('crawler_run_id', $run->id)->count())->toBe(0)
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
    eisReliabilityResponse($header);

    expect(app(ArchiveWindowScanner::class)->scan($crawler, $run, $filter))->toBe(ArchiveWindowScanResult::Drained)
        ->and(DB::table('prc_request_attempts')->where('status', 200)->count())->toBe(2)
        ->and(DB::table('prc_observations')->where('kind', 'adapter_validation')->where('details->verdict', 'accepted')->count())->toBe(2)
        ->and(Fetch::query()->where('crawler_run_id', $run->id)->count())->toBe(0);
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
    eisReliabilityResponse($body);

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
    eisReliabilityResponse('Unavailable', 503);
    eisReliabilityStopAfterResponse($run, $kind);
    expect(app(ArchiveWindowScanner::class)->scan($crawler, $run, $filter))->toBe(ArchiveWindowScanResult::Stopped)
        ->and($run->fresh()->status)->toBe($status)
        ->and($run->fresh()->cursor['day'])->toBe('2025-01-20')
        ->and($this->wire->receipts())->toHaveCount(1);
})->with([[RunStopKind::Pause, RunStatus::Paused], [RunStopKind::Cancel, RunStatus::Cancelled]]);

test('пауза прерывает длинное retry ожидание за один секундный slice', function () {
    [$crawler, $run, $filter] = eisReliabilityRun();
    eisReliabilityResponse('Unavailable', 503);
    eisReliabilityProfile(['delays_ms' => [2000, 2000]]);
    eisReliabilityStopAfterResponse($run, RunStopKind::Pause);
    $started = hrtime(true);
    expect(app(ArchiveWindowScanner::class)->scan($crawler, $run, $filter))->toBe(ArchiveWindowScanResult::Stopped)
        ->and($run->fresh()->status)->toBe(RunStatus::Paused)
        ->and($this->wire->receipts())->toHaveCount(1)
        ->and((hrtime(true) - $started) / 1_000_000)->toBeLessThan(1000);
});

test('пауза прерывает budget ожидание до запроса', function () {
    [$crawler, $run, $filter] = eisReliabilityRun();
    DB::table('prc_network_budgets')->update(['not_before' => DB::raw("clock_timestamp() + interval '60 seconds'")]);
    $done = false;
    DB::listen(function (QueryExecuted $query) use ($run, &$done): void {
        if (! $done && str_contains($query->sql, 'prc_network_budgets') && str_contains(strtoupper($query->sql), 'FOR UPDATE')) {
            $done = true;
            $run->update(['stop_requested_at' => now(), 'stop_kind' => RunStopKind::Pause]);
        }
    });
    $started = hrtime(true);
    expect(app(ArchiveWindowScanner::class)->scan($crawler, $run, $filter))->toBe(ArchiveWindowScanResult::Stopped)
        ->and($run->fresh()->status)->toBe(RunStatus::Paused)
        ->and($this->wire->receipts())->toBe([])
        ->and(DB::table('prc_request_attempts')->count())->toBe(0)
        ->and((hrtime(true) - $started) / 1_000_000)->toBeLessThan(1000);
});

test('listing deadline возвращает checkpoint без изменения курсора и HTTP запроса', function () {
    [$crawler, $run, $filter] = eisReliabilityRun();
    config(['crawler.run.checkpoint_seconds' => 4]);

    expect(app(ArchiveWindowScanner::class)->scan($crawler, $run, $filter))->toBe(ArchiveWindowScanResult::Checkpointed)
        ->and($run->fresh()->status)->toBe(RunStatus::Running)
        ->and($run->fresh()->cursor['day'])->toBe('2025-01-20');
    expect($this->wire->receipts())->toBe([]);
});

test('потеря execution lease между попытками запрещает следующий запрос', function () {
    [$crawler, $run, $filter] = eisReliabilityRun();
    $manager = app(CrawlerRunManager::class);
    expect($manager->acquireExecutionLease($run))->toBeTrue();
    eisReliabilityResponse('Unavailable', 503);
    $done = false;
    DB::listen(function (QueryExecuted $query) use ($run, &$done): void {
        if (! $done && str_contains($query->sql, 'INSERT INTO prc_observations') && str_contains($query->sql, 'network_transport')) {
            $done = true;
            Run::query()->whereKey($run->id)->update(['execution_lease_expires_at' => DB::raw("clock_timestamp() - INTERVAL '1 second'")]);
            expect((new CrawlerRunManager)->acquireExecutionLease($run->fresh()))->toBeTrue();
        }
    });
    expect(fn () => app(ArchiveWindowScanner::class)->scan($crawler, $run, $filter))->toThrow(RuntimeException::class, 'lease')
        ->and($run->fresh()->cursor['day'])->toBe('2025-01-20')
        ->and(Procurement::query()->count())->toBe(0)
        ->and(Fetch::query()->where('crawler_run_id', $run->id)->count())->toBe(0)
        ->and($this->wire->receipts())->toHaveCount(1);
});

test('журнал попыток сохраняет стабильную идентичность и enum без transport secrets', function () {
    [$crawler, $run] = eisReliabilityRun();
    Log::spy();
    eisReliabilityResponse('Unavailable', 503);
    eisReliabilityResponse(mb_convert_encoding('Реестровый номер закупки;Дата размещения;Дата обновления', 'Windows-1251', 'UTF-8'));
    app(CsvSourceMethod::class)->collectListingForRun($crawler, new ListingCursor(blockFrom: 1), app(ListingRunCheckpoint::class)->context($run, microtime(true) + 240));
    $events = [];
    Log::shouldHaveReceived('info')->withArgs(function ($message, $context) use (&$events): bool {
        if ($message !== 'Crawler outbound attempt') {
            return false;
        }
        $events[] = $context;

        return true;
    })->twice();
    expect($events[0]['request_id'])->not->toBe($events[1]['request_id'])
        ->and($events[0]['reason'])->toBe('http_503')->and($events[1]['reason'])->toBe('http_200')
        ->and(DB::table('prc_network_demands')->distinct()->count('operation_id'))->toBe(1)
        ->and(DB::table('prc_request_attempts')->orderBy('request_seq')->pluck('request_seq')->all())->toBe([1, 2])
        ->and(json_encode($events))->not->toContain('private', 'secret', 'proxy.example', 'timed out', 'http://')
        ->and($this->wire->receipts())->toHaveCount(2)
        ->and(RawArtifact::query()->count())->toBe(0);
});

test('исчерпание сети ограничено пятью попытками и не раскрывает исходный transport error', function () {
    [$crawler, $run] = eisReliabilityRun();
    config(['crawler.eis.csv_endpoint' => $this->wire->url('/stall')->reveal()]);
    eisReliabilityProfile(['connect_seconds' => 1, 'low_speed_seconds' => 1, 'total_seconds' => 1, 'delays_ms' => [0, 0]]);
    try {
        app(CsvSourceMethod::class)->collectListingForRun($crawler, new ListingCursor(blockFrom: 1), app(ListingRunCheckpoint::class)->context($run, microtime(true) + 240));
        $this->fail('Expected typed exhausted dependency.');
    } catch (OutboundUnavailable $exception) {
        expect($exception->getMessage())->toBe('Outbound dependency unavailable: attempts_exhausted')
            ->and($exception->getPrevious())->toBeNull()
            ->and($exception->outcome->finalResponse)->toBeNull();
    }
    // Historical title remains exact; the canonical, catalog-pinned retry budget is three.
    expect($this->wire->receipts())->toHaveCount(3)
        ->and(DB::table('prc_request_attempts')->count())->toBe(3)
        ->and($run->fresh()->cursor['day'])->toBe('2025-01-20')
        ->and(RawArtifact::query()->count())->toBe(0)
        ->and(Fetch::query()->where('crawler_run_id', $run->id)->count())->toBe(0);
});

test('HTML content type отклоняет даже тело с формально правильным CSV заголовком', function () {
    [$crawler, $run, $filter] = eisReliabilityRun();
    $body = mb_convert_encoding('Реестровый номер закупки;Дата размещения;Дата обновления', 'Windows-1251', 'UTF-8');
    eisReliabilityResponse($body, contentType: 'text/html; charset=windows-1251');

    expect(app(ArchiveWindowScanner::class)->scan($crawler, $run, $filter))->toBe(ArchiveWindowScanResult::Stopped)
        ->and($run->fresh()->status)->toBe(RunStatus::Paused)
        ->and($run->fresh()->cursor['day'])->toBe('2025-01-20');
});
