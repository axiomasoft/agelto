<?php

declare(strict_types=1);

use App\Dto\Platforms\Eis\EisCardRoutes;
use App\Dto\Platforms\Eis\EisPolicy;
use App\Dto\Procurement\Acquisition\Detail\DetailFetched;
use App\Dto\Procurement\Acquisition\Detail\DetailProgress;
use App\Dto\Procurement\Acquisition\Listing\ListingFetched;
use App\Dto\Procurement\Network\Outcome\TransportSucceeded;
use App\Dto\Procurement\Process\Inputs\DetailInput;
use App\Dto\Procurement\Process\Outcomes\ArtifactRef;
use App\Dto\Procurement\Process\Outcomes\StageSucceeded;
use App\Dto\Procurement\Process\Outcomes\WorkCheckpointEnvelope;
use App\Dto\Procurement\Process\Primitives\ContentHash;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Dto\Procurement\Process\Primitives\WorkId;
use App\Dto\Procurement\Process\Provider\NoProviderRecovery;
use App\Enums\Procurement\Parsing\ParsingRuleActorType;
use App\Enums\Procurement\Parsing\ParsingRuleSource;
use App\Enums\Procurement\Parsing\ParsingRuleVersionStatus;
use App\Enums\Procurement\Platform\Completeness;
use App\Enums\Procurement\Process\Core\StageCode;
use App\Models\Procurement\Parsing\ParsingRuleSet;
use App\Models\Procurement\Platform\Platform;
use App\Models\Procurement\Procurement;
use App\Repositories\Procurement\Crawler\DetailSnapshotRepository;
use App\Services\Platforms\Eis\Card\EisDetailParser;
use App\Services\Platforms\Eis\Csv\EisSummaryParser;
use App\Services\Platforms\Eis\EisDefaultParsingRules;
use App\Services\Platforms\Eis\EisDefinition;
use App\Services\Platforms\Eis\EisDetailSource;
use App\Services\Platforms\Eis\EisSourceClock;
use App\Services\Procurement\Acquisition\Detail\DetailArtifacts;
use App\Services\Procurement\Acquisition\Detail\DetailHandler;
use App\Services\Procurement\Acquisition\Detail\DetailStageCodec;
use App\Services\Procurement\Acquisition\Detail\DetailValueCodec;
use App\Services\Procurement\Acquisition\Listing\AcquisitionSources;
use App\Services\Procurement\Parsing\Engine\UniversalTabularSummaryMapper;
use App\Services\Procurement\Parsing\Healing\ParsingRuleAutoHealer;
use App\Services\Procurement\Parsing\ParsingRuleManager;
use App\Services\Procurement\Parsing\ParsingRuleSetResolver;
use App\Services\Procurement\Platform\Contracts\DetailPartsSource;
use App\Services\Procurement\Platform\Contracts\ListingSource;
use App\Services\Procurement\Platform\FetchOutcomeBridge;
use App\Services\Procurement\Process\Execution\ExecutionContextFactory;
use App\Services\Procurement\Process\Infrastructure\ProcessPayloadCodec;
use Database\Seeders\Platforms\Eis\EisPlatformSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\Platforms\Contract\ContractRuntime;
use Tests\Support\Platforms\Contract\RecordingOutbound;
use Tests\Support\Platforms\Eis\EisContractManifest;
use Tests\Support\Platforms\Eis\EisDetailFixtures;
use Tests\Support\ProcessCore\ContractFixtures;
use Tests\Support\ProcessCore\DetailFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\ListingFixtures;

use function Pest\Laravel\seed;

pest()->group('eis', 'acquisition-operations');

test('сквозной hot-reload и автолечение правил ЕИС работают без перезапуска и поддерживают откат', function (): void {
    seed(EisPlatformSeeder::class);

    $platform = Platform::query()->where('code', 'eis')->sole();
    $resolver = app(ParsingRuleSetResolver::class);
    $manager = app(ParsingRuleManager::class);
    $healer = app(ParsingRuleAutoHealer::class);
    $resolver->clearCache();

    $listingSet = ParsingRuleSet::query()
        ->where('platform_id', $platform->id)
        ->where('stage', StageCode::Listing->value)
        ->sole();
    $detailSet = ParsingRuleSet::query()
        ->where('platform_id', $platform->id)
        ->where('stage', StageCode::Detail->value)
        ->sole();

    expect($listingSet->active_version_id)->not->toBeNull()
        ->and($detailSet->active_version_id)->not->toBeNull();

    $resolvedListingV1 = $resolver->resolve($platform, StageCode::Listing, 'fz44');
    $resolvedDetailV1 = $resolver->resolve($platform, StageCode::Detail, 'fz44');
    expect($resolvedListingV1)->not->toBeNull()
        ->and($resolvedDetailV1)->not->toBeNull();

    // 1. Мутируем HTML карточки и CSV-листинг: v1 правила перестают находить данные.
    $rawDocumentsHtml = file_get_contents(base_path('tests/Fixtures/Platforms/Eis/Card/documents.html'));
    $rawJournalHtml = file_get_contents(base_path('tests/Fixtures/Platforms/Eis/Card/event-journal.html'));
    $rawCommonHtml = file_get_contents(base_path(EisContractManifest::CARD.'contract-common.html'));
    $rawCsv = file_get_contents(base_path('tests/Fixtures/Platforms/Eis/Csv/neutral-valid.csv'));
    assert(is_string($rawDocumentsHtml) && is_string($rawJournalHtml) && is_string($rawCommonHtml) && is_string($rawCsv));

    $shell = '<a class="cardMainInfo__purchaseLink">№ 0338200009125000001</a>'
        .'<div class="cardMainInfo__purchaseObject">Поставка оргтехники</div>'
        .'<div class="cardMainInfo__state">Определение поставщика завершено</div>';
    $mutatedDocumentsHtml = $shell.str_replace('blockFilesTabDocs', 'newFilesWrap', $rawDocumentsHtml);
    $mutatedJournalHtml = $shell.str_replace('id="event" class="table', 'class="eventsNew table', $rawJournalHtml);
    $mutatedCsv = str_replace('Реестровый номер закупки;', 'Код закупки новый;', $rawCsv);

    $definition = app(EisDefinition::class)->definition();
    $targetId = ContractFixtures::id();

    // Проверяем, что v1 на мутированном CSV даёт неполный результат (Incomplete / SchemaMismatch).
    $v1ListingSource = AcquisitionSources::resolve(
        definition: $definition,
        capability: $definition->listing,
        outbound: new RecordingOutbound([
            new TransportSucceeded(ContractRuntime::response($mutatedCsv, 'text/csv')),
        ]),
        targetId: $targetId,
        ruleSet: $resolvedListingV1,
    );
    expect($v1ListingSource)->toBeInstanceOf(ListingSource::class);

    $listingRequest = EisContractManifest::request();
    $v1ListingResult = $v1ListingSource->fetch($listingRequest, ContractRuntime::context());
    if ($v1ListingResult instanceof ListingFetched) {
        expect($v1ListingResult->page->assessment->completeness)->not->toBe(Completeness::Complete);
    } else {
        expect($v1ListingResult)->not->toBeInstanceOf(ListingFetched::class);
    }

    // Проверяем, что v1 на мутированном HTML документов/журнала не находит документы/события.
    $v1DetailSource = AcquisitionSources::resolve(
        definition: $definition,
        capability: $definition->detail,
        outbound: new RecordingOutbound([
            new TransportSucceeded(ContractRuntime::response($mutatedDocumentsHtml, 'text/html')),
            new TransportSucceeded(ContractRuntime::response($mutatedJournalHtml, 'text/html')),
        ]),
        targetId: $targetId,
        ruleSet: $resolvedDetailV1,
    );
    expect($v1DetailSource)->toBeInstanceOf(DetailPartsSource::class);

    $detailRequest = EisDetailFixtures::request('0338200009125000001');
    $v1DocsResult = $v1DetailSource->fetchPart($detailRequest, 'documents', ContractRuntime::context());
    expect($v1DocsResult)->toBeInstanceOf(DetailFetched::class)
        ->and($v1DocsResult->snapshot->documents)->toBe([]);

    $v1JournalResult = $v1DetailSource->fetchPart($detailRequest, 'event-journal', ContractRuntime::context());
    expect($v1JournalResult)->toBeInstanceOf(DetailFetched::class)
        ->and($v1JournalResult->snapshot->events)->toBe([]);

    // 2. Автолечение для detail на основе мутированного HTML (golden + negative фикстуры).
    $healedVersion = $healer->discoverCandidates(
        ruleSet: $detailSet,
        brokenSample: $mutatedDocumentsHtml,
        part: 'documents',
        regressionFixtures: [
            [
                'name' => 'negative-empty',
                'part' => 'documents',
                'body' => '<html><body><div class="empty-page">Нет данных</div></body></html>',
                'negative' => true,
            ],
        ],
    );
    expect($healedVersion)->not->toBeNull()
        ->and($healedVersion?->version)->toBe(2);

    // Публикуем v2 для listing и v3 для detail (hot-reload без перезапуска).
    $v2ListingRules = $resolvedListingV1->rules->toArray();
    array_unshift($v2ListingRules['columns']['columns'][0]['header_aliases'], 'Код закупки новый');
    $v2Listing = $manager->publish(
        platformId: $platform->id,
        stage: StageCode::Listing,
        rules: $v2ListingRules,
        changeSummary: 'Добавлен новый alias заголовка CSV',
        source: ParsingRuleSource::AutoHeal,
        registryCode: 'fz44',
    );

    $v2DetailRules = $resolvedDetailV1->rules->toArray();
    array_unshift($v2DetailRules['documents']['container_selectors'], 'div.newFilesWrap');
    array_unshift($v2DetailRules['event_journal']['row_selectors'], 'table.eventsNew tbody tr');
    $v2Detail = $manager->publish(
        platformId: $platform->id,
        stage: StageCode::Detail,
        rules: $v2DetailRules,
        changeSummary: 'Добавлены новые CSS-селекторы карточки',
        source: ParsingRuleSource::AutoHeal,
        registryCode: 'fz44',
    );

    expect($v2Listing->version)->toBe(2)
        ->and($v2Detail->version)->toBe(3);

    // 3. Повторный вызов через AcquisitionSources::resolve() сразу использует v2 и возвращает Complete!
    $resolvedListingV2 = $resolver->resolve($platform, StageCode::Listing, 'fz44');
    $resolvedDetailV2 = $resolver->resolve($platform, StageCode::Detail, 'fz44');
    expect($resolvedListingV2?->ruleVersionId)->toBe($v2Listing->id)
        ->and($resolvedDetailV2?->ruleVersionId)->toBe($v2Detail->id);

    $v2ListingSource = AcquisitionSources::resolve(
        definition: $definition,
        capability: $definition->listing,
        outbound: new RecordingOutbound([
            new TransportSucceeded(ContractRuntime::response($mutatedCsv, 'text/csv')),
        ]),
        targetId: $targetId,
        ruleSet: $resolvedListingV2,
    );
    $v2ListingResult = $v2ListingSource->fetch($listingRequest, ContractRuntime::context());
    expect($v2ListingResult)->toBeInstanceOf(ListingFetched::class)
        ->and($v2ListingResult->page->assessment->completeness)->toBe(Completeness::Complete)
        ->and($v2ListingResult->page->summaries->items)->toHaveCount(1);

    $v2DetailSource = AcquisitionSources::resolve(
        definition: $definition,
        capability: $definition->detail,
        outbound: new RecordingOutbound([
            new TransportSucceeded(ContractRuntime::response($mutatedDocumentsHtml, 'text/html')),
            new TransportSucceeded(ContractRuntime::response($mutatedJournalHtml, 'text/html')),
        ]),
        targetId: $targetId,
        ruleSet: $resolvedDetailV2,
    );
    $v2DocsResult = $v2DetailSource->fetchPart($detailRequest, 'documents', ContractRuntime::context());
    expect($v2DocsResult)->toBeInstanceOf(DetailFetched::class)
        ->and($v2DocsResult->snapshot->documents)->not->toBeEmpty();

    $v2JournalResult = $v2DetailSource->fetchPart($detailRequest, 'event-journal', ContractRuntime::context());
    expect($v2JournalResult)->toBeInstanceOf(DetailFetched::class)
        ->and($v2JournalResult->snapshot->events)->not->toBeEmpty();

    // 4. Откат к v1: создаётся v3 (source = rollback) и сразу применяется без перезапуска.
    $v1ListingVersionId = $resolvedListingV1->ruleVersionId;
    $v3Listing = $manager->rollback(
        ruleSetId: $listingSet->id,
        targetVersionId: $v1ListingVersionId,
        actorType: ParsingRuleActorType::User,
        actorId: null,
        reason: 'Откат к исходным правилам v1',
    );
    expect($v3Listing->version)->toBe(3)
        ->and($v3Listing->status)->toBe(ParsingRuleVersionStatus::Active)
        ->and($v3Listing->source)->toBe(ParsingRuleSource::Rollback);

    $resolvedAfterRollback = $resolver->resolve($platform, StageCode::Listing, 'fz44');
    expect($resolvedAfterRollback?->ruleVersionId)->toBe($v3Listing->id);
});

test('DetailHandler сбрасывает DetailProgress при смене rulesHash, а DetailValueCodec читает старый JSON без rulesHash', function (): void {
    $codec = new DetailValueCodec;
    $legacyJson = json_encode([
        'type' => DetailProgress::class,
        'value' => [
            'parts' => ['array' => []],
            'pass' => 2,
            'failures' => 1,
            'consistency' => null,
        ],
    ], JSON_THROW_ON_ERROR);

    $decoded = $codec->decode($legacyJson);
    expect($decoded)->toBeInstanceOf(DetailProgress::class)
        ->and($decoded->pass)->toBe(2)
        ->and($decoded->failures)->toBe(1)
        ->and($decoded->rulesHash)->toBeNull();

    // Проверяем сброс DetailProgress при смене rulesHash в DetailHandler.
    DetailFixtures::bind();
    ListingFixtures::bind();
    $run = ListingFixtures::run(from: '2026-10-31');
    $csv = <<<'CSV'
        Реестровый номер закупки;Наименование закупки;Этап закупки;Дата размещения;Дата обновления;Начальная (максимальная) цена контракта;Способ определения поставщика (подрядчика, исполнителя), подрядной организации (размещения закупки)
        00042;Работы;Подача заявок;31.10.2026;31.10.2026;1000,00;Электронный аукцион
        CSV;
    $page = EisContractManifest::listing()->parse(request: EisContractManifest::request(), response: ContractRuntime::response($csv, 'text/csv'));
    ListingFixtures::finish(work: $run['work'], input: $run['input'], page: $page);
    $procurement = Procurement::query()->sole();

    $common = file_get_contents(base_path(EisContractManifest::CARD.'contract-common.html'));
    $documents = file_get_contents(base_path(EisContractManifest::CARD.'contract-documents.html'));
    assert(is_string($common) && is_string($documents));

    $outbound = new RecordingOutbound([
        new TransportSucceeded(ContractRuntime::response($common, 'text/html')),
        new TransportSucceeded(ContractRuntime::response($documents, 'text/html')),
        new TransportSucceeded(ContractRuntime::response($common, 'text/html')),
    ]);

    $routes = new EisCardRoutes(procedures: ['ea20' => [
        'common-info' => 'https://zakupki.gov.ru/common-info?regNumber={regNumber}',
        'documents' => 'https://zakupki.gov.ru/documents?regNumber={regNumber}',
        'event-journal' => null,
        'supplier-results' => null,
    ]], procedureTypes: ['Электронный аукцион' => 'ea20']);

    $source = new EisDetailSource(
        policy: new EisPolicy(config()),
        routes: $routes,
        parser: app(EisDetailParser::class),
        outbound: $outbound,
        targetId: ContractFixtures::id(),
    );

    $newHash = ContentHash::of('rules-v2')->hex;
    $oldHash = ContentHash::of('rules-v1')->hex;

    $handler = new DetailHandler(
        store: app(DetailSnapshotRepository::class),
        source: $source,
        artifacts: app(DetailArtifacts::class),
        bridge: new FetchOutcomeBridge,
        activeRulesHash: $newHash,
    );

    $staleRef = new ArtifactRef(
        disk: 's3',
        key: 'stale/common-info.json',
        sha256: ContentHash::of('stale'),
    );
    $staleProgress = new DetailProgress(
        parts: ['common-info' => $staleRef],
        pass: 1,
        failures: 1,
        rulesHash: $oldHash,
    );

    $workId = DB::table('prc_process_work')->where('stage', 'detail')->where('procurement_id', $procurement->id)->value('id');
    assert(is_string($workId));
    $work = new WorkId(new ProcessUuid($workId));

    $payloadCodec = new ProcessPayloadCodec([new DetailStageCodec(app(DetailValueCodec::class))]);
    $checkpointPayload = $payloadCodec->encodeCheckpoint(
        new WorkCheckpointEnvelope($staleProgress, new NoProviderRecovery),
    );
    DB::table('prc_process_work')->where('id', $workId)->update([
        'checkpoint' => $checkpointPayload->json,
        'checkpoint_schema_version' => $checkpointPayload->schemaVersion,
        'checkpoint_seq' => 1,
    ]);

    $lease = FinalizeFixtures::running($work);
    $input = DetailFixtures::input($work);
    assert($input instanceof DetailInput);
    $execution = app(ExecutionContextFactory::class)->open($lease);

    $outcome = $handler->execute(input: $input, execution: $execution);

    // Т.к. rulesHash сменился, stale common-info был сброшен и загружены оба (common-info + documents),
    // поэтому StageSucceeded содержит свежий результат, а не старый staleRef.
    expect($outcome)->toBeInstanceOf(StageSucceeded::class);
    expect($outbound->plans)->toHaveCount(3);
});

test('маршруты карточки из активных правил подставляют тип процедуры вместо шаблона type', function (): void {
    seed(EisPlatformSeeder::class);

    $platform = Platform::query()->where('code', 'eis')->sole();
    $resolver = app(ParsingRuleSetResolver::class);
    $resolver->clearCache();

    $resolvedDetail = $resolver->resolve($platform, StageCode::Detail, 'fz44');
    expect($resolvedDetail)->not->toBeNull();

    $definition = app(EisDefinition::class)->definition();
    $source = AcquisitionSources::resolve(
        definition: $definition,
        capability: $definition->detail,
        outbound: new RecordingOutbound([]),
        targetId: ContractFixtures::id(),
        ruleSet: $resolvedDetail,
    );

    expect($source)->toBeInstanceOf(EisDetailSource::class);

    $plans = $source->plans(EisDetailFixtures::request('0338200009125000001'));

    expect($plans['common-info'])->not->toBeNull()
        ->and($plans['documents'])->not->toBeNull()
        ->and($plans['event-journal'])->not->toBeNull();

    foreach (['common-info', 'documents', 'event-journal'] as $tab) {
        $url = $plans[$tab]->url->reveal();
        expect($url)
            ->toContain('/ea20/')
            ->toContain('0338200009125000001')
            ->not->toContain('{type}')
            ->not->toContain('{regNumber}');
    }
});

test('парсер листинга с табличным маппером не помечает отсутствующие в CSV колонки как наблюдённые', function (): void {
    $parser = new EisSummaryParser(
        clock: new EisSourceClock,
        rules: EisDefaultParsingRules::listing(),
        mapper: new UniversalTabularSummaryMapper,
    );

    $summary = $parser->parse([
        'Реестровый номер закупки' => '0338200009125000001',
        'Наименование закупки' => 'Поставка оборудования',
        'Этап закупки' => 'Подача заявок',
        'Дата размещения' => '31.10.2026',
        'Дата обновления' => '31.10.2026',
        'Начальная (максимальная) цена контракта' => '1000,00',
        'Способ определения поставщика (подрядчика, исполнителя), подрядной организации (размещения закупки)' => 'Электронный аукцион',
    ]);

    expect($summary->facts)->not->toBeNull()
        ->and($summary->facts->observes('procedureType'))->toBeTrue()
        ->and($summary->facts->observes('procedureTypeRaw'))->toBeTrue()
        ->and($summary->facts->observes('biddingStartsAt'))->toBeFalse()
        ->and($summary->facts->observes('biddingEndsAt'))->toBeFalse()
        ->and($summary->facts->observes('customerName'))->toBeFalse();
});
