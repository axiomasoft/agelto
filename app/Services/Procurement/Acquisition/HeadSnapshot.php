<?php

declare(strict_types=1);

namespace App\Services\Procurement\Acquisition;

use App\Dto\Procurement\Platform\SourceIdentity;
use App\Dto\Procurement\ProcurementSnapshotData;
use App\Models\Procurement\Procurement;
use App\Repositories\Procurement\ProcurementReadRepository;
use App\Services\Procurement\Platform\PlatformRegistry;
use Illuminate\Support\Collection;

final readonly class HeadSnapshot
{
    public function __construct(private PlatformRegistry $platforms,
        private ProcurementReadRepository $read) {}

    public function make(Procurement $p, SourceIdentity $identity, ?string $title = null, ?string $stage = null,
        ?object $extension = null, ?string $initialPrice = null): ProcurementSnapshotData
    {
        if ($extension === null) {
            $saved = $this->read->findLatestRevision($p->id)?->snapshot['extension'] ?? null;
            $type = $this->platforms->get($identity->platform)->extension?->dto;
            if (is_array($saved) && $type !== null && class_exists($type)) {
                $extension = (new DetailValueCodec($type))->decode(json_encode(['type' => $type, 'value' => $saved], JSON_THROW_ON_ERROR));
            }
        }
        $raw = $p->getAttribute('attributes');
        $attributes = null;
        if ($raw instanceof Collection) {
            $attributes = [];
            foreach ($raw as $key => $value) {
                if (! is_string($key)) {
                    throw new \LogicException('Invalid diagnostic attribute key.');
                }
                $attributes[$key] = $value;
            }
        } elseif ($raw !== null) {
            throw new \LogicException('Invalid diagnostic attributes.');
        }

        return new ProcurementSnapshotData($p->registry_id, $identity, $title ?? $p->title, $stage ?? $p->source_stage,
            $p->published_at, $p->source_updated_at ?? $p->published_at,
            sourceUpdatedAtHasDayPrecision: $p->source_updated_has_day_precision,
            extension: $extension, procedureType: $p->procedure_type, procedureTypeRaw: $p->procedure_type_raw,
            initialPrice: $initialPrice ?? $p->initial_price, currency: $p->currency, advancePercent: $p->advance_percent,
            okvedCode: $p->okved_code, okvedAdditional: $p->okved_additional, regionCode: $p->region_code,
            biddingStartsAt: $p->bidding_starts_at, biddingEndsAt: $p->bidding_ends_at, summaryAt: $p->summary_at,
            detailUrl: $p->detail_url, attributes: $attributes, sourceUpdatedBefore: $p->source_updated_before);
    }
}
