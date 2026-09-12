<?php

declare(strict_types=1);

namespace App\Services\Procurement\Eis;

use App\Actions\Procurement\Crawler\StoreRawFetchArtifactAction;
use App\Dto\Procurement\Crawler\EisListingQueryOverrides;
use App\Dto\Procurement\Crawler\ListingBatch;
use App\Dto\Procurement\Crawler\ListingCursor;
use App\Dto\Procurement\Crawler\StageSettings;
use App\Dto\Procurement\ProcurementDetails;
use App\Dto\Procurement\Proxy\ProxyLease;
use App\Dto\Procurement\Proxy\ProxyOutcome;
use App\Dto\Procurement\Proxy\ProxyRequirements;
use App\Enums\Procurement\Crawler\CrawlStage;
use App\Enums\Procurement\ProcurementStatus;
use App\Enums\Procurement\Proxy\ProxyFailureReason;
use App\Enums\Procurement\Proxy\ProxyScope;
use App\Exceptions\Procurement\Crawler\OutboundRequestDeadlineExceeded;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Platform\Platform;
use App\Models\Procurement\Platform\Registry;
use App\Models\Procurement\Procurement;
use App\Services\Procurement\Contracts\DeadlineAwareSourceMethod;
use App\Services\Procurement\CrawlSettingsResolver;
use App\Services\Procurement\Exceptions\CardPageMismatchException;
use App\Services\Procurement\FetchLogger;
use App\Services\Procurement\PlatformRequestBudget;
use App\Services\Procurement\Proxy\ProxyBindingResolver;
use App\Services\Procurement\Proxy\ProxyResolver;
use App\Services\Procurement\SourceTlsTrust;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/**
 * Основной метод забора данных ЕИС — CSV-экспорт реестра закупок
 * (`02-crawling.md` §3). Кодировка источника — Windows-1251, конвертация
 * в UTF-8 обязательна до парсинга; иначе закупки с кириллицей тихо теряются.
 */
final readonly class CsvSourceMethod implements DeadlineAwareSourceMethod
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
        private ProxyResolver $proxyResolver,
        private ProxyBindingResolver $proxyBindingResolver,
        private PlatformRequestBudget $requestBudget,
        private CrawlSettingsResolver $crawlSettings,
        private SourceTlsTrust $tlsTrust,
        private FetchLogger $fetches,
        private StoreRawFetchArtifactAction $rawArtifacts,
    ) {}

    public function collectListing(
        Crawler $crawler,
        ListingCursor $cursor,
        ?EisListingQueryOverrides $queryOverrides = null,
    ): ListingBatch {
        $blockSize = $this->intConfig('crawler.eis.block_size', 500);
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

        $host = $this->canonicalHost($endpoint);
        $lease = $this->listingLease($crawler, $host);
        $settings = $this->crawlSettings->forStage(
            stage: CrawlStage::Listing,
            platform: $this->platformForId($crawler->registry()->firstOrFail()->getAttribute('platform_id')),
            crawler: $crawler,
        );
        $settings = $this->withRequestAttempts(settings: $settings, attempts: $queryOverrides?->requestAttempts);

        $startedAt = hrtime(true);

        $request = $this->requestWithRetries(
            lease: $lease,
            endpoint: $endpoint,
            queryParams: $queryParams,
            settings: $settings,
            stage: CrawlStage::Listing,
            host: $host,
            crawler: $crawler,
        );
        $response = $request['response'];
        $waitMs = $request['wait_ms'];

        if (($queryOverrides === null || $queryOverrides->retryOnEmptyResponse) && $response->status() === 200 && trim($response->body()) === '') {
            $request = $this->requestWithRetries(
                lease: $lease,
                endpoint: $endpoint,
                queryParams: $queryParams,
                settings: $settings,
                stage: CrawlStage::Listing,
                host: $host,
                crawler: $crawler,
            );
            $response = $request['response'];
            $waitMs += $request['wait_ms'];
        }

        $durationMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);

        $utf8Body = mb_convert_encoding($response->body(), 'UTF-8', 'Windows-1251');
        $rows = $this->parseCsv(body: $utf8Body);

        return new ListingBatch(
            rows: $rows,
            rawBody: $utf8Body,
            dedupSignature: $rows[0][self::REGISTRY_NUMBER_COLUMN] ?? null,
            rowsTotal: count($rows),
            httpStatus: $response->status(),
            durationMs: $durationMs,
            requestParams: $queryParams,
            proxyId: $lease->proxyId,
            proxySource: $lease->source,
            waitMs: $waitMs,
        );
    }

    /**
     * Profile передаёт точные привязки и страну, а `default`/`direct` остаются typed требованиями
     * единственного `ProxyResolver`. Поэтому direct-лиза и наблюдаемый source создаются только там,
     * а configured source никогда не становится неявным fallback.
     */
    private function listingLease(Crawler $crawler, string $host): ProxyLease
    {
        return $this->proxyResolver->resolve(
            scope: ProxyScope::CrawlListing,
            target: $host,
            requirements: new ProxyRequirements(
                country: $crawler->proxy_country,
                proxyIds: $this->proxyBindingResolver->forCrawler($crawler),
                crawlerMode: $crawler->proxy_mode,
            ),
        );
    }

    private function withRequestAttempts(StageSettings $settings, ?int $attempts): StageSettings
    {
        if ($attempts === null) {
            return $settings;
        }

        return new StageSettings(
            minPauseMs: $settings->minPauseMs,
            maxPauseMs: $settings->maxPauseMs,
            retries: $attempts,
            retryDelayMs: $settings->retryDelayMs,
            timeoutSeconds: $settings->timeoutSeconds,
            dispatchBatchLimit: $settings->dispatchBatchLimit,
        );
    }

    public function collectDetails(string $registryNumber, Registry $registry): ProcurementDetails
    {
        return $this->collectDetailsInternal(registryNumber: $registryNumber, registry: $registry, deadlineAt: null);
    }

    public function collectDetailsWithinDeadline(string $registryNumber, Registry $registry, float $deadlineAt): ProcurementDetails
    {
        return $this->collectDetailsInternal(registryNumber: $registryNumber, registry: $registry, deadlineAt: $deadlineAt);
    }

    private function collectDetailsInternal(string $registryNumber, Registry $registry, ?float $deadlineAt): ProcurementDetails
    {
        $procurement = Procurement::query()
            ->where('registry_id', $registry->id)
            ->where('registry_number', $registryNumber)
            ->firstOrFail();

        $procedureUrlType = $this->cardUrlBuilder->resolveProcedureUrlType(
            registry: $registry,
            procedureTypeRaw: $procurement->procedure_type_raw,
        );

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
            );
        }

        return new ProcurementDetails(
            registryNumber: $registryNumber,
            procedureUrlType: $procedureUrlType,
            commonInfoHtml: $html['common-info'] ?? null,
            documentsHtml: $html['documents'] ?? null,
            eventJournalHtml: $html['event-journal'] ?? null,
            supplierResultsHtml: $html[self::SUPPLIER_RESULTS_TAB] ?? null,
        );
    }

    public function isAvailable(): bool
    {
        return true;
    }

    private function collectTab(Procurement $procurement, Registry $registry, string $tab, string $registryNumber, string $url, ?float $deadlineAt): string
    {
        $host = $this->canonicalHost($url);
        $lease = $this->detailLease(procurement: $procurement, host: $host);
        $settings = $this->crawlSettings->forStage(stage: CrawlStage::Detail, platform: $this->platformForId($registry->platform_id));

        $startedAt = hrtime(true);
        $request = $this->requestWithRetries(
            lease: $lease,
            endpoint: $url,
            queryParams: [],
            settings: $settings,
            stage: CrawlStage::Detail,
            host: $host,
            deadlineAt: $deadlineAt,
        );
        $response = $request['response'];
        $waitMs = $request['wait_ms'];

        if ($response->status() === 200 && trim($response->body()) === '') {
            $request = $this->requestWithRetries(
                lease: $lease,
                endpoint: $url,
                queryParams: [],
                settings: $settings,
                stage: CrawlStage::Detail,
                host: $host,
                deadlineAt: $deadlineAt,
            );
            $response = $request['response'];
            $waitMs += $request['wait_ms'];
        }

        $this->assertCardPage(response: $response, tab: $tab, url: $url, registryNumber: $registryNumber);

        $html = $response->body();
        $artifactRef = (bool) config('crawler.raw_html_retention_enabled', false)
            ? $this->storeRawHtml(registryId: $registry->id, registryNumber: $registryNumber, tab: $tab, url: $url, html: $html)
            : null;

        $this->fetches->log(stage: CrawlStage::Detail, run: null, procurementId: $procurement->id, attributes: [
            'method' => 'eis-card', 'request_params' => ['tab' => $tab, 'url' => $url],
            'artifact_ref' => $artifactRef,
            'http_status' => $response->status(), 'duration_ms' => (int) ((hrtime(true) - $startedAt) / 1_000_000),
            'proxy_id' => $lease->proxyId, 'proxy_source' => $lease->source?->value, 'wait_ms' => $waitMs,
        ]);

        return $html;
    }

    private function detailLease(Procurement $procurement, string $host): ProxyLease
    {
        return $this->proxyResolver->resolve(
            scope: ProxyScope::CrawlDetail,
            target: $host,
            requirements: $this->proxyBindingResolver->requirementsForProcurement($procurement->id),
        );
    }

    /**
     * Сторож «пришла не та страница» (Д-8): несоответствие = отказ ответа ИЛИ тело не несёт
     * запрошенного реестрового номера (кодировка тела на эту проверку не влияет — номер состоит
     * из цифр). Любая вкладка логирует; `common-info` дополнительно бросает исключение — без неё
     * карточка не считается снятой.
     */
    private function assertCardPage(Response $response, string $tab, string $url, string $registryNumber): void
    {
        if (! $response->failed() && str_contains($response->body(), $registryNumber)) {
            return;
        }

        Log::warning(message: 'CsvSourceMethod: вкладка карточки вернула страницу без запрошенного реестрового номера.', context: [
            'tab' => $tab,
            'url' => $url,
            'status' => $response->status(),
            'body_length' => strlen($response->body()),
            'registry_number' => $registryNumber,
        ]);

        throw CardPageMismatchException::forTab(
            tab: $tab,
            url: $url,
            status: $response->status(),
            bodyLength: strlen($response->body()),
        );
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
     * @param  array<string, string>  $queryParams
     */
    private function request(string $endpoint, array $queryParams, ProxyLease $lease, int $timeoutSeconds): Response
    {
        $tlsOptions = $this->tlsTrust->optionsFor($this->canonicalHost($endpoint));

        $pendingRequest = Http::withOptions([...$lease->httpOptions(), ...$tlsOptions])
            ->withUserAgent($this->stringConfig('crawler.user_agent'))
            ->withHeaders(['Referer' => 'https://zakupki.gov.ru/'])
            ->timeout($timeoutSeconds);

        // Guzzle замещает query-строку URI опцией `query`, ДАЖЕ пустым массивом
        // (`http_build_query([]) === ''`) — endpoint'ы, несущие свою query-строку внутри
        // (карточные URL, Д-8), теряют её, если передать сюда `$queryParams = []`.
        return $queryParams === []
            ? $pendingRequest->get($endpoint)
            : $pendingRequest->get($endpoint, $queryParams);
    }

    /**
     * Оборачивает `request()` учётом исхода в резолвере (research/02 §9.1): сетевой отказ
     * (таймаут/TLS/обрыв соединения) сообщается причиной и пробрасывается дальше, ответ цели
     * (403/429/пустое тело/успех, включая 5xx как транспортный успех — §5) сообщается после
     * получения ответа.
     *
     * @param  array<string, string>  $queryParams
     */
    private function requestWithOutcome(ProxyLease $lease, string $endpoint, array $queryParams, int $timeoutSeconds): Response
    {
        $startedAt = hrtime(true);

        try {
            $response = $this->request(endpoint: $endpoint, queryParams: $queryParams, lease: $lease, timeoutSeconds: $timeoutSeconds);
        } catch (ConnectionException $exception) {
            $this->proxyResolver->reportOutcome($lease, ProxyOutcome::failure($this->connectionFailureReason($exception)));

            throw $exception;
        }

        $latencyMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);

        $this->proxyResolver->reportOutcome($lease, $this->outcomeFrom($response, $latencyMs));

        return $response;
    }

    /**
     * @param  array<string, string>  $queryParams
     * @return array{response: Response, wait_ms: int}
     */
    private function requestWithRetries(
        ProxyLease $lease,
        string $endpoint,
        array $queryParams,
        StageSettings $settings,
        CrawlStage $stage,
        string $host,
        ?Crawler $crawler = null,
        ?float $deadlineAt = null,
    ): array {
        $attempts = max(1, $settings->retries ?? 5);
        $waitMs = 0;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $waitMs += $this->requestBudget->acquire(
                host: $host,
                stage: $stage,
                crawler: $crawler,
                deadlineAt: $deadlineAt,
            );

            try {
                $response = $this->requestWithOutcome(
                    lease: $lease,
                    endpoint: $endpoint,
                    queryParams: $queryParams,
                    timeoutSeconds: $this->requestTimeout(settings: $settings, stage: $stage, deadlineAt: $deadlineAt),
                );
            } catch (ConnectionException $exception) {
                if ($attempt === $attempts) {
                    throw $exception;
                }

                $this->waitBeforeRetry(settings: $settings, stage: $stage, deadlineAt: $deadlineAt);

                continue;
            }

            if (! $this->isRetryableResponse(response: $response) || $attempt === $attempts) {
                return ['response' => $response, 'wait_ms' => $waitMs];
            }

            $this->waitBeforeRetry(settings: $settings, stage: $stage, deadlineAt: $deadlineAt);
        }

        throw new \LogicException('HTTP retry loop ended without a response.');
    }

    private function requestTimeout(StageSettings $settings, CrawlStage $stage, ?float $deadlineAt): int
    {
        $configured = max(1, $settings->timeoutSeconds ?? 30);

        if ($deadlineAt === null) {
            return $configured;
        }

        $remaining = (int) floor($deadlineAt - microtime(true) - 5);

        if ($remaining < 1) {
            throw OutboundRequestDeadlineExceeded::forStage(stage: $stage->value);
        }

        return min($configured, $remaining);
    }

    private function waitBeforeRetry(StageSettings $settings, CrawlStage $stage, ?float $deadlineAt): void
    {
        $waitMs = max(0, $settings->retryDelayMs ?? 500);

        if ($deadlineAt !== null && microtime(true) + ($waitMs / 1000) >= $deadlineAt - 5) {
            throw OutboundRequestDeadlineExceeded::forStage(stage: $stage->value);
        }

        if ($waitMs > 0) {
            Sleep::for($waitMs)->milliseconds();
        }
    }

    private function isRetryableResponse(Response $response): bool
    {
        return $response->status() === 429 || $response->status() >= 500;
    }

    private function outcomeFrom(Response $response, int $latencyMs): ProxyOutcome
    {
        return match (true) {
            $response->status() === 403 => ProxyOutcome::failure(ProxyFailureReason::Http403),
            $response->status() === 429 => ProxyOutcome::failure(ProxyFailureReason::Http429),
            $response->status() === 407 => ProxyOutcome::failure(ProxyFailureReason::ProxyAuthFailed),
            $response->status() === 200 && trim($response->body()) === '' => ProxyOutcome::failure(ProxyFailureReason::EmptyBody),
            default => ProxyOutcome::success(latencyMs: $latencyMs),
        };
    }

    private function connectionFailureReason(ConnectionException $exception): ProxyFailureReason
    {
        $message = $exception->getMessage();

        return match (true) {
            str_contains($message, 'SSL') => ProxyFailureReason::TlsError,
            str_contains($message, 'timed out') || str_contains($message, 'Timeout') => ProxyFailureReason::NetworkTimeout,
            default => ProxyFailureReason::ConnectFailed,
        };
    }

    private function canonicalHost(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return strtolower(is_string($host) ? $host : '');
    }

    /**
     * @return list<array<string, string>>
     */
    private function parseCsv(string $body): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($body));

        if ($lines === false || $lines === [] || $lines[0] === '') {
            return [];
        }

        $header = $this->csvFields($lines[0]);
        $rows = [];

        foreach (array_slice($lines, offset: 1) as $line) {
            if ($line === '') {
                continue;
            }

            $values = $this->csvFields($line);

            if (count($values) !== count($header)) {
                continue;
            }

            $rows[] = array_combine(keys: $header, values: $values);
        }

        return $rows;
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

    /** @return list<string> */
    private function csvFields(string $line): array
    {
        $fields = str_getcsv($line, ';', '"');

        return array_map(static fn (?string $field): string => $field ?? '', $fields);
    }
}
