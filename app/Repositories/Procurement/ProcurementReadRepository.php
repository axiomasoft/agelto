<?php

declare(strict_types=1);

namespace App\Repositories\Procurement;

use App\Dto\Procurement\Acquisition\Control\ProcurementIdentity;
use App\Models\Procurement\Procurement;
use App\Models\Procurement\ProcurementRevision;
use Illuminate\Support\Carbon;

final readonly class ProcurementReadRepository
{
    public function findProcurement(ProcurementIdentity $identity, bool $lock = false): ?Procurement
    {
        $procurement = Procurement::query()
            ->where('platform_id', $identity->platformId)
            ->where('source_identity_type', $identity->source->type->value)
            ->where('source_identity_value', $identity->source->value->value)
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->first();
        if ($procurement !== null && $procurement->registry_id !== $identity->registryId) {
            throw new \LogicException('Primary identity belongs to a different registry.');
        }

        return $procurement;
    }

    public function findLatestRevision(int $procurementId): ?ProcurementRevision
    {
        return ProcurementRevision::query()
            ->where('procurement_id', $procurementId)
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
