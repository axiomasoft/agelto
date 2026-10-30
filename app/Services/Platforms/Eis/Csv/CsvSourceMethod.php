<?php

declare(strict_types=1);

namespace App\Services\Platforms\Eis\Csv;

use App\Actions\Procurement\Crawler\StoreRawFetchArtifactAction;
use App\Dto\Platforms\Eis\EisListingQueryOverrides;
use App\Dto\Procurement\Crawler\ListingBatch;
use App\Dto\Procurement\Crawler\ListingCursor;
use App\Dto\Procurement\Crawler\ListingExecutionContext;
use App\Dto\Procurement\Crawler\ListingMapping;
use App\Dto\Procurement\Crawler\SkippedListingRow;
use App\Dto\Procurement\Network\AdapterInspection;
use App\Dto\Procurement\Network\AdapterValidationCall;
use App\Dto\Procurement\Network\HttpRequestPayload;
use App\Dto\Procurement\Network\OutboundExecutionContext;
use App\Dto\Procurement\Network\SafeUrl;
use App\Dto\Procurement\Network\TransportDeferred;
use App\Dto\Procurement\Platform\CanonicalOrigin;
use App\Dto\Procurement\Platform\RequestProfile;
use App\Dto\Procurement\Platform\SourceTargetDefinition;
use App\Dto\Procurement\Platform\SourceTargetDefinitions;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\RetryAt;
use App\Dto\Procurement\ProcurementDetails;
use App\Enums\Procurement\Crawler\CrawlerMode;
use App\Enums\Procurement\Crawler\CrawlStage;
use App\Enums\Procurement\Network\AdapterVerdict;
use App\Enums\Procurement\Network\NetworkDeferralReason;
use App\Enums\Procurement\Network\ResponseKind;
use App\Enums\Procurement\Network\TrafficClass;
use App\Enums\Procurement\ProcurementStatus;
use App\Exceptions\Procurement\Crawler\InvalidListingResponse;
use App\Exceptions\Procurement\Crawler\OutboundRequestDeadlineExceeded;
use App\Exceptions\Procurement\Network\OutboundUnavailable;
use App\Exceptions\Procurement\Process\DependencyUnavailable;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Platform\Platform;
use App\Models\Procurement\Platform\Registry;
use App\Models\Procurement\Procurement;
use App\Services\Platforms\Eis\Card\EisCardUrlBuilder;
use App\Services\Procurement\Contracts\CooperativeListingSourceMethod;
use App\Services\Procurement\Contracts\DeadlineAwareSourceMethod;
use App\Services\Procurement\Contracts\DiagnosticSourceMethod;
use App\Services\Procurement\Network\AdapterInputInspector;
use App\Services\Procurement\Network\Contracts\LegacyOutbound;
use App\Services\Procurement\Network\Contracts\SourceCatalogProvider;
use App\Services\Procurement\PlatformAdapterRegistry;
use App\Services\Procurement\Proxy\ProxyBindingResolver;
use App\Services\Procurement\Proxy\RoutePolicy;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;

/**
 * Основной метод забора данных ЕИС — CSV-экспорт реестра закупок
 * (`02-crawling.md` §3). Кодировка источника — Windows-1251, конвертация
 * в UTF-8 обязательна до парсинга; иначе закупки с кириллицей тихо теряются.
 */
final readonly class CsvSourceMethod implements CooperativeListingSourceMethod, DeadlineAwareSourceMethod, DiagnosticSourceMethod, SourceCatalogProvider
{
    /**
     * Заголовок колонки «Реестровый номер закупки» в CSV-экспорте ЕИС
     * (`research/eis-csv-recon.md`, флаг `registryNumberCsv`) — источник `dedupSignature`.
     */
    private const string REGISTRY_NUMBER_COLUMN = 'Реестровый номер закупки';

    /**
     * Вкладка карточки, доступная только у закупок на завершающих этапах
     * (`02-crawling.md` §5.1) — забор для незавершённых создаёт лишнюю нагрузку на источник.
     */
    private const string SUPPLIER_RESULTS_TAB = 'supplier-results';

    /**
     * Канонические статусы, на которых карточка считается завершающейся
     * (`02-crawling.md` §5, Implementation Rules P3.1).
     *
     * @var list<ProcurementStatus>
     */
    private const array FINALIZING_STATUSES = [
        ProcurementStatus::Evaluation,
        ProcurementStatus::Awarded,
        ProcurementStatus::Completed,
    ];

    public function __construct(
        private EisCsvUrlBuilder $urlBuilder,
        private EisCardUrlBuilder $cardUrlBuilder,
        private LegacyOutbound $outbound,
        private ProxyBindingResolver $proxyBindingResolver,
        private StoreRawFetchArtifactAction $rawArtifacts,
    ) {}

    /** Config-only network catalog; the full platform Definition remains owned by P2.9–P2.12. */
    public function sourceTargets(): SourceTargetDefinitions
    {
        $csv = CanonicalOrigin::fromUrl(config()->string('crawler.eis.csv_endpoint'));
        $targets = [
            new SourceTargetDefinition('csv', 'listing', $csv, [
                new RequestProfile('eis.csv_export', 5, 30, 90, 16_777_216, 50_331_648, 3, 3, 2, [200, 600], ResponseKind::Text, 'Windows-1251', 1),
            ], adapterContract: app(PlatformAdapterRegistry::class)->adapterContract('eis', 'listing')),
            new SourceTargetDefinition('card', 'detail', $csv, [
                new RequestProfile('eis.card_tab', 5, 15, 30, 8_388_608, 25_165_824, 3, 3, 2, [200, 600], ResponseKind::Text, 'Windows-1251', 1),
            ], adapterContract: app(PlatformAdapterRegistry::class)->adapterContract('eis', 'detail')),
            new SourceTargetDefinition('okpd2', 'reference_sync',
                CanonicalOrigin::fromUrl(config()->string('crawler.okpd2.children_endpoint')), [
                    new RequestProfile('eis.reference', 5, 10, 20, 2_097_152, 6_291_456, 3, 2, 2, [200], ResponseKind::Text, 'UTF-8', 1),
                ]),
        ];
        $hosts = config('crawler.documents_allowed_hosts');
        if (! is_array($hosts) || $hosts === []) {
            throw new \InvalidArgumentException('Document source origins are not configured.');
        }
        foreach ($hosts as $host) {
            if (! is_string($host) || $host === '') {
                throw new \InvalidArgumentException('Document source host is invalid.');
            }
            $origin = CanonicalOrigin::of('https', $host);
            $targets[] = new SourceTargetDefinition('document.'.substr($origin->hash()->hex, 0, 16), 'download', $origin, [
                new RequestProfile('eis.document', 5, 30, 240, 268_435_456, null, 3, 3, 2, [200, 600], ResponseKind::Binary, null, 1),
            ]);
        }

        return new SourceTargetDefinitions('eis', $targets);
    }

    public function diagnoseListing(Crawler $crawler, ListingCursor $cursor, float $deadlineAt): ListingBatch
    {
        return $this->collectListingInternal($crawler, $cursor, null, null, $deadlineAt, true);
    }

    public function diagnoseDetails(string $registryNumber, Registry $registry, float $deadlineAt): ProcurementDetails
    {
        return $this->collectDetailsInternal($registryNumber, $registry, $deadlineAt, true);
    }

    public function collectListingWithinDeadline(Crawler $crawler, ListingCursor $cursor, float $deadlineAt): ListingBatch
    {
        return $this->collectListing(crawler: $crawler, cursor: $cursor, deadlineAt: $deadlineAt);
    }

    public function collectListing(
        Crawler $crawler,
        ListingCursor $cursor,
        ?EisListingQueryOverrides $queryOverrides = null,
        ?float $deadlineAt = null,
    ): ListingBatch {
        return $this->collectListingInternal($crawler, $cursor, $queryOverrides, null, $deadlineAt);
    }

    public function collectListingForRun(Crawler $crawler, ListingCursor $cursor, ListingExecutionContext $context): ListingBatch
    {
        return $this->collectListingInternal($crawler, $cursor, null, $context, $context->deadlineAt);
    }

    private function collectListingInternal(Crawler $crawler, ListingCursor $cursor, ?EisListingQueryOverrides $queryOverrides, ?ListingExecutionContext $context, ?float $deadlineAt, bool $diagnostic = false): ListingBatch
    {
        ($context?->checkpoint)?->__invoke();
        $blockSize = app(PlatformAdapterRegistry::class)->blockSize(registry: $crawler->registry()->firstOrFail());
        $from = $cursor->blockFrom;
        $to = $from + $blockSize - 1;

        $endpoint = $this->stringConfig('crawler.eis.csv_endpoint');
        $queryParams = $this->urlBuilder->buildQueryParams(
            crawler: $crawler,
            from: $from,
            to: $to,
            cursor: $cursor,
            overrides: $queryOverrides,
        );

        $lease = $this->listingLease($crawler);
        $observedAt = now();
        $startedAt = hrtime(true);

        $platform = $this->platformForId($crawler->registry()->firstOrFail()->platform_id);
        $traffic = $diagnostic || $queryOverrides !== null ? TrafficClass::Diagnostic
            : ($crawler->mode === CrawlerMode::Archive ? TrafficClass::ArchiveListing : TrafficClass::FreshListing);
        $call = new AdapterValidationCall('legacy.csv_listing', $platform->id,
            app(PlatformAdapterRegistry::class)->adapterContract($this->platformCode($platform), 'listing'),
            new ContentHash(hash('sha256', json_encode([$crawler->registry_id, $queryParams], JSON_THROW_ON_ERROR))),
            new SafeUrl($endpoint), $traffic, $lease,
            $diagnostic || $queryOverrides !== null ? ['system', 'monitoring'] : ['system', 'acquisition', 'acquisition:platform:'.$this->platformCode($platform)],
            $this->deadlineSeconds($deadlineAt, 240));
        $validated = $this->outbound->validated($call,
            function (OutboundExecutionContext $networkContext) use ($lease, $endpoint, $queryParams,
                $crawler, $deadlineAt, $context, $queryOverrides, $observedAt, $startedAt, $from, $traffic): AdapterInspection {
                try {
                    $request = $this->request(
                        lease: $lease,
                        endpoint: $endpoint,
                        queryParams: $queryParams,
                        stage: CrawlStage::Listing,
                        crawler: $crawler,
                        deadlineAt: $deadlineAt,
                        execution: $context,
                        sendLimit: $queryOverrides?->requestAttempts,
                        outboundContext: $networkContext,
                        trafficClass: $traffic,
                    );
                } catch (OutboundUnavailable $failure) {
                    if ($context !== null && $failure->outcome instanceof TransportDeferred
                        && $failure->outcome->finalResponse !== null) {
                        $evidence = $failure->outcome->finalResponse;
                        $this->retainListingHttpFailure($crawler, $context, $evidence->requestId, $evidence->status, $evidence->body->utf8);
                    }
                    throw $failure;
                }
                $response = $request['response'];
                $waitMs = $request['wait_ms'];

                $durationMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);
                $utf8Body = $response->body();
                if (! $response->successful()) {
                    $ids = $networkContext->successfulRequestIds();
                    $lastId = $ids === [] ? null : $ids[array_key_last($ids)];
                    if ($context !== null && $lastId !== null) {
                        $this->retainListingHttpFailure($crawler, $context, $lastId, $response->status(), $utf8Body);
                    }
                    throw new OutboundUnavailable(new TransportDeferred(
                        NetworkDeferralReason::DependencyUnavailable,
                        new RetryAt(new \DateTimeImmutable('+30 seconds')), lastRequestId: $lastId));
                }
                $reason = match (true) {
                    trim($utf8Body) === '' => 'empty_body',
                    str_contains(strtolower($response->header('Content-Type')), 'text/html') => 'html_response',
                    default => null,
                };
                $parsed = ['rows' => [], 'records' => 0, 'malformed' => 0, 'header' => null];
                if ($reason === null) {
                    try {
                        $parsed = $this->parseCsv(body: $utf8Body, deadlineAt: $deadlineAt);
                    } catch (\UnexpectedValueException) {
                        $reason = 'invalid_csv';
                    }
                }
                ['rows' => $rows, 'records' => $records, 'malformed' => $malformed, 'header' => $header] = $parsed;
                if ($reason === null && ($header === null || array_diff(['Реестровый номер закупки', 'Дата размещения', 'Дата обновления'], $header) !== [])) {
                    $reason = 'invalid_csv_header';
                }
                if ($malformed > 0) {
                    Log::warning(message: 'CsvSourceMethod: CSV-записи листинга не совпали с заголовком по числу полей.', context: [
                        'crawler_id' => $crawler->id, 'block_from' => $from, 'records' => $records, 'malformed' => $malformed,
                    ]);
                }

                $snapshots = [];
                $intentionalSkips = $unmapped = $errors = 0;
                $handlerFault = null;
                if ($reason === null) {
                    $mapper = app(PlatformAdapterRegistry::class)->listingMapper($crawler->registry()->firstOrFail());
                    foreach ($rows as $row) {
                        $networkContext->tick();
                        try {
                            $snapshot = $mapper->map($row, $crawler);
                            if ($snapshot instanceof SkippedListingRow) {
                                $intentionalSkips++;
                            } elseif ($snapshot === null) {
                                $unmapped++;
                            } else {
                                $snapshots[] = $snapshot;
                            }
                        } catch (\UnexpectedValueException) {
                            $errors++;
                        } catch (\Throwable $failure) {
                            if ($failure instanceof QueryException
                                || $failure instanceof DependencyUnavailable) {
                                throw OutboundUnavailable::dependency();
                            }
                            $handlerFault = $failure;
                            break;
                        }
                    }
                }
                if ($reason === null && ($malformed + $unmapped + $errors) > 0) {
                    $reason = 'invalid_csv_row';
                }

                $batch = new ListingBatch(
                    observedAt: $observedAt,
                    mapping: new ListingMapping($snapshots, $intentionalSkips, $unmapped, $errors),
                    rows: $rows,
                    rawBody: $utf8Body,
                    dedupSignature: $rows[0][self::REGISTRY_NUMBER_COLUMN] ?? null,
                    rowsTotal: $records,
                    httpStatus: $response->status(),
                    durationMs: $durationMs,
                    requestParams: $queryParams,
                    proxyId: null,
                    proxySource: null,
                    waitMs: $waitMs,
                    rowsMalformed: $malformed,
                    header: $header,
                    // У ЕИС даже пустая выдача содержит строку заголовка; тело без единого символа —
                    // аномалия (`CRAWLER_CSV_EMPTY`), а не конец данных.
                    emptyBody: trim($utf8Body) === '',
                );

                if ($handlerFault !== null) {
                    return new AdapterInspection($handlerFault,
                        AdapterVerdict::HandlerFault, 'listing.mapper_exception');
                }

                $partial = $reason === 'invalid_csv_row';

                return new AdapterInspection(
                    $reason === null || $partial ? $batch : new InvalidListingResponse(batch: $batch, reason: $reason),
                    $reason === null ? AdapterVerdict::Accepted : ($partial ? AdapterVerdict::Partial : AdapterVerdict::ContractFailure),
                    $reason === null ? 'listing.full_contract' : 'listing.'.$reason,
                    $reason === null || $partial ? null : new ContentHash(hash('sha256', 'listing.'.$reason)));
            }, $context?->checkpoint);
        if ($validated->value instanceof \Throwable) {
            throw $validated->value;
        }

        $batch = $validated->value;

        return $batch->withValidation($validated->receipt);
    }

    /** HTTP dependency evidence is retained outside domain transactions, never parsed as a listing. */
    private function retainListingHttpFailure(Crawler $crawler, ListingExecutionContext $context,
        ProcessUuid $requestId, int $status, string $body): void
    {
        $disk = config()->string('crawler.raw_disk');
        $this->rawArtifacts->execute($disk, "raw/csv/{$crawler->id}/{$context->run->id}", [
            'crawler_id' => $crawler->id, 'run_id' => $context->run->id,
            'physical_request_id' => $requestId->value, 'http_status' => $status,
            'representation' => 'decoded_utf8',
        ], $body, 'csv');
    }

    /**
     * Profile передаёт точные привязки и страну, а `default`/`direct` остаются typed требованиями
     * единственного `ProxyResolver`. Поэтому direct-лиза и наблюдаемый source создаются только там,
     * а configured source никогда не становится неявным fallback.
     */
    private function listingLease(Crawler $crawler): RoutePolicy
    {
        return $this->proxyBindingResolver->forCrawler($crawler);
    }

    public function collectDetails(string $registryNumber, Registry $registry): ProcurementDetails
    {
        return $this->collectDetailsInternal(registryNumber: $registryNumber, registry: $registry, deadlineAt: null);
    }

    public function collectDetailsWithinDeadline(string $registryNumber, Registry $registry, float $deadlineAt): ProcurementDetails
    {
        return $this->collectDetailsInternal(registryNumber: $registryNumber, registry: $registry, deadlineAt: $deadlineAt);
    }

    private function collectDetailsInternal(string $registryNumber, Registry $registry, ?float $deadlineAt, bool $diagnostic = false): ProcurementDetails
    {
        $procurement = Procurement::query()
            ->where('registry_id', $registry->id)
            ->where('registry_number', $registryNumber)
            ->firstOrFail();

        $procedureUrlType = $this->cardUrlBuilder->resolveProcedureUrlType(
            registry: $registry,
            procedureTypeRaw: $procurement->procedure_type_raw,
        );

        $inspector = app(AdapterInputInspector::class);
        $baseline = $inspector->prepare($registry);
        $platform = $this->platformForId($registry->platform_id);
        $first = $this->cardUrlBuilder->buildUrl($registry, $procedureUrlType, 'common-info', $registryNumber)
            ?? throw new \LogicException('Detail adapter must declare a common-info URL.');
        $call = new AdapterValidationCall('legacy.card_details', $platform->id,
            app(PlatformAdapterRegistry::class)->adapterContract($this->platformCode($platform), 'detail'),
            new ContentHash(hash('sha256', json_encode([$registry->id, $registryNumber], JSON_THROW_ON_ERROR))),
            new SafeUrl($first), $diagnostic ? TrafficClass::Diagnostic : TrafficClass::Detail, $this->detailLease($procurement),
            $diagnostic ? ['system', 'monitoring'] : ['system', 'acquisition', 'acquisition:platform:'.$this->platformCode($platform)], $this->deadlineSeconds($deadlineAt, 300));
        $validated = $this->outbound->validated($call,
            function (OutboundExecutionContext $networkContext) use ($procurement, $registry,
                $procedureUrlType, $registryNumber, $deadlineAt, $diagnostic, $inspector, $baseline): AdapterInspection {
                $html = [];

                foreach ($this->stringList(config('crawler.eis.card_tabs', []), 'crawler.eis.card_tabs') as $tab) {
                    if ($tab === self::SUPPLIER_RESULTS_TAB && ! in_array($procurement->status, self::FINALIZING_STATUSES, strict: true)) {
                        $html[$tab] = null;

                        continue;
                    }

                    $url = $this->cardUrlBuilder->buildUrl(
                        registry: $registry,
                        procedureUrlType: $procedureUrlType,
                        tab: $tab,
                        registryNumber: $registryNumber,
                    );

                    if ($url === null) {
                        $html[$tab] = null;

                        continue;
                    }

                    $html[$tab] = $this->collectTab(
                        procurement: $procurement,
                        registry: $registry,
                        tab: $tab,
                        registryNumber: $registryNumber,
                        url: $url,
                        deadlineAt: $deadlineAt,
                        outboundContext: $networkContext,
                        traffic: $diagnostic ? TrafficClass::Diagnostic : TrafficClass::Detail,
                    );
                }

                $details = new ProcurementDetails(
                    registryNumber: $registryNumber,
                    procedureUrlType: $procedureUrlType,
                    commonInfoHtml: $html['common-info'] ?? null,
                    documentsHtml: $html['documents'] ?? null,
                    eventJournalHtml: $html['event-journal'] ?? null,
                    supplierResultsHtml: $html[self::SUPPLIER_RESULTS_TAB] ?? null,
                );

                return $inspector->details($details, $registry, $networkContext, $baseline);

            });
        if ($validated->value instanceof \Throwable) {
            throw $validated->value;
        }
        $details = $validated->value;

        return $details->withValidation($validated->receipt);
    }

    public function isAvailable(): bool
    {
        return true;
    }

    private function collectTab(Procurement $procurement, Registry $registry, string $tab, string $registryNumber, string $url, ?float $deadlineAt, OutboundExecutionContext $outboundContext, TrafficClass $traffic): string
    {
        $lease = $this->detailLease(procurement: $procurement);
        $request = $this->request(
            lease: $lease,
            endpoint: $url,
            queryParams: [],
            stage: CrawlStage::Detail,
            deadlineAt: $deadlineAt,
            platformId: $registry->platform_id,
            outboundContext: $outboundContext,
            trafficClass: $traffic,
        );
        $response = $request['response'];

        if (! $response->successful()) {
            throw OutboundUnavailable::dependency();
        }
        // Identity/layout are inspected once across the complete card before acceptance.
        if (! str_contains($response->body(), $registryNumber)) {
            // Retain the response until the full validator emits a contract rejection.
            return $response->body();
        }

        $html = $response->body();
        // The transport already decoded UTF-8. Keep a legacy HTML parser from decoding the
        // normalized text again according to the source's old meta charset declaration.
        $prefix = substr($html, 0, 4096);
        $normalized = preg_replace_callback('/(<meta\b[^>]*?charset\s*=\s*[\x22\x27]?)([a-zA-Z0-9_.:-]+)/i',
            static fn (array $match): string => in_array(strtolower($match[2]), ['utf-8', 'utf8'], true) ? $match[0] : $match[1].'UTF-8', $prefix);
        if ($normalized === null) {
            throw new \LogicException('Cannot normalize HTML encoding metadata.');
        }
        $html = $normalized.substr($html, strlen($prefix));
        $artifactRef = $traffic !== TrafficClass::Diagnostic && (bool) config('crawler.raw_html_retention_enabled', false)
            ? $this->storeRawHtml(registryId: $registry->id, registryNumber: $registryNumber, tab: $tab, url: $url, html: $html)
            : null;

        return $html;
    }

    private function detailLease(Procurement $procurement): RoutePolicy
    {
        return $this->proxyBindingResolver->forProcurement($procurement->id);
    }

    private function storeRawHtml(int $registryId, string $registryNumber, string $tab, string $url, string $html): string
    {
        $disk = $this->stringConfig('crawler.raw_html_disk', $this->stringConfig('crawler.raw_disk'));
        $contents = (bool) config('crawler.raw_html_gzip', true) ? gzencode($html) : $html;

        if ($contents === false) {
            throw new \RuntimeException('Не удалось gzip-сжать raw HTML.');
        }

        return $this->rawArtifacts->execute(
            disk: $disk,
            prefix: "raw/html/{$registryId}/{$registryNumber}/{$tab}",
            requestIdentity: [
                'registry_id' => $registryId,
                'registry_number' => $registryNumber,
                'tab' => $tab,
                'url' => $url,
            ],
            contents: $contents,
            extension: (bool) config('crawler.raw_html_gzip', true) ? 'html.gz' : 'html',
        );
    }

    /**
     * @param  array<string,string>  $queryParams
     * @return array{response: Response, wait_ms: int}
     */
    private function request(
        RoutePolicy $lease,
        string $endpoint,
        array $queryParams,
        CrawlStage $stage,
        ?Crawler $crawler = null,
        ?float $deadlineAt = null,
        ?ListingExecutionContext $execution = null,
        ?int $platformId = null,
        ?int $sendLimit = null,
        ?OutboundExecutionContext $outboundContext = null,
        ?TrafficClass $trafficClass = null,
    ): array {
        $platform = $this->platformForId($platformId ?? $crawler?->registry?->platform_id);
        $url = $endpoint.($queryParams === [] ? '' : (str_contains($endpoint, '?') ? '&' : '?').http_build_query($queryParams));
        $deadlineAt ??= microtime(true) + ($stage === CrawlStage::Listing ? 240 : 90);
        if ($deadlineAt <= microtime(true)) {
            throw OutboundRequestDeadlineExceeded::forStage($stage->value);
        }
        $platformCode = $platform->getAttribute('code');
        if (! is_string($platformCode)) {
            throw new \LogicException('Platform code is unavailable.');
        }
        $response = $this->outbound->request(
            callerCode: $stage === CrawlStage::Listing ? 'legacy.csv_listing' : 'legacy.card_tab',
            platformId: $platform->id, providerKey: null, capability: $stage->value,
            url: new SafeUrl($url),
            trafficClass: $trafficClass ?? ($stage === CrawlStage::Detail ? TrafficClass::Detail
                : ($crawler?->mode === CrawlerMode::Archive ? TrafficClass::ArchiveListing : TrafficClass::FreshListing)),
            routePolicy: $lease, controls: $trafficClass === TrafficClass::Diagnostic ? ['system', 'monitoring'] : ['system', 'acquisition', 'acquisition:platform:'.$platformCode],
            deadlineSeconds: min(420, max(1, (int) floor($deadlineAt - microtime(true)))),
            payload: new HttpRequestPayload(['user-agent' => config()->string('outbound.http.user_agent')]),
            checkpoint: $execution?->checkpoint, sendLimit: $sendLimit, context: $outboundContext,
        );

        return ['response' => $response, 'wait_ms' => 0];
    }

    private function deadlineSeconds(?float $deadlineAt, int $default): int
    {
        $remaining = $deadlineAt === null ? $default : (int) floor($deadlineAt - microtime(true));
        if ($remaining < 1) {
            throw OutboundRequestDeadlineExceeded::forStage('adapter_validation');
        }

        return min(420, $remaining);
    }

    /**
     * RFC 4180-разбор потоком (`fgetcsv`), а не построчно: поле в кавычках может содержать перевод
     * строки (длинные наименования закупок), и предварительный split по `\n` рвал такую запись на
     * два «битых» фрагмента, которые молча отбрасывались. `escape: ''` отключает нестандартный
     * backslash-эскейп PHP: `\"` в конце поля иначе склеивал соседние поля.
     *
     * `records` — все непустые записи данных (включая битые), `malformed` — записи, у которых
     * число полей не совпало с заголовком.
     *
     * `header` — первая запись как есть (для проверки обязательных колонок), `null` — тело пустое.
     *
     * @return array{rows: list<array<string, string>>, records: int, malformed: int, header: list<string>|null}
     */
    private function parseCsv(string $body, ?float $deadlineAt = null): array
    {
        return (new EisCsvParser)->parseBatch(
            body: $body,
            deadlineAt: $deadlineAt,
            maxRecords: $this->intConfig('crawler.csv_max_records', 1000),
            maxFieldBytes: $this->intConfig('crawler.csv_max_field_bytes', 262_144),
        );
    }

    private function intConfig(string $key, int $default): int
    {
        $value = config($key, $default);

        if (! is_int($value)) {
            throw new \LogicException("{$key} должен быть целым числом.");
        }

        return $value;
    }

    private function stringConfig(string $key, ?string $default = null): string
    {
        $value = config($key, $default);

        if (! is_string($value)) {
            throw new \LogicException("{$key} должен быть строкой.");
        }

        return $value;
    }

    private function platformCode(Platform $platform): string
    {
        $code = $platform->getAttribute('code');
        if (! is_string($code) || $code === '') {
            throw new \LogicException('Platform code is unavailable.');
        }

        return $code;
    }

    private function platformForId(mixed $platformId): Platform
    {
        if (! is_int($platformId)) {
            throw new \LogicException('platform_id должен быть целым числом.');
        }

        return Platform::query()->whereKey($platformId)->firstOrFail();
    }

    /** @return list<string> */
    private function stringList(mixed $value, string $context): array
    {
        if (! is_array($value)) {
            throw new \LogicException("{$context} должен быть списком строк.");
        }

        $strings = [];

        foreach ($value as $entry) {
            if (! is_string($entry)) {
                throw new \LogicException("{$context} должен быть списком строк.");
            }

            $strings[] = $entry;
        }

        return $strings;
    }
}
