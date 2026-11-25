<?php

declare(strict_types=1);

namespace App\Services\Procurement\Process;

use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\PruneResult;
use App\Enums\Procurement\Process\ProjectionMode;
use App\Repositories\Procurement\Process\ProjectionRepository;
use App\Services\Procurement\Process\Contracts\ProjectionReducer;

/**
 * Единственный writer, удаляющий ledger events, на которые ссылаются projection receipts.
 * Перед удалением берёт FOR UPDATE ВСЕХ затронутых state rows в
 * sorted projector_key (никакого SHARE→UPDATE upgrade): maintenance rebuild откладывает batch
 * целиком, а идущий rebuild не теряет source, потому что его snapshot удерживает тот же lock.
 * Событие удаляется только если receipts всех required consumers есть и projection fanout
 * подтверждён; затронутые projectors необратимо получают history_complete=false, после чего
 * rebuild отказывает. Выбор КАКИЕ события устарели (active incidents, admin audit, provenance,
 * dedup) принадлежит политике RetentionService, сюда приходят уже отобранные IDs.
 */
final readonly class ProjectionRetention
{
    public function __construct(
        private ProjectionRegistry $registry,
        private ProjectionRepository $projections,
        private ProjectionPolicy $policy,
        private ProcessTransaction $transaction,
    ) {}

    /** @param list<ProcessUuid> $eventIds не больше `process.projection.prune_batch` за вызов */
    public function prune(array $eventIds): PruneResult
    {
        if (count($eventIds) > $this->policy->pruneBatch) {
            throw new \InvalidArgumentException('Prune batch exceeds process.projection.prune_batch.');
        }
        $eventIds = array_values(array_unique($eventIds, SORT_REGULAR));
        // Kind неизменяем: безопасно читать до locks, чтобы выбрать state rows для блокировки.
        $kinds = $this->projections->entityKinds($eventIds);
        if ($kinds === []) {
            return new PruneResult(0, 0, false);
        }
        $requiredByKind = [];
        foreach (array_unique($kinds) as $kind) {
            $requiredByKind[$kind] = array_map(
                static fn (ProjectionReducer $reducer): string => $reducer->key(),
                $this->registry->forEntityKind($kind),
            );
        }
        $keys = array_values(array_unique(array_merge(...array_values($requiredByKind))));

        return $this->transaction->run(function () use ($eventIds, $kinds, $requiredByKind, $keys): PruneResult {
            $locked = $this->projections->lockPruneStates($keys);
            if (in_array(ProjectionMode::Maintenance, $locked, strict: true)) {
                return PruneResult::maintenance();
            }
            $lockedKeys = array_keys($locked);
            $status = $this->projections->pruneEventStatus($eventIds);
            $eligible = [];
            $ineligible = 0;
            $touched = [];
            foreach ($eventIds as $eventId) {
                $kind = $kinds[$eventId->value] ?? null;
                $event = $status[$eventId->value] ?? null;
                if ($kind === null || $event === null) {
                    continue; // уже удалено: идемпотентный повтор
                }
                $required = $requiredByKind[$kind];
                if ($event['fanoutPending'] || array_diff($required, $event['receiptKeys']) !== [] || array_diff($required, $lockedKeys) !== []) {
                    $ineligible++;

                    continue;
                }
                $eligible[] = $eventId;
                foreach ($required as $key) {
                    $touched[$key] = true;
                }
            }
            $pruned = $this->projections->pruneEvents(eventIds: $eligible, projectorKeys: array_keys($touched));

            return new PruneResult($pruned, $ineligible, false);
        });
    }
}
