<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Crawler;

use App\Dto\Procurement\Acquisition\ListingDetailReference;
use App\Dto\Procurement\Acquisition\ListingPlan;
use App\Dto\Procurement\Acquisition\ListingReceipt;
use App\Dto\Procurement\Acquisition\ListingTask;
use App\Dto\Procurement\Acquisition\ListingWindow;
use App\Dto\Procurement\Platform\CursorEnvelope;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\ListingInput;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\VersionedPayload;
use App\Enums\Procurement\Process\AcquisitionKind;
use App\Repositories\Procurement\Process\CallerTransaction;
use App\Repositories\Procurement\Process\LedgerRow;
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

    public function detailReference(ProcessUuid $intentId): ?ListingDetailReference
    {
        $row = LedgerRow::one("SELECT d.value AS reference FROM prc_crawl_blocks b,
            LATERAL jsonb_array_elements_text(COALESCE(b.terminal_proof->'details','[]'::jsonb)) d(value)
            WHERE d.value::jsonb->>'intent'=? ORDER BY b.id LIMIT 1", [$intentId->value]);

        return $row === null ? null : ListingDetailReference::fromJson($row->string('reference'));
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
    }

    /** Deterministic IDs also survive transaction retries; no random ID allocation under locks. */
    public static function id(string $material): ProcessUuid
    {
        $h = hash('sha256', 'listing:v1:'.$material);

        return new ProcessUuid(substr($h, 0, 8).'-'.substr($h, 8, 4).'-5'.substr($h, 13, 3).'-a'.substr($h, 17, 3).'-'.substr($h, 20, 12));
    }
}
