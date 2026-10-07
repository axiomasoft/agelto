<?php

declare(strict_types=1);

namespace App\Services\Procurement;

use App\Dto\Procurement\ProcurementSnapshotData;
use App\Enums\Procurement\ProcurementEventType;
use App\Models\Procurement\Procurement;
use App\Repositories\Procurement\ProcurementReadRepository;
use App\Repositories\Procurement\ProcurementStoreRepository;
use App\Support\Database\PostgresAdvisoryLock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
        private PostgresAdvisoryLock $locks,
    ) {}

    public function ingest(ProcurementSnapshotData $data, ?int $crawlerRunId = null): Procurement
    {
        return DB::transaction(function () use ($data, $crawlerRunId): Procurement {
            $now = Carbon::now();
            $resolution = $this->statusMapping->resolve(registryId: $data->registryId, sourceStage: $data->sourceStage);
            $snapshot = $data->toSnapshotArray(canonicalStatus: $resolution->status->value);
            $snapshotHash = hash('sha256', json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            $this->locks->acquireProcurement(
                registryId: $data->registryId,
                registryNumber: $data->registryNumber,
            );

            $procurement = $this->procurements->findProcurement(
                registryId: $data->registryId,
                registryNumber: $data->registryNumber,
            );

            $procurement = $procurement === null
                ? $this->discover(
                    data: $data,
                    status: $resolution->status->value,
                    snapshot: $snapshot,
                    snapshotHash: $snapshotHash,
                    crawlerRunId: $crawlerRunId,
                    now: $now,
                )
                : $this->applyRevision(
                    procurement: $procurement,
                    data: $data,
                    status: $resolution->status->value,
                    snapshot: $snapshot,
                    snapshotHash: $snapshotHash,
                    crawlerRunId: $crawlerRunId,
                    now: $now,
                );

            $this->outcomeEvaluator->evaluate(procurement: $procurement);

            return $procurement;
        });
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function discover(
        ProcurementSnapshotData $data,
        string $status,
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
        ?int $crawlerRunId,
        Carbon $now,
    ): Procurement {
        $latestRevision = $this->procurements->findLatestRevision(procurementId: $procurement->id);

        if ($latestRevision !== null && $latestRevision->snapshot_hash === $snapshotHash) {
            $this->store->touchLastSeen(procurement: $procurement, seenAt: $now);

            return $procurement;
        }

        if ($this->isOlderThanHead(procurement: $procurement, data: $data)) {
            Log::info(message: 'ProcurementIngestProcessor: снапшот старше текущей головы закупки пропущен.', context: [
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

        $oldSnapshot = $latestRevision === null ? [] : $latestRevision->snapshot;
        $this->storeDiffEvents(procurementId: $procurement->id, revisionId: $revision->id, occurredAt: $now, oldSnapshot: $oldSnapshot, newSnapshot: $snapshot);

        return $procurement->refresh();
    }

    /**
     * Листинг читается ВНЕ advisory lock, поэтому два прогона (fresh и delta/backlog) могут
     * инжестить один номер в обратном порядке: блок, снятый раньше, приходит позже. Без этой
     * проверки устаревший снапшот становился новой головой и порождал ложную ревизию «откат»
     * (status_changed назад), которую следующий скан снова «исправлял». Для даты с точностью до
     * суток (ЕИС «Дата обновления») сравнивается календарный день — время 00:00 не означает
     * «раньше любого события того же дня».
     */
    private function isOlderThanHead(Procurement $procurement, ProcurementSnapshotData $data): bool
    {
        $headUpdatedAt = $procurement->source_updated_at;

        if ($headUpdatedAt === null) {
            return false;
        }

        if ($data->sourceUpdatedAtHasDayPrecision) {
            return $data->sourceUpdatedAt->toDateString() < $headUpdatedAt->toDateString();
        }

        return $data->sourceUpdatedAt->lt($headUpdatedAt);
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
