<?php

declare(strict_types=1);

namespace App\Services\Procurement\Eis;

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
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Platform\Platform;
use App\Models\Procurement\Platform\Registry;
use App\Models\Procurement\Procurement;
use App\Services\Procurement\Contracts\SourceMethod;
use App\Services\Procurement\CrawlSettingsResolver;
use App\Services\Procurement\Exceptions\CardPageMismatchException;
use App\Services\Procurement\FetchLogger;
use App\Services\Procurement\PlatformRequestBudget;
use App\Services\Procurement\Proxy\ProxyBindingResolver;
use App\Services\Procurement\Proxy\ProxyResolver;
use App\Services\Procurement\SourceTlsTrust;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Основной метод забора данных ЕИС — CSV-экспорт реестра закупок
 * (`02-crawling.md` §3). Кодировка источника — Windows-1251, конвертация
 * в UTF-8 обязательна до парсинга; иначе закупки с кириллицей тихо теряются.
 */
final readonly class CsvSourceMethod implements SourceMethod
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
    ) {}

    public function collectListing(Crawler $crawler, ListingCursor $cursor): ListingBatch
    {
        $blockSize = (int) config('crawler.eis.block_size', 500);
        $from = $cursor->blockFrom;
        $to = $from + $blockSize - 1;

        $endpoint = (string) config('crawler.eis.csv_endpoint');
        $queryParams = $this->urlBuilder->buildQueryParams(crawler: $crawler, from: $from, to: $to, cursor: $cursor);

        $host = $this->canonicalHost($endpoint);
        $lease = $this->listingLease($crawler, $host);
        $settings = $this->crawlSettings->forStage(
            stage: CrawlStage::Listing,
            platform: Platform::query()->findOrFail($crawler->registry()->firstOrFail()->getAttribute('platform_id')),
            crawler: $crawler,
        );

        $startedAt = hrtime(true);

        $waitMs = $this->requestBudget->acquire(host: $host, stage: CrawlStage::Listing, crawler: $crawler);
        $response = $this->requestWithOutcome(lease: $lease, endpoint: $endpoint, queryParams: $queryParams, settings: $settings);

        if ($response->status() === 200 && trim($response->body()) === '') {
            $waitMs += $this->requestBudget->acquire(host: $host, stage: CrawlStage::Listing, crawler: $crawler);
            $response = $this->requestWithOutcome(lease: $lease, endpoint: $endpoint, queryParams: $queryParams, settings: $settings);
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
            waitMs: $waitMs,
        );
    }

    /**
     * `use_proxy = false` — единственный легальный способ уйти напрямую, обходящий резолвер
     * ЦЕЛИКОМ (research/02 §10, D83): direct-лиза обязана быть видна логом, а не только значением
     * `null`. `use_proxy = true` передаёт требование профиля к стране — резолвер складывает его с
     * гео-политикой скоупа `crawl_listing` (Implementation Rules P1.9).
     */
    private function listingLease(Crawler $crawler, string $host): ProxyLease
    {
        if (! $crawler->use_proxy) {
            Log::info('Резолвер прокси: профиль отключил прокси, запрос уходит напрямую', [
                'crawler' => $crawler->name,
                'scope' => ProxyScope::CrawlListing->value,
                'target' => $host,
            ]);

            return ProxyLease::direct(ProxyScope::CrawlListing, $host);
        }

        return $this->proxyResolver->resolve(
            ProxyScope::CrawlListing,
            $host,
            new ProxyRequirements(
                country: $crawler->proxy_country,
                proxyIds: $this->proxyBindingResolver->forCrawler($crawler),
            ),
        );
    }

    public function collectDetails(string $registryNumber, Registry $registry): ProcurementDetails
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

        foreach ((array) config('crawler.eis.card_tabs', []) as $tab) {
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

    private function collectTab(Procurement $procurement, Registry $registry, string $tab, string $registryNumber, string $url): string
    {
        $host = $this->canonicalHost($url);
        $lease = $this->detailLease(procurement: $procurement, host: $host);
        $settings = $this->crawlSettings->forStage(stage: CrawlStage::Detail, platform: Platform::query()->findOrFail($registry->platform_id));

        $startedAt = hrtime(true);
        $waitMs = $this->requestBudget->acquire(host: $host, stage: CrawlStage::Detail);
        $response = $this->requestWithOutcome(lease: $lease, endpoint: $url, queryParams: [], settings: $settings);

        if ($response->status() === 200 && trim($response->body()) === '') {
            $this->requestBudget->acquire(host: $host, stage: CrawlStage::Detail);
            $response = $this->requestWithOutcome(lease: $lease, endpoint: $url, queryParams: [], settings: $settings);
        }

        $this->fetches->log(CrawlStage::Detail, null, $procurement->id, [
            'method' => 'eis-card', 'request_params' => ['tab' => $tab, 'url' => $url],
            'http_status' => $response->status(), 'duration_ms' => (int) ((hrtime(true) - $startedAt) / 1_000_000),
            'proxy_id' => $lease->proxyId, 'wait_ms' => $waitMs,
        ]);

        $this->assertCardPage(response: $response, tab: $tab, url: $url, registryNumber: $registryNumber);

        $html = $response->body();

        if ((bool) config('crawler.raw_html_retention_enabled', false)) {
            $this->storeRawHtml(registryId: $registry->id, registryNumber: $registryNumber, tab: $tab, html: $html);
        }

        return $html;
    }

    private function detailLease(Procurement $procurement, string $host): ProxyLease
    {
        $proxyIds = $this->proxyBindingResolver->forProcurement($procurement->id);

        if ($proxyIds === []) {
            Log::info('Резолвер прокси: для карточки нет активной привязки, запрос уходит напрямую', [
                'procurement_id' => $procurement->id,
                'scope' => ProxyScope::CrawlDetail->value,
                'target' => $host,
            ]);

            return ProxyLease::direct(ProxyScope::CrawlDetail, $host);
        }

        return $this->proxyResolver->resolve(
            scope: ProxyScope::CrawlDetail,
            target: $host,
            requirements: new ProxyRequirements(proxyIds: $proxyIds),
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

        if ($tab === 'common-info') {
            throw CardPageMismatchException::forTab(
                tab: $tab,
                url: $url,
                status: $response->status(),
                bodyLength: strlen($response->body()),
            );
        }
    }

    private function storeRawHtml(int $registryId, string $registryNumber, string $tab, string $html): void
    {
        $disk = config('crawler.raw_html_disk', config('crawler.raw_disk'));
        $path = "raw/html/{$registryId}/{$registryNumber}/{$tab}.html.gz";
        $contents = (bool) config('crawler.raw_html_gzip', true) ? gzencode($html) : $html;

        Storage::disk($disk)->put($path, $contents);
    }

    /**
     * @param  array<string, string>  $queryParams
     */
    private function request(string $endpoint, array $queryParams, ProxyLease $lease, StageSettings $settings): Response
    {
        $tlsOptions = $this->tlsTrust->optionsFor($this->canonicalHost($endpoint));

        $pendingRequest = Http::withOptions([...$lease->httpOptions(), ...$tlsOptions])
            ->withUserAgent((string) config('crawler.user_agent'))
            ->withHeaders(['Referer' => 'https://zakupki.gov.ru/'])
            ->timeout($settings->timeoutSeconds ?? 30)
            ->retry(
                times: $settings->retries ?? 5,
                sleepMilliseconds: $settings->retryDelayMs ?? 500,
                when: static fn (\Throwable $exception): bool => $exception instanceof RequestException
                    && ($exception->response->status() === 429 || $exception->response->status() >= 500),
                throw: false,
            );

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
    private function requestWithOutcome(ProxyLease $lease, string $endpoint, array $queryParams, StageSettings $settings): Response
    {
        $startedAt = hrtime(true);

        try {
            $response = $this->request(endpoint: $endpoint, queryParams: $queryParams, lease: $lease, settings: $settings);
        } catch (ConnectionException $exception) {
            $this->proxyResolver->reportOutcome($lease, ProxyOutcome::failure($this->connectionFailureReason($exception)));

            throw $exception;
        }

        $latencyMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);

        $this->proxyResolver->reportOutcome($lease, $this->outcomeFrom($response, $latencyMs));

        return $response;
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
        return strtolower((string) parse_url($url, PHP_URL_HOST));
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

        $header = str_getcsv($lines[0], ';', '"');
        $rows = [];

        foreach (array_slice($lines, offset: 1) as $line) {
            if ($line === '') {
                continue;
            }

            $values = str_getcsv($line, ';', '"');

            if (count($values) !== count($header)) {
                continue;
            }

            $rows[] = array_combine(keys: $header, values: $values);
        }

        return $rows;
    }
}
