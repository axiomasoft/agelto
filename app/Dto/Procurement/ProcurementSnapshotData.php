<?php

declare(strict_types=1);

namespace App\Dto\Procurement;

use App\Dto\Procurement\Platform\SourceIdentity;
use Illuminate\Support\Carbon;

/**
 * Нормализованный снапшот закупки для инжест-процессора. Поля соответствуют диффуемым
 * колонкам `prc_procurements`, без id, uuid, revision metadata и timestamps.
 * Нормализацию CSV/HTML выполняет caller; `customer_org_id` устанавливает detail-слой через `setCustomerOrg()`.
 */
final readonly class ProcurementSnapshotData
{
    /**
     * @param  array<string, mixed>|null  $attributes
     */
    public function __construct(
        public int $registryId,
        public SourceIdentity $identity,
        public string $title,
        public string $sourceStage,
        public Carbon $publishedAt,
        public Carbon $sourceUpdatedAt,
        public bool $sourceUpdatedAtHasDayPrecision = false,
        public ?object $extension = null,
        public ?string $procedureType = null,
        public ?string $procedureTypeRaw = null,
        public ?string $initialPrice = null,
        public ?string $currency = null,
        public ?string $advancePercent = null,
        public ?string $okvedCode = null,
        public ?string $okvedAdditional = null,
        public ?string $regionCode = null,
        public ?Carbon $biddingStartsAt = null,
        public ?Carbon $biddingEndsAt = null,
        public ?Carbon $summaryAt = null,
        public ?string $detailUrl = null,
        public ?array $attributes = null,
        public ?Carbon $sourceUpdatedBefore = null,
    ) {}

    /**
     * Нормализованный снапшот для `prc_revisions.snapshot`/`snapshot_hash` — все диффуемые поля
     * + резолвленный канонический статус (сырой статус сам по себе недостаточен для диффа, канон
     * может измениться из-за правки `prc_status_mappings` без изменения source_stage).
     *
     * @return array<string, mixed>
     */
    public function toSnapshotArray(string $canonicalStatus): array
    {
        $snapshot = [
            'registry_id' => $this->registryId,
            'source_identity' => $this->identity->toArray(),
            'title' => $this->title,
            'status' => $canonicalStatus,
            'source_stage' => $this->sourceStage,
            'extension' => $this->extension,
            'procedure_type' => $this->procedureType,
            'procedure_type_raw' => $this->procedureTypeRaw,
            'initial_price' => $this->initialPrice,
            'currency' => $this->currency,
            'advance_percent' => $this->advancePercent,
            'okved_code' => $this->okvedCode,
            'okved_additional' => $this->okvedAdditional,
            'region_code' => $this->regionCode,
            'published_at' => $this->publishedAt->toIso8601String(),
            'source_updated_at' => $this->sourceUpdatedAt->toIso8601String(),
            'bidding_starts_at' => $this->biddingStartsAt?->toIso8601String(),
            'bidding_ends_at' => $this->biddingEndsAt?->toIso8601String(),
            'summary_at' => $this->summaryAt?->toIso8601String(),
            'detail_url' => $this->detailUrl,
            'attributes' => $this->attributes,
        ];

        ksort($snapshot);

        return $snapshot;
    }
}
