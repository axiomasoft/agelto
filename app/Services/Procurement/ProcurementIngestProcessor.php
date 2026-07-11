<?php

declare(strict_types=1);

namespace App\Services\Procurement;

use App\Dto\Procurement\ProcurementSnapshotData;
use App\Enums\Procurement\ProcurementEventType;
use App\Models\Procurement\Procurement;
use App\Repositories\Procurement\ProcurementReadRepository;
use App\Repositories\Procurement\ProcurementStoreRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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
    ) {}

    public function ingest(ProcurementSnapshotData $data, ?int $crawlerRunId = null): Procurement
    {
        return DB::transaction(function () use ($data, $crawlerRunId): Procurement {
            $now = Carbon::now();
            $resolution = $this->statusMapping->resolve(registryId: $data->registryId, sourceStage: $data->sourceStage);
            $snapshot = $data->toSnapshotArray(canonicalStatus: $resolution->status->value);
            $snapshotHash = hash('sha256', json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            $procurement = $this->procurements->findProcurement(
                registryId: $data->registryId,
                registryNumber: $data->registryNumber,
            );

            if ($procurement === null) {
                return $this->discover(
                    data: $data,
                    status: $resolution->status->value,
                    isUnknownStatus: $resolution->isNewlyMapped,
                    snapshot: $snapshot,
                    snapshotHash: $snapshotHash,
                    crawlerRunId: $crawlerRunId,
                    now: $now,
                );
            }

            return $this->applyRevision(
                procurement: $procurement,
                data: $data,
                status: $resolution->status->value,
                isUnknownStatus: $resolution->isNewlyMapped,
                snapshot: $snapshot,
                snapshotHash: $snapshotHash,
                crawlerRunId: $crawlerRunId,
                now: $now,
            );
        });
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function discover(
        ProcurementSnapshotData $data,
        string $status,
        bool $isUnknownStatus,
        array $snapshot,
        string $snapshotHash,
        ?int $crawlerRunId,
        Carbon $now,
    ): Procurement {
        $procurement = $this->store->storeProcurement(data: $data, status: $status, seenAt: $now);

        $revision = $this->store->storeRevision(
            procurementId: $procurement->id,
            revisionNumber: 1,
            snapshot: $snapshot,
            snapshotHash: $snapshotHash,
            detectedAt: $now,
            sourceUpdatedAt: $data->sourceUpdatedAt,
            crawlerRunId: $crawlerRunId,
        );

        $this->store->updateProcurementHead(
            procurement: $procurement,
            data: $data,
            status: $status,
            revision: $revision,
            revisionCount: 1,
            seenAt: $now,
        );

        $this->store->storeEvent(
            procurementId: $procurement->id,
            eventType: ProcurementEventType::Discovered,
            occurredAt: $now,
            revisionId: $revision->id,
        );

        if ($isUnknownStatus) {
            $this->storeUnknownStatusEvent(procurementId: $procurement->id, revisionId: $revision->id, occurredAt: $now, oldStatus: null, newStatus: $status);
        }

        return $procurement->refresh();
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function applyRevision(
        Procurement $procurement,
        ProcurementSnapshotData $data,
        string $status,
        bool $isUnknownStatus,
        array $snapshot,
        string $snapshotHash,
        ?int $crawlerRunId,
        Carbon $now,
    ): Procurement {
        $latestRevision = $this->procurements->findLatestRevision(procurementId: $procurement->id);

        if ($latestRevision !== null && $latestRevision->snapshot_hash === $snapshotHash) {
            $this->store->touchLastSeen(procurement: $procurement, seenAt: $now);

            return $procurement;
        }

        $revisionNumber = $procurement->revision_count + 1;

        $revision = $this->store->storeRevision(
            procurementId: $procurement->id,
            revisionNumber: $revisionNumber,
            snapshot: $snapshot,
            snapshotHash: $snapshotHash,
            detectedAt: $now,
            sourceUpdatedAt: $data->sourceUpdatedAt,
            crawlerRunId: $crawlerRunId,
        );

        $this->store->updateProcurementHead(
            procurement: $procurement,
            data: $data,
            status: $status,
            revision: $revision,
            revisionCount: $revisionNumber,
            seenAt: $now,
        );

        $this->store->storeEvent(
            procurementId: $procurement->id,
            eventType: ProcurementEventType::RevisionPublished,
            occurredAt: $now,
            revisionId: $revision->id,
        );

        $oldSnapshot = $latestRevision?->snapshot ?? [];
        $this->storeDiffEvents(procurementId: $procurement->id, revisionId: $revision->id, occurredAt: $now, oldSnapshot: $oldSnapshot, newSnapshot: $snapshot);

        if ($isUnknownStatus) {
            $this->storeUnknownStatusEvent(procurementId: $procurement->id, revisionId: $revision->id, occurredAt: $now, oldStatus: $oldSnapshot['status'] ?? null, newStatus: $status);
        }

        return $procurement->refresh();
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
                oldValue: $oldSnapshot['status'] ?? null,
                newValue: $newSnapshot['status'],
            );
        }

        if (($oldSnapshot['initial_price'] ?? null) !== $newSnapshot['initial_price']) {
            $this->store->storeEvent(
                procurementId: $procurementId,
                eventType: ProcurementEventType::PriceChanged,
                occurredAt: $occurredAt,
                revisionId: $revisionId,
                field: 'initial_price',
                oldValue: $oldSnapshot['initial_price'] ?? null,
                newValue: $newSnapshot['initial_price'],
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

    /**
     * Незнакомая пара (registry_id, source_stage), заведённая `StatusMappingService` только что
     * (см. Decision Log D7) — переиспользуем `status_changed`, отдельный `event_type` не заводим.
     */
    private function storeUnknownStatusEvent(int $procurementId, int $revisionId, Carbon $occurredAt, ?string $oldStatus, string $newStatus): void
    {
        $this->store->storeEvent(
            procurementId: $procurementId,
            eventType: ProcurementEventType::StatusChanged,
            occurredAt: $occurredAt,
            revisionId: $revisionId,
            field: 'status',
            oldValue: $oldStatus,
            newValue: $newStatus,
        );
    }
}
