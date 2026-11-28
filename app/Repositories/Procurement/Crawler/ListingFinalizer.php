<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Crawler;

use App\Dto\Procurement\Acquisition\Fetch\ProcurementSummary;
use App\Dto\Procurement\Acquisition\Listing\ListingDetailReference;
use App\Dto\Procurement\Acquisition\Listing\ListingFacts;
use App\Dto\Procurement\Acquisition\Listing\ListingReceipt;
use App\Dto\Procurement\Acquisition\Listing\ListingResult;
use App\Dto\Procurement\Acquisition\Listing\ListingTask;
use App\Dto\Procurement\Process\Governance\TransitionData;
use App\Dto\Procurement\Process\Inputs\DetailInput;
use App\Dto\Procurement\Process\Inputs\ListingInput;
use App\Dto\Procurement\Process\Inputs\WorkInput;
use App\Dto\Procurement\Process\Outcomes\StageCheckpoint;
use App\Dto\Procurement\Process\Outcomes\ValidatedStageResult;
use App\Dto\Procurement\Process\Primitives\ContentHash;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Dto\Procurement\Process\Primitives\WorkId;
use App\Dto\Procurement\ProcurementSnapshotData;
use App\Enums\Procurement\Platform\TerminalProof;
use App\Enums\Procurement\Process\Core\StageCode;
use App\Enums\Procurement\Process\Governance\ActorKind;
use App\Enums\Procurement\Process\Outcomes\BlockedReason;
use App\Exceptions\Procurement\Parsing\ParsingRuleSetDisabledException;
use App\Exceptions\Procurement\Parsing\ParsingRuleSetUnavailableException;
use App\Models\Procurement\Procurement;
use App\Repositories\Procurement\Process\Projection\TransitionRepository;
use App\Repositories\Procurement\Process\Support\CallerTransaction;
use App\Repositories\Procurement\Process\Support\LedgerRow;
use App\Services\Procurement\Acquisition\Detail\HeadSnapshot;
use App\Services\Procurement\Acquisition\Detail\IdentityResolver;
use App\Services\Procurement\Acquisition\Detail\SnapshotFinalizer;
use App\Services\Procurement\Acquisition\Listing\ListingPublicationLocks;
use App\Services\Procurement\Acquisition\Orchestration\AcquisitionProvenance;
use App\Services\Procurement\Parsing\ParsingRuleSetResolver;
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
        $publication = $this->publication;
        try {
            $task = $result->task;
            $uninterrupted = $this->isBlockUninterrupted($input, $task);
            ['successors' => $successors, 'references' => $references] = $this->persistSummariesLocked($input, $result, $publication);
            // Run graph/budget authority is acquired in derived-domain tier, never before a work lock.
            $run = LedgerRow::one('SELECT r.id FROM prc_crawl_runs r JOIN prc_crawl_blocks b ON b.crawl_run_id=r.id WHERE b.id=? FOR UPDATE OF r',
                [$input->crawlBlockId->value]) ?? throw new \LogicException('Missing crawl run.');
            $crawlRun = new ProcessUuid($run->string('id'));
            ['outcome' => $outcome, 'reason' => $reason, 'next' => $next] = $this->decideNextCrawlStep($input, $result, $crawlRun, $uninterrupted);
            foreach ($next as [$id, $nextTask]) {
                $successors[$id->value] = $this->store->storeTask($crawlRun, $id, $input->crawlBlockId, $nextTask);
            }
            $this->store->receipt($input, ListingReceipt::page($result->page, $outcome, $reason, $uninterrupted, $references, $result->activeRulesHash));

            return array_values($successors);
        } finally {
            $this->publication = null;
        }
    }

    private function isBlockUninterrupted(ListingInput $input, ListingTask $task): bool
    {
        // Recovery clears the work reason, but the transition keeps the hold for that attempt.
        $uninterrupted = LedgerRow::one("SELECT NOT EXISTS (
            SELECT 1 FROM prc_process_attempts a JOIN prc_process_work w ON w.id=a.work_id
            WHERE w.crawl_block_id=? AND (
                a.terminal_kind='interrupted' OR a.fault_signature IS NOT NULL
                OR (a.terminal_kind='blocked' AND EXISTS (
                    SELECT 1 FROM prc_process_transitions t
                    WHERE t.work_id=a.work_id AND t.generation=a.generation
                      AND t.previous_state='running' AND t.new_state='blocked' AND t.reason_code=?
                ))
            )
        ) AS uninterrupted", [$input->crawlBlockId->value, BlockedReason::HandlerHold->value])?->bool('uninterrupted') ?? false;
        foreach ($this->store->receipts($task) as $prior) {
            $uninterrupted = $uninterrupted && $prior->uninterrupted;
        }

        return $uninterrupted;
    }

    /**
     * @return array{successors: array<string, WorkInput>, references: list<ListingDetailReference>}
     */
    private function persistSummariesLocked(ListingInput $input, ListingResult $result, ListingPublicationLocks $publication): array
    {
        $successors = [];
        $references = [];
        if (! $result->page->assessment->usable()) {
            return ['successors' => $successors, 'references' => $references];
        }
        $task = $result->task;
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
            $locked = $publication->locked[$i];
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
            $detail = $this->detailIntent($procurement->id, $procurement->head_generation, $input, $summary, $task->registryId);
            $successors[$detail->intentId->value] = $detail;
            $references[] = new ListingDetailReference($detail->intentId, $procurement->id,
                $summary->fingerprint, $summary->sourceRevision, $summary->detailLocator);
        }

        return ['successors' => $successors, 'references' => $references];
    }

    /**
     * @return array{outcome: string, reason: ?string, next: list<array{0: ProcessUuid, 1: ListingTask}>}
     */
    private function decideNextCrawlStep(ListingInput $input, ListingResult $result, ProcessUuid $crawlRun, bool $uninterrupted): array
    {
        $task = $result->task;
        $newHash = $result->activeRulesHash;
        $outcome = 'page';
        $reason = null;
        $next = [];
        if ($task->pass > 1 && $newHash !== null && $task->rulesHash !== null && $newHash !== $task->rulesHash && $task->ruleEpoch < 3) {
            $nextEpoch = $task->ruleEpoch + 1;
            $next[] = [
                ListingStoreRepository::id($task->rootBlockId->value.':e'.$nextEpoch.':1:1'),
                $task->withRuleEpoch($nextEpoch, $newHash),
            ];

            return ['outcome' => 'pass', 'reason' => null, 'next' => $next];
        }
        if ($result->split !== null) {
            $count = $this->store->allocatedPartitions($input->crawlBlockId);
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
                $nextTask = $task->next($cursor, rulesHash: $newHash);
                $epochSuffix = $nextTask->ruleEpoch > 0 ? ':e'.$nextTask->ruleEpoch : '';
                $next[] = [ListingStoreRepository::id($task->rootBlockId->value.$epochSuffix.':'.$nextTask->pass.':'.$nextTask->page), $nextTask];
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
                $nextTask = $task->next($task->rootCursor, true, $comparison, $newHash);
                $epochSuffix = $nextTask->ruleEpoch > 0 ? ':e'.$nextTask->ruleEpoch : '';
                $next[] = [ListingStoreRepository::id($task->rootBlockId->value.$epochSuffix.':'.$nextTask->pass.':1'), $nextTask];
            }
        }

        return ['outcome' => $outcome, 'reason' => $reason, 'next' => $next];
    }

    private function detailIntent(int $procurementId, int $head, ListingInput $input, ProcurementSummary $summary, int $registryId): DetailInput
    {
        $detailRulesHash = $this->activeDetailRulesHash($summary, $registryId);
        $semantic = $detailRulesHash !== null
            ? ContentHash::of($input->filterHash->hex.':'.$input->adapterVersion.':'.$detailRulesHash)
            : ContentHash::of($input->filterHash->hex.':'.$input->adapterVersion);
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

    private function activeDetailRulesHash(ProcurementSummary $summary, int $registryId): ?string
    {
        $platformCode = $summary->identity->platform->value;
        $row = LedgerRow::one(
            'SELECT r.platform_id, r.code AS registry_code FROM prc_registries r WHERE r.id = ?',
            [$registryId],
        );
        if ($row === null) {
            return null;
        }

        try {
            $resolved = app(ParsingRuleSetResolver::class)->resolveForPlatformId(
                platformId: $row->int('platform_id'),
                platformCode: $platformCode,
                stage: StageCode::Detail,
                registryCode: $row->string('registry_code'),
            );

            return $resolved?->rulesHashHex;
        } catch (ParsingRuleSetDisabledException|ParsingRuleSetUnavailableException) {
            return null;
        }
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
            $counts = LedgerRow::one("SELECT count(*) FILTER (WHERE b.terminal_proof IS NULL AND EXISTS (
                    SELECT 1 FROM prc_process_work lw WHERE lw.crawl_block_id = b.id
                        AND lw.state NOT IN ('succeeded', 'failed_item', 'cancelled', 'superseded')
                )) AS pending,
                count(*) FILTER (WHERE b.terminal_proof IS NULL OR b.terminal_proof->>'outcome' IN ('incomplete','observed')) AS debt
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
            $this->transitions->recordRun(new TransitionData(
                eventId: ListingStoreRepository::id($eventId->value.':'.$run->string('id')),
                projectionMessageId: ListingStoreRepository::id($projectionId->value.':'.$run->string('id')),
                entityId: new ProcessUuid($run->string('id')),
                entitySeq: $run->int('transition_seq') + 1,
                previousState: 'collecting',
                newState: $outcome,
                reasonCode: $reason,
                actor: ActorKind::Worker,
            ));
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
