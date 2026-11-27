<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Crawler;

use App\Dto\Procurement\Acquisition\Listing\ListingDetailReference;
use App\Dto\Procurement\Acquisition\Listing\ListingPlan;
use App\Dto\Procurement\Acquisition\Listing\ListingReceipt;
use App\Dto\Procurement\Acquisition\Listing\ListingTask;
use App\Dto\Procurement\Acquisition\Listing\ListingWindow;
use App\Dto\Procurement\Platform\Diagnostics\CursorEnvelope;
use App\Dto\Procurement\Process\Inputs\ListingInput;
use App\Dto\Procurement\Process\Primitives\ContentHash;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Dto\Procurement\Process\Primitives\VersionedPayload;
use App\Enums\Procurement\Process\Core\AcquisitionKind;
use App\Repositories\Procurement\Process\Support\CallerTransaction;
use App\Repositories\Procurement\Process\Support\LedgerRow;
use Illuminate\Support\Facades\DB;

/** Real crawl subjects carry immutable page assignments and bounded, indexed page evidence. */
final readonly class ListingStoreRepository
{
    public function __construct(private CrawlStoreRepository $crawl) {}

    /**
     * Called by the producer in the run-allocation transaction.
     *
     * @return list<ListingInput>
     */
    public function allocate(ProcessUuid $crawlRun, ListingPlan $plan, int $registryId, string $registry,
        CursorEnvelope $filter, ContentHash $streamKey, int $maxPartitions = 10000): array
    {
        CallerTransaction::require('Listing allocation');
        if ($maxPartitions < 1 || $maxPartitions > 10000) {
            throw new \InvalidArgumentException('Invalid run partition budget.');
        }
        $windows = array_slice($plan->windows, 0, $maxPartitions);
        $continuation = $plan->continuation;
        if (count($windows) < count($plan->windows)) {
            $first = $plan->windows[count($windows)];
            $last = $plan->continuation ?? $plan->windows[count($plan->windows) - 1];
            $continuation = new ListingWindow($first->cursor, $first->start, $last->end, $last->closesAt);
        }
        $inputs = [];
        foreach ($windows as $window) {
            $id = self::id($crawlRun->value.':'.$window->cursor->payloadHash->hex);
            $task = new ListingTask($registryId, $registry, $filter, $window->cursor, $window->cursor, $streamKey,
                $window->start, $window->end, $window->closesAt, $id, maxPartitions: $maxPartitions);
            $inputs[] = $this->storeTask($crawlRun, $id, null, $task);
        }
        if ($continuation !== null) {
            $window = $continuation;
            $id = self::id($crawlRun->value.':tail:'.$window->cursor->payloadHash->hex);
            $task = new ListingTask($registryId, $registry, $filter, $window->cursor, $window->cursor, $streamKey,
                $window->start, $window->end, $window->closesAt, $id, maxPartitions: $maxPartitions);
            $input = $this->storeTask($crawlRun, $id, null, $task);
            $this->receipt($input, new ListingReceipt(ContentHash::of(''), [], 'incomplete', 'root_allocation_bound'));
        }

        return $inputs;
    }

    public function task(ListingInput $input): ListingTask
    {
        $row = LedgerRow::one("SELECT b.partition_payload::text AS payload, encode(r.filter_hash,'hex') AS filter_hash,
            encode(r.access_policy_hash,'hex') AS access_hash, r.adapter_version
            FROM prc_crawl_blocks b JOIN prc_crawl_runs r ON r.id=b.crawl_run_id WHERE b.id=?", [$input->crawlBlockId->value])
            ?? throw new \LogicException('Missing listing subject.');
        if ($row->string('filter_hash') !== $input->filterHash->hex || $row->string('access_hash') !== $input->accessPolicyHash->hex
            || $row->string('adapter_version') !== $input->adapterVersion || $input->cursorVersion !== 1) {
            throw new \LogicException('Listing immutable snapshot mismatch.');
        }

        $task = ListingTask::fromJson($row->string('payload'));
        if ($task->filter->payloadHash->hex !== $input->filterHash->hex) {
            throw new \LogicException('Recovery filter differs from the immutable crawl snapshot.');
        }

        return $task;
    }

    public function kind(ListingInput $input): AcquisitionKind
    {
        $row = LedgerRow::one('SELECT r.listing_kind FROM prc_crawl_runs r JOIN prc_crawl_blocks b ON b.crawl_run_id=r.id WHERE b.id=?',
            [$input->crawlBlockId->value]) ?? throw new \LogicException('Missing listing kind.');

        return AcquisitionKind::from($row->string('listing_kind'));
    }

    public function allocatedPartitions(ProcessUuid $blockId): int
    {
        return LedgerRow::one("SELECT count(DISTINCT partition_payload->>'rootBlockId') AS n FROM prc_crawl_blocks
            WHERE crawl_run_id=(SELECT crawl_run_id FROM prc_crawl_blocks WHERE id=?)
              AND limitation_reason_code IS DISTINCT FROM 'root_allocation_bound'", [$blockId->value])?->int('n') ?? 0;
    }

    public function detailReference(ProcessUuid $intentId): ?ListingDetailReference
    {
        $id = self::id('reference:'.$intentId->value);
        $row = LedgerRow::one(
            "SELECT procurement_id, source_version, result_ref::text AS reference
             FROM prc_detail_snapshots
             WHERE id = ? AND part_key = ? AND completeness = 'not_applicable' AND result_ref_schema_version = 1",
            [$id->value, $intentId->value.':reference'],
        );
        if ($row === null) {
            return null;
        }
        $reference = ListingDetailReference::fromJson($row->string('reference'));
        if ($reference->intentId->value !== $intentId->value
            || $reference->procurementId !== $row->int('procurement_id')
            || $reference->sourceRevision !== $row->nullableString('source_version')) {
            throw new \LogicException('Corrupted listing detail reference snapshot.');
        }

        return $reference;
    }

    public function detailReferenceForProcurement(int $procurementId): ?ListingDetailReference
    {
        $row = LedgerRow::one(
            "SELECT s.procurement_id, s.source_version, s.result_ref::text AS reference
             FROM prc_detail_snapshots s
             LEFT JOIN prc_operation_intents i
               ON i.id::text = split_part(s.part_key, ':', 1)
              AND i.subject_kind = 'prc_procurements'
              AND i.subject_id = s.procurement_id::text
             WHERE s.procurement_id = ?
               AND s.part_key LIKE '%:reference'
               AND s.completeness = 'not_applicable'
               AND s.result_ref_schema_version = 1
             ORDER BY s.input_revision DESC, i.cycle_seq DESC NULLS LAST, i.requested_at DESC NULLS LAST, s.created_at DESC, s.id DESC
             LIMIT 1",
            [$procurementId],
        );
        if ($row === null) {
            return null;
        }
        $reference = ListingDetailReference::fromJson($row->string('reference'));
        if ($reference->procurementId !== $row->int('procurement_id')
            || $reference->sourceRevision !== $row->nullableString('source_version')) {
            throw new \LogicException('Corrupted listing detail reference snapshot.');
        }

        return $reference;
    }

    public function storeTask(ProcessUuid $crawlRun, ProcessUuid $id, ?ProcessUuid $parent, ListingTask $task): ListingInput
    {
        $this->crawl->storeCrawlBlock($id, $crawlRun, $parent, 'listing:'.$task->rootBlockId->value.':'.$task->pass.':'.$task->page,
            new VersionedPayload($task->json(), 1));
        $row = LedgerRow::one("SELECT encode(filter_hash,'hex') AS filter_hash, encode(access_policy_hash,'hex') AS access_hash,
            adapter_version FROM prc_crawl_runs WHERE id=?", [$crawlRun->value]) ?? throw new \LogicException('Missing crawl run.');

        return new ListingInput($id, new ContentHash($row->string('filter_hash')), new ContentHash($row->string('access_hash')), $row->string('adapter_version'), 1);
    }

    /** @return list<ListingReceipt> */
    public function receipts(ListingTask $task): array
    {
        $rows = LedgerRow::all("SELECT terminal_proof::text AS proof FROM prc_crawl_blocks
            WHERE crawl_run_id=(SELECT crawl_run_id FROM prc_crawl_blocks WHERE id=?)
              AND partition_payload->>'rootBlockId'=? AND (partition_payload->>'pass')::int=?
              AND terminal_proof IS NOT NULL ORDER BY (partition_payload->>'page')::int LIMIT 13",
            [$task->rootBlockId->value, $task->rootBlockId->value, $task->pass]);
        if (count($rows) > 12) {
            throw new \LogicException('Listing page bound exceeded.');
        }

        return array_map(static fn (LedgerRow $r): ListingReceipt => ListingReceipt::fromJson($r->string('proof')), $rows);
    }

    /** @return list<ContentHash> */
    public function observedPages(ListingTask $task): array
    {
        return array_map(static fn (ListingReceipt $r): ContentHash => $r->identities, $this->receipts($task));
    }

    public function fullDigest(ListingTask $task, ListingReceipt $current): ContentHash
    {
        $prior = $this->receipts($task);
        if (count($prior) !== $task->page - 1) {
            throw new \LogicException('Missing page membership cannot prove a full pass.');
        }
        $hashes = [];
        foreach ([...$prior, $current] as $receipt) {
            foreach ($receipt->observations as $hash) {
                $hashes[] = $hash->hex;
            }
        }
        sort($hashes, SORT_STRING);

        return ContentHash::of(json_encode($hashes, JSON_THROW_ON_ERROR));
    }

    public function receipt(ListingInput $input, ListingReceipt $receipt): void
    {
        CallerTransaction::require('Listing receipt');
        if (DB::update('UPDATE prc_crawl_blocks SET terminal_proof=?::jsonb, terminal_proof_schema_version=1,
            limitation_reason_code=? WHERE id=? AND terminal_proof IS NULL',
            [$receipt->json(), $receipt->reason, $input->crawlBlockId->value]) !== 1) {
            throw new \LogicException('Listing page already finalized.');
        }
        foreach ($receipt->details as $reference) {
            $this->storeDetailReference($reference);
        }
    }

    /**
     * @return array{processed: int, inserted: int, next_block_id: ?ProcessUuid, next_ordinal: int, done: bool}
     */
    public function backfillDetailReferences(?ProcessUuid $afterBlockId = null, int $afterOrdinal = 0, int $limit = 500): array
    {
        CallerTransaction::require('Listing detail reference backfill');
        if ($limit < 1 || $limit > 5000 || $afterOrdinal < 0 || ($afterBlockId === null && $afterOrdinal > 0)) {
            throw new \InvalidArgumentException('Invalid listing detail reference backfill cursor or limit.');
        }

        $rows = $afterBlockId === null
            ? LedgerRow::all(
                "SELECT b.id AS block_id, d.ordinal::int AS ordinal, d.value AS reference
                 FROM prc_crawl_blocks b,
                      LATERAL jsonb_array_elements_text(COALESCE(b.terminal_proof->'details', '[]'::jsonb)) WITH ORDINALITY AS d(value, ordinal)
                 WHERE b.terminal_proof IS NOT NULL
                 ORDER BY b.id ASC, d.ordinal ASC
                 LIMIT ?",
                [$limit + 1],
            )
            : LedgerRow::all(
                "SELECT b.id AS block_id, d.ordinal::int AS ordinal, d.value AS reference
                 FROM prc_crawl_blocks b,
                      LATERAL jsonb_array_elements_text(COALESCE(b.terminal_proof->'details', '[]'::jsonb)) WITH ORDINALITY AS d(value, ordinal)
                 WHERE b.terminal_proof IS NOT NULL
                   AND (b.id > ?::uuid OR (b.id = ?::uuid AND d.ordinal > ?))
                 ORDER BY b.id ASC, d.ordinal ASC
                 LIMIT ?",
                [$afterBlockId->value, $afterBlockId->value, $afterOrdinal, $limit + 1],
            );

        $hasMore = count($rows) > $limit;
        $batch = array_slice($rows, 0, $limit);
        $processed = 0;
        $inserted = 0;
        $nextBlockId = $afterBlockId;
        $nextOrdinal = $afterOrdinal;

        foreach ($batch as $row) {
            $processed++;
            if ($this->storeDetailReference(ListingDetailReference::fromJson($row->string('reference')))) {
                $inserted++;
            }
            $nextBlockId = new ProcessUuid($row->string('block_id'));
            $nextOrdinal = $row->int('ordinal');
        }

        return [
            'processed' => $processed,
            'inserted' => $inserted,
            'next_block_id' => $nextBlockId,
            'next_ordinal' => $nextOrdinal,
            'done' => ! $hasMore,
        ];
    }

    private function storeDetailReference(ListingDetailReference $reference): bool
    {
        $meta = LedgerRow::one(
            "SELECT p.head_generation, i.expected_head_generation
             FROM prc_procurements p
             LEFT JOIN prc_operation_intents i ON i.id = ? AND i.subject_kind = 'prc_procurements' AND i.subject_id = p.id::text
             WHERE p.id = ?",
            [$reference->intentId->value, $reference->procurementId],
        ) ?? throw new \LogicException('Listing detail reference requires an existing procurement subject.');
        $revision = $meta->nullableInt('expected_head_generation') ?? $meta->int('head_generation');
        $id = self::id('reference:'.$reference->intentId->value);
        $partKey = $reference->intentId->value.':reference';
        $payload = $reference->json();
        $inserted = DB::affectingStatement(
            "INSERT INTO prc_detail_snapshots(id, procurement_id, input_revision, part_key, completeness, result_ref, result_ref_schema_version, source_version, created_at)
             VALUES (?, ?, ?, ?, 'not_applicable', ?::jsonb, 1, ?, clock_timestamp())
             ON CONFLICT DO NOTHING",
            [$id->value, $reference->procurementId, $revision, $partKey, $payload, $reference->sourceRevision],
        ) === 1;
        $stored = LedgerRow::one(
            'SELECT procurement_id, part_key, completeness, result_ref_schema_version, source_version, result_ref = ?::jsonb AS same_payload
             FROM prc_detail_snapshots WHERE id = ?',
            [$payload, $id->value],
        );
        if ($stored === null
            || $stored->int('procurement_id') !== $reference->procurementId
            || $stored->string('part_key') !== $partKey
            || $stored->string('completeness') !== 'not_applicable'
            || $stored->int('result_ref_schema_version') !== 1
            || $stored->nullableString('source_version') !== $reference->sourceRevision
            || ! $stored->bool('same_payload')) {
            throw new \LogicException('Immutable listing detail reference conflict.');
        }

        return $inserted;
    }

    /** Deterministic IDs also survive transaction retries; no random ID allocation under locks. */
    public static function id(string $material): ProcessUuid
    {
        $h = hash('sha256', 'listing:v1:'.$material);

        return new ProcessUuid(substr($h, 0, 8).'-'.substr($h, 8, 4).'-5'.substr($h, 13, 3).'-a'.substr($h, 17, 3).'-'.substr($h, 20, 12));
    }
}
