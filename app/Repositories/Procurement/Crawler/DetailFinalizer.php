<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Crawler;

use App\Dto\Procurement\Acquisition\DetailProgress;
use App\Dto\Procurement\Acquisition\DetailResult;
use App\Dto\Procurement\Acquisition\LockedProcurement;
use App\Dto\Procurement\Acquisition\ProcurementSnapshot;
use App\Dto\Procurement\Acquisition\RefreshPolicy;
use App\Dto\Procurement\Details\CommonInfoData;
use App\Dto\Procurement\Details\CommonInfoSnapshot;
use App\Dto\Procurement\Process\DetailInput;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\StageCheckpoint;
use App\Dto\Procurement\Process\ValidatedStageResult;
use App\Dto\Procurement\Process\WorkId;
use App\Dto\Procurement\Process\WorkInput;
use App\Enums\Procurement\DetailSnapshotStatus;
use App\Enums\Procurement\Platform\Completeness;
use App\Enums\Procurement\Process\BlockedReason;
use App\Exceptions\Procurement\Process\PublicationBlocked;
use App\Models\Procurement\Procurement;
use App\Repositories\Procurement\Document\DocumentCatalogRepository;
use App\Repositories\Procurement\Document\DocumentReferenceLocks;
use App\Repositories\Procurement\Process\CallerTransaction;
use App\Repositories\Procurement\Process\LedgerRow;
use App\Services\Procurement\Acquisition\AcquisitionProvenance;
use App\Services\Procurement\Acquisition\DetailValueCodec;
use App\Services\Procurement\Acquisition\HeadSnapshot;
use App\Services\Procurement\Acquisition\IdentityResolver;
use App\Services\Procurement\Acquisition\SnapshotFinalizer;
use App\Services\Procurement\Acquisition\UnmappedStatusReporter;
use App\Services\Procurement\Detail\CommonInfoProcessor;
use App\Services\Procurement\Detail\EventJournalProcessor;
use App\Services\Procurement\Detail\SupplierResultsProcessor;
use App\Services\Procurement\Process\Contracts\PublicationLocks;
use App\Services\Procurement\Process\Contracts\StagePublicationPreparation;
use App\Services\Procurement\Process\Contracts\StagePublicationRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final readonly class DetailFinalizer implements StagePublicationPreparation, StagePublicationRepository
{
    public function __construct(private DetailSnapshotRepository $parts, private SnapshotFinalizer $snapshots,
        private IdentityResolver $identities, private HeadSnapshot $heads, private CommonInfoProcessor $common,
        private EventJournalProcessor $events, private SupplierResultsProcessor $results,
        private UnmappedStatusReporter $unknown, private DetailValueCodec $codec, private RefreshPolicy $policy,
        private DocumentCatalogRepository $documents) {}

    public function preparePublication(ValidatedStageResult $result): void
    {
        if (! $result instanceof DetailResult) {
            throw new \InvalidArgumentException('Detail result required.');
        }
        $snapshot = $result->snapshot;
        $complete = $this->completeParts($snapshot);
        $results = isset($complete['results']) ? $snapshot->results : null;
        // Older checkpoints can contain flattened bids that were incorrectly marked complete.
        if ($results?->hasMultipleWinners()) {
            throw new PublicationBlocked(BlockedReason::PolicyReview);
        }
        if (isset($complete['events'])) {
            $this->events->ensurePartitions($snapshot->events);
        }
        if ($results !== null) {
            $this->results->ensurePartitions(data: $results, observedAt: Carbon::instance($result->observedAt));
        }
    }

    public function publicationLocks(WorkInput $input, ValidatedStageResult $result): PublicationLocks
    {
        if (! $input instanceof DetailInput || ! $result instanceof DetailResult) {
            throw new \InvalidArgumentException('Detail result required.');
        }
        $p = Procurement::query()->findOrFail($input->procurementId);

        return new DocumentReferenceLocks($p->platform_id, $p->id, $result->snapshot->documents);
    }

    public function afterSuccessors(WorkInput $input, WorkId $workId,
        ProcessUuid $eventId, ProcessUuid $projectionId): void {}

    public function storeProgress(WorkInput $input, StageCheckpoint $progress): void
    {
        if (! $input instanceof DetailInput || ! $progress instanceof DetailProgress) {
            throw new \InvalidArgumentException('Detail checkpoint required.');
        }
        $this->parts->progress($input, $progress);
    }

    public function storeResult(WorkInput $input, ValidatedStageResult $result): array
    {
        CallerTransaction::require('Fenced detail publication');
        if (! $input instanceof DetailInput || ! $result instanceof DetailResult) {
            throw new \InvalidArgumentException('Detail result required.');
        }
        $p = Procurement::query()->findOrFail($input->procurementId);
        $request = $this->parts->request($p->id, $input->intentId);
        if ($request->identity->toArray() !== $result->snapshot->identity->toArray()) {
            throw new \InvalidArgumentException('Detail result identity mismatch.');
        }
        $identity = $this->identities->resolve($p->registry_id, $request->identity);
        $tx = LedgerRow::one('SELECT txid_current()::text AS id') ?? throw new \LogicException('Missing tx');
        $s = $result->snapshot;
        $complete = $this->completeParts($s);
        $observedAt = Carbon::instance($result->observedAt);
        $hasAttributes = isset($complete['attributes']);
        $p = $this->snapshots->persistLocked($this->heads->make(
            p: $p,
            identity: $s->identity,
            title: $hasAttributes ? $s->title : null,
            stage: $hasAttributes ? $s->sourceStage : null,
            extension: $hasAttributes ? $s->extension : null,
            initialPrice: $hasAttributes ? $s->initialPrice : null,
        ),
            new LockedProcurement($identity, $p, $tx->string('id')),
            processRunId: AcquisitionProvenance::runFor($input), observedAt: $observedAt);
        $this->common->process($p, new CommonInfoSnapshot(isset($complete['attributes']) ? DetailSnapshotStatus::Complete : DetailSnapshotStatus::Incomplete,
            new CommonInfoData(extension: $s->extension, financingRows: $s->financing, items: $s->items), $s->parties, isset($complete['parties'])));
        if (isset($complete['events'])) {
            $this->events->storePrepared(procurement: $p, entries: $s->events);
        }
        if ($s->results !== null && isset($complete['results'])) {
            $this->results->storePrepared(procurement: $p, data: $s->results, observedAt: $observedAt);
        }
        $references = [];
        foreach ($s->documents as $reference) {
            $references[] = $this->documents->upsertReference($p, $reference, $input->accessPolicyHash)->reference->value;
        }
        if (isset($complete['documents'])) {
            $this->documents->reconcile($p->id, $references);
        }
        $activeIntentId = LedgerRow::one(
            "SELECT intent_id FROM prc_process_work WHERE procurement_id=? AND stage='detail' AND state='running' AND intent_id IS NOT NULL ORDER BY updated_at DESC, id DESC LIMIT 1",
            [$p->id],
        )?->string('intent_id') ?? $input->intentId->value;
        $this->parts->progress($input, $result->progress);
        if ($activeIntentId !== $input->intentId->value) {
            $this->parts->saveRequest($p->id, $input->expectedHeadGeneration, new ProcessUuid($activeIntentId), $request);
        }
        $this->parts->insert($p->id, $input->expectedHeadGeneration, $activeIntentId.':published', 'complete', $this->codec->encode($result), $s->sourceRevision);
        $this->unknown->record($p);
        if (DB::update("UPDATE prc_operation_intents SET state='terminal', next_due_at=clock_timestamp()+(? * interval '1 second') WHERE id=? AND state='active'", [$this->policy->detailSeconds, $activeIntentId]) !== 1) {
            throw new \LogicException('Detail intent fence failed.');
        }
        DB::update('UPDATE prc_procurements SET next_status_due_at=COALESCE(next_status_due_at,clock_timestamp()) WHERE id=?', [$p->id]);

        return [];
    }

    /** @return array<string, true> */
    private function completeParts(ProcurementSnapshot $snapshot): array
    {
        $complete = [];
        foreach ($snapshot->parts as $part) {
            if (in_array($part->completeness, [Completeness::Complete, Completeness::CompleteEmpty], true)) {
                $complete[$part->name] = true;
            }
        }

        return $complete;
    }
}
