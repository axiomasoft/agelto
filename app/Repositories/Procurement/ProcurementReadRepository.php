<?php

declare(strict_types=1);

namespace App\Repositories\Procurement;

use App\Models\Procurement\Procurement;
use App\Models\Procurement\ProcurementRevision;
use Illuminate\Support\Carbon;

final readonly class ProcurementReadRepository
{
    public function findProcurement(int $registryId, string $registryNumber): ?Procurement
    {
        return Procurement::query()
            ->where('registry_id', $registryId)
            ->where('registry_number', $registryNumber)
            ->first();
    }

    public function findLatestRevision(int $procurementId): ?ProcurementRevision
    {
        return ProcurementRevision::query()
            ->where('procurement_id', $procurementId)
            ->orderByDesc('detected_at')
            ->orderByDesc('revision_number')
            ->first();
    }

    /**
     * Ревизия, действовавшая на дату X: последняя с `detected_at <= $asOf` (чек-лист §11 «Состояние на дату X»).
     */
    public function findRevisionAsOf(int $procurementId, Carbon $asOf): ?ProcurementRevision
    {
        return ProcurementRevision::query()
            ->where('procurement_id', $procurementId)
            ->where('detected_at', '<=', $asOf)
            ->orderByDesc('detected_at')
            ->orderByDesc('revision_number')
            ->first();
    }
}
