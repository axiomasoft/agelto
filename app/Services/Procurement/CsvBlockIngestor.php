<?php

declare(strict_types=1);

namespace App\Services\Procurement;

use App\Actions\Procurement\Crawler\StoreRawFetchArtifactAction;
use App\Dto\Procurement\Acquisition\LockedProcurement;
use App\Dto\Procurement\Crawler\FetchResult;
use App\Dto\Procurement\Crawler\ListingBatch;
use App\Dto\Procurement\Crawler\SkippedListingRow;
use App\Dto\Procurement\ProcurementSnapshotData;
use App\Exceptions\Procurement\Crawler\ExecutionLeaseLost;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\IgnoredItem;
use App\Models\Procurement\Crawler\Run;
use App\Repositories\Procurement\Network\AdapterHealthRepository;
use App\Services\Procurement\Acquisition\IdentityResolver;
use App\Services\Procurement\Acquisition\SnapshotFinalizer;
use App\Services\Procurement\Contracts\ListingRowMapper;
use App\Services\Procurement\Contracts\ListingSchemaAware;
use App\Services\Procurement\Network\LegacyResponseAssessment;
use App\Support\SecretRedactor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Агностик-инжест одного блока листинга (`02-crawling.md` §3.4): denylist-фильтр →
 * `ProcurementIngestProcessor` на каждую строку → метрики блока → сырой CSV в storage →
 * retained raw artifact. Раскладка колонок площадки — только в инжектированном
 * `ListingRowMapper` (в этом классе нет ЕИС-строк). Физические попытки учитывает network ledger.
 */
final readonly class CsvBlockIngestor
{
    public function __construct(
        private SnapshotFinalizer $ingestProcessor,
        private PlatformAdapterRegistry $adapters,
        private CrawlerRunManager $runs,
        private StoreRawFetchArtifactAction $rawArtifacts,
        private OperationLogWriter $operationLogs,
        private LegacyResponseAssessment $failures,
    ) {}

    public function ingest(Crawler $crawler, Run $run, ListingBatch $batch, ?string $artifactRef = null): FetchResult
    {
        $artifactRef ??= $this->stageArtifact(crawler: $crawler, run: $run, batch: $batch);

        return DB::transaction(function () use ($crawler, $run, $batch): FetchResult {
            if ($batch->validation !== null) {
                app(AdapterHealthRepository::class)->assertReceipt($batch->validation);
            }

            return $this->ingestValidated($crawler, $run, $batch);
        });
    }

    private function ingestValidated(Crawler $crawler, Run $run, ListingBatch $batch): FetchResult
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

        if (! $preflightRejected && $batch->mapping !== null) {
            $snapshots = $batch->mapping->snapshots;
            $intentionalSkips = $batch->mapping->intentionalSkips;
            $unmapped = $batch->mapping->unmapped;
            $skipped = $intentionalSkips + $unmapped;
            $rowsErrors = $batch->mapping->errors;
        }
        foreach ($preflightRejected || $batch->mapping !== null ? [] : $batch->rows as $row) {
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
        [$snapshots, $duplicates] = $this->deduplicateBySourceIdentity(snapshots: $snapshots);

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
        $identities = array_map(fn (ProcurementSnapshotData $snapshot) => app(IdentityResolver::class)->resolve($snapshot->registryId, $snapshot->identity), $snapshots);
        $locked = $this->ingestProcessor->lockIdentities($identities);

        $new = 0;
        $updated = 0;
        $rowsParsed = 0;

        foreach ($snapshots as $index => $snapshot) {
            if (in_array($snapshot->identity->value->value, $ignoredNumbers, strict: true)) {
                $skipped++;

                continue;
            }

            // Persistence defects must roll back the whole block, including its cursor.
            // Only source mapping failures above are classified as individual row errors.
            [$isNew, $isUpdated] = $this->ingestSnapshot($snapshot, $run, $batch->observedAt, $locked[$index]);
            $rowsParsed++;
            $new += $isNew ? 1 : 0;
            $updated += $isUpdated ? 1 : 0;
        }

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
            fetchId: null,
            httpStatus: $batch->httpStatus,
        );
    }

    /** Rejected responses retain evidence without entering the ingestion/cursor transaction. */
    public function recordRejectedBatch(Crawler $crawler, Run $run, ListingBatch $batch, string $reason): void
    {
        $artifact = $this->stageArtifact(crawler: $crawler, run: $run, batch: $batch);
        Log::warning('listing_response_rejected', [
            'crawler_id' => $crawler->id, 'run_id' => $run->id, 'reason' => $reason,
            'http_status' => $batch->httpStatus, 'artifact_ref' => $artifact,
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
    private function deduplicateBySourceIdentity(array $snapshots): array
    {
        $seen = [];
        $duplicates = 0;
        $deduped = [];

        foreach ($snapshots as $snapshot) {
            $key = json_encode($snapshot->identity->toArray(), JSON_THROW_ON_ERROR);
            if (isset($seen[$key])) {
                $duplicates++;

                continue;
            }

            $seen[$key] = true;
            $deduped[] = $snapshot;
        }

        return [$deduped, $duplicates];
    }

    /**
     * @return array{0: bool, 1: bool} [этоНоваяЗакупка, этоОбновлённаяРевизия]
     */
    private function ingestSnapshot(ProcurementSnapshotData $snapshot, Run $run, ?Carbon $observedAt, LockedProcurement $locked): array
    {
        $existing = $locked->procurement;
        $revisionCountBefore = $existing === null ? 0 : $existing->revision_count;

        $procurement = $this->ingestProcessor->persistLocked($snapshot, $locked, $run->id, $observedAt);

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

        $registryNumbers = array_map(static fn (ProcurementSnapshotData $snapshot): string => $snapshot->identity->value->value, $snapshots);

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
