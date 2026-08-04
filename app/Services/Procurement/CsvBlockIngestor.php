<?php

declare(strict_types=1);

namespace App\Services\Procurement;

use App\Dto\Procurement\Crawler\FetchResult;
use App\Dto\Procurement\Crawler\ListingBatch;
use App\Dto\Procurement\ProcurementSnapshotData;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\Fetch;
use App\Models\Procurement\Crawler\IgnoredItem;
use App\Models\Procurement\Crawler\Run;
use App\Repositories\Procurement\ProcurementReadRepository;
use App\Services\Procurement\Contracts\ListingRowMapper;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

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
    ) {}

    public function ingest(Crawler $crawler, Run $run, ListingBatch $batch): FetchResult
    {
        $skipped = 0;
        $errors = 0;
        $snapshots = [];

        foreach ($batch->rows as $row) {
            try {
                $snapshot = $this->rowMapper->map(row: $row, crawler: $crawler);
            } catch (\Throwable $exception) {
                $this->logRowFailure(crawler: $crawler, exception: $exception, registryNumber: null);
                $errors++;

                continue;
            }

            if ($snapshot === null) {
                $skipped++;

                continue;
            }

            $snapshots[] = $snapshot;
        }

        $ignoredNumbers = $this->ignoredRegistryNumbers(crawler: $crawler, snapshots: $snapshots);

        $new = 0;
        $updated = 0;

        foreach ($snapshots as $snapshot) {
            if (in_array($snapshot->registryNumber, $ignoredNumbers, strict: true)) {
                $skipped++;

                continue;
            }

            try {
                [$isNew, $isUpdated] = $this->ingestSnapshot(snapshot: $snapshot, run: $run);
                $new += $isNew ? 1 : 0;
                $updated += $isUpdated ? 1 : 0;
            } catch (\Throwable $exception) {
                $this->logRowFailure(crawler: $crawler, exception: $exception, registryNumber: $snapshot->registryNumber);
                $errors++;
            }
        }

        $artifactRef = $this->storeRawArtifact(crawler: $crawler, run: $run, batch: $batch);

        Fetch::create([
            'crawler_run_id' => $run->id,
            'method' => $crawler->source_method->value,
            'request_params' => $batch->requestParams,
            'artifact_ref' => $artifactRef,
            'http_status' => $batch->httpStatus,
            'duration_ms' => $batch->durationMs,
            'rows_total' => $batch->rowsTotal,
            'rows_new' => $new,
            'rows_updated' => $updated,
            'dedup_signature' => $batch->dedupSignature,
            'proxy_id' => $batch->proxyId,
            'fetched_at' => Carbon::now(),
        ]);

        return new FetchResult(
            rowsTotal: $batch->rowsTotal,
            rowsNew: $new,
            rowsUpdated: $updated,
            rowsSkipped: $skipped,
            errors: $errors,
        );
    }

    /**
     * @return array{0: bool, 1: bool} [этоНоваяЗакупка, этоОбновлённаяРевизия]
     */
    private function ingestSnapshot(ProcurementSnapshotData $snapshot, Run $run): array
    {
        $existing = $this->procurements->findProcurement(registryId: $snapshot->registryId, registryNumber: $snapshot->registryNumber);
        $revisionCountBefore = $existing?->revision_count ?? 0;

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

        return IgnoredItem::query()
            ->where('registry_id', $crawler->registry_id)
            ->whereIn('registry_number', $registryNumbers)
            ->pluck('registry_number')
            ->all();
    }

    private function storeRawArtifact(Crawler $crawler, Run $run, ListingBatch $batch): string
    {
        $from = (string) ($batch->requestParams['from'] ?? '0');
        $path = "raw/csv/{$crawler->id}/{$run->id}/{$from}.csv";

        Storage::disk(config('crawler.raw_disk'))->put($path, $batch->rawBody);

        return $path;
    }

    private function logRowFailure(Crawler $crawler, \Throwable $exception, ?string $registryNumber): void
    {
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
}
