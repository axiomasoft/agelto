<?php

declare(strict_types=1);

namespace App\Services\Procurement\Eis;

use App\Dto\Procurement\ListingBatch;
use App\Dto\Procurement\ListingCursor;
use App\Dto\Procurement\ProcurementDetails;
use App\Enums\Procurement\ProcurementStatus;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\Proxy;
use App\Models\Procurement\Platform\Registry;
use App\Models\Procurement\Procurement;
use App\Services\Procurement\Contracts\SourceMethod;
use App\Services\Procurement\PoliteThrottle;
use App\Services\Procurement\ProxyPool;
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
        private ProxyPool $proxyPool,
        private PoliteThrottle $throttle,
    ) {}

    public function collectListing(Crawler $crawler, ListingCursor $cursor): ListingBatch
    {
        $blockSize = (int) config('crawler.eis.block_size', 500);
        $from = $cursor->blockFrom;
        $to = $from + $blockSize - 1;

        $endpoint = (string) config('crawler.eis.csv_endpoint');
        $queryParams = $this->urlBuilder->buildQueryParams(crawler: $crawler, from: $from, to: $to, cursor: $cursor);

        $proxy = $this->proxyPool->pick(target: $endpoint);

        if ($proxy === null) {
            Log::warning(
                message: 'CsvSourceMethod: no active proxy, requesting directly.',
                context: ['crawler_id' => $crawler->id],
            );
        }

        $startedAt = hrtime(true);

        $this->throttle->wait();
        $response = $this->request(endpoint: $endpoint, queryParams: $queryParams, proxy: $proxy);

        if ($response->status() === 200 && trim($response->body()) === '') {
            $this->throttle->wait();
            $response = $this->request(endpoint: $endpoint, queryParams: $queryParams, proxy: $proxy);
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
            proxyId: $proxy?->id,
        );
    }

    public function collectDetails(string $sourceNumber, Registry $registry): ProcurementDetails
    {
        $procurement = Procurement::query()
            ->where('registry_id', $registry->id)
            ->where('source_number', $sourceNumber)
            ->firstOrFail();

        $procedureUrlType = $this->cardUrlBuilder->resolveProcedureUrlType(
            registry: $registry,
            procedureType: $procurement->procedure_type,
        );

        $html = [];

        foreach ((array) config('crawler.eis.card_tabs', []) as $tab) {
            if ($tab === self::SUPPLIER_RESULTS_TAB && ! in_array($procurement->status, self::FINALIZING_STATUSES, strict: true)) {
                $html[$tab] = null;

                continue;
            }

            $html[$tab] = $this->collectTab(
                registry: $registry,
                procedureUrlType: $procedureUrlType,
                tab: $tab,
                sourceNumber: $sourceNumber,
            );
        }

        return new ProcurementDetails(
            sourceNumber: $sourceNumber,
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

    private function collectTab(Registry $registry, string $procedureUrlType, string $tab, string $sourceNumber): string
    {
        $url = $this->cardUrlBuilder->buildUrl(
            registry: $registry,
            procedureUrlType: $procedureUrlType,
            tab: $tab,
            sourceNumber: $sourceNumber,
        );

        $proxy = $this->proxyPool->pick(target: $url);

        $this->throttle->wait();
        $response = $this->request(endpoint: $url, queryParams: [], proxy: $proxy);

        if ($response->status() === 200 && trim($response->body()) === '') {
            $this->throttle->wait();
            $response = $this->request(endpoint: $url, queryParams: [], proxy: $proxy);
        }

        $html = $response->body();

        if ((bool) config('crawler.raw_html_retention_enabled', false)) {
            $this->storeRawHtml(registryId: $registry->id, sourceNumber: $sourceNumber, tab: $tab, html: $html);
        }

        return $html;
    }

    private function storeRawHtml(int $registryId, string $sourceNumber, string $tab, string $html): void
    {
        $disk = config('crawler.raw_html_disk', config('crawler.raw_disk'));
        $path = "raw/html/{$registryId}/{$sourceNumber}/{$tab}.html.gz";
        $contents = (bool) config('crawler.raw_html_gzip', true) ? gzencode($html) : $html;

        Storage::disk($disk)->put($path, $contents);
    }

    /**
     * @param  array<string, string>  $queryParams
     */
    private function request(string $endpoint, array $queryParams, ?Proxy $proxy): Response
    {
        $options = $proxy !== null ? $this->proxyPool->proxyOptions(proxy: $proxy) : [];

        return Http::withOptions($options)
            ->withUserAgent((string) config('crawler.user_agent'))
            ->withHeaders(['Referer' => 'https://zakupki.gov.ru/'])
            ->timeout((int) config('crawler.http.timeout', 30))
            ->retry(
                times: (int) config('crawler.http.retries', 3),
                sleepMilliseconds: (int) config('crawler.http.base_delay_ms', 500),
                when: static fn (\Throwable $exception): bool => $exception instanceof RequestException
                    && ($exception->response->status() === 429 || $exception->response->status() >= 500),
                throw: false,
            )
            ->get($endpoint, $queryParams);
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
