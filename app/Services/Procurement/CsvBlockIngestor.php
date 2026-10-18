<?php

declare(strict_types=1);

namespace App\Services\Procurement;

use App\Actions\Procurement\Crawler\StoreRawFetchArtifactAction;
use App\Dto\Procurement\Crawler\FetchResult;
use App\Dto\Procurement\Crawler\ListingBatch;
use App\Dto\Procurement\ProcurementSnapshotData;
use App\Enums\Procurement\Crawler\CrawlStage;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\IgnoredItem;
use App\Models\Procurement\Crawler\Run;
use App\Repositories\Procurement\ProcurementReadRepository;
use App\Services\Procurement\Contracts\ListingRowMapper;
use Illuminate\Support\Facades\Log;

/**
 * Агностик-инжест одного блока листинга (`02-crawling.md` §3.4): denylist-фильтр →
 * `ProcurementIngestProcessor` на каждую строку → метрики блока → сырой CSV в storage →
 * запись `prc_fetches`. Площадко-специфичая раскладка колонок — только в инжектированном
 * `ListingRowMapper` (в этом классе нет ЕИС-строк).
 */
final readonly class CsvBlockIngestor
{
    public function __construct(
        private ProcurementIngestProcessor $ingestProcessor,
        private ProcurementReadRepository $procurements,
        private ListingRowMapper $rowMapper,
        private FetchLogger $fetches,
        private CrawlerRunManager $runs,
        private StoreRawFetchArtifactAction $rawArtifacts,
        private OperationLogWriter $operationLogs,
    ) {}

    public function ingest(Crawler $crawler, Run $run, ListingBatch $batch): FetchResult
    {
        $skipped = 0;
        $rowsErrors = 0;
        $snapshots = [];

        foreach ($batch->rows as $row) {
            try {
                $snapshot = $this->rowMapper->map(row: $row, crawler: $crawler);
            } catch (\Throwable $exception) {
                $this->logRowFailure(crawler: $crawler, run: $run, exception: $exception, registryNumber: null);
                $rowsErrors++;

                continue;
            }

            if ($snapshot === null) {
                $skipped++;

                continue;
            }

            $snapshots[] = $snapshot;
        }

        [$snapshots, $duplicates] = $this->deduplicateByRegistryNumber(snapshots: $snapshots);

        if ($duplicates > 0) {
            Log::warning(
                message: 'CsvBlockIngestor: строки блока схлопнуты дедупом по реестровому номеру.',
                context: ['crawler_id' => $crawler->id, 'run_id' => $run->id, 'duplicates' => $duplicates],
            );
        }

        $oldestUpdateSeen = null;
        $oldestUpdateSeenHasDayPrecision = false;

        foreach ($snapshots as $snapshot) {
            if ($oldestUpdateSeen === null || $snapshot->sourceUpdatedAt->lt($oldestUpdateSeen)) {
                $oldestUpdateSeen = $snapshot->sourceUpdatedAt;
                $oldestUpdateSeenHasDayPrecision = $snapshot->sourceUpdatedAtHasDayPrecision;

                continue;
            }

            if ($snapshot->sourceUpdatedAt->eq($oldestUpdateSeen) && $snapshot->sourceUpdatedAtHasDayPrecision) {
                $oldestUpdateSeenHasDayPrecision = true;
            }
        }

        $ignoredNumbers = $this->ignoredRegistryNumbers(crawler: $crawler, snapshots: $snapshots);

        $new = 0;
        $updated = 0;
        $rowsParsed = 0;

        foreach ($snapshots as $snapshot) {
            if (in_array($snapshot->registryNumber, $ignoredNumbers, strict: true)) {
                $skipped++;

                continue;
            }

            try {
                [$isNew, $isUpdated] = $this->ingestSnapshot(snapshot: $snapshot, run: $run);
                $rowsParsed++;
                $new += $isNew ? 1 : 0;
                $updated += $isUpdated ? 1 : 0;
            } catch (\Throwable $exception) {
                $this->logRowFailure(crawler: $crawler, run: $run, exception: $exception, registryNumber: $snapshot->registryNumber);
                $rowsErrors++;
            }
        }

        $artifactRef = $this->storeRawArtifact(crawler: $crawler, run: $run, batch: $batch);

        $this->fetches->log(stage: CrawlStage::Listing, run: $run, procurementId: null, attributes: [
            'crawler_run_id' => $run->id,
            'method' => $crawler->source_method->value,
            'request_params' => $batch->requestParams,
            'artifact_ref' => $artifactRef,
            'http_status' => $batch->httpStatus,
            'duration_ms' => $batch->durationMs,
            'rows_total' => $batch->rowsTotal,
            'rows_new' => $new,
            'rows_updated' => $updated,
            'rows_parsed' => $rowsParsed,
            'rows_errors' => $rowsErrors,
            'dedup_signature' => $batch->dedupSignature,
            'proxy_id' => $batch->proxyId,
            'proxy_source' => $batch->proxySource?->value,
            'wait_ms' => $batch->waitMs,
        ]);

        $stats = $run->stats === null ? [] : $this->stringKeyedArray($run->stats->toArray());
        $totalWait = $stats['budget_wait_total_ms'] ?? 0;
        $maxWait = $stats['budget_wait_max_ms'] ?? 0;

        if (! is_int($totalWait) || ! is_int($maxWait)) {
            throw new \UnexpectedValueException('Crawler run wait metrics must be integers.');
        }

        if (! $this->runs->updateStats(run: $run, stats: array_merge($stats, [
            'budget_wait_total_ms' => $totalWait + $batch->waitMs,
            'budget_wait_max_ms' => max($maxWait, $batch->waitMs),
        ]))) {
            throw new \RuntimeException('Execution lease is no longer owned by this processor.');
        }

        if ($rowsErrors > 0) {
            throw new \RuntimeException("Блок листинга не обработан полностью: ошибок строк {$rowsErrors}. Курсор сохранён для повтора; подробности в журнале запросов.");
        }

        return new FetchResult(
            rowsTotal: $batch->rowsTotal,
            rowsParsed: $rowsParsed,
            rowsNew: $new,
            rowsUpdated: $updated,
            rowsSkipped: $skipped,
            rowsErrors: $rowsErrors,
            rowsDuplicated: $duplicates,
            oldestUpdateSeen: $oldestUpdateSeen,
            oldestUpdateSeenHasDayPrecision: $oldestUpdateSeenHasDayPrecision,
        );
    }

    /** Rejected responses retain evidence without entering the ingestion/cursor transaction. */
    public function recordRejectedBatch(Crawler $crawler, Run $run, ListingBatch $batch, string $reason): void
    {
        $artifactRef = $this->storeRawArtifact(crawler: $crawler, run: $run, batch: $batch);
        $this->fetches->log(stage: CrawlStage::Listing, run: $run, procurementId: null, attributes: [
            'method' => $crawler->source_method->value,
            'request_params' => [...$batch->requestParams, '_rejection_reason' => $reason],
            'artifact_ref' => $artifactRef,
            'http_status' => $batch->httpStatus,
            'duration_ms' => $batch->durationMs,
            'rows_total' => $batch->rowsTotal,
            'rows_parsed' => 0,
            'rows_errors' => 1,
            'proxy_id' => $batch->proxyId,
            'proxy_source' => $batch->proxySource?->value,
            'wait_ms' => $batch->waitMs,
        ]);
    }

    /**
     * Дедуп ВНУТРИ блока по реестровому номеру (П-5) — первая строка выигрывает (порядок сервера
     * значимый). `rowsTotal`/`dedup_signature` не пересчитываются: они про полноту блока сервера
     * (стоп-условие `ArchiveWindowScanner:66`, детект тихого cap), не про уникальность строк.
     *
     * @param  list<ProcurementSnapshotData>  $snapshots
     * @return array{0: list<ProcurementSnapshotData>, 1: int}
     */
    private function deduplicateByRegistryNumber(array $snapshots): array
    {
        $seen = [];
        $duplicates = 0;
        $deduped = [];

        foreach ($snapshots as $snapshot) {
            if (isset($seen[$snapshot->registryNumber])) {
                $duplicates++;

                continue;
            }

            $seen[$snapshot->registryNumber] = true;
            $deduped[] = $snapshot;
        }

        return [$deduped, $duplicates];
    }

    /**
     * @return array{0: bool, 1: bool} [этоНоваяЗакупка, этоОбновлённаяРевизия]
     */
    private function ingestSnapshot(ProcurementSnapshotData $snapshot, Run $run): array
    {
        $existing = $this->procurements->findProcurement(registryId: $snapshot->registryId, registryNumber: $snapshot->registryNumber);
        $revisionCountBefore = $existing === null ? 0 : $existing->revision_count;

        $procurement = $this->ingestProcessor->ingest(data: $snapshot, crawlerRunId: $run->id);

        if ($existing === null) {
            return [true, false];
        }

        return [false, $procurement->revision_count > $revisionCountBefore];
    }

    /**
     * @param  list<ProcurementSnapshotData>  $snapshots
     * @return list<string>
     */
    private function ignoredRegistryNumbers(Crawler $crawler, array $snapshots): array
    {
        if ($snapshots === []) {
            return [];
        }

        $registryNumbers = array_map(static fn (ProcurementSnapshotData $snapshot): string => $snapshot->registryNumber, $snapshots);

        $numbers = IgnoredItem::query()
            ->where('registry_id', $crawler->registry_id)
            ->whereIn('registry_number', $registryNumbers)
            ->pluck('registry_number')
            ->all();

        return array_values(array_filter($numbers, is_string(...)));
    }

    private function storeRawArtifact(Crawler $crawler, Run $run, ListingBatch $batch): string
    {
        $from = $batch->requestParams['from'] ?? '0';

        if (! is_string($from) && ! is_int($from)) {
            $from = '0';
        }

        $disk = config('crawler.raw_disk');

        if (! is_string($disk)) {
            throw new \UnexpectedValueException('crawler.raw_disk must be a string.');
        }

        return $this->rawArtifacts->execute(
            disk: $disk,
            prefix: "raw/csv/{$crawler->id}/{$run->id}",
            requestIdentity: [
                'crawler_id' => $crawler->id,
                'run_id' => $run->id,
                'request_params' => $batch->requestParams,
            ],
            contents: $batch->rawBody,
            extension: 'csv',
        );
    }

    private function logRowFailure(Crawler $crawler, Run $run, \Throwable $exception, ?string $registryNumber): void
    {
        $this->operationLogs->listingRowFailed(runId: $run->id, registryNumber: $registryNumber, exception: $exception);

        Log::error(
            message: 'CsvBlockIngestor: row processing failed.',
            context: [
                'crawler_id' => $crawler->id,
                'registry_id' => $crawler->registry_id,
                'registry_number' => $registryNumber,
                'exception' => $exception->getMessage(),
            ],
        );
    }

    /**
     * @param  array<mixed>  $values
     * @return array<string, mixed>
     */
    private function stringKeyedArray(array $values): array
    {
        $result = [];

        foreach ($values as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
