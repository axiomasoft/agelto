<?php

declare(strict_types=1);

namespace Tests\Support\Procurement;

use App\Dto\Procurement\ProcurementSnapshotData;
use App\Models\Procurement\Platform\Registry;
use Illuminate\Support\Carbon;

/**
 * Тестовый билдер `ProcurementSnapshotData` для сценариев §11, гоняемых через
 * `ProcurementIngestProcessor` (Discovery/Revision/StatusMapping).
 */
final class SnapshotFactory
{
    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function make(Registry $registry, array $overrides = []): ProcurementSnapshotData
    {
        return new ProcurementSnapshotData(
            registryId: $registry->id,
            registryNumber: $overrides['registryNumber'] ?? '0173100000123000001',
            title: $overrides['title'] ?? 'Поставка бумаги офисной',
            sourceStage: $overrides['sourceStage'] ?? 'Размещение извещения',
            publishedAt: $overrides['publishedAt'] ?? Carbon::parse('2026-01-10 10:00:00'),
            sourceUpdatedAt: $overrides['sourceUpdatedAt'] ?? Carbon::parse('2026-01-10 10:00:00'),
            initialPrice: $overrides['initialPrice'] ?? '150000.00',
            biddingStartsAt: $overrides['biddingStartsAt'] ?? null,
            biddingEndsAt: $overrides['biddingEndsAt'] ?? null,
            summaryAt: $overrides['summaryAt'] ?? null,
        );
    }
}
