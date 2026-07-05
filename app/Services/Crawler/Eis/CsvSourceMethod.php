<?php

declare(strict_types=1);

namespace App\Services\Crawler\Eis;

use App\Dto\Crawler\ListingBatch;
use App\Dto\Crawler\ListingCursor;
use App\Dto\Crawler\ProcurementDetails;
use App\Models\Crawler\Crawler;
use App\Models\Crawler\Proxy;
use App\Models\Platform\Registry;
use App\Services\Crawler\Contracts\SourceMethod;
use App\Services\Crawler\Exceptions\NotImplementedException;
use App\Services\Crawler\PoliteThrottle;
use App\Services\Crawler\ProxyPool;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Основной метод забора данных ЕИС — CSV-экспорт реестра закупок
 * (`02-crawling.md` §3). Кодировка источника — Windows-1251, конвертация
 * в UTF-8 обязательна до парсинга; иначе закупки с кириллицей тихо теряются.
 */
final readonly class CsvSourceMethod implements SourceMethod
{
    public function __construct(
        private EisCsvUrlBuilder $urlBuilder,
        private ProxyPool $proxyPool,
        private PoliteThrottle $throttle,
    ) {}

    public function collectListing(Crawler $crawler, ListingCursor $cursor): ListingBatch
    {
        $blockSize = (int) config('crawler.eis.block_size', 500);
        $from = $cursor->blockFrom;
        $to = $from + $blockSize - 1;

        $endpoint = (string) config('crawler.eis.csv_endpoint');
        $queryParams = $this->urlBuilder->buildQueryParams(crawler: $crawler, from: $from, to: $to);

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
            dedupSignature: $rows[0]['reg_number'] ?? null,
            rowsTotal: count($rows),
            httpStatus: $response->status(),
            durationMs: $durationMs,
            requestParams: $queryParams,
        );
    }

    public function collectDetails(string $sourceNumber, Registry $registry): ProcurementDetails
    {
        throw NotImplementedException::forMethod(method: self::class.'::collectDetails');
    }

    public function isAvailable(): bool
    {
        return true;
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
