<?php

declare(strict_types=1);

namespace App\Services\Procurement\SourceControl;

use App\Dto\Procurement\Crawler\SourceControlPolicy;
use App\Dto\Procurement\Crawler\StagePolicy;
use App\Enums\Procurement\Crawler\SourceBreakerOutcome;
use App\Enums\Procurement\Crawler\SourceFailureKind;
use App\Enums\Procurement\Crawler\SourceFailureReaction;
use App\Enums\Procurement\Crawler\SourceStage;
use App\Models\Procurement\Crawler\SourceCircuit;
use App\Repositories\Procurement\Crawler\SourceCircuitRepository;

/**
 * Правила брейкера этапа (площадка × этап; этапы независимы). Только состояние: журнал и логи
 * ведёт `SourceFailureReporter` по возвращённому исходу.
 *
 * Два независимых счётчика, чтобы сетевой шум не прятал смену структуры и наоборот:
 * - `CountTowardWait` → `consecutive_failures`; порог → ожидание `retry_interval_minutes`,
 *   затем этап просто продолжает работу (пробных запросов нет).
 * - `CountTowardHold` → `consecutive_layout_failures`; порог → остановка (`held_at`) до
 *   оператора: повтор на изменённой вёрстке даст тот же результат.
 * Успех сбрасывает оба. Пороги — `StagePolicy` с переопределениями строки этапа.
 * `enabled = false`: счётчики идут, но этап не ждёт и не останавливается (пауза — всегда).
 */
final readonly class SourceCircuitBreaker
{
    public const string SystemActor = 'system';

    public function __construct(
        private SourceCircuitRepository $circuits,
        private SourceControlPolicy $policy,
    ) {}

    public function admits(int $platformId, SourceStage $stage): bool
    {
        return $this->circuits->forStage($platformId, $stage)->admits();
    }

    /** Действующие правила этапа: конфиг + переопределения из админки. */
    public function effectivePolicy(SourceCircuit $circuit): StagePolicy
    {
        return $this->policy->stage($circuit->stage)->withOverrides(
            failureThreshold: $circuit->failure_threshold,
            layoutThreshold: $circuit->layout_threshold,
            retryIntervalMinutes: $circuit->retry_interval_minutes,
        );
    }

    public function recordFailure(int $platformId, SourceStage $stage, SourceFailureKind $kind, SourceFailureReaction $reaction): SourceBreakerOutcome
    {
        return $this->circuits->withLock($platformId, $stage, function (SourceCircuit $circuit) use ($kind, $reaction): SourceBreakerOutcome {
            $circuit->last_failure_kind = $kind;
            $circuit->last_failure_at = now();
            $outcome = $circuit->admits() ? $this->count($circuit, $reaction) : SourceBreakerOutcome::NotAdmitting;
            $circuit->save();

            return $outcome;
        });
    }

    public function recordSuccess(int $platformId, SourceStage $stage): void
    {
        $this->circuits->resetCounters($platformId, $stage);
    }

    public function pause(SourceCircuit $circuit, string $actor): void
    {
        $circuit->update(['manually_paused' => true, 'changed_by' => $actor]);
    }

    /** Снимает паузу, остановку и ожидание; счётчики обнуляются. */
    public function resume(SourceCircuit $circuit, string $actor): void
    {
        $circuit->update([
            'manually_paused' => false,
            'held_at' => null,
            'retry_at' => null,
            'consecutive_failures' => 0,
            'consecutive_layout_failures' => 0,
            'changed_by' => $actor,
        ]);
    }

    /** Ожидание истекло: этап снова работает. */
    public function finishWaiting(SourceCircuit $circuit): void
    {
        $circuit->update(['retry_at' => null]);
    }

    private function count(SourceCircuit $circuit, SourceFailureReaction $reaction): SourceBreakerOutcome
    {
        $policy = $this->effectivePolicy($circuit);

        if ($reaction === SourceFailureReaction::CountTowardWait) {
            $circuit->consecutive_failures++;

            if (! $circuit->enabled || $circuit->consecutive_failures < $policy->failureThreshold) {
                return SourceBreakerOutcome::Running;
            }

            $circuit->consecutive_failures = 0;
            $circuit->retry_at = now()->addMinutes($policy->retryIntervalMinutes);
            $circuit->changed_by = self::SystemActor;

            return SourceBreakerOutcome::StartedWaiting;
        }

        if ($reaction === SourceFailureReaction::CountTowardHold) {
            $circuit->consecutive_layout_failures++;

            if (! $circuit->enabled || $circuit->consecutive_layout_failures < $policy->layoutThreshold) {
                return SourceBreakerOutcome::Running;
            }

            $circuit->consecutive_layout_failures = 0;
            $circuit->held_at = now();
            $circuit->changed_by = self::SystemActor;

            return SourceBreakerOutcome::StartedHold;
        }

        return SourceBreakerOutcome::Running;
    }
}
