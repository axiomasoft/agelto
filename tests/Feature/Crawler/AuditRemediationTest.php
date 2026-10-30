<?php

declare(strict_types=1);

use App\Actions\Procurement\Crawler\StoreRawFetchArtifactAction;
use App\Dto\Procurement\Crawler\ListingBatch;
use App\Dto\Procurement\Crawler\SkippedListingRow;
use App\Dto\Procurement\Crawler\WindowFilter;
use App\Dto\Procurement\Network\ProxyNodeConfiguration;
use App\Dto\Procurement\ProcurementDetails;
use App\Enums\Procurement\Crawler\ArchiveWindowScanResult;
use App\Enums\Procurement\Crawler\CrawlerMode;
use App\Enums\Procurement\Crawler\RunStatus;
use App\Enums\Procurement\Crawler\SourceMethod as Method;
use App\Exceptions\Procurement\Crawler\OutboundRequestDeadlineExceeded;
use App\Jobs\Procurement\CrawlRunJob;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\Fetch;
use App\Models\Procurement\Crawler\Proxy;
use App\Models\Procurement\Crawler\RawArtifact;
use App\Models\Procurement\Crawler\Run;
use App\Models\Procurement\Crawler\SourceIncident;
use App\Models\Procurement\Platform\Platform;
use App\Models\Procurement\Platform\Registry;
use App\Models\Procurement\Procurement;
use App\Services\PartitionCoverage;
use App\Services\Platforms\Eis\Csv\EisCsvRowMapper;
use App\Services\Platforms\Eis\EisSourceClock;
use App\Services\Procurement\ArchiveWindowScanner;
use App\Services\Procurement\Contracts\ListingRowMapper;
use App\Services\Procurement\Contracts\SourceMethod;
use App\Services\Procurement\Crawler\ResumeWaitingRuns;
use App\Services\Procurement\CsvBlockIngestor;
use App\Services\Procurement\DetailRefetchSelector;
use App\Services\Procurement\Health\CardLayoutPreflight;
use App\Services\Procurement\Modes\FreshRunProcessor;
use App\Services\Procurement\Network\BoundedResponseReader;
use App\Services\Procurement\ProcurementIngestProcessor;
use App\Services\Procurement\Proxy\ProxyBindingResolver;
use App\Support\SecretRedactor;
use GuzzleHttp\Psr7\Response as PsrResponse;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Database\QueryException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Crawler\SourceControlScenario as S;
use Tests\Support\Doubles\Crawler\ScriptedSourceMethod;
use Tests\Support\Postgres\ConcurrentTransactions;
use Tests\Support\ProcessCore\Http\PermitFixtures;
use Tests\Support\ProcessCore\LegacyBusinessNetwork;

beforeEach(function (): void {
    LegacyBusinessNetwork::install();
    Storage::fake('s3');
    $this->realQueue = Queue::getFacadeRoot();
    Queue::fake();
});

test('распакованное тело ограничено фактическими байтами и поток закрывается после отказа', function (): void {
    $input = Utils::streamFor(str_repeat('x', 8193));
    $response = new Response(new PsrResponse(200, ['Content-Length' => '1'], $input));
    expect(fn () => app(BoundedResponseReader::class)->read($response, 8192, microtime(true) + 1))
        ->toThrow(RuntimeException::class, 'decoded bytes');
    expect($input->isReadable())->toBeFalse();
});

test('просроченный deadline закрывает тело до его чтения', function (): void {
    $input = Utils::streamFor('payload');
    expect(fn () => app(BoundedResponseReader::class)->read(new Response(new PsrResponse(200, [], $input)), 100, microtime(true) - 1))
        ->toThrow(OutboundRequestDeadlineExceeded::class);
    expect($input->isReadable())->toBeFalse();
});

test('documents допускает пустой контейнер и отклоняет заглушку', function (): void {
    $preflight = app(CardLayoutPreflight::class);
    expect($preflight->missingBaseSelectors(new ProcurementDetails(registryNumber: '1', procedureUrlType: 'test', documentsHtml: '<div class="blockFilesTabDocs"></div>')))->toBe([])
        ->and($preflight->missingBaseSelectors(new ProcurementDetails(registryNumber: '1', procedureUrlType: 'test', documentsHtml: '<html>maintenance</html>')))
        ->toBe(['documents' => ['div.blockFilesTabDocs']]);
});

test('карточки маленькой площадки получают долю общего лимита', function (): void {
    $large = Registry::factory()->create();
    $small = Registry::factory()->for(Platform::factory()->create(), 'platform')->create();
    Procurement::factory()->count(20)->create(['registry_id' => $large->id, 'detail_fetched_at' => null]);
    $one = Procurement::factory()->create(['registry_id' => $small->id, 'detail_fetched_at' => null]);
    expect(app(DetailRefetchSelector::class)->due(2)->pluck('id')->all())->toContain($one->id);
});

test('plaintext прокси обновляется идемпотентно без изменения уже зашифрованного пароля', function (): void {
    $proxy = Proxy::factory()->create(['username' => 'user']);
    DB::table('prc_network_routes')->where('id', $proxy->route_id)->update(['secret_ref' => 'AUDIT_PROXY_SECRET']);
    $crawler = Crawler::factory()->withProxies($proxy)->create();
    putenv('AUDIT_PROXY_SECRET=first');
    try {
        $resolver = app(ProxyBindingResolver::class);
        expect(array_values($resolver->forCrawler($crawler)->bindings)[0]->credentials())->toBe('user:first');
        putenv('AUDIT_PROXY_SECRET=second');
        expect(array_values($resolver->forCrawler($crawler)->bindings)[0]->credentials())->toBe('user:second')
            ->and(Schema::hasColumn('prc_proxies', 'password'))->toBeFalse()
            ->and($proxy->fresh()->load('route')->toJson())->not->toContain('first', 'second');
    } finally {
        putenv('AUDIT_PROXY_SECRET');
    }
});

test('повреждённый ciphertext блокирует весь upgrade без частичной перезаписи', function (): void {
    $proxy = Proxy::factory()->create();
    $before = $proxy->fresh()->load('route')->toArray();
    expect(fn () => ProxyNodeConfiguration::fromArray([
        'service' => 'audit', 'host' => 'proxy.test', 'port' => 8080, 'secret_ref' => 'eyJdamaged',
    ]))->toThrow(InvalidArgumentException::class);
    expect($proxy->fresh()->load('route')->toArray())->toBe($before);
});

test('чужой APP_KEY не превращает ciphertext в новый plaintext', function (): void {
    $proxy = Proxy::factory()->create();
    $foreign = new Encrypter(random_bytes(32), 'AES-256-CBC');
    $value = $foreign->encryptString('private');
    $before = $proxy->fresh()->load('route')->toArray();
    expect(fn () => ProxyNodeConfiguration::fromArray([
        'service' => 'audit', 'host' => 'proxy.test', 'port' => 8080, 'secret_ref' => $value,
    ]))->toThrow(InvalidArgumentException::class);
    expect($proxy->fresh()->load('route')->toArray())->toBe($before);
});

test('граница суток хранит 23 и 25 часов в зоне с DST', function (string $date, int $hours): void {
    $clock = new EisSourceClock('Europe/Berlin');
    $start = $clock->parseDateTime('!d.m.Y', $date);
    expect((int) $start->diffInHours($clock->endOfSourceDay($start)))->toBe($hours);
})->with([['29.03.2026', 23], ['25.10.2026', 25]]);

test('поздний ingest старого дневного наблюдения не откатывает более поздний HTTP снимок', function (): void {
    $crawler = Crawler::factory()->create();
    $row = S::row('0173200001234000999');
    $row['Дата обновления'] = '25.10.2026';
    $mapper = new EisCsvRowMapper(new EisSourceClock('Europe/Berlin'));
    $old = $mapper->map($row, $crawler);
    $row['Наименование закупки'] = 'Новое название';
    $new = $mapper->map($row, $crawler);
    $ingest = app(ProcurementIngestProcessor::class);
    $head = $ingest->ingest($new, observedAt: Carbon::parse('2026-10-25 12:00:00.200000 UTC'));
    $ingest->ingest($old, observedAt: Carbon::parse('2026-10-25 12:00:00.100000 UTC'));
    expect($head->refresh()->title)->toBe('Новое название')
        ->and($head->getAttribute('source_updated_has_day_precision'))->toBeTrue()
        ->and($head->getAttribute('source_order_ambiguous'))->toBeTrue()
        ->and($head->getAttribute('source_updated_before')->toIso8601String())->toBe('2026-10-25T23:00:00+00:00')
        ->and($head->revision_count)->toBe(1);
});

test('частично покрытый месяц достраивается по границам а имя чужой таблицы не считается покрытием', function (): void {
    DB::statement('CREATE TABLE audit_partition_probe (at timestamptz) PARTITION BY RANGE (at)');
    DB::statement("CREATE TABLE audit_partition_probe_half PARTITION OF audit_partition_probe FOR VALUES FROM ('2026-01-01') TO ('2026-01-15')");
    $coverage = app(PartitionCoverage::class);
    $coverage->ensure('audit_partition_probe', Carbon::parse('2026-01-01 UTC'), Carbon::parse('2026-02-01 UTC'));
    $coverage->ensure('audit_partition_probe', Carbon::parse('2026-01-01 UTC'), Carbon::parse('2026-02-01 UTC'));
    DB::insert("INSERT INTO audit_partition_probe VALUES ('2026-01-31')");
    expect(DB::table('audit_partition_probe')->count())->toBe(1);
    DB::statement('CREATE TABLE audit_partition_probe_2026_02 (id int)');
    expect(fn () => $coverage->ensure('audit_partition_probe', Carbon::parse('2026-02-01 UTC'), Carbon::parse('2026-03-01 UTC')))
        ->toThrow(QueryException::class);
});

test('повтор окна восстанавливает строку потерянную при удалении перед offset и не дублирует ревизии', function (): void {
    config(['crawler.platforms.eis.block_size' => 2]);
    $crawler = Crawler::factory()->create(['mode' => CrawlerMode::Archive]);
    $run = Run::factory()->for($crawler)->create(['cursor' => null]);
    // Pass one misses C after a deletion shifts it before offset 3. Two later stable
    // passes see A,C. Both remain represented, and repeat ingest creates no new revisions.
    $first = S::rows(1, 2);
    $changed = S::rows(2, 2);
    $source = S::install(new ScriptedSourceMethod(listing: [$first, S::end(), $changed, S::end(), $changed, S::end()]));
    $result = app(ArchiveWindowScanner::class)->scan($crawler, $run, new WindowFilter('update', now()->startOfDay(), now()->startOfDay()));
    expect($result)->toBe(ArchiveWindowScanResult::Drained)
        ->and($source->requestedBlocks)->toBe([1, 3, 1, 3, 1, 3])
        ->and(Procurement::query()->count())->toBe(4)
        ->and(Procurement::query()->max('revision_count'))->toBe(1);
});

test('нестабильное окно остаётся незавершённым после трёх проходов', function (): void {
    $crawler = Crawler::factory()->create(['mode' => CrawlerMode::Archive]);
    $run = Run::factory()->for($crawler)->create(['cursor' => null]);
    S::install(new ScriptedSourceMethod(listing: [S::rows(1), S::rows(2), S::rows(3)]));
    expect(app(ArchiveWindowScanner::class)->scan($crawler, $run, new WindowFilter('update', now()->startOfDay(), now()->startOfDay())))
        ->toBe(ArchiveWindowScanResult::Stopped)
        ->and($run->refresh()->status)->toBe(RunStatus::Paused);
});

test('изменение маршрута между блоками останавливает старый fresh профиль', function (string $change): void {
    $crawler = Crawler::factory()->create();
    $source = Mockery::mock(SourceMethod::class);
    $source->shouldReceive('isAvailable')->once()->andReturnTrue();
    $source->shouldReceive('collectListing')->once()->andReturnUsing(function () use ($crawler, $change) {
        if ($change === 'platform') {
            $crawler->registry->platform->update(['base_url' => 'https://changed.test']);
        } else {
            $crawler->registry()->update(['settings' => json_encode(['changed' => true])]);
        }

        return S::rows(1);
    });
    app()->instance($source::class, $source);
    config(['crawler.platforms.eis.source_methods' => [Method::Csv->value => $source::class]]);
    $run = app(FreshRunProcessor::class)->run($crawler);
    expect($run->status)->toBe(RunStatus::Paused)
        ->and($run->stats->get('stop_reason'))->toContain('между блоками')
        ->and($run->cursor->get('blockFrom'))->toBe(501);
})->with(['registry', 'platform']);

test('секреты скрываются в URL SQL и вложенном контексте', function (): void {
    $clean = SecretRedactor::text('https://user:secret@host.test/?token=abc password="xyz" SQL: update proxies set password=private');
    expect($clean)->not->toContain('secret', 'abc', 'xyz', 'private')
        ->and(SecretRedactor::context(['nested' => ['password' => 'x'], 'ok' => 'value']))
        ->toBe(['nested' => ['password' => '[redacted]'], 'ok' => 'value']);
});

test('retention удаляет ограниченную порцию просроченных объектов и оставляет свежие', function (): void {
    SourceIncident::factory()->count(3)->create(['created_at' => now()->subDays(100)]);
    $action = app(StoreRawFetchArtifactAction::class);
    $this->travel(-31)->days();
    $old = $action->execute('s3', 'raw/test', ['id' => 1], 'old', 'txt');
    $this->travelBack();
    $fresh = $action->execute('s3', 'raw/test', ['id' => 2], 'fresh', 'txt');
    expect(Artisan::call('crawler:prune-history', ['--limit' => 1]))->toBe(0)
        ->and(SourceIncident::query()->count())->toBe(2);
    Storage::disk('s3')->assertMissing($old);
    Storage::disk('s3')->assertExists($fresh);
});

test('осознанный пропуск строки отличается от неразобранной строки', function (): void {
    $crawler = Crawler::factory()->create();
    $run = Run::factory()->for($crawler)->create();
    $mapper = Mockery::mock(ListingRowMapper::class);
    $mapper->shouldReceive('map')->once()->andReturn(new SkippedListingRow('metadata'));
    app()->instance($mapper::class, $mapper);
    config(['crawler.platforms.eis.listing_mapper' => $mapper::class]);
    $result = app(CsvBlockIngestor::class)->ingest($crawler, $run, S::rows(1, 1));
    expect($result->failure)->toBeNull()->and($result->rowsSkipped)->toBe(1);
});

test('невалидные первые contexts не голодают последующие due прогоны и burst ограничен', function (): void {
    $registry = Registry::factory()->eis()->create();
    for ($i = 0; $i < 12; $i++) {
        $crawler = Crawler::factory()->create(['registry_id' => $registry->id]);
        Run::factory()->for($crawler)->create(['status' => RunStatus::Paused, 'stats' => ['source_suspended' => true]]);
    }
    $crawler = Crawler::factory()->create(['registry_id' => $registry->id]);
    $valid = Run::factory()->for($crawler)->create(['status' => RunStatus::Paused, 'stats' => [
        'source_suspended' => true, 'execution_context' => ['mode' => 'fresh', 'execution_lane' => 'standard'],
    ]]);
    $resumer = app(ResumeWaitingRuns::class);
    expect($resumer->resumeDue(limit: 10))->toBe(0)->and($resumer->resumeDue(limit: 10))->toBe(1)->and($resumer->resumeDue(limit: 10))->toBe(0);
    Queue::assertPushed(CrawlRunJob::class, 1);
    expect($valid->refresh()->stats->get('source_suspended'))->toBeTrue();
});

test('реальный отказ Redis не теряет due прогон и не оставляет dispatch claim', function (): void {
    $crawler = Crawler::factory()->create();
    $run = Run::factory()->for($crawler)->create(['status' => RunStatus::Paused, 'stats' => [
        'source_suspended' => true, 'execution_context' => ['mode' => 'fresh', 'execution_lane' => 'standard'],
    ]]);
    // Real socket refusal in the isolated container; no Redis service or data is stopped/deleted.
    Queue::swap($this->realQueue);
    config(['database.redis.default.port' => 1, 'database.redis.default.timeout' => 0.1]);
    Redis::purge('default');
    try {
        expect(fn () => app(ResumeWaitingRuns::class)->resumeDue())->toThrow(RedisException::class);
        expect($run->refresh()->stats->get('source_suspended'))->toBeTrue()
            ->and($run->getAttribute('source_resume_claimed_until'))->toBeNull();
    } finally {
        config(['database.redis.default.port' => 6379]);
        Redis::purge('default');
        Queue::fake();
    }
    expect(app(ResumeWaitingRuns::class)->resumeDue())->toBe(1);
    Queue::assertPushed(CrawlRunJob::class, 1);
});

test('монитор обнаруживает старую паузу несмотря на свежую попытку dispatch', function (): void {
    $crawler = Crawler::factory()->create();
    Run::factory()->for($crawler)->create(['status' => RunStatus::Paused, 'last_heartbeat_at' => now()->subHours(2), 'source_resume_checked_at' => now()]);
    expect(Artisan::call('crawler:progress'))->toBe(1);
});

test('четыре процесса не расходуют больше одного общего HTTP слота', function (): void {
    PermitFixtures::clean();
    try {
        $f = new PermitFixtures;
        DB::table('prc_network_budgets')->update(['max_inflight' => 1, 'emission_interval_us' => 60_000_000]);
        $workers = [];
        foreach (range(1, 4) as $index) {
            $context = $f->context();
            $ticket = $f->ticket($context);
            $id = var_export($ticket->id->value, true);
            $owner = var_export($context->lease()->id->value, true);
            $url = var_export($f->url->reveal(), true);
            $workers[(string) $index] = <<<PHP
\$context = \Tests\Support\ProcessCore\Http\PermitFixtures::importContext({$owner});
\$ticket = \Tests\Support\ProcessCore\Http\PermitFixtures::importTicket({$id}, {$url});
\$permit = app(\App\Repositories\Procurement\Network\NetworkPermitRepository::class)->reserve(\$ticket, \$context);
return ['admitted' => \$permit instanceof \App\Dto\Procurement\Network\ScheduledNetworkPermit,
    'backend_pid' => \Illuminate\Support\Facades\DB::scalar('select pg_backend_pid()')];
PHP;
        }
        $results = ConcurrentTransactions::concurrently($workers);
        expect(array_sum(array_column($results, 'admitted')))->toBe(1)
            ->and(array_unique(array_column($results, 'backend_pid')))->toHaveCount(4)
            ->and(DB::table('prc_network_permits')->count())->toBe(1);
    } finally {
        PermitFixtures::clean();
    }

});

test('backfill raw каталога сохраняет новейшее использование повторяющегося пути', function (): void {
    $path = 'raw/csv/legacy/payload.csv';
    Fetch::factory()->create(['artifact_ref' => $path, 'fetched_at' => now()->subDays(40)]);
    Fetch::factory()->create(['artifact_ref' => $path, 'fetched_at' => now()->subDay()]);
    expect(Artisan::call('crawler:catalog-raw-history'))->toBe(0);
    $seen = DB::table((new RawArtifact)->getTable())->where('path', $path)->value('last_seen_at');
    expect(Carbon::parse($seen)->toDateString())->toBe(now()->subDay()->toDateString());
    expect(Artisan::call('crawler:catalog-raw-history'))->toBe(0)
        ->and(DB::table((new RawArtifact)->getTable())->where('path', $path)->count())->toBe(1);
});

test('после нестабильного многоблочного окна возобновление проверяет весь интервал', function (): void {
    config(['crawler.platforms.eis.block_size' => 2]);
    $crawler = Crawler::factory()->create(['mode' => CrawlerMode::Archive]);
    $run = Run::factory()->for($crawler)->create(['cursor' => null]);
    $window = new WindowFilter('update', now()->startOfDay(), now()->startOfDay());
    S::install(new ScriptedSourceMethod(listing: [S::rows(1, 2), S::end(), S::rows(2, 2), S::end(), S::rows(3, 2), S::end()]));
    $scanner = app(ArchiveWindowScanner::class);
    expect($scanner->scan($crawler, $run, $window))->toBe(ArchiveWindowScanResult::Stopped)
        ->and($run->refresh()->cursor->get('blockFrom'))->toBe(1);
    $run->update(['status' => RunStatus::Running]);
    $source = S::install(new ScriptedSourceMethod(listing: [S::rows(3, 2), S::end()]));
    expect($scanner->scan($crawler, $run, $window))->toBe(ArchiveWindowScanResult::Drained)
        ->and($source->requestedBlocks)->toBe([1, 3])
        ->and(Procurement::query()->max('revision_count'))->toBe(1);
});

test('backfill различает диски HTML карточек и CSV листинга', function (): void {
    config(['crawler.raw_disk' => 'listing-disk', 'crawler.raw_html_disk' => 'detail-disk']);
    Fetch::factory()->create(['stage' => 'listing', 'artifact_ref' => 'raw/csv/legacy.csv']);
    Fetch::factory()->create(['stage' => 'detail', 'artifact_ref' => 'raw/html/legacy.html']);
    expect(Artisan::call('crawler:catalog-raw-history'))->toBe(0)
        ->and(DB::table((new RawArtifact)->getTable())->where('path', 'raw/html/legacy.html')->value('disk'))->toBe('detail-disk')
        ->and(DB::table((new RawArtifact)->getTable())->where('path', 'raw/csv/legacy.csv')->value('disk'))->toBe('listing-disk');
});

test('HTTP 200 с ошибкой схемы не обновляет время успешного блока', function (): void {
    $crawler = Crawler::factory()->create();
    $run = Run::factory()->for($crawler)->create();
    $ingestor = app(CsvBlockIngestor::class);
    $ingestor->ingest($crawler, $run, S::rows(1));
    $success = $run->refresh()->stats->get('listing_last_success_at');
    expect($success)->not->toBeNull();
    $this->travel(1)->minutes();
    $invalid = new ListingBatch(
        rows: [], rawBody: 'changed-header', dedupSignature: null, rowsTotal: 0,
        httpStatus: 200, durationMs: 1, requestParams: [], header: ['changed-header'],
    );
    expect($ingestor->ingest($crawler, $run, $invalid)->failure)->not->toBeNull()
        ->and($run->refresh()->stats->get('listing_last_success_at'))->toBe($success);
});

test('более новый source day сохраняет время собственного HTTP снимка', function (): void {
    $crawler = Crawler::factory()->create();
    $row = S::row('0173200001234000988');
    $mapper = app(EisCsvRowMapper::class);
    $row['Дата обновления'] = '25.10.2026';
    $ingest = app(ProcurementIngestProcessor::class);
    $head = $ingest->ingest($mapper->map($row, $crawler), observedAt: Carbon::parse('2026-10-26 12:00:00.300000 UTC'));
    $row['Дата обновления'] = '26.10.2026';
    $row['Наименование закупки'] = 'Новая редакция';
    $ingest->ingest($mapper->map($row, $crawler), observedAt: Carbon::parse('2026-10-26 12:00:00.100000 UTC'));
    expect($head->refresh()->getAttribute('source_observed_at')->format('u'))->toBe('100000');
    $row['Наименование закупки'] = 'Ещё новее';
    $ingest->ingest($mapper->map($row, $crawler), observedAt: Carbon::parse('2026-10-26 12:00:00.200000 UTC'));
    expect($head->refresh()->title)->toBe('Ещё новее');
});
