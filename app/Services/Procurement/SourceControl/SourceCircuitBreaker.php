<?php

declare(strict_types=1);

namespace App\Services\Procurement\SourceControl;

use App\Enums\Procurement\Crawler\SourceBreakerOutcome;
use App\Enums\Procurement\Crawler\SourceFailureKind;
use App\Enums\Procurement\Crawler\SourceStage;
use App\Models\Procurement\Crawler\SourceCircuit;
use App\Repositories\Procurement\Crawler\SourceCircuitRepository;

/**
 * Правила брейкера этапа (площадка × этап; этапы независимы). Только состояние: журнал и логи
 * ведёт `SourceFailureReporter` по возвращённому исходу.
 *
 * - `failure_threshold` отказов подряд → ожидание `retry_interval_minutes`, затем этап просто
 *   продолжает работу (без пробных запросов — решение владельца).
 * - Если порог добил отказ «изменилась структура» (`requiresOperator`) — этап останавливается
 *   до решения оператора (`manually_paused`, `changed_by = system`), таймера нет: повтор на
 *   изменённой вёрстке даст тот же результат.
 * - Ошибки нашего кода, пустое тело и ошибки строк счётчик не увеличивают.
 *
 * Ревьюеру: серия считается по любым видам вперемешку; остановка решается видом отказа,
 * добившего порог. Если это окажется шумно — считать дрейф отдельным счётчиком (нужно обсуждение).
 */
final readonly class SourceCircuitBreaker
{
    public const string SystemActor = 'system';

    public function __construct(private SourceCircuitRepository $circuits) {}

    public function admits(int $platformId, SourceStage $stage): bool
    {
        return $this->circuits->forStage($platformId, $stage)->admits();
    }

    public function recordFailure(int $platformId, SourceStage $stage, SourceFailureKind $kind): SourceBreakerOutcome
    {
        return $this->circuits->withLock($platformId, $stage, function (SourceCircuit $circuit) use ($kind): SourceBreakerOutcome {
            $circuit->last_failure_kind = $kind;
            $circuit->last_failure_at = now();

            if (! $circuit->admits()) {
                $circuit->save();

                return SourceBreakerOutcome::NotAdmitting;
            }

            if ($kind->countsTowardCircuit()) {
                $circuit->consecutive_failures++;
            }

            $outcome = SourceBreakerOutcome::Running;

            if ($circuit->enabled && $circuit->consecutive_failures >= max(1, $circuit->failure_threshold)) {
                $outcome = $kind->requiresOperator() ? SourceBreakerOutcome::StartedHold : SourceBreakerOutcome::StartedWaiting;
                $circuit->consecutive_failures = 0;
                $circuit->changed_by = self::SystemActor;

                if ($outcome === SourceBreakerOutcome::StartedHold) {
                    $circuit->manually_paused = true;
                } else {
                    $circuit->retry_at = now()->addMinutes(max(1, $circuit->retry_interval_minutes));
                }
            }

            $circuit->save();

            return $outcome;
        });
    }

    public function recordSuccess(int $platformId, SourceStage $stage): void
    {
        $this->circuits->resetFailures($platformId, $stage);
    }

    public function pause(SourceCircuit $circuit, string $actor): void
    {
        $circuit->update(['manually_paused' => true, 'changed_by' => $actor]);
    }

    /** Снимает паузу/остановку и ожидание, счётчик обнуляется. */
    public function resume(SourceCircuit $circuit, string $actor): void
    {
        $circuit->update(['manually_paused' => false, 'retry_at' => null, 'consecutive_failures' => 0, 'changed_by' => $actor]);
    }

    /** Ожидание истекло: этап снова работает. */
    public function finishWaiting(SourceCircuit $circuit): void
    {
        $circuit->update(['retry_at' => null]);
    }
}
