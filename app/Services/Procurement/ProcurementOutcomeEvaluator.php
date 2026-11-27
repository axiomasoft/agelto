<?php

declare(strict_types=1);

namespace App\Services\Procurement;

use App\Enums\Procurement\ProcurementEventType;
use App\Enums\Procurement\ProcurementStatus;
use App\Events\Procurement\ProcurementSucceeded;
use App\Models\Procurement\Estimate\Estimate;
use App\Models\Procurement\Procurement;
use App\Repositories\Procurement\ProcurementStoreRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Единственный носитель правила положительного исхода: канонический статус или award с победителем.
 */
final readonly class ProcurementOutcomeEvaluator
{
    public function __construct(private ProcurementStoreRepository $store) {}

    public function evaluate(Procurement $procurement): void
    {
        $procurement = $procurement->fresh();

        if ($procurement === null) {
            throw new \LogicException('Нельзя вычислить outcome удалённой закупки.');
        }

        if ($procurement->getAttribute('succeeded_at') !== null) {
            return;
        }

        $award = $procurement->awards()->whereNotNull('winner_org_id')->first();
        $status = $procurement->getAttribute('status');
        assert($status instanceof ProcurementStatus);
        $isStatusOutcome = $status->isPositiveTerminal();

        if (! $isStatusOutcome && $award === null) {
            return;
        }

        $succeededAt = Carbon::now();

        if (! $this->store->markSucceeded(procurement: $procurement, succeededAt: $succeededAt)) {
            return;
        }

        $reason = $isStatusOutcome ? 'status' : 'award';
        $estimatesCount = Estimate::query()->where('procurement_id', $procurement->id)->count();

        $this->store->storeEvent(
            procurementId: $procurement->id,
            eventType: ProcurementEventType::Succeeded,
            occurredAt: $succeededAt,
            entityRef: $award === null ? null : ['award_id' => $award->id],
            payload: ['reason' => $reason, 'winner_org_id' => $award?->getAttribute('winner_org_id')],
        );

        DB::afterCommit(function () use ($procurement, $award, $reason, $status, $succeededAt, $estimatesCount): void {
            event(new ProcurementSucceeded(
                procurementId: $procurement->id,
                registryId: $this->intAttribute($procurement, 'registry_id'),
                registryNumber: $this->stringAttribute($procurement, 'source_identity_value'),
                canonicalStatus: $status->value,
                reason: $reason,
                succeededAt: $succeededAt,
                hasEstimate: $estimatesCount > 0,
                estimatesCount: $estimatesCount,
                winnerOrgId: $award === null ? null : $this->intValue($award->getAttribute('winner_org_id'), 'award winner_org_id'),
                awardId: $award?->id,
            ));
        });
    }

    private function intAttribute(Procurement $procurement, string $attribute): int
    {
        return $this->intValue($procurement->getAttribute($attribute), "procurement {$attribute}");
    }

    private function stringAttribute(Procurement $procurement, string $attribute): string
    {
        $value = $procurement->getAttribute($attribute);

        if (! is_string($value)) {
            throw new \LogicException("procurement {$attribute} должен быть строкой.");
        }

        return $value;
    }

    private function intValue(mixed $value, string $context): int
    {
        if (! is_int($value)) {
            throw new \LogicException("{$context} должен быть целым числом.");
        }

        return $value;
    }
}
