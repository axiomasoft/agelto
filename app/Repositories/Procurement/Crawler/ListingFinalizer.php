<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Crawler;

use App\Dto\Procurement\Acquisition\ListingDetailReference;
use App\Dto\Procurement\Acquisition\ListingFacts;
use App\Dto\Procurement\Acquisition\ListingReceipt;
use App\Dto\Procurement\Acquisition\ListingResult;
use App\Dto\Procurement\Acquisition\ProcurementSummary;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\DetailInput;
use App\Dto\Procurement\Process\ListingInput;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\StageCheckpoint;
use App\Dto\Procurement\Process\ValidatedStageResult;
use App\Dto\Procurement\Process\WorkId;
use App\Dto\Procurement\Process\WorkInput;
use App\Dto\Procurement\ProcurementSnapshotData;
use App\Enums\Procurement\Platform\TerminalProof;
use App\Enums\Procurement\Process\ActorKind;
use App\Models\Procurement\Procurement;
use App\Repositories\Procurement\Process\CallerTransaction;
use App\Repositories\Procurement\Process\LedgerRow;
use App\Repositories\Procurement\Process\TransitionRepository;
use App\Services\Procurement\Acquisition\AcquisitionProvenance;
use App\Services\Procurement\Acquisition\HeadSnapshot;
use App\Services\Procurement\Acquisition\IdentityResolver;
use App\Services\Procurement\Acquisition\ListingPublicationLocks;
use App\Services\Procurement\Acquisition\SnapshotFinalizer;
use App\Services\Procurement\Process\Contracts\PublicationLocks;
use App\Services\Procurement\Process\Contracts\StagePublicationRepository;
use App\Support\Database\PostgresAdvisoryLock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Invoked only by WorkFinalizer: summary, intents, page receipt, graph and run closure share its fence and transaction. */
final class ListingFinalizer implements StagePublicationRepository
{
    private ?ListingPublicationLocks $publication = null;

    public function __construct(private readonly ListingStoreRepository $store, private readonly SnapshotFinalizer $snapshots,
        private readonly IdentityResolver $identities, private readonly PostgresAdvisoryLock $locks,
        private readonly TransitionRepository $transitions, private readonly HeadSnapshot $heads) {}

    public function publicationLocks(WorkInput $input, ValidatedStageResult $result): PublicationLocks
    {
        $result = $this->result($input, $result);
        $identities = array_map(fn (ProcurementSummary $s) => $this->identities->resolve($result->task->registryId, $s->identity),
            $result->page->assessment->usable() ? $result->page->summaries->items : []);

        return $this->publication = new ListingPublicationLocks($identities, $this->locks, $this->snapshots);
    }

    public function storeProgress(WorkInput $input, StageCheckpoint $progress): void
    {
        throw new \LogicException('Listing page progress is committed with its receipt and successor.');
    }

    /** @return list<WorkInput> */
    public function storeResult(WorkInput $input, ValidatedStageResult $result): array
    {
        CallerTransaction::require('Listing finalization');
        $result = $this->result($input, $result);
        if (! $input instanceof ListingInput || $this->publication === null) {
            throw new \LogicException('Missing listing publication locks.');
        }
        $task = $result->task;
        $attempts = LedgerRow::one('SELECT count(*) AS n FROM prc_process_attempts a JOIN prc_process_work w ON w.id=a.work_id WHERE w.crawl_block_id=?',
            [$input->crawlBlockId->value])?->int('n') ?? 0;
        $uninterrupted = $attempts === 1;
        foreach ($this->store->receipts($task) as $prior) {
            $uninterrupted = $uninterrupted && $prior->uninterrupted;
        }
        $successors = [];
        $references = [];
        if ($result->page->assessment->usable()) {
            $ignored = DB::table('prc_ignored_items')->where('registry_id', $task->registryId)
                ->whereIn('registry_number', array_map(fn (ProcurementSummary $s) => $s->identity->value->value, $result->page->summaries->items))
                ->pluck('registry_number')->all();
            $seen = [];
            foreach ($result->page->summaries->items as $i => $summary) {
                $identityKey = json_encode($summary->identity->toArray(), JSON_THROW_ON_ERROR);
                if (isset($seen[$identityKey]) || in_array($summary->identity->value->value, $ignored, true)) {
                    continue;
                }
                $seen[$identityKey] = true;
                $locked = $this->publication->locked[$i];
                $head = $locked->procurement;
                $facts = $summary->facts;
                $extension = $head === null ? null : $this->heads->make($head, $summary->identity)->extension;
                $extension = $facts?->mergeExtension($extension) ?? $extension;
                $data = new ProcurementSnapshotData($task->registryId, $summary->identity, $summary->title, $summary->sourceStage,
                    Carbon::instance($summary->publishedAt), Carbon::instance($summary->updatedAt),
                    sourceUpdatedAtHasDayPrecision: $summary->updatedAtDayPrecision,
                    extension: $extension,
                    procedureType: $facts !== null && $facts->observes('procedureType') ? $facts->procedureType : $head?->procedure_type,
                    procedureTypeRaw: $facts !== null && $facts->observes('procedureTypeRaw') ? $facts->procedureTypeRaw : $head?->procedure_type_raw,
                    initialPrice: $summary->initialPrice, currency: $summary->currency,
                    advancePercent: $head?->advance_percent, okvedCode: $head?->okved_code, okvedAdditional: $head?->okved_additional,
                    regionCode: $head?->region_code,
                    biddingStartsAt: $facts !== null && $facts->observes('biddingStartsAt') ? ($facts->biddingStartsAt === null ? null : Carbon::instance($facts->biddingStartsAt)) : $head?->bidding_starts_at,
                    biddingEndsAt: $facts !== null && $facts->observes('biddingEndsAt') ? ($facts->biddingEndsAt === null ? null : Carbon::instance($facts->biddingEndsAt)) : $head?->bidding_ends_at,
                    summaryAt: $head?->summary_at, detailUrl: $head?->detail_url,
                    attributes: $this->attributes($head, $facts),
                    sourceUpdatedBefore: $summary->updatedBefore === null ? null : Carbon::instance($summary->updatedBefore));
                $procurement = $this->snapshots->persistLocked($data, $locked,
                    processRunId: AcquisitionProvenance::runFor($input), observedAt: Carbon::instance($result->observedAt));
                $detail = $this->detailIntent($procurement->id, $procurement->head_generation, $input, $summary);
                $successors[$detail->intentId->value] = $detail;
                $references[] = new ListingDetailReference($detail->intentId, $procurement->id,
                    $summary->fingerprint, $summary->sourceRevision, $summary->detailLocator);
            }
        }
        // Run graph/budget authority is acquired in derived-domain tier, never before a work lock.
        $run = LedgerRow::one('SELECT r.id FROM prc_crawl_runs r JOIN prc_crawl_blocks b ON b.crawl_run_id=r.id WHERE b.id=? FOR UPDATE OF r',
            [$input->crawlBlockId->value]) ?? throw new \LogicException('Missing crawl run.');
        $crawlRun = new ProcessUuid($run->string('id'));
        $outcome = 'page';
        $reason = null;
        $next = [];
        if ($result->split !== null) {
            $count = LedgerRow::one("SELECT count(DISTINCT partition_payload->>'rootBlockId') AS n FROM prc_crawl_blocks
                WHERE crawl_run_id=? AND limitation_reason_code IS DISTINCT FROM 'root_allocation_bound'", [$crawlRun->value])?->int('n') ?? 0;
            if ($result->split->children === [] || $count + count($result->split->children) > $task->maxPartitions) {
                $outcome = 'incomplete';
                $reason = 'listing_cap_unresolved';
            } else {
                $outcome = 'split';
                foreach ($result->split->children as $cursor) {
                    $id = ListingStoreRepository::id($crawlRun->value.':'.$cursor->payloadHash->hex);
                    $next[] = [$id, $task->child($cursor, $id)];
                }
            }
        } elseif ($result->page->terminalProof === TerminalProof::None) {
            if ($task->page >= 12) {
                $outcome = 'incomplete';
                $reason = 'listing_cap_unresolved';
            } else {
                $cursor = $result->page->nextCursor ?? throw new \LogicException('Missing next cursor.');
                if ($cursor->payloadHash->hex === $task->cursor->payloadHash->hex) {
                    throw new \LogicException('Non-progressing listing cursor.');
                }
                $nextTask = $task->next($cursor);
                $next[] = [ListingStoreRepository::id($task->rootBlockId->value.':'.$nextTask->pass.':'.$nextTask->page), $nextTask];
            }
        } else {
            $digest = $this->store->fullDigest($task, ListingReceipt::page($result->page, 'pass'));
            $closed = LedgerRow::one('SELECT clock_timestamp() >= ?::timestamptz AS closed', [$task->closesAt])?->bool('closed') ?? false;
            if (! $closed) {
                $outcome = 'observed';
            } elseif ($uninterrupted && $task->previousPass?->hex === $digest->hex) {
                $outcome = 'complete';
            } elseif ($task->pass === 3) {
                $outcome = 'incomplete';
                $reason = 'unstable_source';
            } else {
                $outcome = 'pass';
                // Resumed offset passes are best-observed, never the first half of stability proof.
                $comparison = $uninterrupted ? $digest : ContentHash::of('interrupted:'.$task->rootBlockId->value.':'.$task->pass);
                $nextTask = $task->next($task->rootCursor, true, $comparison);
                $next[] = [ListingStoreRepository::id($task->rootBlockId->value.':'.$nextTask->pass.':1'), $nextTask];
            }
        }
        foreach ($next as [$id, $nextTask]) {
            $successors[$id->value] = $this->store->storeTask($crawlRun, $id, $input->crawlBlockId, $nextTask);
        }
        $this->store->receipt($input, ListingReceipt::page($result->page, $outcome, $reason, $uninterrupted, $references));

        return array_values($successors);
    }

    private function detailIntent(int $procurementId, int $head, ListingInput $input, ProcurementSummary $summary): DetailInput
    {
        $semantic = ContentHash::of($input->filterHash->hex.':'.$input->adapterVersion);
        $key = ContentHash::of(json_encode([$procurementId, $summary->sourceRevision, $summary->fingerprint->hex,
            $semantic->hex, $input->accessPolicyHash->hex], JSON_THROW_ON_ERROR));
        $id = ListingStoreRepository::id('detail:'.$key->hex);
        DB::insert("INSERT INTO prc_operation_intents
            (id,intent_key,stage,subject_kind,subject_id,semantic_policy_hash,access_policy_hash,expected_head_generation,state,requested_at)
            VALUES (?,decode(?,'hex'),'detail','prc_procurements',?,decode(?,'hex'),decode(?,'hex'),?,
            CASE WHEN EXISTS(SELECT 1 FROM prc_operation_intents WHERE stage='detail' AND subject_kind='prc_procurements'
                AND subject_id=? AND state='active') THEN 'pending' ELSE 'active' END,clock_timestamp())
            ON CONFLICT (intent_key) DO NOTHING", [$id->value, $key->hex, (string) $procurementId, $semantic->hex,
            $input->accessPolicyHash->hex, $head, (string) $procurementId]);
        $row = LedgerRow::one("SELECT id, expected_head_generation FROM prc_operation_intents WHERE intent_key=decode(?,'hex')", [$key->hex])
            ?? throw new \LogicException('Missing detail intent.');

        return new DetailInput($procurementId, new ProcessUuid($row->string('id')), $row->int('expected_head_generation'),
            $semantic, $input->accessPolicyHash, $input->adapterVersion);
    }

    /** @return array<string, mixed>|null */
    private function attributes(?Procurement $head, ?ListingFacts $facts): ?array
    {
        $attributes = $head?->getAttribute('attributes');
        if ($attributes !== null && ! $attributes instanceof Collection) {
            throw new \LogicException('Invalid canonical diagnostic attributes.');
        }
        $result = [];
        foreach ($attributes ?? [] as $key => $value) {
            if (! is_string($key)) {
                throw new \LogicException('Invalid diagnostic attribute key.');
            }
            $result[$key] = $value;
        }
        if ($facts !== null && $facts->observes('customerName')) {
            if ($facts->customerName === null) {
                unset($result['customer_name']);
            } else {
                $result['customer_name'] = $facts->customerName;
            }
        }

        return $result === [] && $attributes === null ? null : $result;
    }

    public function afterSuccessors(WorkInput $input, WorkId $workId, ProcessUuid $eventId, ProcessUuid $projectionId): void
    {
        if (! $input instanceof ListingInput) {
            throw new \InvalidArgumentException('Listing input required.');
        }
        $runs = LedgerRow::all('SELECT r.id, r.transition_seq FROM prc_process_runs r JOIN prc_process_run_work rw ON rw.run_id=r.id
            WHERE rw.work_id=? AND rw.accounting_role=\'required\' AND r.cancel_requested_at IS NULL AND r.discovery_outcome=\'collecting\'
            ORDER BY r.id FOR UPDATE OF r', [$workId->value->value]);
        foreach ($runs as $run) {
            // Required listing graph can span shared runs. Every unfinished registered page retains discovery.
            $counts = LedgerRow::one("SELECT count(*) FILTER (WHERE b.terminal_proof IS NULL) AS pending,
                count(*) FILTER (WHERE b.terminal_proof->>'outcome' IN ('incomplete','observed')) AS debt
                FROM prc_crawl_blocks b WHERE b.crawl_run_id IN (
                    SELECT DISTINCT cb.crawl_run_id FROM prc_process_run_work rw JOIN prc_process_work w ON w.id=rw.work_id
                    JOIN prc_crawl_blocks cb ON cb.id=w.crawl_block_id WHERE rw.run_id=? AND rw.accounting_role='required')", [$run->string('id')]);
            if ($counts === null || $counts->int('pending') > 0) {
                continue;
            }
            $outcome = $counts->int('debt') > 0 ? 'incomplete' : 'complete';
            $reason = $outcome === 'incomplete' ? 'listing_coverage_debt' : null;
            DB::update('UPDATE prc_process_runs SET discovery_outcome=?, discovery_reason_code=?, discovery_closed_at=clock_timestamp(),
                transition_seq=transition_seq+1 WHERE id=?', [$outcome, $reason, $run->string('id')]);
            $this->transitions->recordRun(ListingStoreRepository::id($eventId->value.':'.$run->string('id')),
                ListingStoreRepository::id($projectionId->value.':'.$run->string('id')), new ProcessUuid($run->string('id')),
                $run->int('transition_seq') + 1, 'collecting', $outcome, $reason, ActorKind::Worker);
        }
    }

    private function result(WorkInput $input, ValidatedStageResult $result): ListingResult
    {
        if (! $input instanceof ListingInput || ! $result instanceof ListingResult
            || $this->store->task($input)->json() !== $result->task->json()) {
            throw new \InvalidArgumentException('Listing result does not match its immutable assignment.');
        }

        return $result;
    }
}
