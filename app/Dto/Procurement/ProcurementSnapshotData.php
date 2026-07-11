<?php

declare(strict_types=1);

namespace App\Dto\Procurement;

use Illuminate\Support\Carbon;

/**
 * Нормализованный снапшот закупки — вход инжест-процессора.
 * Поля 1:1 диффуемым колонкам `prc_procurements` (без служебных: id/uuid/revision-мета/timestamps).
 * Нормализацию из сырого CSV/HTML источника выполняет вызывающий код (вне Scope P1.10).
 * `customer_org_id` — не поле листинга, владелец detail-слой (`setCustomerOrg()`).
 */
final readonly class ProcurementSnapshotData
{
    /**
     * @param  list<string>|null  $okpd2Codes
     * @param  array<string, mixed>|null  $attributes
     */
    public function __construct(
        public int $registryId,
        public string $registryNumber,
        public string $title,
        public string $sourceStage,
        public Carbon $publishedAt,
        public Carbon $sourceUpdatedAt,
        public ?string $etpNumber = null,
        public ?string $ikz = null,
        public ?string $procedureType = null,
        public ?string $procedureTypeRaw = null,
        public ?string $initialPrice = null,
        public ?string $currency = null,
        public ?string $advancePercent = null,
        public ?string $okpd2Code = null,
        public ?array $okpd2Codes = null,
        public ?string $okvedCode = null,
        public ?string $okvedAdditional = null,
        public ?string $regionCode = null,
        public ?Carbon $biddingStartsAt = null,
        public ?Carbon $biddingEndsAt = null,
        public ?Carbon $summaryAt = null,
        public ?string $detailUrl = null,
        public ?string $registryGuid = null,
        public ?array $attributes = null,
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
            'registry_number' => $this->registryNumber,
            'title' => $this->title,
            'status' => $canonicalStatus,
            'source_stage' => $this->sourceStage,
            'etp_number' => $this->etpNumber,
            'ikz' => $this->ikz,
            'procedure_type' => $this->procedureType,
            'procedure_type_raw' => $this->procedureTypeRaw,
            'initial_price' => $this->initialPrice,
            'currency' => $this->currency,
            'advance_percent' => $this->advancePercent,
            'okpd2_code' => $this->okpd2Code,
            'okpd2_codes' => $this->okpd2Codes,
            'okved_code' => $this->okvedCode,
            'okved_additional' => $this->okvedAdditional,
            'region_code' => $this->regionCode,
            'published_at' => $this->publishedAt->toIso8601String(),
            'source_updated_at' => $this->sourceUpdatedAt->toIso8601String(),
            'bidding_starts_at' => $this->biddingStartsAt?->toIso8601String(),
            'bidding_ends_at' => $this->biddingEndsAt?->toIso8601String(),
            'summary_at' => $this->summaryAt?->toIso8601String(),
            'detail_url' => $this->detailUrl,
            'registry_guid' => $this->registryGuid,
            'attributes' => $this->attributes,
        ];

        ksort($snapshot);

        return $snapshot;
    }
}
