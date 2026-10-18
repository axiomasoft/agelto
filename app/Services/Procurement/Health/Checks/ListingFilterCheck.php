<?php

declare(strict_types=1);

namespace App\Services\Procurement\Health\Checks;

use App\Dto\Procurement\Crawler\EisListingQueryOverrides;
use App\Dto\Procurement\Crawler\ListingBatch;
use App\Dto\Procurement\Crawler\ListingCursor;
use App\Dto\Procurement\Health\CheckResult;
use App\Dto\Procurement\Health\HealthBaseline;
use App\Enums\Procurement\Crawler\CrawlerMode;
use App\Enums\Procurement\Crawler\SourceMethod;
use App\Enums\Procurement\Health\PlatformCheckKind;
use App\Enums\Procurement\Health\PlatformCheckVerdict;
use App\Exceptions\Procurement\Crawler\InvalidListingResponse;
use App\Exceptions\Procurement\Crawler\PlatformBudgetExhaustedException;
use App\Exceptions\Procurement\Proxy\NoProxyAvailableException;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Platform\Platform;
use App\Services\Procurement\Eis\CsvSourceMethod;
use App\Services\Procurement\Health\Contracts\HealthCheck;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use LogicException;

/**
 * Проверяет ДЕЙСТВИЕ серверного фильтра ОКПД2 двумя блоками того же CSV-пути, что использует
 * обход. Прямой HTTP-запрос здесь намеренно запрещён: он не заметит поломку builder'а, proxy/TLS
 * или budget-цепочки. Невозможность выполнить запрос (бюджет, прокси, сеть) не доказывает
 * поломку ЕИС, поэтому возвращается `not_enough_data`, а не `broken`.
 */
final readonly class ListingFilterCheck implements HealthCheck
{
    private const string REGISTRY_NUMBER_COLUMN = 'Реестровый номер закупки';

    public function __construct(private CsvSourceMethod $source) {}

    public function kind(): PlatformCheckKind
    {
        return PlatformCheckKind::ListingFilter;
    }

    public function run(Platform $platform, HealthBaseline $baseline, CarbonInterface $from, CarbonInterface $to): CheckResult
    {
        $crawler = Crawler::query()
            ->where('mode', CrawlerMode::Fresh->value)
            ->where('source_method', SourceMethod::Csv->value)
            ->where('is_active', true)
            ->whereHas('registry', fn ($query) => $query->where('platform_id', $platform->id))
            ->with('registry')
            ->orderBy('id')
            ->first();

        if (! $crawler instanceof Crawler) {
            return $this->notEnoughData(
                message: 'Активный fresh-профиль CSV для пробы ОКПД2 не найден.',
                requestsUsed: 0,
                measured: [],
            );
        }

        $windowFrom = CarbonImmutable::instance($to)->subDay()->startOfDay();
        $windowTo = $windowFrom->endOfDay();
        $cursor = new ListingCursor(blockFrom: 1);
        $requestsUsed = 0;

        try {
            $unfiltered = $this->source->collectListing(
                crawler: $crawler,
                cursor: $cursor,
                queryOverrides: new EisListingQueryOverrides(
                    publishedFrom: $windowFrom,
                    publishedTo: $windowTo,
                    withoutOkpd2: true,
                    retryOnEmptyResponse: false,
                    requestAttempts: 1,
                ),
            );
            $requestsUsed++;

            $filtered = $this->source->collectListing(
                crawler: $crawler,
                cursor: $cursor,
                queryOverrides: new EisListingQueryOverrides(
                    publishedFrom: $windowFrom,
                    publishedTo: $windowTo,
                    retryOnEmptyResponse: false,
                    requestAttempts: 1,
                ),
            );
            $requestsUsed++;
        } catch (PlatformBudgetExhaustedException|NoProxyAvailableException $exception) {
            return $this->notEnoughData(
                message: "Проба фильтра ОКПД2 не выполнилась: {$exception->getMessage()}",
                requestsUsed: $requestsUsed,
                measured: $this->measured(crawler: $crawler, windowFrom: $windowFrom, windowTo: $windowTo),
            );
        } catch (ConnectionException|InvalidListingResponse $exception) {
            return $this->notEnoughData(
                message: 'Проба фильтра ОКПД2 не получила проверенный CSV-ответ.',
                requestsUsed: $requestsUsed + 1,
                measured: $this->measured(crawler: $crawler, windowFrom: $windowFrom, windowTo: $windowTo),
            );
        }

        if ($unfiltered->httpStatus !== 200 || $filtered->httpStatus !== 200) {
            return $this->notEnoughData(
                message: "Проба фильтра ОКПД2 получила HTTP {$unfiltered->httpStatus}/{$filtered->httpStatus}.",
                requestsUsed: $requestsUsed,
                measured: [
                    ...$this->measured(crawler: $crawler, windowFrom: $windowFrom, windowTo: $windowTo),
                    'http_status_unfiltered' => $unfiltered->httpStatus,
                    'http_status_filtered' => $filtered->httpStatus,
                ],
            );
        }

        $unfilteredNumbers = $this->registryNumbers(batch: $unfiltered);
        $filteredNumbers = $this->registryNumbers(batch: $filtered);
        $overlap = count(array_intersect($unfilteredNumbers, $filteredNumbers));
        $overlapShare = $unfilteredNumbers === [] ? 1.0 : $overlap / count($unfilteredNumbers);
        $measured = [
            ...$this->measured(crawler: $crawler, windowFrom: $windowFrom, windowTo: $windowTo),
            'rows_unfiltered' => $unfiltered->rowsTotal,
            'rows_filtered' => $filtered->rowsTotal,
            'registry_numbers_unfiltered' => count($unfilteredNumbers),
            'registry_numbers_filtered' => count($filteredNumbers),
            'overlap' => $overlap,
            'overlap_share' => (float) $overlapShare,
        ];

        if ($filteredNumbers === []) {
            return new CheckResult(
                kind: $this->kind(),
                verdict: PlatformCheckVerdict::Broken,
                measured: $measured,
                sampleSize: count($unfilteredNumbers),
                requestsUsed: $requestsUsed,
                message: 'Фильтр ОКПД2 отсёк весь блок либо карта id устарела.',
            );
        }

        if ($unfilteredNumbers === []) {
            return new CheckResult(
                kind: $this->kind(),
                verdict: PlatformCheckVerdict::Broken,
                measured: $measured,
                sampleSize: 0,
                requestsUsed: $requestsUsed,
                message: 'Нефильтрованный блок не содержит реестровых номеров.',
            );
        }

        $threshold = $this->maxOverlapShare(baseline: $baseline);
        $measured['max_overlap_share'] = $threshold;
        $calibrationNote = $baseline->calibrated ? '' : '; порог некалиброван';

        return new CheckResult(
            kind: $this->kind(),
            verdict: $overlapShare > $threshold ? PlatformCheckVerdict::Broken : PlatformCheckVerdict::Ok,
            measured: $measured,
            sampleSize: count($unfilteredNumbers),
            requestsUsed: $requestsUsed,
            message: sprintf(
                'Пересечение реестровых номеров: %d/%d (%.3f), порог %.3f%s.',
                $overlap,
                count($unfilteredNumbers),
                $overlapShare,
                $threshold,
                $calibrationNote,
            ),
        );
    }

    /** @return array<string, int|string> */
    private function measured(Crawler $crawler, CarbonImmutable $windowFrom, CarbonImmutable $windowTo): array
    {
        return [
            'crawler_id' => $crawler->id,
            'window_from' => $windowFrom->toAtomString(),
            'window_to' => $windowTo->toAtomString(),
        ];
    }

    /** @return list<string> */
    private function registryNumbers(ListingBatch $batch): array
    {
        $numbers = [];

        foreach ($batch->rows as $row) {
            $number = trim((string) ($row[self::REGISTRY_NUMBER_COLUMN] ?? ''));

            if ($number !== '') {
                $numbers[] = $number;
            }
        }

        return array_values(array_unique($numbers));
    }

    private function maxOverlapShare(HealthBaseline $baseline): float
    {
        $threshold = $baseline->thresholds[$this->kind()->value]['max_overlap_share'] ?? null;

        if (! is_numeric($threshold)) {
            throw new LogicException('В эталоне отсутствует порог пересечения listing_filter.');
        }

        return (float) $threshold;
    }

    /** @param array<string, mixed> $measured */
    private function notEnoughData(string $message, int $requestsUsed, array $measured): CheckResult
    {
        return new CheckResult(
            kind: $this->kind(),
            verdict: PlatformCheckVerdict::NotEnoughData,
            measured: $measured,
            sampleSize: 0,
            requestsUsed: $requestsUsed,
            message: $message,
        );
    }
}
