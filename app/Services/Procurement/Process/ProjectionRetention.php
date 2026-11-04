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
        $keys = [];
        foreach ($kinds as $kind) {
            foreach ($this->registry->forEntityKind($kind) as $reducer) {
                $keys[$reducer->key()] = true;
            }
        }
        $keys = array_keys($keys);
        sort($keys);

        return $this->transaction->run(function () use ($eventIds, $kinds, $keys): PruneResult {
            $locked = [];
            foreach ($keys as $key) {
                $state = $this->projections->stateForUpdate($key);
                if ($state !== null && $state->mode === ProjectionMode::Maintenance) {
                    return PruneResult::maintenance();
                }
                $locked[$key] = $state !== null;
            }
            $pruned = 0;
            $ineligible = 0;
            $touched = [];
            foreach ($eventIds as $eventId) {
                $kind = $kinds[$eventId->value] ?? null;
                if ($kind === null) {
                    continue; // уже удалено: идемпотентный повтор
                }
                $required = array_map(static fn (ProjectionReducer $reducer): string => $reducer->key(), $this->registry->forEntityKind($kind));
                $complete = array_diff($required, $this->projections->receiptKeys($eventId, $required)) === []
                    && array_reduce($required, static fn (bool $all, string $key): bool => $all && ($locked[$key] ?? false), true)
                    && ! $this->projections->fanoutPending($eventId);
                if (! $complete) {
                    $ineligible++;

                    continue;
                }
                $this->projections->deleteEvent($eventId);
                $pruned++;
                foreach ($required as $key) {
                    $touched[$key] = true;
                }
            }
            $this->projections->markHistoryPruned(array_keys($touched));

            return new PruneResult($pruned, $ineligible, false);
        });
    }
}
