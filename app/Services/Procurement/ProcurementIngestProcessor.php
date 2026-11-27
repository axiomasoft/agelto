<?php

declare(strict_types=1);

namespace App\Services\Procurement;

use App\Dto\Procurement\Acquisition\Control\ProcurementIdentity;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Dto\Procurement\ProcurementSnapshotData;
use App\Enums\Procurement\ProcurementEventType;
use App\Enums\Telemetry\LogChannel;
use App\Models\Procurement\Procurement;
use App\Repositories\Procurement\Process\Support\CallerTransaction;
use App\Repositories\Procurement\ProcurementReadRepository;
use App\Repositories\Procurement\ProcurementStoreRepository;
use Illuminate\Support\Carbon;

/**
 * Закупка обнаружена/изменена → ревизия + события (`01-database.md` §11, чек-лист сценариев).
 * Идемпотентность — по `snapshot_hash`: повторный вызов с тем же нормализованным снапшотом
 * не создаёт вторую ревизию (только `last_seen_at`-touch), см. `07-carryover-from-v1.md` §1
 * «Идемпотентная джоба» — тот же принцип guard-по-состоянию, применённый на уровне вызова ingest.
 */
final readonly class ProcurementIngestProcessor
{
    public function __construct(
        private ProcurementReadRepository $procurements,
        private ProcurementStoreRepository $store,
        private StatusMappingService $statusMapping,
        private ProcurementOutcomeEvaluator $outcomeEvaluator,
    ) {}

    /** Caller owns the identity/subject locks and the fenced finalization transaction. */
    public function ingestLocked(ProcurementSnapshotData $data, ProcurementIdentity $identity,
        ?Procurement $procurement, ?ProcessUuid $processRunId = null, ?Carbon $observedAt = null): Procurement
    {
        CallerTransaction::require('Canonical ingestion');
        $observedAt ??= Carbon::now();
        $now = Carbon::now();
        $resolution = $this->statusMapping->resolve(registryId: $data->registryId, sourceStage: $data->sourceStage);
        $snapshot = $data->toSnapshotArray(canonicalStatus: $resolution->status->value);
        $snapshotHash = hash('sha256', json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $procurement ??= $this->store->storeProcurement($data, $resolution->status->value, $now, $identity);
        $procurement = $procurement->wasRecentlyCreated
            ? $this->discover($data, $resolution->status->value, $snapshot, $snapshotHash, $processRunId, $now, $observedAt, $procurement)
            : $this->applyRevision($procurement, $data, $resolution->status->value, $snapshot, $snapshotHash, $processRunId, $now, $observedAt);
        $this->outcomeEvaluator->evaluate(procurement: $procurement);

        return $procurement;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function discover(
        ProcurementSnapshotData $data,
        string $status,
        array $snapshot,
        string $snapshotHash,
        ?ProcessUuid $processRunId,
        Carbon $now,
        Carbon $observedAt,
        Procurement $procurement,
    ): Procurement {

        $revision = $this->store->storeRevision(
            procurementId: $procurement->id,
            revisionNumber: 1,
            snapshot: $snapshot,
            snapshotHash: $snapshotHash,
            detectedAt: $now,
            sourceUpdatedAt: $data->sourceUpdatedAt,
            processRunId: $processRunId,
        );

        $this->store->updateProcurementHead(
            procurement: $procurement,
            data: $data,
            status: $status,
            revision: $revision,
            revisionCount: 1,
            seenAt: $now,
            observedAt: $observedAt,
        );

        $this->store->storeEvent(
            procurementId: $procurement->id,
            eventType: ProcurementEventType::Discovered,
            occurredAt: $now,
            revisionId: $revision->id,
        );

        $this->storeInitialStatusEvent(
            procurementId: $procurement->id,
            revisionId: $revision->id,
            occurredAt: $now,
            status: $status,
        );

        return $procurement->refresh();
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function applyRevision(
        Procurement $procurement,
        ProcurementSnapshotData $data,
        string $status,
        array $snapshot,
        string $snapshotHash,
        ?ProcessUuid $processRunId,
        Carbon $now,
        Carbon $observedAt,
    ): Procurement {
        $latestRevision = $this->procurements->findLatestRevision(procurementId: $procurement->id);

        if ($latestRevision !== null && $latestRevision->snapshot_hash === $snapshotHash) {
            $this->store->recordObservation(procurement: $procurement, data: $data, observedAt: $observedAt);
            $this->store->touchLastSeen(procurement: $procurement, seenAt: $now);

            return $procurement;
        }

        if ($this->isOlderThanHead(procurement: $procurement, data: $data, observedAt: $observedAt)) {
            LogChannel::Procurement->info(message: 'ProcurementIngestProcessor: снапшот старше текущей головы закупки пропущен.', context: [
                'procurement_id' => $procurement->id,
                'head_source_updated_at' => $procurement->source_updated_at?->toIso8601String(),
                'snapshot_source_updated_at' => $data->sourceUpdatedAt->toIso8601String(),
            ]);
            $this->store->touchLastSeen(procurement: $procurement, seenAt: $now);

            return $procurement;
        }

        $latestRevisionNumber = $latestRevision === null
            ? 0
            : $latestRevision->revision_number;

        if ($procurement->revision_count !== $latestRevisionNumber) {
            throw new \LogicException("Procurement {$procurement->id} has an inconsistent revision head.");
        }

        $revisionNumber = $latestRevisionNumber + 1;

        $revision = $this->store->storeRevision(
            procurementId: $procurement->id,
            revisionNumber: $revisionNumber,
            snapshot: $snapshot,
            snapshotHash: $snapshotHash,
            detectedAt: $now,
            sourceUpdatedAt: $data->sourceUpdatedAt,
            processRunId: $processRunId,
        );

        $this->store->updateProcurementHead(
            procurement: $procurement,
            data: $data,
            status: $status,
            revision: $revision,
            revisionCount: $revisionNumber,
            seenAt: $now,
            observedAt: $observedAt,
        );

        $this->store->storeEvent(
            procurementId: $procurement->id,
            eventType: ProcurementEventType::RevisionPublished,
            occurredAt: $now,
            revisionId: $revision->id,
        );

        $oldSnapshot = $latestRevision === null ? [] : $latestRevision->snapshot;
        $this->storeDiffEvents(procurementId: $procurement->id, revisionId: $revision->id, occurredAt: $now, oldSnapshot: $oldSnapshot, newSnapshot: $snapshot);

        return $procurement->refresh();
    }

    /**
     * Disjoint source intervals establish order. Overlapping intervals use capture time,
     * never ingestion/lock order. This is an observation policy, not a claim that the source
     * supplied an exact revision timestamp; the persisted ambiguity flag makes that explicit.
     */
    private function isOlderThanHead(Procurement $procurement, ProcurementSnapshotData $data, Carbon $observedAt): bool
    {
        $head = $procurement->source_updated_at;
        if ($head === null) {
            return false;
        }
        $end = $data->sourceUpdatedBefore ?? ($data->sourceUpdatedAtHasDayPrecision ? $data->sourceUpdatedAt->copy()->addDay() : $data->sourceUpdatedAt);
        $headEnd = $procurement->getAttribute('source_updated_before');
        $headEnd = $headEnd instanceof Carbon ? $headEnd : $head;
        if ($end->lt($head) || ($data->sourceUpdatedAtHasDayPrecision && $end->eq($head))) {
            return true;
        }
        if ($data->sourceUpdatedAt->gt($headEnd) || ($headEnd->gt($head) && $data->sourceUpdatedAt->eq($headEnd))) {
            return false;
        }
        if (! $data->sourceUpdatedAtHasDayPrecision && $procurement->getAttribute('source_updated_has_day_precision') !== true) {
            return false;
        }
        $headObserved = $procurement->getAttribute('source_observed_at');

        return $headObserved instanceof Carbon && $observedAt->lt($headObserved);
    }

    /**
     * @param  array<string, mixed>  $oldSnapshot
     * @param  array<string, mixed>  $newSnapshot
     */
    private function storeDiffEvents(int $procurementId, int $revisionId, Carbon $occurredAt, array $oldSnapshot, array $newSnapshot): void
    {
        if (($oldSnapshot['status'] ?? null) !== $newSnapshot['status']) {
            $this->store->storeEvent(
                procurementId: $procurementId,
                eventType: ProcurementEventType::StatusChanged,
                occurredAt: $occurredAt,
                revisionId: $revisionId,
                field: 'status',
                oldValue: $this->nullableString($oldSnapshot['status'] ?? null),
                newValue: $this->nullableString($newSnapshot['status'] ?? null),
            );
        }

        if (($oldSnapshot['initial_price'] ?? null) !== $newSnapshot['initial_price']) {
            $this->store->storeEvent(
                procurementId: $procurementId,
                eventType: ProcurementEventType::PriceChanged,
                occurredAt: $occurredAt,
                revisionId: $revisionId,
                field: 'initial_price',
                oldValue: $this->nullableString($oldSnapshot['initial_price'] ?? null),
                newValue: $this->nullableString($newSnapshot['initial_price'] ?? null),
            );
        }

        $dateFields = ['bidding_starts_at', 'bidding_ends_at', 'summary_at'];
        $changedDateFields = array_filter($dateFields, fn (string $field): bool => ($oldSnapshot[$field] ?? null) !== $newSnapshot[$field]);

        if ($changedDateFields !== []) {
            $this->store->storeEvent(
                procurementId: $procurementId,
                eventType: ProcurementEventType::DatesChanged,
                occurredAt: $occurredAt,
                revisionId: $revisionId,
                payload: array_intersect_key($newSnapshot, array_flip($changedDateFields)),
            );
        }
    }

    /** The first observed status uses the same event schema as all later transitions. */
    private function storeInitialStatusEvent(int $procurementId, int $revisionId, Carbon $occurredAt, string $status): void
    {
        $this->store->storeEvent(
            procurementId: $procurementId,
            eventType: ProcurementEventType::StatusChanged,
            occurredAt: $occurredAt,
            revisionId: $revisionId,
            field: 'status',
            newValue: $status,
        );
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || is_string($value)) {
            return $value;
        }

        throw new \LogicException('Snapshot event value must be a string or null.');
    }
}
