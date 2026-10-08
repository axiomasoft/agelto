<?php

declare(strict_types=1);

namespace App\Services\Procurement;

use App\Actions\Procurement\Crawler\StoreRawFetchArtifactAction;
use App\Dto\Procurement\Crawler\FetchResult;
use App\Dto\Procurement\Crawler\ListingBatch;
use App\Dto\Procurement\Crawler\SkippedListingRow;
use App\Dto\Procurement\ProcurementSnapshotData;
use App\Enums\Procurement\Crawler\CrawlStage;
use App\Exceptions\Procurement\Crawler\ExecutionLeaseLost;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\IgnoredItem;
use App\Models\Procurement\Crawler\Run;
use App\Repositories\Procurement\ProcurementReadRepository;
use App\Services\Procurement\Contracts\ListingRowMapper;
use App\Services\Procurement\Contracts\ListingSchemaAware;
use App\Services\Procurement\SourceControl\SourceFailureClassifier;
use App\Support\SecretRedactor;
use Illuminate\Support\Carbon;
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
        private PlatformAdapterRegistry $adapters,
        private FetchLogger $fetches,
        private CrawlerRunManager $runs,
        private StoreRawFetchArtifactAction $rawArtifacts,
        private OperationLogWriter $operationLogs,
        private SourceFailureClassifier $failures,
    ) {}

    public function ingest(Crawler $crawler, Run $run, ListingBatch $batch, ?string $artifactRef = null): FetchResult
    {
        $rowMapper = $this->adapters->listingMapper(registry: $crawler->registry ?? throw new \LogicException('Crawler registry is missing.'));
        $skipped = 0;
        $unmapped = 0;
        $intentionalSkips = 0;
        $rowsErrors = 0;
        $snapshots = [];
        $httpRejected = $batch->httpStatus < 200 || $batch->httpStatus >= 300;

        // Проверка до построчного разбора: не 2xx — файл не скачался; нет обязательных колонок
        // в заголовке — скачано не то (смена формата, HTML-заглушка). Такой блок не маппится
        // построчно: тысячи «ошибок строк» вместо одного точного отказа никому не помогут.
        $missingColumns = $httpRejected ? [] : $this->missingRequiredColumns(batch: $batch, rowMapper: $rowMapper);
        $preflightRejected = $httpRejected || $missingColumns !== [];

        foreach ($preflightRejected ? [] : $batch->rows as $row) {
            try {
                $snapshot = $rowMapper->map(row: $row, crawler: $crawler);
            } catch (\Throwable $exception) {
                $this->logRowFailure(crawler: $crawler, run: $run, exception: $exception, registryNumber: null);
                $rowsErrors++;

                continue;
            }

            if ($snapshot instanceof SkippedListingRow) {
                $skipped++;
                $intentionalSkips++;

                continue;
            }

            if ($snapshot === null) {
                $skipped++;
                $unmapped++;

                continue;
            }

            $snapshots[] = $snapshot;
        }

        $mappedRows = count($snapshots);
        [$snapshots, $duplicates] = $this->deduplicateByRegistryNumber(snapshots: $snapshots);

        if ($duplicates > 0) {
            Log::warning(
                message: 'CsvBlockIngestor: строки блока схлопнуты дедупом по реестровому номеру.',
                context: ['crawler_id' => $crawler->id, 'run_id' => $run->id, 'duplicates' => $duplicates],
            );
        }

        $oldestUpdateSeen = null;
        $oldestUpdateBefore = null;
        $oldestUpdateSeenHasDayPrecision = false;

        foreach ($snapshots as $snapshot) {
            if ($oldestUpdateSeen === null || $snapshot->sourceUpdatedAt->lt($oldestUpdateSeen)) {
                $oldestUpdateSeen = $snapshot->sourceUpdatedAt;
                $oldestUpdateBefore = $snapshot->sourceUpdatedBefore;
                $oldestUpdateSeenHasDayPrecision = $snapshot->sourceUpdatedAtHasDayPrecision;

                continue;
            }

            if ($snapshot->sourceUpdatedAt->eq($oldestUpdateSeen) && $snapshot->sourceUpdatedAtHasDayPrecision) {
                $oldestUpdateSeenHasDayPrecision = true;
                $oldestUpdateBefore = $snapshot->sourceUpdatedBefore ?? $oldestUpdateBefore;
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
                [$isNew, $isUpdated] = $this->ingestSnapshot(snapshot: $snapshot, run: $run, observedAt: $batch->observedAt);
                $rowsParsed++;
                $new += $isNew ? 1 : 0;
                $updated += $isUpdated ? 1 : 0;
            } catch (\Throwable $exception) {
                $this->logRowFailure(crawler: $crawler, run: $run, exception: $exception, registryNumber: $snapshot->registryNumber);
                $rowsErrors++;
            }
        }

        $artifactRef ??= $this->stageArtifact(crawler: $crawler, run: $run, batch: $batch);

        $fetch = $this->fetches->log(stage: CrawlStage::Listing, run: $run, procurementId: null, attributes: [
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
            // Битые CSV-записи видны в telemetry и делают блок неполным:
            // вызывающий сохраняет курсор, пока формат не исправлен.
            'rows_errors' => $rowsErrors + $batch->rowsMalformed,
            'dedup_signature' => $batch->dedupSignature,
            'proxy_id' => $batch->proxyId,
            'proxy_source' => $batch->proxySource?->value,
            'wait_ms' => $batch->waitMs,
        ]);

        // Отказ только классифицируется; в `SourceFailureReporter` его передаёт вызывающий ПОСЛЕ
        // транзакции блока (`SourceRunControl::afterBlock`), чтобы откат не стёр запись о проблеме.
        $rowFailures = $rowsErrors + $unmapped + $batch->rowsMalformed;
        $failure = $this->failures->classifyResponse(
            httpStatus: $batch->httpStatus,
            records: $batch->rowsTotal,
            mapped: $mappedRows + $intentionalSkips,
            rowFailures: $rowFailures,
            missingColumns: $missingColumns,
            emptyBody: $batch->emptyBody,
        );

        $stats = $run->stats === null ? [] : $this->stringKeyedArray($run->stats->toArray());
        $totalWait = $stats['budget_wait_total_ms'] ?? 0;
        $maxWait = $stats['budget_wait_max_ms'] ?? 0;

        if (! is_int($totalWait) || ! is_int($maxWait)) {
            throw new \UnexpectedValueException('Crawler run wait metrics must be integers.');
        }

        if (! $this->runs->updateStats(run: $run, stats: array_merge($stats, [
            ...($failure === null ? ['listing_last_success_at' => now()->toIso8601String()] : []),
            'budget_wait_total_ms' => $totalWait + $batch->waitMs,
            'budget_wait_max_ms' => max($maxWait, $batch->waitMs),
        ]))) {
            throw ExecutionLeaseLost::noLongerOwned();
        }

        return new FetchResult(
            rowsTotal: $batch->rowsTotal,
            rowsParsed: $rowsParsed,
            rowsNew: $new,
            rowsUpdated: $updated,
            rowsSkipped: $skipped,
            rowsErrors: $rowsErrors + $batch->rowsMalformed,
            rowsDuplicated: $duplicates,
            oldestUpdateSeen: $oldestUpdateSeen,
            oldestUpdateBefore: $oldestUpdateBefore,
            oldestUpdateSeenHasDayPrecision: $oldestUpdateSeenHasDayPrecision,
            failure: $failure,
            failureDetail: $failure === null ? null
                : "HTTP {$batch->httpStatus}, записей {$batch->rowsTotal}, распознано {$mappedRows}, ошибок строк {$rowFailures}."
                    .($missingColumns === [] ? '' : ' Нет колонок: '.implode(', ', $missingColumns).'.'),
            fetchId: $fetch->id,
            httpStatus: $batch->httpStatus,
        );
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
    private function ingestSnapshot(ProcurementSnapshotData $snapshot, Run $run, ?Carbon $observedAt): array
    {
        $existing = $this->procurements->findProcurement(registryId: $snapshot->registryId, registryNumber: $snapshot->registryNumber);
        $revisionCountBefore = $existing === null ? 0 : $existing->revision_count;

        $procurement = $this->ingestProcessor->ingest(data: $snapshot, crawlerRunId: $run->id, observedAt: $observedAt);

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

    public function stageArtifact(Crawler $crawler, Run $run, ListingBatch $batch): string
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
                'exception' => SecretRedactor::text($exception->getMessage()),
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

    /**
     * Обязательные колонки маппера, которых нет в заголовке блока. Заголовок — как пришёл от
     * источника (`ListingBatch::$header`); если источник его не передал — ключи первой записи.
     * Пустое тело заголовка не имеет и проверкой не считается дрейфом.
     *
     * Нужно обсудить: отдаёт ли ЕИС для пустой выборки CSV с заголовком или пустое тело. Если
     * всегда с заголовком, пустое тело тоже стоит считать отказом; сейчас нет живого образца.
     *
     * @return list<string>
     */
    private function missingRequiredColumns(ListingBatch $batch, ListingRowMapper $rowMapper): array
    {
        if (! $rowMapper instanceof ListingSchemaAware) {
            return [];
        }

        $header = $batch->header ?? ($batch->rows === [] ? null : array_keys($batch->rows[array_key_first($batch->rows)]));

        if ($header === null) {
            return [];
        }

        return array_values(array_diff($rowMapper->requiredColumns(), $header));
    }
}
