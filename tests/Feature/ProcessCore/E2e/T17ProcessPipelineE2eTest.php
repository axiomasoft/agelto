<?php

declare(strict_types=1);

use App\Dto\Llm\Requests\PinnedLlmContext;
use App\Dto\Platforms\Eis\EisCardRoutes;
use App\Dto\Platforms\Eis\EisSearchFilter;
use App\Dto\Procurement\Ai\AnalysisProviderConnection;
use App\Dto\Procurement\Ai\PageRange;
use App\Dto\Procurement\Network\ProviderKey;
use App\Dto\Procurement\Network\ProviderTargetDefinitions;
use App\Dto\Procurement\Platform\PlatformCode;
use App\Dto\Procurement\Platform\SourceTargetDefinition;
use App\Dto\Procurement\Process\DeliveryEnvelope;
use App\Dto\Procurement\Process\DeliveryGeneration;
use App\Dto\Procurement\Process\ExecutionContext;
use App\Dto\Procurement\Process\StageOutcome;
use App\Dto\Procurement\Process\WorkInput;
use App\Enums\Procurement\Process\AcquisitionKind;
use App\Enums\Procurement\Process\StageCode;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Estimate\Estimate;
use App\Models\Procurement\Platform\Okpd2Node;
use App\Models\Procurement\Platform\Platform;
use App\Models\Procurement\Platform\Registry;
use App\Models\Procurement\Procurement;
use App\Pulse\Cards\ProcessPlatformHealthCard;
use App\Repositories\Procurement\Crawler\RefreshRepository;
use App\Repositories\Procurement\Network\RouteRepository;
use App\Repositories\Procurement\Process\PlatformMetricsReadRepository;
use App\Services\Llm\Execution\ReceiptPolicies;
use App\Services\Llm\Providers\GeminiAssetReceiptPolicy;
use App\Services\Platforms\Eis\Listing\EisProfilePreparer;
use App\Services\Procurement\Acquisition\AcquisitionHandler;
use App\Services\Procurement\Acquisition\AcquisitionScheduler;
use App\Services\Procurement\Acquisition\AcquisitionValidation;
use App\Services\Procurement\Document\RuntimeDownloadHandler;
use App\Services\Procurement\Extraction\AnalysisProviderConnections;
use App\Services\Procurement\Network\OutboundPolicy;
use App\Services\Procurement\Network\SourceCatalogSync;
use App\Services\Procurement\Network\UrlPolicy;
use App\Services\Procurement\Platform\Contracts\DocumentUrlPolicy;
use App\Services\Procurement\Platform\PlatformRegistry;
use App\Services\Procurement\Process\Contracts\StageHandler;
use App\Services\Procurement\Process\FeederDaemon;
use App\Services\Procurement\Process\ProcessAuthoritySync;
use App\Services\Procurement\Process\ProcessTransaction;
use App\Services\Procurement\Process\ProjectorDaemon;
use App\Services\Procurement\Process\ReconcileDaemon;
use App\Services\Procurement\Process\RecoveryReconciler;
use App\Services\Procurement\Process\RelayDaemon;
use App\Services\Procurement\Process\StageQueueMap;
use App\Services\Procurement\Process\StageRegistry;
use App\Services\Procurement\Process\WorkRunner;
use App\Services\Procurement\Proxy\ProxyResolver;
use Carbon\CarbonImmutable;
use Illuminate\Queue\Jobs\RedisJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Symfony\Component\Process\Process;
use Tests\Support\Doubles\Crawler\CardHtmlFixture;
use Tests\Support\Platforms\Eis\EisDetailFixtures;
use Tests\Support\Platforms\Eis\EisListingFactsFixtures;
use Tests\Support\ProcessCore\AiFixtures;
use Tests\Support\ProcessCore\DocumentStorageFixtures;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\FinancialFixtures;
use Tests\Support\ProcessCore\Http\CallerFixtures;
use Tests\Support\ProcessCore\Http\PermitFixtures;
use Tests\Support\ProcessCore\Http\TransportFixtures;
use Tests\Support\ProcessCore\Http\WireFixture;
use Tests\Support\ProcessCore\LedgerFixtures;
use Tests\Support\ProcessCore\ScreeningRuntimeFixtures;

pest()->group('p316', 'e2e');

function e2eTwoPagePdfBytes(string $workDir): string
{
    $source = base_path('tests/Fixtures/ProcessCore/Documents/mixed-native-scan-blank.pdf');
    $target = $workDir.'/two-page-estimate.pdf';
    $process = new Process(['qpdf', $source, '--pages', '.', '1-2', '--', $target]);
    $process->mustRun();

    return (string) file_get_contents($target);
}

function e2eGeminiEnvelope(string $modelJson): string
{
    return json_encode([
        'modelVersion' => 'fixture-model-resolved/1',
        'candidates' => [
            [
                'content' => [
                    'role' => 'model',
                    'parts' => [
                        ['text' => $modelJson],
                    ],
                ],
                'finishReason' => 'STOP',
            ],
        ],
        'usageMetadata' => [
            'promptTokenCount' => 100,
            'cachedContentTokenCount' => 20,
            'candidatesTokenCount' => 40,
            'thoughtsTokenCount' => 10,
        ],
    ], JSON_THROW_ON_ERROR);
}

/** @param list<array{int,int}> $positions */
function e2eExtractionJson(int $ordinal, PageRange $range, array $positions): string
{
    $template = json_decode(AiFixtures::raw(), true, flags: JSON_THROW_ON_ERROR)['rows'][0];
    $rows = [];
    foreach ($positions as [$page, $line]) {
        $row = $template;
        $row['estimate_ordinal'] = $ordinal;
        $row['page_index'] = $page;
        $row['source_anchor'] = 'page:'.$page.':line:'.$line;
        $row['row']['row_index'] = $page * 100000 + $line;
        $rows[] = $row;
    }

    return json_encode([
        'rows' => $rows,
        'totals' => [],
        'covered_pages' => range($range->start, $range->end - 1),
    ], JSON_THROW_ON_ERROR);
}

function e2eDrainRedisQueues(): int
{
    $dispatched = 0;
    $routes = [
        ['connection' => 'process_long', 'queue' => 'process-listing'],
        ['connection' => 'process_short', 'queue' => 'process-acquisition'],
        ['connection' => 'process_download', 'queue' => 'process-download'],
        ['connection' => 'process_long', 'queue' => 'process-prepare'],
        ['connection' => 'process_ai', 'queue' => 'process-ai'],
        ['connection' => 'process_short', 'queue' => 'process-publication'],
    ];

    foreach ($routes as $route) {
        while (($job = Queue::connection($route['connection'])->pop($route['queue'])) !== null) {
            expect($job)->toBeInstanceOf(RedisJob::class);
            $job->fire();
            $dispatched++;
        }
    }

    return $dispatched;
}

beforeEach(function (): void {
    PermitFixtures::clean();
    ExecutionFixtures::truncateLedger();
    DocumentStorageFixtures::reset();
    Redis::connection()->flushdb();
    $this->workDir = storage_path('app/testing/e2e-'.bin2hex(random_bytes(6)));
    mkdir($this->workDir, 0700, true);
    $this->wire = new WireFixture;
});

afterEach(function (): void {
    if (isset($this->pipelineHorizon)) {
        $this->pipelineHorizon->stop(10, SIGKILL);
    }
    $this->wire->stop();
    File::deleteDirectory($this->workDir);
    Redis::connection()->flushdb();
});

function processPipelineE2e(bool $horizon): Closure
{
    return function () use ($horizon): void {
        $wire = $this->wire;
        $period = FinancialFixtures::reset();

        $this->artisan('process:sync-documents')->assertSuccessful();

        config([
            'platforms.eis.endpoints.listing.url' => $wire->url('/eis/csv')->reveal(),
            'platforms.eis.endpoints.card.url' => $wire->url('/eis/card', true)->reveal(),
            'platforms.eis.endpoints.document.url' => $wire->url('/44fz/filestore/public/1.0/download/priz/file.html', true)->reveal(),
            'outbound.http.tls_bundles' => ['fixture.test' => $wire->root.'/cert.pem'],
        ]);

        app()->instance(UrlPolicy::class, $wire->policy());
        $platforms = new PlatformRegistry(config());
        app()->instance(PlatformRegistry::class, $platforms);
        $definition = $platforms->get(new PlatformCode('eis'));

        $platform = Platform::query()->firstOrCreate(
            ['code' => 'eis'],
            ['name' => 'ЕИС', 'base_url' => 'https://zakupki.gov.ru', 'is_active' => true, 'is_public' => true],
        );
        $registry = Registry::query()->firstOrCreate(
            ['platform_id' => $platform->id, 'code' => 'fz44'],
            ['name' => '44-ФЗ', 'settings' => []],
        );

        app(SourceCatalogSync::class)->sync($definition->sourceTargets);
        $directRouteId = (string) DB::table('prc_network_routes')->where('kind', 'direct')->soleValue('id');
        $defaultProfileKey = app(OutboundPolicy::class)->defaultRouteProfile;
        $providerProfileKey = app(OutboundPolicy::class)->providerRouteProfile;
        app(ProcessTransaction::class)->run(function () use ($directRouteId, $defaultProfileKey, $providerProfileKey): void {
            app(RouteRepository::class)->ensureProfile($defaultProfileKey, $directRouteId);
            app(RouteRepository::class)->ensureProfile($providerProfileKey, $directRouteId);
            $providerProfile = DB::table('prc_network_route_profiles')->where('profile_key', $providerProfileKey)->sole();
            if (! DB::table('prc_network_route_profile_members')->where('profile_id', $providerProfile->id)->exists()) {
                app(RouteRepository::class)->updateProfile(
                    $providerProfileKey,
                    (int) $providerProfile->policy_version,
                    [$directRouteId],
                    1,
                    'e2e provider route setup',
                    LedgerFixtures::uuid(),
                    LedgerFixtures::uuid(),
                );
            }
        });

        $llmContext = new PinnedLlmContext(
            'fixture-provider',
            'fixture-account',
            'v1beta',
            'fixture-model',
            'route/1',
            ['max_output_tokens' => 4096, 'temperature' => '0.25'],
            ['budget' => 128, 'include_thoughts' => false],
        );
        $providerProfile = TransportFixtures::profile(['total_seconds' => 4]);
        app(SourceCatalogSync::class)->syncProvider(new ProviderTargetDefinitions(new ProviderKey('fixture-provider'), [
            new SourceTargetDefinition(
                'provider:fixture-provider:generate',
                'provider',
                $wire->url('/', false)->origin,
                [$providerProfile],
            ),
        ]));

        app(ReceiptPolicies::class)->register('fixture-provider', new GeminiAssetReceiptPolicy);
        $connections = new AnalysisProviderConnections;
        $connections->register(new AnalysisProviderConnection(
            $llmContext,
            $wire->url('/', false)->origin->value(),
            FinancialFixtures::pricing(),
            $period,
            $providerProfile,
            'fixture-only-key',
            $wire->policy(),
        ), preferred: true);
        app()->instance(AnalysisProviderConnections::class, $connections);

        DB::table('prc_adapter_health')->update(['state' => 'healthy']);
        CallerFixtures::healthReady();
        foreach (['prc_route_health', 'prc_route_target_health', 'prc_target_health'] as $table) {
            DB::table($table)->update(['state' => 'closed']);
        }
        DB::table('prc_network_budgets')->update(['emission_interval_us' => 50_000]);

        $cardRoutes = [];
        foreach (['common-info', 'documents', 'event-journal', 'supplier-results'] as $tab) {
            $cardRoutes[$tab] = $wire->url('/eis/card/'.$tab.'?regNumber={regNumber}', true)->reveal();
        }
        app()->instance(EisCardRoutes::class, new EisCardRoutes(['ea20' => $cardRoutes], ['Электронный аукцион' => 'ea20']));
        $allowedOrigin = $wire->url('/', true)->origin->value();
        app()->instance(DocumentUrlPolicy::class, new class($allowedOrigin) implements DocumentUrlPolicy
        {
            public function __construct(private readonly string $origin) {}

            public function assertDocumentUrl(string $url): void
            {
                if (! str_starts_with($url, $this->origin.'/')) {
                    throw new InvalidArgumentException('Untrusted document origin: '.$url);
                }
            }
        });
        app()->instance(RuntimeDownloadHandler::class, app()->makeWith(RuntimeDownloadHandler::class, ['urls' => $wire->policy()]));
        app()->bind(AcquisitionHandler::class, static fn ($app, array $params): AcquisitionHandler => new AcquisitionHandler(
            $params['stage'],
            $app->make(PlatformRegistry::class),
            $app->make(AcquisitionValidation::class),
            $app->make(ProxyResolver::class),
            $wire->policy(),
        ));

        app()->forgetInstance(StageRegistry::class);
        $stageRegistry = app(StageRegistry::class);
        $authoritySync = app(ProcessAuthoritySync::class);
        $authoritySync->ensureBaseline();
        foreach (StageCode::cases() as $stageCode) {
            $authoritySync->registerHandler($stageCode, $stageRegistry->handlerVersion($stageCode));
        }

        $regNumber = '0338200009125000001';
        $csvUtf8 = EisListingFactsFixtures::body([
            EisListingFactsFixtures::row($regNumber, [
                'Наименование закупки' => 'Капитальный ремонт кровли здания поликлиники',
                'Этап закупки' => 'Подача заявок',
                'Дата размещения' => '01.03.2026',
                'Дата обновления' => '01.03.2026 10:15:00',
                'Начальная (максимальная) цена контракта' => '1250000,50',
                'Способ определения поставщика (подрядчика, исполнителя), подрядной организации (размещения закупки)' => 'Электронный аукцион',
                'Идентификационный код закупки (ИКЗ)' => '252230802411223080100100090015814244',
                'Наименование Заказчика' => 'ГБУЗ КККВД',
                'Начало подачи заявок' => '01.03.2026 09:00',
                'Окончание подачи заявок' => '10.03.2026 09:00',
            ]),
        ]);
        $csvWin1251 = iconv('UTF-8', 'Windows-1251//IGNORE', $csvUtf8);
        expect($csvWin1251)->toBeString();
        $wire->respond('/eis/csv', (string) $csvWin1251, headers: ['Content-Type' => 'text/csv; charset=windows-1251']);

        $cardBodies = EisDetailFixtures::completeBodies();
        $shell = '<a class="cardMainInfo__purchaseLink">№ '.$regNumber.'</a>'
            .'<div class="cardMainInfo__purchaseObject">Капитальный ремонт кровли здания поликлиники</div>'
            .'<div class="cardMainInfo__state">Подача заявок</div>';
        $cardBodies['common-info'] = $shell.CardHtmlFixture::commonInfo();
        $cardBodies['event-journal'] = $shell.CardHtmlFixture::eventJournal();
        $cardBodies['supplier-results'] = str_replace(
            '<a class="cardMainInfo__purchaseLink">№ 0338200009125000001</a>',
            $shell,
            $cardBodies['supplier-results'],
        );
        $docUrl = htmlspecialchars($wire->url('/44fz/filestore/public/1.0/download/priz/file.html?uid=7389C369F4B745D9B38B824FAED4FAE6', true)->reveal(), ENT_QUOTES);
        $cardBodies['documents'] = $shell.'<!DOCTYPE html><html lang="ru"><body><div class="container"><div class="row blockInfo"><div class="col">'
            .'<div class="row no-gutters notice-documents blockInfo__section first-row-active-documents"><div class="col-sm-6"><div class="blockFilesTabDocs">'
            .'<div class="section__title mb-3">Прикрепленные файлы</div>'
            .'<div class="attachment row"><div class="col clipText">'
            .'<span class="section__value"><a href="'.$docUrl.'" title="Локальный сметный расчёт.pdf">Локальный сметный расчёт.pdf</a></span>'
            .'</div></div></div></div></div></div></div></div></body></html>';

        foreach ($cardBodies as $tab => $html) {
            $wire->respond('/eis/card/'.$tab, $html, headers: ['Content-Type' => 'text/html; charset=utf-8']);
        }

        $pdfBytes = e2eTwoPagePdfBytes($this->workDir);
        $wire->respond('/44fz/filestore/public/1.0/download/priz/file.html', $pdfBytes, headers: [
            'Content-Type' => 'application/pdf',
            'Content-Length' => (string) strlen($pdfBytes),
        ]);

        $wire->respond(
            '/v1beta/models/fixture-model:generateContent',
            e2eGeminiEnvelope(ScreeningRuntimeFixtures::raw()),
            headers: ['Content-Type' => 'application/json'],
            method: 'POST',
        );
        $wire->respond(
            '/v1beta/models/fixture-model:generateContent',
            e2eGeminiEnvelope('{"pages":[{"page_index":0,"estimate_ordinal":1},{"page_index":1,"estimate_ordinal":1}],"ambiguous_pages":[]}'),
            headers: ['Content-Type' => 'application/json'],
            method: 'POST',
        );
        $wire->respond(
            '/v1beta/models/fixture-model:generateContent',
            e2eGeminiEnvelope(e2eExtractionJson(1, new PageRange(0, 2), [[0, 0], [1, 0]])),
            headers: ['Content-Type' => 'application/json'],
            method: 'POST',
        );

        Okpd2Node::query()->firstOrCreate(
            ['platform_id' => $platform->id, 'code' => '41.20'],
            ['name' => 'Здания жилые и нежилые', 'external_id' => '8873944', 'level' => 2],
        );
        $crawler = Crawler::factory()->create([
            'registry_id' => $registry->id,
            'name' => 'ЕИС 44-ФЗ E2E',
            'listing_kind' => AcquisitionKind::Archive,
            'is_active' => true,
            'filter' => new EisSearchFilter(
                publishedFrom: CarbonImmutable::parse('2026-03-01'),
                publishedTo: CarbonImmutable::parse('2026-03-01'),
                okpd2Codes: ['41.20'],
            ),
        ]);

        $launch = app(EisProfilePreparer::class)->prepare($crawler, AcquisitionKind::Archive);
        $runId = app(AcquisitionScheduler::class)->manual($launch, LedgerFixtures::uuid(), null);
        expect($runId->value)->not->toBeEmpty();

        if ($horizon) {
            $disks = config('filesystems.disks');
            foreach ($disks as $name => &$disk) {
                if (($disk['driver'] ?? null) === 'local') {
                    $disk['root'] = Storage::disk($name)->path('');
                }
            }
            unset($disk);
            $config = ['filesystems.disks' => $disks];
            foreach (['process', 'platforms', 'outbound', 'documents', 'ai'] as $key) {
                $config[$key] = config($key);
            }
            file_put_contents($this->workDir.'/pipeline.json', json_encode([
                'config' => $config, 'origins' => [$wire->url('/')->origin->value(), $wire->url('/', true)->origin->value()],
                'card_routes' => ['ea20' => $cardRoutes], 'document_origin' => $allowedOrigin,
                'provider' => base64_encode(serialize(app(AnalysisProviderConnections::class)->for($llmContext))),
            ], JSON_THROW_ON_ERROR));
            $this->pipelineHorizon = new Process([PHP_BINARY, 'tests/Support/ProcessCore/artisan.php', 'horizon'], base_path(), [
                'PROCESS_CORE_PIPELINE_FILE' => $this->workDir.'/pipeline.json',
                'PROCESS_CORE_QUEUE_MODE' => 'redis', 'QUEUE_CONNECTION' => 'redis',
            ]);
            $this->pipelineHorizon->setTimeout(null)->start();
        }

        $relay = app(RelayDaemon::class);
        $projector = app(ProjectorDaemon::class);

        $totalDispatched = 0;
        for ($cycle = 0; $cycle < ($horizon ? 1000 : 20); $cycle++) {
            app(FeederDaemon::class)->tick();
            $relay->tick();
            $dispatched = $horizon ? 0 : e2eDrainRedisQueues();
            $totalDispatched += $dispatched;
            if ($horizon) {
                expect($this->pipelineHorizon->isRunning())->toBeTrue($this->pipelineHorizon->getOutput().$this->pipelineHorizon->getErrorOutput());
                $totalDispatched = DB::table('prc_process_attempts')->count();
            }
            $projector->tick();

            if (Estimate::query()->count() === 1
                && DB::table('prc_process_work')->whereIn('state', ['ready', 'admitted', 'running', 'deferred'])->count() === 0
                && DB::table('prc_process_outbox')->whereNull('published_at')->count() === 0) {
                break;
            }
            if ($horizon) {
                usleep(100_000);
            }
        }

        expect($totalDispatched)->toBeGreaterThanOrEqual(10);

        $procurement = Procurement::query()->where('source_identity_value', $regNumber)->sole();
        expect($procurement->title)->toBe('Капитальный ремонт кровли здания поликлиники')
            ->and($procurement->source_stage)->toBe('Подача заявок');

        $document = DB::table('prc_documents')->where('procurement_id', $procurement->id)->sole();
        expect($document->current_version_id)->not->toBeNull()
            ->and((int) $document->head_generation)->toBeGreaterThanOrEqual(1);

        $version = DB::table('prc_document_versions')->where('id', $document->current_version_id)->sole();
        expect($version->published_analysis_run_id)->not->toBeNull();

        $representation = DB::table('prc_document_representations')
            ->where('document_version_id', $version->id)
            ->where('range_key', 'complete')
            ->sole();
        $coverage = json_decode((string) $representation->coverage_manifest, true, flags: JSON_THROW_ON_ERROR);
        expect($coverage['complete'])->toBeTrue()
            ->and($coverage['source_units'])->toBe(2)
            ->and(array_column($coverage['pages'], 'status'))->toBe(['native_text', 'ocr_text']);

        $analysisRun = DB::table('prc_analysis_runs')->where('id', $version->published_analysis_run_id)->sole();
        expect($analysisRun->publication_status)->toBe('published')
            ->and(DB::table('prc_analysis_windows')->where('run_id', $analysisRun->id)->count())->toBe(1)
            ->and(DB::table('prc_analysis_chunks')->where('run_id', $analysisRun->id)->count())->toBe(1)
            ->and(DB::table('llm_provider_calls')->where('receipt_state', 'verified')->where('outcome', 'success')->count())->toBe(3)
            ->and(DB::table('llm_provider_requests')->where('state', 'completed')->count())->toBe(3)
            ->and(DB::table('llm_budget_reservations')->where('state', 'settled')->count())->toBe(3);

        $estimate = Estimate::query()->where('document_version_id', $version->id)->sole();
        expect($estimate->procurement_id)->toBe($procurement->id)
            ->and(DB::table('prc_estimate_items')->where('estimate_id', $estimate->id)->count())->toBe(2);

        foreach (['listing', 'detail', 'download', 'prepare', 'screening', 'extraction', 'publication'] as $stage) {
            expect(DB::table('prc_process_work')->where('stage', $stage)->where('state', 'succeeded')->count())
                ->toBeGreaterThanOrEqual(1, "Stage {$stage} must have succeeded work");
        }

        // Exercise status_refresh stage through RefreshRepository producer and real Redis queue delivery.
        $refreshShell = '<a class="cardMainInfo__purchaseLink">№ '.$regNumber.'</a>'
            .'<div class="cardMainInfo__purchaseObject">Капитальный ремонт кровли здания поликлиники</div>'
            .'<div class="cardMainInfo__state">Работа комиссии</div>';
        $wire->respond('/eis/card/common-info', $refreshShell.CardHtmlFixture::commonInfo(), headers: ['Content-Type' => 'text/html; charset=utf-8']);

        DB::update("UPDATE prc_procurements SET next_status_due_at = clock_timestamp() - interval '1 second' WHERE id = ?", [$procurement->id]);
        $refreshWork = app(RefreshRepository::class)->scheduleStatus($procurement->id);
        expect($refreshWork)->not->toBeNull();

        app(FeederDaemon::class)->tick();
        $relay->tick();
        if ($horizon) {
            $until = hrtime(true) + 15_000_000_000;
            while (ExecutionFixtures::work($refreshWork)->state !== 'succeeded' && hrtime(true) < $until) {
                usleep(100_000);
            }
        } else {
            expect(e2eDrainRedisQueues())->toBe(1);
        }
        $projector->tick();

        expect(ExecutionFixtures::work($refreshWork)->state)->toBe('succeeded')
            ->and($procurement->fresh()?->source_stage)->toBe('Работа комиссии');

        // Verify metrics projection, CLI status, CLI diagnose, and Pulse card rendering.
        expect(DB::table('prc_process_metric_totals')->count())->toBeGreaterThan(0)
            ->and(DB::table('prc_process_projection_receipts')->count())->toBeGreaterThan(0);

        $snapshot = app(PlatformMetricsReadRepository::class)->read(new PlatformCode('eis'));
        expect($snapshot->platform->value)->toBe('eis')
            ->and($snapshot->counters)->not->toBeEmpty();

        $this->artisan('process:status', ['--platform' => 'eis'])
            ->expectsOutputToContain('work_transition_total v1;platform=eis;stage=listing;state=succeeded')
            ->assertSuccessful();

        $this->artisan('process:diagnose', ['platform' => 'eis', '--dry-run' => true])
            ->assertSuccessful();

        Livewire::withoutLazyLoading()->test(ProcessPlatformHealthCard::class)
            ->assertSee('Площадки: покрытие и процессы')
            ->assertSee('eis');
        if ($horizon) {
            $executions = array_map(fn ($line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
                file($this->workDir.'/horizon-executions.jsonl', FILE_IGNORE_NEW_LINES));
            expect(count($executions))->toBeGreaterThanOrEqual(11)
                ->and(array_unique(array_column($executions, 'pid')))->not->toContain(getmypid());
            foreach (['listing', 'detail', 'download', 'prepare', 'screening', 'extraction', 'publication', 'status_refresh'] as $stage) {
                expect(DB::table('prc_process_work')->whereIn('id', array_column($executions, 'work'))
                    ->where('stage', $stage)->where('state', 'succeeded')->exists())->toBeTrue();
            }
        }
    };
}

test('полный E2E конвейер проходит от планировщика обхода через Redis очереди, HTTP/TLS, OCR и fake Gemini до публикации сметы и проекции метрик', processPipelineE2e(false));
test('все реальные стадии EIS download OCR screening extraction publication и status выполняются дочерними Horizon workers', processPipelineE2e(true));

test('ReconcileDaemon восстанавливает просроченную lease и потерянную доставку Redis без дублирования эффектов', function (): void {
    FinalizeFixtures::authority([StageCode::Listing]);
    app(StageRegistry::class)->bindHandler(StageCode::Listing, new class implements StageHandler
    {
        public function execute(WorkInput $input, ExecutionContext $execution): StageOutcome
        {
            return FinalizeFixtures::succeeded(StageCode::Listing, 'e2e-reconcile-'.$execution->lease()->workId->value->value);
        }
    });

    $work = ExecutionFixtures::listingWork();
    $feeder = app(FeederDaemon::class);
    $relay = app(RelayDaemon::class);
    $reconcile = app(ReconcileDaemon::class);
    foreach (app(RecoveryReconciler::class)->categories() as $category) {
        $reconcile->register($category);
    }

    expect($feeder->tick()->processed)->toBeGreaterThanOrEqual(1)
        ->and($relay->tick()->processed)->toBe(1);

    $route = app(StageQueueMap::class)->forStage(StageCode::Listing);
    $popped = Queue::connection($route->connection)->pop($route->queue);
    expect($popped)->toBeInstanceOf(RedisJob::class);

    // Simulate Redis loss before worker execution: drop the popped job without running it.
    $popped->delete();
    DB::update("UPDATE prc_process_work SET dispatch_claim_until = clock_timestamp() - interval '15 minutes' WHERE id = ?", [$work->value->value]);

    expect($reconcile->tick()->processed)->toBeGreaterThanOrEqual(1)
        ->and($relay->tick()->processed)->toBe(1);

    $requeued = Queue::connection($route->connection)->pop($route->queue);
    expect($requeued)->toBeInstanceOf(RedisJob::class);
    $requeued->delete();

    // Claim lease and then simulate worker crash (expired running lease).
    $lease = FinalizeFixtures::running($work);
    FinalizeFixtures::expire($work, 600);
    expect(app(RecoveryReconciler::class)->recoverExpiredRunning())->toBe(1)
        ->and(ExecutionFixtures::work($work)->state)->toBe('ready');

    // Re-admit via feeder, then verify stale delivery envelope is rejected with stale_delivery.
    expect($feeder->tick()->processed)->toBeGreaterThanOrEqual(1)
        ->and((int) ExecutionFixtures::work($work)->dispatch_generation)->toBeGreaterThan($lease->generation);

    $staleOutcome = app(WorkRunner::class)->runDelivery(new DeliveryEnvelope($work, new DeliveryGeneration($lease->generation), LedgerFixtures::uuid(), DeliveryEnvelope::SCHEMA_VERSION));
    expect($staleOutcome->value)->toBe('rejected');

    // Current delivery envelope after relay re-wake succeeds.
    $relay->tick();
    expect(e2eDrainRedisQueues())->toBeGreaterThanOrEqual(1)
        ->and(ExecutionFixtures::work($work)->state)->toBe('succeeded');
});
