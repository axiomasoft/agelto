<?php

declare(strict_types=1);

namespace App\Repositories\Procurement;

use App\Dto\Procurement\ProcurementSnapshotData;
use App\Enums\Procurement\ProcurementEventType;
use App\Models\Procurement\Procurement;
use App\Models\Procurement\ProcurementEvent;
use App\Models\Procurement\ProcurementRevision;
use Illuminate\Support\Carbon;

final readonly class ProcurementStoreRepository
{
    public function storeProcurement(ProcurementSnapshotData $data, string $status, Carbon $seenAt): Procurement
    {
        return Procurement::query()->create([
            'registry_id' => $data->registryId,
            'source_number' => $data->sourceNumber,
            'etp_number' => $data->etpNumber,
            'ikz' => $data->ikz,
            'title' => $data->title,
            'status' => $status,
            'source_status' => $data->sourceStatus,
            'procedure_type' => $data->procedureType,
            'procedure_type_raw' => $data->procedureTypeRaw,
            'customer_org_id' => $data->customerOrgId,
            'initial_price' => $data->initialPrice,
            'currency' => $data->currency,
            'advance_percent' => $data->advancePercent,
            'okpd2_code' => $data->okpd2Code,
            'okpd2_codes' => $data->okpd2Codes,
            'okved_code' => $data->okvedCode,
            'okved_additional' => $data->okvedAdditional,
            'region_code' => $data->regionCode,
            'published_at' => $data->publishedAt,
            'source_updated_at' => $data->sourceUpdatedAt,
            'bidding_starts_at' => $data->biddingStartsAt,
            'bidding_ends_at' => $data->biddingEndsAt,
            'summary_at' => $data->summaryAt,
            'detail_url' => $data->detailUrl,
            'source_guid' => $data->sourceGuid,
            'attributes' => $data->attributes,
            'revision_count' => 1,
            'first_seen_at' => $seenAt,
            'last_seen_at' => $seenAt,
        ]);
    }

    public function updateProcurementHead(
        Procurement $procurement,
        ProcurementSnapshotData $data,
        string $status,
        ProcurementRevision $revision,
        int $revisionCount,
        Carbon $seenAt,
    ): void {
        $procurement->update([
            'title' => $data->title,
            'status' => $status,
            'source_status' => $data->sourceStatus,
            'procedure_type' => $data->procedureType,
            'procedure_type_raw' => $data->procedureTypeRaw,
            'customer_org_id' => $data->customerOrgId,
            'initial_price' => $data->initialPrice,
            'currency' => $data->currency,
            'advance_percent' => $data->advancePercent,
            'okpd2_code' => $data->okpd2Code,
            'okpd2_codes' => $data->okpd2Codes,
            'okved_code' => $data->okvedCode,
            'okved_additional' => $data->okvedAdditional,
            'region_code' => $data->regionCode,
            'source_updated_at' => $data->sourceUpdatedAt,
            'bidding_starts_at' => $data->biddingStartsAt,
            'bidding_ends_at' => $data->biddingEndsAt,
            'summary_at' => $data->summaryAt,
            'detail_url' => $data->detailUrl,
            'source_guid' => $data->sourceGuid,
            'attributes' => $data->attributes,
            'current_revision_id' => $revision->id,
            'current_revision_detected_at' => $revision->detected_at,
            'revision_count' => $revisionCount,
            'last_seen_at' => $seenAt,
        ]);
    }

    public function touchLastSeen(Procurement $procurement, Carbon $seenAt): void
    {
        $procurement->update(['last_seen_at' => $seenAt]);
    }

    /**
     * Детальный слой снят успешно (`02-crawling.md` §5.2) — error-блок сбрасывается,
     * повторный успешный снимок «лечит» ранее накопленные отказы.
     */
    public function markDetailFetched(Procurement $procurement, Carbon $fetchedAt): void
    {
        $procurement->update([
            'detail_fetched_at' => $fetchedAt,
            'detail_attempts' => 0,
            'detail_last_error' => null,
            'detail_next_retry_at' => null,
            'detail_failed' => false,
        ]);
    }

    /**
     * Снятие детального слоя упало (`01-database.md` §11 «Детальная карточка упала») —
     * error-блок `detail_*`, ретрай по `detail_next_retry_at` без реконструкции из логов.
     */
    public function markDetailFailed(Procurement $procurement, string $error, ?Carbon $nextRetryAt, bool $failed): void
    {
        $procurement->update([
            'detail_attempts' => $procurement->detail_attempts + 1,
            'detail_last_error' => $error,
            'detail_next_retry_at' => $nextRetryAt,
            'detail_failed' => $failed,
        ]);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function storeRevision(
        int $procurementId,
        int $revisionNumber,
        array $snapshot,
        string $snapshotHash,
        Carbon $detectedAt,
        ?Carbon $sourceUpdatedAt = null,
        ?int $crawlerRunId = null,
        ?string $sourceEdition = null,
        ?string $changeReason = null,
    ): ProcurementRevision {
        return ProcurementRevision::query()->create([
            'procurement_id' => $procurementId,
            'revision_number' => $revisionNumber,
            'source_edition' => $sourceEdition,
            'change_reason' => $changeReason,
            'snapshot' => $snapshot,
            'snapshot_hash' => $snapshotHash,
            'source_updated_at' => $sourceUpdatedAt,
            'detected_at' => $detectedAt,
            'crawler_run_id' => $crawlerRunId,
        ]);
    }

    public function hasEvent(
        int $procurementId,
        ProcurementEventType $eventType,
        Carbon $occurredAt,
        ?string $newValue = null,
    ): bool {
        return ProcurementEvent::query()
            ->where('procurement_id', $procurementId)
            ->where('event_type', $eventType)
            ->where('occurred_at', $occurredAt)
            ->where('new_value', $newValue)
            ->exists();
    }

    public function storeEvent(
        int $procurementId,
        ProcurementEventType $eventType,
        Carbon $occurredAt,
        ?int $revisionId = null,
        ?string $field = null,
        ?string $oldValue = null,
        ?string $newValue = null,
        ?array $entityRef = null,
        ?array $payload = null,
    ): ProcurementEvent {
        return ProcurementEvent::query()->create([
            'procurement_id' => $procurementId,
            'revision_id' => $revisionId,
            'event_type' => $eventType,
            'entity_ref' => $entityRef,
            'field' => $field,
            'old_value' => $oldValue,
            'new_value' => $newValue,
            'payload' => $payload,
            'occurred_at' => $occurredAt,
        ]);
    }
}
